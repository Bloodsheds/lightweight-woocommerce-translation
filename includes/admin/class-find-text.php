<?php
defined( 'ABSPATH' ) || exit;

/** Search results as an editable worksheet: originals and translations remain visible together. */
class LT_Find_Text {
	const PER_PAGE = 50;
	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 10 );
		add_action( 'wp_ajax_lt_find_save', array( $this, 'ajax_save' ) );
	}
	public function menu() { add_submenu_page( 'lt-settings', __( 'Find a text', 'lightweight-translation' ), '🔍 ' . __( 'Find a text', 'lightweight-translation' ), lt_capability(), 'lt-find', array( $this, 'page' ) ); }
	private function languages() { $r = LT_Language_Router::instance(); return array_values( array_diff( $r->languages(), array( $r->default_language() ) ) ); }
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
			foreach ( get_posts( array( 'post_type' => $types, 's' => $search, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'numberposts' => -1, 'suppress_filters' => true ) ) as $post ) $add( 'post:' . $post->post_type, $post->ID );
			// Exact title match first (WordPress orders by relevance, but a short word matches everywhere).
			$exact = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_title=%s AND post_status IN ('publish','draft','pending','private','future') AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', array_merge( array( $search ), $types ) ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $exact as $row ) $add( 'post:' . $row->post_type, $row->ID );
		}
		// Originals: menu labels typed by hand.
		if ( isset( $groups['menu'] ) ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='nav_menu_item' AND post_title LIKE %s", $this->like( $search ) ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $ids as $id ) $add( 'menu', $id );
		}
		// Originals: categories, tags, attribute values.
		$taxonomies = array();
		foreach ( array_keys( $groups ) as $group ) if ( 0 === strpos( $group, 'tax:' ) ) $taxonomies[] = substr( $group, 4 );
		if ( $taxonomies ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomies, 'search' => $search, 'hide_empty' => false, 'number' => 0 ) );
			if ( ! is_wp_error( $terms ) ) foreach ( $terms as $term ) $add( 'tax:' . $term->taxonomy, $term->term_id );
		}
		// Originals: attribute names.
		if ( isset( $groups['attribute'] ) && function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attribute ) if ( false !== mb_stripos( (string) $attribute->attribute_label, $search ) ) $add( 'attribute', $attribute->attribute_id );
		}

		// Translations (and originals of fields WordPress does not search, e.g. product tabs).
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_type, object_id, language_code, (translated_text LIKE %s) AS in_translation FROM $table WHERE object_id > 0 AND object_type NOT IN ('string','email') AND (translated_text LIKE %s OR source_text LIKE %s) ORDER BY id DESC", $this->like( $search ), $this->like( $search ), $this->like( $search ) ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->object_id;
			if ( 'term' === $row->object_type ) { $term = get_term( $id ); $group = $term instanceof WP_Term ? 'tax:' . $term->taxonomy : ''; }
			elseif ( 'attribute' === $row->object_type ) $group = 'attribute';
			elseif ( 'nav_menu_item' === $row->object_type ) $group = 'menu';
			else $group = 'post:' . $row->object_type;
			$add( $group, $id, $row->in_translation ? $row->language_code : '' );
		}
		return $found;
	}

	/** Global strings use their existing stable row handle; new e-mail templates come from WooCommerce. */
	private function global_items( $search ) {
		global $wpdb;
		$table = LT_Translation_Store::table();
		$types = function_exists( 'WC' ) ? "object_type='string'" : "object_type IN ('string','email')";
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT MIN(id) AS id, field_key FROM $table WHERE object_id=0 AND $types GROUP BY object_type, field_key HAVING MAX(source_text LIKE %s OR translated_text LIKE %s)>0 ORDER BY MIN(id)", $this->like( $search ), $this->like( $search ) ) ); // phpcs:ignore WordPress.DB
		$index = (array) get_option( LT_Code_Scanner::INDEX, array() );
		$refs = array();
		foreach ( (array) $rows as $row ) {
			$entry = (array) ( $index[ $row->field_key ] ?? array() );
			if ( ! empty( $entry['x'] ) || LT_Code_Scanner::admin_only( $entry ) ) continue;
			$refs[] = array( 'string', (int) $row->id );
		}
		if ( function_exists( 'WC' ) ) foreach ( LT_Content_Catalog::item_ids( 'email' ) as $id ) $refs[] = array( 'email', $id );
		return $refs;
	}

	public static function matches( $text, $search ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return '' !== $search && false !== mb_stripos( $text, $search );
	}

	/** Same source + same saved translation = safe to save. Prevents overwriting another open editor. */
	public static function revision( $entry, $language ) {
		$row = LT_Translation_Store::get( $language, $entry['type'], $entry['id'], $entry['field'] );
		return LT_Translation_Store::hash( wp_json_encode( $row ? array( $row->source_hash, $row->translated_text, $row->status ) : null ) );
	}

	/** One field per result; originals are always resolved from the catalogue, never from the browser. */
	public function results( $search ) {
		$refs = array_merge( array_values( $this->items( $search ) ), $this->global_items( $search ) );
		$languages = $this->languages();
		foreach ( $languages as $language ) { LT_Translation_Store::prime_global( $language, 'string' ); LT_Translation_Store::prime_global( $language, 'email' ); }
		$groups = LT_Content_Catalog::groups();
		$entries = array();
		foreach ( $refs as $ref ) {
			list( $group, $id ) = $ref;
			$item = LT_Content_Catalog::item( $group, $id );
			if ( ! $item ) continue;
			foreach ( $item['fields'] as $field => $source ) {
				if ( 'slug' === $field ) continue;
				$key = $item['type'] . '|' . $item['id'] . '|' . $field;
				if ( isset( $entries[ $key ] ) ) continue;
				$entry = array( 'type' => $item['type'], 'id' => $item['id'], 'field' => $field, 'source' => $source, 'label' => $item['labels'][ $field ] ?? LT_Status_Panel::label( $field ) );
				$states = array(); $matches = self::matches( $source, $search );
				foreach ( $languages as $language ) {
					$states[ $language ] = LT_Status_Panel::field_state( $entry, $language );
					if ( self::matches( $states[ $language ]['value'], $search ) ) $matches = true;
				}
				if ( ! $matches ) continue;
				$entries[ $key ] = array( 'group' => $group, 'handle' => (int) $id, 'entry' => $entry, 'states' => $states, 'title' => $item['title'], 'kind' => $groups[ $group ] ?? $group );
			}
		}
		return array_values( $entries );
	}

	public function ajax_save() {
		if ( ! current_user_can( lt_capability() ) || ! check_ajax_referer( 'lt_find', 'nonce', false ) ) wp_send_json_error( array( 'message' => __( 'Your session expired: reload the page.', 'lightweight-translation' ) ), 403 );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- resolved against the catalogue below
		foreach ( array( 'group', 'lang', 'field', 'hash', 'revision' ) as $key ) if ( ! isset( $post[ $key ] ) || ! is_string( $post[ $key ] ) ) wp_send_json_error( array( 'message' => __( 'Invalid translation fields.', 'lightweight-translation' ) ), 400 );
		if ( ! isset( $post['id'] ) || ! is_scalar( $post['id'] ) || ! ctype_digit( (string) $post['id'] ) ) wp_send_json_error( array( 'message' => __( 'Invalid translation fields.', 'lightweight-translation' ) ), 400 );
		$group = sanitize_text_field( (string) ( $post['group'] ?? '' ) );
		$language = sanitize_key( $post['lang'] ?? '' );
		$field = sanitize_key( $post['field'] ?? '' );
		if ( ! isset( LT_Content_Catalog::groups()[ $group ] ) || ! in_array( $language, $this->languages(), true ) ) wp_send_json_error( array( 'message' => __( 'Unknown item.', 'lightweight-translation' ) ), 400 );
		$item = LT_Content_Catalog::item( $group, absint( $post['id'] ?? 0 ) );
		if ( ! $item || ! isset( $item['fields'][ $field ] ) || 'slug' === $field ) wp_send_json_error( array( 'message' => __( 'This item no longer exists.', 'lightweight-translation' ) ), 404 );
		$entry = array( 'type' => $item['type'], 'id' => $item['id'], 'field' => $field, 'source' => $item['fields'][ $field ], 'label' => '' );
		if ( LT_Translation_Store::hash( $entry['source'] ) !== ( $post['hash'] ?? '' ) ) wp_send_json_error( array( 'message' => __( 'The original changed. Reload the results before saving this text.', 'lightweight-translation' ) ), 409 );
		$state = LT_Status_Panel::field_state( $entry, $language );
		$parts = isset( $post['parts'] ) ? json_decode( (string) $post['parts'], true ) : null;
		$is_parts = isset( $post['parts'] );
		if ( $is_parts ) {
			if ( 'parts' !== $state['mode'] || ! is_array( $parts ) || count( $parts ) !== count( $state['parts'] ) || count( array_filter( $parts, 'is_string' ) ) !== count( $parts ) ) wp_send_json_error( array( 'message' => __( 'Invalid translation fields.', 'lightweight-translation' ) ), 400 );
			$filled = array_filter( $parts, function( $part ) { return '' !== trim( $part ); } );
			$value = $filled ? LT_Segments::rebuild( $entry['source'], array_values( $parts ) ) : '';
		} else {
			if ( ! isset( $post['value'] ) || ! is_string( $post['value'] ) ) wp_send_json_error( array( 'message' => __( 'Invalid translation fields.', 'lightweight-translation' ) ), 400 );
			$value = $post['value'];
		}
		$current_revision = self::revision( $entry, $language );
		if ( $current_revision !== ( $post['revision'] ?? '' ) && $state['value'] !== $value ) wp_send_json_error( array( 'message' => __( 'This translation changed in another editor. Reload the results before saving it.', 'lightweight-translation' ) ), 409 );
		$data = array( 'h' => array( $entry['type'] => array( $entry['id'] => array( $field => LT_Translation_Store::hash( $entry['source'] ) ) ) ) );
		$data[ $is_parts ? 'p' : 'v' ][ $entry['type'] ][ $entry['id'] ][ $field ] = $is_parts ? array_values( $parts ) : $value;
		$result = LT_Status_Panel::save( array( 'sections' => array( array( 'entries' => array( $entry ) ) ) ), $language, $data, false );
		if ( ! $result['saved'] || $result['invalid'] || $result['stale'] ) wp_send_json_error( array( 'message' => __( 'Could not save this text. Keep all placeholders and check the original.', 'lightweight-translation' ) ), 400 );
		wp_send_json_success( array( 'revision' => self::revision( $entry, $language ), 'state' => LT_Status_Panel::field_state( $entry, $language ) ) );
	}

	private function mark( $text, $search ) {
		return preg_replace( '/' . preg_quote( esc_html( $search ), '/' ) . '/iu', '<mark>$0</mark>', esc_html( $text ) );
	}

	public function page() {
		if ( ! current_user_can( lt_capability() ) ) return;
		$search = trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$languages = $this->languages();
		$language = sanitize_key( $_GET['lang'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$shown = in_array( $language, $languages, true ) ? array( $language ) : $languages;
		$full = ! empty( $_GET['full'] ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<style>' . file_get_contents( LT_DIR . 'assets/css/status.css' ) . file_get_contents( LT_DIR . 'assets/css/find-text.css' ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="wrap lt-status lt-find"><h1>' . esc_html__( 'Find a text', 'lightweight-translation' ) . '</h1><p class="description">' . esc_html__( 'Search in originals and translations. Tick texts, edit their translations here, then save the selection. Originals stay unchanged.', 'lightweight-translation' ) . '</p>';
		echo '<form method="get" class="lt-head lt-find-search"><input type="hidden" name="page" value="lt-find"><label class="screen-reader-text" for="lt-find-query">' . esc_html__( 'Search', 'lightweight-translation' ) . '</label><input type="search" id="lt-find-query" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Find a text…', 'lightweight-translation' ) . '"> <button class="button button-primary">' . esc_html__( 'Search', 'lightweight-translation' ) . '</button> <select name="lang" aria-label="' . esc_attr__( 'Languages', 'lightweight-translation' ) . '"><option value="">' . esc_html__( 'All languages', 'lightweight-translation' ) . '</option>';
		foreach ( $languages as $lang ) echo '<option value="' . esc_attr( $lang ) . '"' . selected( $lang, $language, false ) . '>' . esc_html( LT_Language_Router::flag( $lang ) . ' ' . LT_Language_Router::language_label( $lang ) ) . '</option>';
		echo '</select><label><input type="checkbox" name="full" value="1"' . checked( $full, true, false ) . '> ' . esc_html__( 'Show the full matching fields', 'lightweight-translation' ) . '</label></form>';
		if ( '' === $search || ! $shown ) { echo '<p>' . esc_html( $shown ? __( 'Search for a word or sentence to edit every matching occurrence.', 'lightweight-translation' ) : __( 'Add a secondary language in the settings first.', 'lightweight-translation' ) ) . '</p></div>'; return; }
		$entries = $this->results( $search );
		$total = count( $entries );
		$all = 'all' === ( $_GET['per_page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$entries = $all ? $entries : array_slice( $entries, ( min( $page, $pages ) - 1 ) * self::PER_PAGE, self::PER_PAGE );
		echo '<div class="lt-head"><h2>' . esc_html( sprintf( __( '%d matching fields', 'lightweight-translation' ), $total ) ) . '</h2><a class="button" href="' . esc_url( add_query_arg( array( 'per_page' => $all ? '' : 'all', 'paged' => 1 ) ) ) . '">' . esc_html( $all ? __( 'Show 50 at a time', 'lightweight-translation' ) : __( 'Show all results', 'lightweight-translation' ) ) . '</a></div>';
		if ( ! $entries ) { echo '<p>' . esc_html__( 'No matching text found.', 'lightweight-translation' ) . '</p></div>'; return; }
		$this->bulk_tools( $shown, $search );
		echo '<div class="lt-find-table-wrap"><table class="widefat striped lt-find-table"><thead><tr><td class="check-column"><input type="checkbox" id="lt-find-all" aria-label="' . esc_attr__( 'Select all', 'lightweight-translation' ) . '"></td><th>' . esc_html( LT_Language_Router::flag( LT_Language_Router::instance()->default_language() ) . ' ' . __( 'Original', 'lightweight-translation' ) ) . '</th>';
		foreach ( $shown as $lang ) echo '<th>' . esc_html( LT_Language_Router::flag( $lang ) . ' ' . LT_Language_Router::language_label( $lang ) ) . '</th>';
		echo '</tr></thead><tbody>';
		$config = array();
		foreach ( $entries as $result ) {
			$entry = $result['entry'];
			$key = md5( $entry['type'] . '|' . $entry['id'] . '|' . $entry['field'] );
			$parts = true;
			foreach ( $shown as $lang ) if ( 'parts' !== $result['states'][ $lang ]['mode'] ) $parts = false;
			// If a translation has a different layout, keep its whole field editable instead of guessing paragraphs.
			$sources = $parts ? LT_Segments::texts( $entry['source'] ) : array( $entry['source'] );
			$config[ $key ] = array( 'group' => $result['group'], 'id' => $result['handle'], 'field' => $entry['field'], 'hash' => LT_Translation_Store::hash( $entry['source'] ), 'languages' => array() );
			foreach ( $shown as $lang ) {
				$state = $result['states'][ $lang ];
				$config[ $key ]['languages'][ $lang ] = array( 'revision' => self::revision( $entry, $lang ), 'parts' => $parts, 'values' => $parts ? array_column( $state['parts'], 1 ) : array( $state['value'] ) );
			}
			echo '<tr class="lt-heading"><td colspan="' . ( count( $shown ) + 2 ) . '"><strong>' . esc_html( $result['title'] ) . '</strong> <span class="lt-find-meta">' . esc_html( $result['kind'] . ' · ' . $entry['label'] ) . '</span></td></tr>';
			$hidden_match = false;
			foreach ( array_diff( $languages, $shown ) as $lang ) if ( self::matches( $result['states'][ $lang ]['value'], $search ) ) $hidden_match = true;
			$whole = ! $parts && LT_Segments::is_long( $entry['source'] );
			foreach ( $sources as $part => $source ) {
				$matches = $hidden_match || self::matches( $source, $search );
				foreach ( $shown as $lang ) if ( self::matches( $config[ $key ]['languages'][ $lang ]['values'][ $part ], $search ) ) $matches = true;
				if ( ! $full && ! $matches ) continue;
				echo '<tr class="lt-find-row" data-key="' . esc_attr( $key ) . '" data-part="' . (int) $part . '"><th class="check-column"><input type="checkbox" class="lt-find-check" aria-label="' . esc_attr( $result['title'] . ' · ' . $entry['label'] ) . '"></th><td><div class="lt-find-source">' . $this->mark( $source, $search ) . '</div></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( $shown as $lang ) {
					$value = $config[ $key ]['languages'][ $lang ]['values'][ $part ];
					$state = $result['states'][ $lang ]['state'];
					echo '<td><textarea rows="2" class="lt-find-value is-' . esc_attr( $state ) . '" data-lang="' . esc_attr( $lang ) . '" data-whole="' . ( $whole ? '1' : '0' ) . '" aria-label="' . esc_attr( $result['title'] . ' · ' . $entry['label'] . ' · ' . LT_Language_Router::language_label( $lang ) ) . '" data-original="' . esc_attr( $source ) . '">' . esc_textarea( $value ) . '</textarea></td>';
				}
				echo '</tr>';
			}
		}
		echo '</tbody></table></div>';
		if ( ! $all && $pages > 1 ) echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => min( $page, $pages ), 'total' => $pages ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="lt-find-save"><span id="lt-find-message" role="status" aria-live="polite"></span><button type="button" class="button button-primary" id="lt-find-save">' . esc_html__( 'Save selected translations', 'lightweight-translation' ) . '</button></div><p class="description">' . esc_html__( 'Editing a field selects its row. Only changed translations in checked rows are saved. Keep placeholders and numbered formatting tags unchanged.', 'lightweight-translation' ) . '</p></div>';
		$settings = array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'lt_find' ), 'entries' => $config, 'i18n' => array(
			'pick' => __( 'Tick at least one text first.', 'lightweight-translation' ), 'empty' => __( 'Enter a translation first.', 'lightweight-translation' ), 'nothing' => __( 'No changed translations in the selection.', 'lightweight-translation' ),
			'unsaved' => __( 'You have unsaved translations. Leave anyway?', 'lightweight-translation' ), 'error' => __( 'Could not save. Check your connection and try again.', 'lightweight-translation' ),
			'saving' => __( 'Saving…', 'lightweight-translation' ), 'saved' => __( '%d translations saved.', 'lightweight-translation' ), 'preview' => __( '%d fields will change. Review them, then apply to the fields and save.', 'lightweight-translation' ),
			'whole' => __( 'Choose one language or edit this full field directly: its existing translation has a different layout.', 'lightweight-translation' ),
			'filled' => __( '%d fields updated. Save the selection to keep these changes.', 'lightweight-translation' ), 'none' => __( 'No occurrence found in the selected translations.', 'lightweight-translation' ),
		) );
		echo '<script>window.ltFind=' . wp_json_encode( $settings ) . ';</script><script>' . file_get_contents( LT_DIR . 'assets/js/find-text.js' ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function bulk_tools( $languages, $search ) {
		echo '<div class="lt-find-tools"><div class="lt-find-target"><strong>' . esc_html__( 'Selected texts', 'lightweight-translation' ) . '</strong> <select id="lt-find-target" aria-label="' . esc_attr__( 'Target language', 'lightweight-translation' ) . '">';
		foreach ( $languages as $lang ) echo '<option value="' . esc_attr( $lang ) . '">' . esc_html( LT_Language_Router::flag( $lang ) . ' ' . LT_Language_Router::language_label( $lang ) ) . '</option>';
		echo '</select><span id="lt-find-selected" class="description"></span></div><div class="lt-find-tool-grid"><div><label for="lt-find-common"><strong>' . esc_html__( 'Use the same translation', 'lightweight-translation' ) . '</strong></label><textarea id="lt-find-common" rows="2" placeholder="' . esc_attr__( 'Translation for the checked texts…', 'lightweight-translation' ) . '"></textarea><button type="button" class="button" id="lt-find-fill">' . esc_html__( 'Fill selected fields', 'lightweight-translation' ) . '</button></div><div><strong>' . esc_html__( 'Replace a word in the translations', 'lightweight-translation' ) . '</strong><div class="lt-find-replace-inputs"><input id="lt-find-from" type="text" value="' . esc_attr( $search ) . '" aria-label="' . esc_attr__( 'Find', 'lightweight-translation' ) . '"><input id="lt-find-to" type="text" placeholder="' . esc_attr__( 'Replace with…', 'lightweight-translation' ) . '" aria-label="' . esc_attr__( 'Replace with', 'lightweight-translation' ) . '"></div><label><input id="lt-find-from-source" type="checkbox"> ' . esc_html__( 'Start from the original when a translation is empty', 'lightweight-translation' ) . '</label><p><button type="button" class="button" id="lt-find-preview">' . esc_html__( 'Preview replacement', 'lightweight-translation' ) . '</button></p></div></div><div id="lt-find-preview-box" hidden><p id="lt-find-preview-message"></p><ul id="lt-find-preview-list"></ul><button type="button" class="button" id="lt-find-apply">' . esc_html__( 'Apply to the fields', 'lightweight-translation' ) . '</button></div></div>';
	}
}
