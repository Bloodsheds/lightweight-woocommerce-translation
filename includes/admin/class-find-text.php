<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Find a text": one search box for everything — pages, posts, products, synced patterns, categories,
 * tags, attributes, menu labels, interface texts and e-mails — in the original language AND in the
 * translations. Each result says what is translated and opens the right screen to translate it.
 */
class LT_Find_Text {
	const LIMIT = 25;

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() { add_action( 'admin_menu', array( $this, 'menu' ), 10 ); }

	public function menu() { add_submenu_page( 'lt-settings', __( 'Find a text', 'lightweight-translation' ), '🔍 ' . __( 'Find a text', 'lightweight-translation' ), lt_capability(), 'lt-find', array( $this, 'page' ) ); }

	private function languages() { $router = LT_Language_Router::instance(); return array_values( array_diff( $router->languages(), array( $router->default_language() ) ) ); }

	private function like( $search ) { global $wpdb; return '%' . $wpdb->esc_like( $search ) . '%'; }

	/* ---------- Searches ---------- */

	/** Items (posts, terms, attributes, menu links) whose original or translation contains the text: [group, id, matched languages]. */
	private function items( $search ) {
		global $wpdb;
		$table  = LT_Translation_Store::table();
		$groups = LT_Content_Catalog::groups();
		$found  = array(); // "group|id" => [ group, id, [ languages where the translation matches ] ]
		$add    = function( $group, $id, $language = '' ) use ( &$found, $groups ) {
			if ( ! isset( $groups[ $group ] ) || ! $id ) return;
			$k = $group . '|' . $id;
			if ( ! isset( $found[ $k ] ) ) $found[ $k ] = array( $group, (int) $id, array() );
			if ( $language ) $found[ $k ][2][ $language ] = true;
		};

		// Originals: WordPress search on title / content / excerpt.
		$types = array();
		foreach ( array_keys( $groups ) as $group ) if ( 0 === strpos( $group, 'post:' ) ) $types[] = substr( $group, 5 );
		if ( $types ) {
			foreach ( get_posts( array( 'post_type' => $types, 's' => $search, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'numberposts' => self::LIMIT, 'suppress_filters' => true ) ) as $post ) $add( 'post:' . $post->post_type, $post->ID );
			// Exact title match first (WordPress orders by relevance, but a short word matches everywhere).
			$exact = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_title=%s AND post_status IN ('publish','draft','pending','private','future') AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') LIMIT 10', array_merge( array( $search ), $types ) ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $exact as $row ) $add( 'post:' . $row->post_type, $row->ID );
		}
		// Originals: menu labels typed by hand.
		if ( isset( $groups['menu'] ) ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='nav_menu_item' AND post_title LIKE %s LIMIT %d", $this->like( $search ), self::LIMIT ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $ids as $id ) $add( 'menu', $id );
		}
		// Originals: categories, tags, attribute values.
		$taxonomies = array();
		foreach ( array_keys( $groups ) as $group ) if ( 0 === strpos( $group, 'tax:' ) ) $taxonomies[] = substr( $group, 4 );
		if ( $taxonomies ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomies, 'search' => $search, 'hide_empty' => false, 'number' => self::LIMIT ) );
			if ( ! is_wp_error( $terms ) ) foreach ( $terms as $term ) $add( 'tax:' . $term->taxonomy, $term->term_id );
		}
		// Originals: attribute names.
		if ( isset( $groups['attribute'] ) && function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attribute ) if ( false !== mb_stripos( (string) $attribute->attribute_label, $search ) ) $add( 'attribute', $attribute->attribute_id );
		}

		// Translations (and originals of fields WordPress does not search, e.g. product tabs).
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_type, object_id, language_code, (translated_text LIKE %s) AS in_translation FROM $table WHERE object_id > 0 AND object_type NOT IN ('string','email') AND (translated_text LIKE %s OR source_text LIKE %s) ORDER BY id DESC LIMIT 200", $this->like( $search ), $this->like( $search ), $this->like( $search ) ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->object_id;
			if ( 'term' === $row->object_type ) { $term = get_term( $id ); $group = $term instanceof WP_Term ? 'tax:' . $term->taxonomy : ''; }
			elseif ( 'attribute' === $row->object_type ) $group = 'attribute';
			elseif ( 'nav_menu_item' === $row->object_type ) $group = 'menu';
			else $group = 'post:' . $row->object_type;
			$add( $group, $id, $row->in_translation ? $row->language_code : '' );
		}
		return array_slice( $found, 0, self::LIMIT * 2, true );
	}

	/** Interface texts and e-mail texts containing the search: [object_type, field_key, source, translations]. */
	private function texts( $search, &$total ) {
		global $wpdb;
		$table = LT_Translation_Store::table();
		$where = $wpdb->prepare( "object_id=0 AND object_type IN ('string','email') AND (source_text LIKE %s OR translated_text LIKE %s)", $this->like( $search ), $this->like( $search ) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT object_type, field_key) FROM $table WHERE $where" ); // phpcs:ignore WordPress.DB
		$keys  = $wpdb->get_results( "SELECT object_type, field_key, MAX(source_text) AS source FROM $table WHERE $where GROUP BY object_type, field_key ORDER BY (MAX(source_text) = " . $wpdb->prepare( '%s', $search ) . ') DESC, CHAR_LENGTH(MAX(source_text)) ASC LIMIT ' . self::LIMIT ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( (array) $keys as $row ) {
			$translations = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT language_code, translated_text, status FROM $table WHERE object_id=0 AND object_type=%s AND field_key=%s", $row->object_type, $row->field_key ) ) as $t ) { // phpcs:ignore WordPress.DB
				if ( in_array( $t->status, LT_Translation_Store::LIVE_STATUSES, true ) && '' !== trim( (string) $t->translated_text ) ) $translations[ $t->language_code ] = $t->translated_text;
			}
			$out[] = array( $row->object_type, $row->field_key, (string) $row->source, $translations );
		}
		return $out;
	}

	/** Per language: translated / missing / outdated, for the fields of one item. */
	private function status( $item, $language ) {
		$missing = 0; $outdated = 0;
		foreach ( $item['fields'] as $field => $source ) {
			$row = LT_Translation_Store::get_translated( $language, $item['type'], $item['id'], $field );
			if ( ! $row ) $missing++;
			elseif ( ! LT_Translation_Store::source_matches( $row, $source ) ) $outdated++;
		}
		if ( ! $item['fields'] ) return '';
		if ( $missing === count( $item['fields'] ) ) return '<span style="color:#b32d2e">' . esc_html__( 'not translated', 'lightweight-translation' ) . '</span>';
		if ( $missing || $outdated ) return '<span style="color:#996800">' . esc_html( $outdated ? __( 'to update', 'lightweight-translation' ) : __( 'partly', 'lightweight-translation' ) ) . '</span>';
		return '<span style="color:#00a32a">✓</span>';
	}

	/* ---------- Page ---------- */

	public function page() {
		if ( ! current_user_can( lt_capability() ) ) return;
		$search    = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$languages = $this->languages();
		$groups    = LT_Content_Catalog::groups();
		echo '<style>.lt-find td{vertical-align:top}.lt-find .lt-muted{color:#646970;font-size:12px}.lt-find mark{background:#fff3b0;padding:0}.lt-find-box input[type=search]{font-size:16px;padding:6px 10px;min-width:min(520px,100%)}.lt-hint{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:8px 14px;margin:16px 0}</style>';
		echo '<div class="wrap lt-find"><h1>' . esc_html__( 'Find a text', 'lightweight-translation' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Type a text exactly as you see it on the site, in any language: pages, products, categories, menus, buttons, messages, e-mails… Then click Translate.', 'lightweight-translation' ) . '</p>';
		echo '<form method="get" class="lt-find-box" style="margin:14px 0"><input type="hidden" name="page" value="lt-find"><input type="search" name="s" value="' . esc_attr( $search ) . '" autofocus placeholder="' . esc_attr__( 'e.g. Add to cart, Free shipping, Endodontic kit…', 'lightweight-translation' ) . '"> ';
		submit_button( __( 'Search', 'lightweight-translation' ), 'primary', '', false );
		echo '</form>';
		if ( '' === $search ) { $this->tips(); echo '</div>'; return; }
		if ( ! $languages ) { echo '<p>' . esc_html__( 'Add a secondary language in the settings first.', 'lightweight-translation' ) . '</p></div>'; return; }

		$mark = function( $text ) use ( $search ) {
			$text = wp_strip_all_tags( (string) $text );
			$pos  = mb_stripos( $text, $search );
			if ( mb_strlen( $text ) > 140 ) { $start = max( 0, ( false === $pos ? 0 : $pos ) - 50 ); $text = ( $start ? '…' : '' ) . mb_substr( $text, $start, 140 ) . '…'; }
			return preg_replace( '/' . preg_quote( esc_html( $search ), '/' ) . '/iu', '<mark>$0</mark>', esc_html( $text ) );
		};
		$head = function() use ( $languages ) {
			echo '<thead><tr><th style="width:40%">' . esc_html__( 'Found', 'lightweight-translation' ) . '</th><th>' . esc_html__( 'Type', 'lightweight-translation' ) . '</th>';
			foreach ( $languages as $language ) echo '<th>' . esc_html( LT_Language_Router::flag( $language ) . ' ' . strtoupper( $language ) ) . '</th>';
			echo '<th></th></tr></thead><tbody>';
		};

		// 1. Content: pages, products, categories, menus…
		$items = $this->items( $search );
		echo '<h2>' . esc_html__( 'Pages, products, categories & menus', 'lightweight-translation' ) . ' <span class="lt-muted">(' . count( $items ) . ')</span></h2>';
		if ( ! $items ) echo '<p class="lt-muted">' . esc_html__( 'Nothing found in the content.', 'lightweight-translation' ) . '</p>';
		else {
			echo '<table class="widefat striped">';
			$head();
			foreach ( $items as list( $group, $id, $matched ) ) {
				$item = LT_Content_Catalog::item( $group, $id );
				if ( ! $item ) continue;
				$where = array();
				foreach ( $item['fields'] as $field => $source ) if ( false !== mb_stripos( wp_strip_all_tags( (string) $source ), $search ) ) { $where[] = $field; }
				$snippet = $where ? $mark( $item['fields'][ $where[0] ] ) : '';
				echo '<tr><td><strong>' . esc_html( $item['title'] ) . '</strong>' . ( $snippet && 'title' !== $where[0] && 'term_name' !== $where[0] ? '<br><span class="lt-muted">' . $snippet . '</span>' : '' ) . ( $matched ? '<br><span class="lt-muted">' . esc_html( sprintf( __( 'found in the translation: %s', 'lightweight-translation' ), strtoupper( implode( ', ', array_keys( $matched ) ) ) ) ) . '</span>' : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<td class="lt-muted">' . esc_html( $groups[ $group ] ?? $group ) . '</td>';
				foreach ( $languages as $language ) echo '<td>' . $this->status( $item, $language ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				$links = array();
				if ( $item['edit'] ) {
					if ( $item['block_editor'] ) foreach ( $languages as $language ) $links[] = '<a class="button button-small button-primary" href="' . esc_url( add_query_arg( 'lt_lang', $language, $item['edit'] ) ) . '">' . esc_html( sprintf( __( 'Translate %s', 'lightweight-translation' ), strtoupper( $language ) ) ) . '</a>';
					elseif ( 'menu' === $group ) $links[] = '<a class="button button-small button-primary" href="' . esc_url( admin_url( 'admin.php?page=lt-export' ) ) . '">' . esc_html__( 'Translate (Import / Export › Menu links)', 'lightweight-translation' ) . '</a>';
					elseif ( 0 === strpos( $group, 'tax:' ) || 'attribute' === $group ) $links[] = '<a class="button button-small button-primary" href="' . esc_url( admin_url( 'admin.php?page=lt-taxonomies&tab=' . rawurlencode( $group ) . '&s=' . rawurlencode( $item['title'] ) ) ) . '">' . esc_html__( 'Translate', 'lightweight-translation' ) . '</a> <a class="button button-small" href="' . esc_url( $item['edit'] ) . '">' . esc_html__( 'Edit', 'lightweight-translation' ) . '</a>';
					else $links[] = '<a class="button button-small button-primary" href="' . esc_url( $item['edit'] . '#lt-translations' ) . '">' . esc_html__( 'Translate', 'lightweight-translation' ) . '</a>';
				}
				if ( 0 === strpos( $group, 'post:' ) && ( $view = get_permalink( $id ) ) ) $links[] = '<a class="button button-small" href="' . esc_url( $view ) . '" target="_blank">' . esc_html__( 'View', 'lightweight-translation' ) . '</a>';
				echo '<td style="white-space:nowrap">' . implode( ' ', $links ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		}

		// 2. Interface texts & e-mails.
		$total = 0;
		$texts = $this->texts( $search, $total );
		echo '<h2 style="margin-top:28px">' . esc_html__( 'Interface texts & e-mails', 'lightweight-translation' ) . ' <span class="lt-muted">(' . (int) $total . ')</span></h2>';
		if ( ! $texts ) echo '<p class="lt-muted">' . esc_html__( 'No interface text found.', 'lightweight-translation' ) . '</p>';
		else {
			echo '<table class="widefat striped">';
			$head();
			foreach ( $texts as list( $type, $field, $source, $translations ) ) {
				$email = 'email' === $type;
				echo '<tr><td>' . $mark( $source ) . '</td><td class="lt-muted">' . esc_html( $email ? __( 'E-mail', 'lightweight-translation' ) : __( 'Interface text', 'lightweight-translation' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( $languages as $language ) echo '<td>' . ( isset( $translations[ $language ] ) ? $mark( $translations[ $language ] ) : '<span style="color:#b32d2e">—</span>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				$href = $email ? admin_url( 'admin.php?page=lt-woocommerce&sub=emails' ) : add_query_arg( array( 'page' => 'lt-strings', 'key' => $field ), admin_url( 'admin.php' ) );
				echo '<td><a class="button button-small button-primary" href="' . esc_url( $href ) . '">' . esc_html__( 'Translate', 'lightweight-translation' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
			if ( $total > count( $texts ) ) echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => 'lt-strings', 's' => rawurlencode( $search ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( sprintf( __( 'See the %d interface texts →', 'lightweight-translation' ), $total ) ) . '</a></p>';
		}
		$this->tips( true );
		echo '</div>';
	}

	private function tips( $not_found = false ) {
		$a = function( $page, $args = array() ) { return esc_url( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) ); };
		echo '<div class="lt-hint"><p><strong>' . esc_html( $not_found ? __( 'Not the right result?', 'lightweight-translation' ) : __( 'The fastest way', 'lightweight-translation' ) ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';
		echo '<li>' . wp_kses_post( sprintf( __( 'Open the page where you see the text (logged in): it then appears in <a href="%s">Interface texts</a>, and 🌐 Translate in the admin bar takes you straight to it.', 'lightweight-translation' ), $a( 'lt-strings' ) ) ) . '</li>';
		echo '<li>' . wp_kses_post( sprintf( __( 'Still nothing? <a href="%s">Add the text by hand</a> with its exact wording.', 'lightweight-translation' ), $a( 'lt-strings', array( 'tab' => 'find' ) ) . '#lt-add' ) ) . '</li>';
		echo '</ul></div>';
	}
}
