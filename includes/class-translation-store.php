<?php
defined( 'ABSPATH' ) || exit;

/**
 * Storage layer: one row per (language, object type, object id, field).
 * A translation is only served while the hash of its source text matches the current original.
 */
class LT_Translation_Store {
	const LIVE_STATUSES = array( 'translated', 'reviewed', 'partial' ); // partial: some paragraphs translated (shown, but still to finish)

	/** @var array<string,array> Preloaded global strings, per language. */
	private static $strings = array();
	private static $translations = array();

	public static function table() { global $wpdb; return $wpdb->prefix . 'light_translations'; }

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
id bigint unsigned NOT NULL AUTO_INCREMENT,
language_code varchar(12) NOT NULL,
object_type varchar(24) NOT NULL,
object_id bigint unsigned NOT NULL,
field_key varchar(191) NOT NULL,
source_text longtext NOT NULL,
translated_text longtext NOT NULL,
source_hash char(64) NOT NULL,
status varchar(16) NOT NULL DEFAULT 'empty',
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY translation_key (language_code,object_type,object_id,field_key),
KEY source_hash (source_hash),
KEY field_key (field_key),
KEY object_lookup (language_code,object_type,object_id),
KEY type_lookup (object_type,object_id),
KEY slug_lookup (language_code,field_key(40),translated_text(100))
) $charset;" );
	}

	/** Identical interface texts (same original) all get the translation one of them already has. @return int rows filled */
	public static function sync_identical() {
		global $wpdb;
		$table = self::table();
		$count = (int) $wpdb->query( "UPDATE $table a JOIN (SELECT language_code, source_hash, MAX(translated_text) AS tt FROM $table WHERE object_type='string' AND object_id=0 AND status IN ('translated','reviewed') AND translated_text <> '' GROUP BY language_code, source_hash) b ON a.language_code=b.language_code AND a.source_hash=b.source_hash SET a.translated_text=b.tt, a.status='translated' WHERE a.object_type='string' AND a.object_id=0 AND (a.translated_text='' OR a.status='empty')" ); // phpcs:ignore WordPress.DB
		if ( $count ) foreach ( LT_Language_Router::instance()->languages() as $language ) { delete_transient( 'lt_strings_' . $language ); delete_transient( 'lt_wcjs_' . $language ); }
		return $count;
	}

	public static function hash( $source ) { return hash( 'sha256', (string) $source ); }

	public static function source_matches( $row, $source ) { return $row && hash_equals( (string) $row->source_hash, self::hash( $source ) ); }

	/** @var array Translations loaded in one query for a list of items: [lang][type][id] => [field => row]. */
	private static $primed = array();
	private static $partial = array();
	/** @var array Global object_id=0 rows loaded as a map for list screens such as WooCommerce e-mails. */
	private static $global_primed = array();
	private static $global_rows = array();
	/** @var array Performance counters shown in debug mode. */
	public static $stats = array( 'queries' => 0, 'primed' => 0, 'strings' => 0, 'strings_cache' => '' );

	/**
	 * Loads in ONE query the translations of many items (e.g. the products of a shop page), so the
	 * title / description filters do not run one query per product and per field.
	 */
	public static function prime( $language, $type, $ids ) {
		global $wpdb;
		$ids = array_values( array_diff( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ), array_keys( self::$primed[ $language ][ $type ] ?? array() ) ) );
		if ( ! $language || ! $type || ! $ids ) return;
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE language_code=%s AND object_type=%s AND object_id IN (' . implode( ',', $chunk ) . ')', $language, $type ) ); // phpcs:ignore WordPress.DB
			self::$stats['queries']++;
			foreach ( $chunk as $id ) self::$primed[ $language ][ $type ][ $id ] = array();
			foreach ( (array) $rows as $row ) self::$primed[ $language ][ $type ][ (int) $row->object_id ][ $row->field_key ] = $row;
			self::$stats['primed'] += count( $chunk );
		}
	}

	public static function get( $language, $type, $object_id, $field ) {
		// One query loads every field of the item (title, content, slug…), reused for the rest of the page.
		if ( ! (int) $object_id ) { // global texts (interface, e-mails): one row
			if ( ! empty( self::$global_primed[ $language ][ $type ] ) ) return self::$global_rows[ $language ][ $type ][ $field ] ?? null;
			global $wpdb;
			self::$stats['queries']++;
			return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE language_code=%s AND object_type=%s AND object_id=0 AND field_key=%s', $language, $type, $field ) );
		}
		if ( ! isset( self::$primed[ $language ][ $type ][ (int) $object_id ] ) && array_key_exists( $field, self::$partial[ $language ][ $type ][ (int) $object_id ] ?? array() ) ) return self::$partial[ $language ][ $type ][ (int) $object_id ][ $field ];
		if ( ! isset( self::$primed[ $language ][ $type ][ (int) $object_id ] ) ) self::prime( $language, $type, array( $object_id ) );
		return self::$primed[ $language ][ $type ][ (int) $object_id ][ $field ] ?? null;
	}

	/** Load all global translation rows of one type in a single query. Safe to call more than once per request. */
	public static function prime_global( $language, $type ) {
		if ( ! $language || ! $type || ! empty( self::$global_primed[ $language ][ $type ] ) ) return;
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE language_code=%s AND object_type=%s AND object_id=0', $language, $type ) ); // phpcs:ignore WordPress.DB
		self::$stats['queries']++;
		self::$global_rows[ $language ][ $type ] = array();
		foreach ( (array) $rows as $row ) self::$global_rows[ $language ][ $type ][ $row->field_key ] = $row;
		self::$global_primed[ $language ][ $type ] = true;
	}

	/** Product grids and menus need their labels and links, not every long description. */
	public static function prime_fields( $language, $type, $ids, $fields ) {
		global $wpdb;
		$fields = array_values( array_unique( array_filter( array_map( 'sanitize_key', $fields ) ) ) );
		$ids = array_values( array_filter( array_unique( array_map( 'absint', $ids ) ), function( $id ) use ( $language, $type, $fields ) {
			return $id && ! isset( self::$primed[ $language ][ $type ][ $id ] ) && array_diff( $fields, array_keys( self::$partial[ $language ][ $type ][ $id ] ?? array() ) );
		} ) );
		if ( ! $language || ! $fields || ! $ids ) return;
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$args = array_merge( array( $language, $type ), $fields );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE language_code=%s AND object_type=%s AND object_id IN (' . implode( ',', $chunk ) . ') AND field_key IN (' . implode( ',', array_fill( 0, count( $fields ), '%s' ) ) . ')', $args ) );
			foreach ( $chunk as $id ) foreach ( $fields as $field ) self::$partial[ $language ][ $type ][ $id ][ $field ] = null;
			foreach ( (array) $rows as $row ) self::$partial[ $language ][ $type ][ (int) $row->object_id ][ $row->field_key ] = $row;
			self::$stats['queries']++; self::$stats['primed'] += count( $chunk );
		}
	}

	/**
	 * Translation of one interface text, or null. Plain array lookup in the cached map; the original is checked
	 * once per text (a changed original must not show an old translation).
	 */
	public static function string_lookup( $language, $field, $source ) {
		static $checked = array();
		$map = self::strings( $language );
		if ( ! isset( $map[ $field ] ) ) return null;
		$key = $language . $field . self::hash( $source );
		if ( ! isset( $checked[ $key ] ) ) $checked[ $key ] = self::source_matches( $map[ $field ], $source );
		return $checked[ $key ] ? (string) $map[ $field ]->translated_text : null;
	}

	/** Short hashes of every translated content of a language (cached): lets the site skip queries for untranslated content. */
	public static function content_hashes( $language ) {
		static $hashes = array();
		if ( ! isset( $hashes[ $language ] ) ) {
			$cached = get_transient( 'lt_content_hashes_' . $language );
			if ( ! is_array( $cached ) ) {
				global $wpdb;
				$cached = array_fill_keys( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT LEFT(source_hash,16) FROM ' . self::table() . " WHERE language_code=%s AND field_key='content' AND status IN ('translated','reviewed','partial') AND translated_text <> ''", $language ) ), true ); // phpcs:ignore WordPress.DB
				set_transient( 'lt_content_hashes_' . $language, $cached, DAY_IN_SECONDS );
			}
			$hashes[ $language ] = $cached;
		}
		return $hashes[ $language ];
	}

	/** Returns the row only when it holds a usable (non-empty, published) translation, whatever its source hash. */
	public static function get_translated( $language, $type, $object_id, $field ) {
		$row = self::get( $language, $type, $object_id, $field );
		if ( ! $row || ! in_array( $row->status, self::LIVE_STATUSES, true ) || '' === trim( (string) $row->translated_text ) ) return null;
		return $row;
	}

	/** Loads every global string translation for a language in one query (gettext is called hundreds of times per page). */
	/** Translated interface texts of a language, cached (no database query on most page views). */
	private static function strings( $language ) {
		if ( ! isset( self::$strings[ $language ] ) ) {
			$cached = get_transient( 'lt_strings_' . $language );
			if ( is_array( $cached ) ) {
				self::$stats['strings_cache'] = 'hit';
			} else {
				global $wpdb;
				$cached = array();
				$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT field_key, source_text, translated_text, source_hash, status FROM ' . self::table() . " WHERE language_code=%s AND object_type='string' AND object_id=0 AND status IN ('translated','reviewed') AND translated_text <> ''", $language ) );
				$by_text = array();
				foreach ( (array) $rows as $row ) {
					$cached[ $row->field_key ] = (object) array( 'translated_text' => $row->translated_text, 'source_hash' => $row->source_hash, 'status' => $row->status );
					if ( strlen( (string) $row->source_text ) < 500 ) $by_text[ md5( trim( (string) $row->source_text ) ) ] = $row->translated_text; // same wording anywhere = same translation
				}
				$cached['__by_text'] = $by_text;
				set_transient( 'lt_strings_' . $language, $cached, 12 * HOUR_IN_SECONDS );
				self::$stats['queries']++;
				self::$stats['strings_cache'] = 'miss';
			}
			self::$strings[ $language ] = $cached;
			self::$stats['strings'] = count( $cached );
		}
		return self::$strings[ $language ];
	}

	/** Translated interface text by key, without comparing the original (used for JavaScript texts). */
	public static function string_value( $language, $field ) {
		$map = self::strings( $language );
		return isset( $map[ $field ] ) ? (string) $map[ $field ]->translated_text : null;
	}

	/** Translated interface text as a row (translated_text, source_hash, status) from the cached map, or null. */
	public static function string_row( $language, $field ) {
		$map = self::strings( $language );
		return isset( $map[ $field ] ) && is_object( $map[ $field ] ) ? $map[ $field ] : null;
	}

	/** Translation of a visible wording, whatever plugin / context it comes from (null when none). */
	public static function by_text( $language, $text ) {
		$map = self::strings( $language );
		$key = md5( trim( (string) $text ) );
		return isset( $map['__by_text'][ $key ] ) ? (string) $map['__by_text'][ $key ] : null;
	}

	/** Only texts added by hand ("text_…") need the second lookup by visible wording. */
	public static function has_manual_texts( $language ) {
		static $has = array();
		if ( ! isset( $has[ $language ] ) ) { $has[ $language ] = false; foreach ( array_keys( self::strings( $language ) ) as $key ) if ( 0 === strpos( $key, 'text_' ) ) { $has[ $language ] = true; break; } }
		return $has[ $language ];
	}

	public static function translate( $language, $type, $id, $field, $source ) {
		if ( ! $language || '' === (string) $source || ! $type || ! $field ) return $source;
		$cache =& self::$translations;
		$key = "$language:$type:$id:$field:" . md5( (string) $source );
		if ( array_key_exists( $key, $cache ) ) return $cache[ $key ];
		if ( 'string' === $type && 0 === (int) $id ) {
			$map = self::strings( $language );
			$row = $map[ $field ] ?? null;
		} else {
			$row = self::get( $language, $type, $id, $field );
		}
		if ( ! $row || ! in_array( $row->status, self::LIVE_STATUSES, true ) || '' === trim( (string) $row->translated_text ) ) return $cache[ $key ] = $source;
		return $cache[ $key ] = ( self::source_matches( $row, $source ) ? $row->translated_text : $source );
	}

	public static function save( $data ) {
		global $wpdb;
		$source     = (string) ( $data['source_text'] ?? '' );
		$translated = (string) ( $data['translated_text'] ?? '' );
		// Administrators keep their markup (scripts, iframes, SVG…) exactly like in the original editor.
		if ( ! current_user_can( 'unfiltered_html' ) ) $translated = wp_kses_post( $translated );
		$status = $data['status'] ?? 'translated';
		if ( ! in_array( $status, array( 'empty', 'translated', 'reviewed', 'partial' ), true ) ) $status = 'translated';
		$row = array(
			'language_code'   => sanitize_key( $data['language_code'] ?? '' ),
			'object_type'     => sanitize_key( $data['object_type'] ?? '' ),
			'object_id'       => absint( $data['object_id'] ?? 0 ),
			'field_key'       => sanitize_key( $data['field_key'] ?? '' ),
			'source_text'     => $source,
			'translated_text' => $translated,
			'source_hash'     => self::hash( $source ),
			'status'          => $status,
			'updated_at'      => current_time( 'mysql' ),
		);
		$old = $wpdb->get_row( $wpdb->prepare( 'SELECT language_code, object_type, object_id, field_key, source_hash, translated_text, status FROM ' . self::table() . ' WHERE language_code=%s AND object_type=%s AND object_id=%d AND field_key=%s', $row['language_code'], $row['object_type'], $row['object_id'], $row['field_key'] ) );
		self::$translations = array();
		unset( self::$global_primed[ $row['language_code'] ][ $row['object_type'] ], self::$global_rows[ $row['language_code'] ][ $row['object_type'] ] );
		unset( self::$strings[ $row['language_code'] ], self::$primed[ $row['language_code'] ][ $row['object_type'] ][ $row['object_id'] ] );
		unset( self::$partial[ $row['language_code'] ][ $row['object_type'] ][ $row['object_id'] ] );
		if ( in_array( $row['object_type'], array( 'string', 'email' ), true ) ) { delete_transient( 'lt_strings_' . $row['language_code'] ); delete_transient( 'lt_wcjs_' . $row['language_code'] ); }
		if ( 'content' === $row['field_key'] ) delete_transient( 'lt_content_hashes_' . $row['language_code'] );
		$result = $wpdb->replace( self::table(), $row );
		if ( false !== $result ) {
			if ( class_exists( 'LT_Content_Catalog' ) ) {
				LT_Content_Catalog::translation_changed( $old, $row );
				LT_Content_Catalog::changed_object( $row['object_type'], $row['object_id'] );
			}
			do_action( 'lt_translation_saved', $row );
		}
		return $result;
	}

	/** @param string $where Must be built with $wpdb->prepare() by the caller. */
	public static function rows( $where = '' ) { global $wpdb; return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ' . $where . ' ORDER BY object_id,language_code,field_key' ); }

	public static function collect_empty( $language, $field, $source ) {
		global $wpdb;
		static $seen = array();
		$language = sanitize_key( $language );
		$field    = sanitize_key( $field );
		$key      = $language . ':' . $field;
		if ( isset( $seen[ $key ] ) ) return;
		$seen[ $key ] = true;
		// The same text is already translated elsewhere (other plugin, context…): reuse its translation at once.
		$known = $wpdb->get_var( $wpdb->prepare( 'SELECT translated_text FROM ' . self::table() . " WHERE language_code=%s AND object_type='string' AND object_id=0 AND source_hash=%s AND status IN ('translated','reviewed') AND translated_text <> '' LIMIT 1", $language, self::hash( $source ) ) );
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (language_code,object_type,object_id,field_key,source_text,translated_text,source_hash,status,updated_at) VALUES (%s,%s,%d,%s,%s,%s,%s,%s,%s)', $language, 'string', 0, $field, (string) $source, null !== $known ? (string) $known : '', self::hash( $source ), null !== $known ? 'translated' : 'empty', current_time( 'mysql' ) ) );
		if ( null !== $known && $wpdb->rows_affected ) { delete_transient( 'lt_strings_' . $language ); delete_transient( 'lt_wcjs_' . $language ); }
	}

	/** Batch registration is additive: existing translations, originals and statuses are never overwritten. */
	public static function collect_many( $languages, $texts ) {
		global $wpdb;
		foreach ( $languages as $language ) foreach ( array_chunk( $texts, 100, true ) as $chunk ) {
			$values = array(); $args = array();
			foreach ( $chunk as $field => $source ) {
				$values[] = '(%s,%s,0,%s,%s,%s,%s,%s,%s)';
				$args = array_merge( $args, array( sanitize_key( $language ), 'string', sanitize_key( $field ), (string) $source, '', self::hash( $source ), 'empty', current_time( 'mysql' ) ) );
			}
			if ( $values && false === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (language_code,object_type,object_id,field_key,source_text,translated_text,source_hash,status,updated_at) VALUES ' . implode( ',', $values ), $args ) ) ) return false;
		}
		return true;
	}

	public static function find_slug( $language, $slug, $object_type = '' ) {
		$rows = self::find_slugs( $language, $slug, $object_type );
		return $rows ? reset( $rows ) : null;
	}

	public static function find_slugs( $language, $slug, $object_type = '' ) {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::table() . " WHERE language_code=%s AND field_key=%s AND translated_text=%s AND status IN ('translated','reviewed')";
		$args = array( $language, 'slug', sanitize_title( $slug ) );
		if ( $object_type ) { $sql .= ' AND object_type=%s'; $args[] = sanitize_key( $object_type ); }
		return $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id ASC', $args ) );
	}
}
