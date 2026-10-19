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
	public static function reset_strings() { self::$strings = array(); self::$translations = array(); LT_Script_Texts::flush(); }

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
		if ( $count ) { self::$strings = array(); self::$translations = array(); LT_Script_Texts::flush(); }
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
	public static $stats = array( 'queries' => 0, 'primed' => 0, 'strings' => 0 );

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
		$map = self::strings( $language );
		if ( ! isset( $map[ $field ] ) ) return null;
		return hash_equals( substr( $map[ $field ], 1, 64 ), self::hash( $source ) ) ? substr( $map[ $field ], 65 ) : null;
	}

	/** Returns the row only when it holds a usable (non-empty, published) translation, whatever its source hash. */
	public static function get_translated( $language, $type, $object_id, $field ) {
		$row = self::get( $language, $type, $object_id, $field );
		if ( ! $row || ! in_array( $row->status, self::LIVE_STATUSES, true ) || '' === trim( (string) $row->translated_text ) ) return null;
		return $row;
	}

	/** Load interface texts once in this PHP request; nothing is persisted or compressed. */
	private static function strings( $language ) {
		if ( ! isset( self::$strings[ $language ] ) ) {
			global $wpdb;
			$map = array( '__manual' => false );
			// The original is only needed for the lookup by visible wording (short texts): long originals (HTML widgets,
			// e-mails…) are not read from the database at all.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT field_key, IF(LENGTH(source_text) < 500, source_text, NULL) AS source_text, translated_text, source_hash, status FROM ' . self::table() . " WHERE language_code=%s AND object_type='string' AND object_id=0 AND status IN ('translated','reviewed') AND translated_text <> ''", $language ) );
			$by_text = array(); $manual_by_text = array();
			foreach ( (array) $rows as $row ) {
				$map[ $row->field_key ] = ( 'reviewed' === $row->status ? 'R' : 'T' ) . $row->source_hash . $row->translated_text;
				if ( 0 === strpos( $row->field_key, 'text_' ) ) $map['__manual'] = true;
				if ( null !== $row->source_text ) {
					$text_key = md5( trim( (string) $row->source_text ) );
					$by_text[ $text_key ] = $row->translated_text;
					if ( 0 === strpos( $row->field_key, 'text_' ) ) $manual_by_text[ $text_key ] = $row->translated_text;
				}
			}
			$map['__by_text'] = $by_text;
			$map['__manual_by_text'] = $manual_by_text;
			self::$strings[ $language ] = $map;
			self::$stats['queries']++;
			self::$stats['strings'] = max( 0, count( $map ) - 3 );
		}
		return self::$strings[ $language ];
	}

	/** Translated interface text by key, without comparing the original (used for JavaScript texts). */
	public static function string_value( $language, $field ) {
		$map = self::strings( $language );
		return isset( $map[ $field ] ) ? substr( $map[ $field ], 65 ) : null;
	}

	/** Translated interface text as a row (translated_text, source_hash, status) from the cached map, or null. */
	public static function string_row( $language, $field ) {
		$map = self::strings( $language );
		if ( ! isset( $map[ $field ] ) ) return null;
		$value = $map[ $field ];
		return (object) array( 'translated_text' => substr( $value, 65 ), 'source_hash' => substr( $value, 1, 64 ), 'status' => 'R' === $value[0] ? 'reviewed' : 'translated' );
	}

	/** Translation of a visible wording, whatever plugin / context it comes from (null when none). */
	public static function by_text( $language, $text ) {
		$map = self::strings( $language );
		$key = md5( trim( (string) $text ) );
		return isset( $map['__by_text'][ $key ] ) ? (string) $map['__by_text'][ $key ] : null;
	}

	public static function manual_by_text( $language, $text ) {
		$map = self::strings( $language );
		return $map['__manual_by_text'][ md5( trim( (string) $text ) ) ] ?? null;
	}

	/** Only texts added by hand ("text_…") need the second lookup by visible wording. */
	public static function has_manual_texts( $language ) {
		$map = self::strings( $language );
		return ! empty( $map['__manual'] );
	}

	public static function translate( $language, $type, $id, $field, $source ) {
		if ( ! $language || '' === (string) $source || ! $type || ! $field ) return $source;
		$cache =& self::$translations;
		$key = "$language:$type:$id:$field:" . md5( (string) $source );
		if ( array_key_exists( $key, $cache ) ) return $cache[ $key ];
		if ( 'string' === $type && 0 === (int) $id ) {
			$translated = self::string_lookup( $language, $field, $source );
			return $cache[ $key ] = null !== $translated ? $translated : $source;
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
		if ( 'email' === ( $data['object_type'] ?? '' ) && class_exists( 'LT_Email_Translations' ) && ! LT_Email_Translations::valid_translation( $source, $translated ) ) return false;
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
		$old = $wpdb->get_row( $wpdb->prepare( 'SELECT id, language_code, object_type, object_id, field_key, source_hash, translated_text, status FROM ' . self::table() . ' WHERE language_code=%s AND object_type=%s AND object_id=%d AND field_key=%s', $row['language_code'], $row['object_type'], $row['object_id'], $row['field_key'] ) );
		self::$translations = array();
		unset( self::$global_primed[ $row['language_code'] ][ $row['object_type'] ], self::$global_rows[ $row['language_code'] ][ $row['object_type'] ] );
		unset( self::$strings[ $row['language_code'] ], self::$primed[ $row['language_code'] ][ $row['object_type'] ][ $row['object_id'] ] );
		unset( self::$partial[ $row['language_code'] ][ $row['object_type'] ][ $row['object_id'] ] );
		if ( in_array( $row['object_type'], array( 'string', 'email' ), true ) ) LT_Script_Texts::flush();
		$result = $old ? $wpdb->update( self::table(), $row, array( 'id' => (int) $old->id ) ) : $wpdb->insert( self::table(), $row );
		if ( false !== $result ) {
			if ( ! in_array( $row['object_type'], array( 'string', 'email' ), true ) && class_exists( 'LT_Multilingual_Search' ) ) LT_Multilingual_Search::invalidate();
			if ( class_exists( 'LT_Content_Catalog' ) ) {
				LT_Content_Catalog::changed_object( $row['object_type'], $row['object_id'] );
			}
			do_action( 'lt_translation_saved', $row );
		}
		return $result;
	}

	/** @param string $where Must be built with $wpdb->prepare() by the caller. */
	public static function rows( $where = '' ) { global $wpdb; return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ' . $where . ' ORDER BY object_id,language_code,field_key' ); }

	public static function collect_empty( $language, $field, $source, $legacy = null ) {
		global $wpdb;
		static $seen = array();
		$language = sanitize_key( $language );
		$field    = sanitize_key( $field );
		$key      = $language . ':' . $field;
		if ( isset( $seen[ $key ] ) ) return;
		$seen[ $key ] = true;
		// Scoped gettext may inherit only its own legacy key, never another plugin's wording.
		$known = '' === $legacy ? null : ( null === $legacy
			? $wpdb->get_var( $wpdb->prepare( 'SELECT translated_text FROM ' . self::table() . " WHERE language_code=%s AND object_type='string' AND object_id=0 AND source_hash=%s AND status IN ('translated','reviewed') AND translated_text <> '' LIMIT 1", $language, self::hash( $source ) ) )
			: $wpdb->get_var( $wpdb->prepare( 'SELECT translated_text FROM ' . self::table() . " WHERE language_code=%s AND object_type='string' AND object_id=0 AND field_key=%s AND source_hash=%s AND status IN ('translated','reviewed') AND translated_text <> '' LIMIT 1", $language, $legacy, self::hash( $source ) ) ) );
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (language_code,object_type,object_id,field_key,source_text,translated_text,source_hash,status,updated_at) VALUES (%s,%s,%d,%s,%s,%s,%s,%s,%s)', $language, 'string', 0, $field, (string) $source, null !== $known ? (string) $known : '', self::hash( $source ), null !== $known ? 'translated' : 'empty', current_time( 'mysql' ) ) );
		if ( null !== $known && $wpdb->rows_affected ) { unset( self::$strings[ $language ] ); LT_Script_Texts::flush(); }
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
