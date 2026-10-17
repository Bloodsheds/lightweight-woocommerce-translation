<?php
defined( 'ABSPATH' ) || exit;

/**
 * Translation panel of "Pages & products": everything a visitor sees on one page or product, translated in
 * place — its fields, its content paragraph by paragraph, its variations, the blocks shown inside it and the
 * interface texts of that page. Also the bulk actions of the list.
 */
class LT_Status_Panel {
	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() {
		add_action( 'wp_ajax_lt_panel', array( $this, 'ajax_render' ) );
		add_action( 'wp_ajax_lt_panel_save', array( $this, 'ajax_save' ) );
		add_action( 'admin_post_lt_status_bulk', array( $this, 'bulk' ) );
	}

	private static function languages() { $router = LT_Language_Router::instance(); return array_values( array_diff( $router->languages(), array( $router->default_language() ) ) ); }

	/* ---------- What is on a page ---------- */

	/** Key of a page in the "texts per page" map (special WooCommerce pages have their own name). */
	public static function map_key( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id && (int) get_option( 'page_on_front' ) === $post_id ) return 'home';
		if ( $post_id && (int) get_option( 'page_for_posts' ) === $post_id ) return 'blog';
		if ( function_exists( 'wc_get_page_id' ) ) foreach ( array( 'shop' => 'shop', 'cart' => 'cart', 'checkout' => 'checkout', 'myaccount' => 'account' ) as $page => $key ) if ( (int) wc_get_page_id( $page ) === $post_id ) return $key;
		return 'page:' . $post_id;
	}

	/**
	 * Blocks shown inside a page and interface texts of that page (texts on every page excluded: they are in
	 * "Interface texts › Everywhere").
	 * @return array|null [ read => bool, items => [ [group, id] ], texts => [ field => source ] ], null when not a page.
	 */
	public static function extras( $group, $id ) {
		if ( 'post:page' !== $group ) return null;
		static $groups = null;
		if ( null === $groups ) $groups = LT_Page_Texts::groups();
		list( $map, $common ) = $groups;
		$key = self::map_key( $id );
		if ( ! isset( $map[ $key ] ) ) return array( 'read' => false, 'items' => array(), 'texts' => array() );
		// Blocks found on most pages (header, footer, side cart…) are translated once in "Blocks & layouts".
		static $shared = null;
		if ( null === $shared ) {
			$count = array();
			foreach ( $map as $entry ) foreach ( array_unique( (array) ( $entry['items'] ?? array() ) ) as $ref ) $count[ $ref ] = ( $count[ $ref ] ?? 0 ) + 1;
			$limit  = max( 3, (int) ceil( count( $map ) * 0.6 ) );
			$shared = count( $map ) >= 3 ? array_filter( $count, function( $n ) use ( $limit ) { return $n >= $limit; } ) : array();
		}
		$items = array();
		foreach ( (array) ( $map[ $key ]['items'] ?? array() ) as $ref ) {
			list( $type, $item_id ) = array_pad( explode( ':', (string) $ref, 2 ), 2, 0 );
			if ( ! $type || ! (int) $item_id || (int) $item_id === (int) $id || isset( $shared[ $ref ] ) || ! post_type_exists( $type ) ) continue;
			if ( function_exists( 'is_post_type_viewable' ) && is_post_type_viewable( $type ) ) continue; // another page / product: it has its own line
			$items[] = array( 'post:' . $type, (int) $item_id );
		}
		$texts = array();
		foreach ( array_diff_key( (array) $map[ $key ]['keys'], $common ) as $field => $text ) {
			if ( is_array( $text ) && in_array( $text['z'] ?? '', array( 'header', 'footer', 'panel' ), true ) ) continue; // translated in "Interface texts › Every page"
			$source = is_array( $text ) ? (string) ( $text[0] ?? '' ) : (string) $text;
			if ( '' !== trim( $source ) ) $texts[ $field ] = array( $source, is_array( $text ) ? $text : array() );
		}
		return array( 'read' => true, 'items' => $items, 'texts' => $texts );
	}

	/** Ordered fields of an item, with names a person understands. */
	private static function entries( $item ) {
		$order  = array( 'title' => 1, 'woodmart_variation_title' => 1, 'term_name' => 1, 'label' => 1, 'slug' => 2, 'short_description' => 3, 'excerpt' => 3, 'content' => 4, 'term_description' => 4 );
		$fields = $item['fields'];
		uksort( $fields, function( $a, $b ) use ( $order ) { return ( $order[ $a ] ?? 5 ) - ( $order[ $b ] ?? 5 ); } );
		$out = array();
		foreach ( $fields as $field => $source ) $out[] = array( 'type' => $item['type'], 'id' => (int) $item['id'], 'field' => $field, 'source' => (string) $source, 'label' => self::label( $field ) );
		return $out;
	}

	public static function label( $field ) {
		$labels = array( 'title' => __( 'Title', 'lightweight-translation' ), 'woodmart_variation_title' => __( 'Title', 'lightweight-translation' ), 'term_name' => __( 'Name', 'lightweight-translation' ), 'label' => __( 'Name', 'lightweight-translation' ), 'slug' => __( 'URL', 'lightweight-translation' ), 'short_description' => __( 'Short description', 'lightweight-translation' ), 'excerpt' => __( 'Excerpt', 'lightweight-translation' ), 'content' => __( 'Content', 'lightweight-translation' ), 'term_description' => __( 'Description', 'lightweight-translation' ) );
		if ( isset( $labels[ $field ] ) ) return $labels[ $field ];
		if ( preg_match( '/^woodmart_tab_(title|content)_(\d+)$/', $field, $m ) ) return sprintf( 'title' === $m[1] ? __( 'Tab %d – title', 'lightweight-translation' ) : __( 'Tab %d – content', 'lightweight-translation' ), $m[2] );
		if ( preg_match( '/^bundle_item_(title|description)_(\d+)$/', $field, $m ) ) return sprintf( 'title' === $m[1] ? __( 'Bundle item %d – title', 'lightweight-translation' ) : __( 'Bundle item %d – description', 'lightweight-translation' ), $m[2] );
		return ucfirst( str_replace( '_', ' ', $field ) );
	}

	/**
	 * Everything to translate for one item, in sections. Sections marked "own" belong to the item (bulk actions
	 * work on them); the others are shared with other pages (blocks, interface texts).
	 */
	public static function model( $group, $id ) {
		$item = LT_Content_Catalog::item( $group, $id );
		if ( ! $item ) return null;
		$sections = array();
		$groups   = LT_Content_Catalog::groups();
		$own        = array( 'post:page' => __( 'The page', 'lightweight-translation' ), 'post:post' => __( 'The post', 'lightweight-translation' ), 'post:product' => __( 'The product', 'lightweight-translation' ) );
		$sections[] = array( 'title' => $own[ $group ] ?? ( $groups[ $group ] ?? '' ), 'own' => true, 'entries' => self::entries( $item ) );
		if ( 'post:product' === $group ) {
			foreach ( get_posts( array( 'post_type' => 'product_variation', 'post_status' => LT_Content_Catalog::POST_STATUSES, 'numberposts' => -1, 'post_parent' => (int) $id, 'fields' => 'ids', 'orderby' => 'menu_order', 'order' => 'ASC', 'suppress_filters' => true ) ) as $vid ) {
				$variation = LT_Content_Catalog::item( 'post:product_variation', $vid );
				if ( ! $variation || ! $variation['fields'] ) continue;
				$name = (string) $variation['title'];
				if ( false !== strpos( $name, ' → ' ) ) $name = substr( $name, strpos( $name, ' → ' ) + 5 );
				$sections[] = array( 'title' => sprintf( __( 'Variation: %s', 'lightweight-translation' ), $name ), 'own' => true, 'entries' => self::entries( $variation ) );
			}
		}
		$extras = self::extras( $group, $id );
		if ( $extras ) {
			foreach ( $extras['items'] as $ref ) {
				$block = LT_Content_Catalog::item( $ref[0], $ref[1] );
				if ( ! $block || ! $block['fields'] ) continue;
				$sections[] = array( 'title' => sprintf( __( 'Block shown on this page: %s', 'lightweight-translation' ), $block['title'] ), 'note' => __( 'Shared: also changes it on the other pages where it appears.', 'lightweight-translation' ), 'own' => false, 'entries' => self::entries( $block ) );
			}
			if ( $extras['texts'] ) {
				$entries = array();
				uasort( $extras['texts'], function( $a, $b ) { return ( $a[1]['o'] ?? 100 ) - ( $b[1]['o'] ?? 100 ); } ); // in page order
				foreach ( $extras['texts'] as $field => $text ) $entries[] = array( 'type' => 'string', 'id' => 0, 'field' => $field, 'source' => $text[0], 'label' => '', 'place' => LT_Page_Places::summary( $text[1], false ) );
				$sections[] = array( 'title' => __( 'Other texts on this page (buttons, forms, messages…)', 'lightweight-translation' ), 'note' => __( 'Shared: the same text is translated everywhere.', 'lightweight-translation' ), 'own' => false, 'entries' => $entries );
			}
		}
		return array( 'group' => $group, 'id' => (int) $id, 'item' => $item, 'sections' => $sections, 'read' => $extras ? $extras['read'] : true );
	}

	/* ---------- State of one field in one language ---------- */

	/**
	 * @return array [ state => ok|changed|todo, value, old (original when translated), mode => input|text|parts|whole, parts => [ [source, value, state] ] ]
	 */
	public static function field_state( $entry, $language ) {
		$row    = 'string' === $entry['type'] ? LT_Translation_Store::string_row( $language, $entry['field'] ) : LT_Translation_Store::get( $language, $entry['type'], $entry['id'], $entry['field'] );
		$live   = $row && in_array( $row->status, LT_Translation_Store::LIVE_STATUSES, true ) && '' !== trim( (string) $row->translated_text );
		$same   = $live && LT_Translation_Store::source_matches( $row, $entry['source'] );
		$source = $entry['source'];
		$out    = array( 'state' => $live ? ( $same && 'partial' !== $row->status ? 'ok' : 'changed' ) : 'todo', 'value' => $live ? (string) $row->translated_text : '', 'old' => ( $live && ! $same ) ? (string) ( $row->source_text ?? '' ) : '', 'mode' => ( 'slug' === $entry['field'] || ( mb_strlen( $source ) < 90 && false === strpos( $source, "\n" ) ) ) ? 'input' : 'text', 'parts' => array() );
		if ( 'string' === $entry['type'] || ! LT_Segments::is_long( $source ) ) return $out;

		// Long content: paragraph by paragraph. Paragraphs already translated are recovered even after the
		// original was edited, so only new or changed paragraphs are left to do.
		$memory = array();
		if ( $live ) {
			$memory = LT_Segments::memory( $row->source_text, $row->translated_text, 'partial' === $row->status );
			if ( null === $memory ) { $out['mode'] = 'whole'; return $out; } // translated elsewhere with another layout
		}
		$out['mode'] = 'parts';
		$todo        = 0;
		foreach ( LT_Segments::texts( $source ) as $text ) {
			$value = LT_Segments::recall( $memory, $text );
			if ( null === $value ) $todo++;
			$out['parts'][] = array( $text, null === $value ? '' : $value, null === $value ? ( $live ? 'new' : 'todo' ) : 'ok' );
		}
		$out['state'] = $todo ? ( $live ? 'changed' : 'todo' ) : 'ok';
		$out['old']   = '';
		return $out;
	}

	/** Totals of a model in one language: [ todo, changed, total ] (paragraphs counted one by one). */
	public static function totals( $model, $language ) {
		$t = array( 'todo' => 0, 'changed' => 0, 'total' => 0 );
		foreach ( $model['sections'] as $section ) foreach ( $section['entries'] as $entry ) {
			if ( 'slug' === $entry['field'] ) continue;
			$st = self::field_state( $entry, $language );
			if ( 'parts' === $st['mode'] ) {
				foreach ( $st['parts'] as $part ) { $t['total']++; if ( 'todo' === $part[2] ) $t['todo']++; elseif ( 'new' === $part[2] ) $t['changed']++; }
				continue;
			}
			$t['total']++;
			if ( 'ok' !== $st['state'] ) $t[ $st['state'] ]++;
		}
		return $t;
	}

	/* ---------- Panel ---------- */

	private static function view_url( $group, $id, $language ) {
		if ( 0 === strpos( $group, 'post:' ) ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) return '';
			return (string) LT_Language_Router::instance()->with_language( $language, function() use ( $id ) { return get_permalink( $id ); } );
		}
		if ( 0 === strpos( $group, 'tax:' ) ) {
			$link = LT_Language_Router::instance()->with_language( $language, function() use ( $id, $group ) { return get_term_link( (int) $id, substr( $group, 4 ) ); } );
			return is_wp_error( $link ) ? '' : (string) $link;
		}
		return '';
	}

	private static function name( $prefix, $entry, $suffix = '' ) { return $prefix . '[' . sanitize_key( $entry['type'] ) . '][' . (int) $entry['id'] . '][' . sanitize_key( $entry['field'] ) . ']' . $suffix; }

	public static function render( $group, $id, $language ) {
		$model = self::model( $group, $id );
		if ( ! $model ) return '<p>' . esc_html__( 'This item no longer exists.', 'lightweight-translation' ) . '</p>';
		$languages = self::languages();
		$item      = $model['item'];
		$view      = self::view_url( $group, $id, $language );
		$h         = '<form class="lt-panel" data-group="' . esc_attr( $group ) . '" data-id="' . (int) $id . '" data-lang="' . esc_attr( $language ) . '">';
		$h        .= '<div class="lt-panel-head"><div class="lt-panel-title"><h2>' . esc_html( $item['title'] ) . '</h2><div class="lt-panel-links">';
		if ( $view ) $h .= '<a href="' . esc_url( $view ) . '" target="_blank" rel="noopener">' . esc_html__( 'View on the site', 'lightweight-translation' ) . ' ↗</a>';
		if ( $item['edit'] && 0 === strpos( $group, 'post:' ) ) $h .= '<a href="' . esc_url( $item['block_editor'] ? add_query_arg( 'lt_lang', $language, $item['edit'] ) : $item['edit'] . '#lt-translations' ) . '">' . esc_html( sprintf( __( 'Translate in the editor (%s)', 'lightweight-translation' ), strtoupper( $language ) ) ) . '</a>';
		if ( $item['edit'] ) $h .= '<a href="' . esc_url( $item['edit'] ) . '">' . esc_html__( 'Edit the original', 'lightweight-translation' ) . '</a>';
		$h .= '</div></div><button type="button" class="lt-panel-close" aria-label="' . esc_attr__( 'Close', 'lightweight-translation' ) . '">×</button></div>';

		// Language tabs with what is left in each one.
		$h .= '<div class="lt-panel-langs" role="tablist">';
		foreach ( $languages as $l ) {
			$t     = self::totals( $model, $l );
			$left  = $t['todo'] + $t['changed'];
			$h    .= '<button type="button" role="tab" class="lt-lang-tab' . ( $l === $language ? ' is-active' : '' ) . '" data-lang="' . esc_attr( $l ) . '" aria-selected="' . ( $l === $language ? 'true' : 'false' ) . '">' . esc_html( LT_Language_Router::flag( $l ) . ' ' . LT_Language_Router::language_label( $l ) ) . ' ' . ( $left ? '<span class="lt-count todo">' . esc_html( sprintf( __( '%d left', 'lightweight-translation' ), $left ) ) . '</span>' : '<span class="lt-count done">✓</span>' ) . '</button>';
		}
		$h .= '<label class="lt-only-left"><input type="checkbox" class="lt-filter-left"> ' . esc_html__( 'Show only what is left', 'lightweight-translation' ) . '</label></div>';

		if ( ! $model['read'] && $view ) {
			$h .= '<div class="lt-panel-notice"><p>' . esc_html__( 'The blocks and texts shown inside this page are not known yet. Read the page once to list them here.', 'lightweight-translation' ) . '</p><button type="button" class="button lt-read-page" data-url="' . esc_url( get_permalink( $id ) ) . '">' . esc_html__( 'Read this page', 'lightweight-translation' ) . '</button></div>';
		}

		$h .= '<div class="lt-panel-body">';
		foreach ( $model['sections'] as $section ) {
			if ( ! $section['entries'] ) continue;
			$h .= '<section class="lt-sec"><h3>' . esc_html( $section['title'] ) . '</h3>' . ( ! empty( $section['note'] ) ? '<p class="lt-sec-note">' . esc_html( $section['note'] ) . '</p>' : '' );
			foreach ( $section['entries'] as $entry ) $h .= self::entry_html( $entry, $language );
			$h .= '</section>';
		}
		$h .= '</div>';
		$h .= '<div class="lt-panel-foot"><span class="lt-panel-msg" aria-live="polite"></span><button type="button" class="button lt-save">' . esc_html__( 'Save', 'lightweight-translation' ) . '</button> <button type="button" class="button button-primary lt-save-next">' . esc_html__( 'Save and next', 'lightweight-translation' ) . ' →</button></div>';
		return $h . '</form>';
	}

	private static function entry_html( $entry, $language ) {
		$st   = self::field_state( $entry, $language );
		$hash = '<input type="hidden" name="' . esc_attr( self::name( 'h', $entry ) ) . '" value="' . esc_attr( LT_Translation_Store::hash( $entry['source'] ) ) . '">';
		$copy = '<button type="button" class="button-link lt-copy" title="' . esc_attr__( 'Copy the original (names, brands…)', 'lightweight-translation' ) . '">⇥</button>';
		if ( 'parts' === $st['mode'] ) {
			$h = '<div class="lt-field lt-parts" data-field="' . esc_attr( self::name( 'p', $entry ) ) . '">' . $hash . '<div class="lt-field-label">' . esc_html( $entry['label'] ) . ' <span class="lt-count">' . esc_html( sprintf( _n( '%d paragraph', '%d paragraphs', count( $st['parts'] ), 'lightweight-translation' ), count( $st['parts'] ) ) ) . '</span></div>';
			foreach ( $st['parts'] as $k => $part ) {
				$h .= '<div class="lt-row is-' . esc_attr( $part[2] ) . '"><div class="lt-src" data-src="' . esc_attr( $part[0] ) . '">' . self::show( $part[0] ) . '</div><div class="lt-tr"><textarea rows="' . (int) min( 8, max( 1, ceil( mb_strlen( $part[0] ) / 70 ) ) ) . '" name="' . esc_attr( self::name( 'p', $entry, '[' . $k . ']' ) ) . '">' . esc_textarea( $part[1] ) . '</textarea>' . $copy . ( 'new' === $part[2] ? '<span class="lt-badge">' . esc_html__( 'New or changed paragraph', 'lightweight-translation' ) . '</span>' : '' ) . '</div></div>';
			}
			return $h . '</div>';
		}
		$label = '' !== $entry['label'] ? '<div class="lt-field-label">' . esc_html( $entry['label'] ) . ( 'slug' === $entry['field'] ? ' <span class="lt-count">' . esc_html__( 'optional', 'lightweight-translation' ) . '</span>' : '' ) . '</div>' : '';
		$name  = esc_attr( self::name( 'v', $entry ) );
		$input = 'input' === $st['mode'] ? '<input type="text" name="' . $name . '" value="' . esc_attr( $st['value'] ) . '">' : '<textarea rows="' . ( 'whole' === $st['mode'] ? 12 : (int) min( 8, max( 2, ceil( mb_strlen( $entry['source'] ) / 70 ) ) ) ) . '" name="' . $name . '">' . esc_textarea( $st['value'] ) . '</textarea>';
		$extra = '';
		if ( 'changed' === $st['state'] ) {
			$extra = '<div class="lt-changed"><span class="lt-badge">' . esc_html__( 'The original was edited after this translation', 'lightweight-translation' ) . '</span>' . ( '' !== $st['old'] ? ' <details><summary>' . esc_html__( 'See the old original', 'lightweight-translation' ) . '</summary><div class="lt-old">' . self::show( $st['old'] ) . '</div></details>' : '' ) . ' <button type="button" class="button button-small lt-still">' . esc_html__( 'Still correct', 'lightweight-translation' ) . '</button></div>';
		}
		if ( 'whole' === $st['mode'] ) $extra .= '<p class="lt-sec-note">' . esc_html__( 'This translation was made in the editor with another layout: it is edited here as a whole.', 'lightweight-translation' ) . '</p>';
		$where = ! empty( $entry['place'] ) ? '<div class="lt-where">' . esc_html( $entry['place'] ) . '</div>' : '';
		return '<div class="lt-field lt-row is-' . esc_attr( $st['state'] ) . '">' . $hash . $label . '<div class="lt-pair"><div><div class="lt-src" data-src="' . esc_attr( $entry['source'] ) . '">' . self::show( $entry['source'] ) . '</div>' . $where . '</div><div class="lt-tr">' . $input . $copy . $extra . '</div></div></div>';
	}

	/** Original shown as readable text; tags kept visible but discreet (they must stay in the translation). */
	private static function show( $text ) {
		$parts = preg_split( '#(<[^>]+>|\[/?[a-zA-Z_][^\]]*\])#', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		foreach ( $parts as $i => $part ) $out .= $i % 2 ? '<code class="lt-tag">' . esc_html( $part ) . '</code>' : nl2br( esc_html( $part ) );
		return $out;
	}

	/* ---------- AJAX ---------- */

	private function check() {
		if ( ! current_user_can( lt_capability() ) || ! check_ajax_referer( 'lt_panel', 'nonce', false ) ) wp_send_json_error( array( 'message' => __( 'Your session expired: reload the page.', 'lightweight-translation' ) ), 403 );
		$group    = sanitize_text_field( wp_unslash( $_REQUEST['group'] ?? '' ) );
		$id       = absint( $_REQUEST['id'] ?? 0 );
		$language = sanitize_key( wp_unslash( $_REQUEST['lang'] ?? '' ) );
		if ( ! isset( LT_Content_Catalog::groups()[ $group ] ) || ! in_array( $language, self::languages(), true ) ) wp_send_json_error( array( 'message' => __( 'Unknown item.', 'lightweight-translation' ) ), 400 );
		return array( $group, $id, $language );
	}

	public function ajax_render() {
		list( $group, $id, $language ) = $this->check();
		wp_send_json_success( array( 'html' => self::render( $group, $id, $language ) ) );
	}

	public function ajax_save() {
		list( $group, $id, $language ) = $this->check();
		$model = self::model( $group, $id );
		if ( ! $model ) wp_send_json_error( array( 'message' => __( 'This item no longer exists.', 'lightweight-translation' ) ), 404 );
		$result = self::save( $model, $language, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is checked against the model
		$message = $result['stale'] ? sprintf( __( 'Saved. %d text(s) were edited in the original meanwhile: check them again.', 'lightweight-translation' ), $result['stale'] ) : __( 'Saved.', 'lightweight-translation' );
		wp_send_json_success( array(
			'saved'   => $result['saved'],
			'message' => $message,
			'html'    => self::render( $group, $id, $language ),
			'row'     => LT_Translation_Status::instance()->row_markup( $group, $id, absint( $_POST['depth'] ?? 0 ) ),
		) );
	}

	/**
	 * Saves what was sent for the fields of a model. The originals always come from WordPress (never from the
	 * browser); a field whose original changed while the panel was open is skipped.
	 * @return array [ saved, stale ]
	 */
	public static function save( $model, $language, $post ) {
		$saved = 0;
		$stale = 0;
		foreach ( $model['sections'] as $section ) foreach ( $section['entries'] as $entry ) {
			$type  = sanitize_key( $entry['type'] );
			$id    = (int) $entry['id'];
			$field = sanitize_key( $entry['field'] );
			$parts = $post['p'][ $type ][ $id ][ $field ] ?? null;
			$value = $post['v'][ $type ][ $id ][ $field ] ?? null;
			if ( null === $parts && null === $value ) continue;
			if ( (string) ( $post['h'][ $type ][ $id ][ $field ] ?? '' ) !== LT_Translation_Store::hash( $entry['source'] ) ) { $stale++; continue; }
			if ( is_array( $parts ) ) {
				$texts  = LT_Segments::texts( $entry['source'] );
				$filled = 0;
				$values = array();
				foreach ( array_keys( $texts ) as $k ) { $values[ $k ] = isset( $parts[ $k ] ) && is_string( $parts[ $k ] ) ? $parts[ $k ] : ''; if ( '' !== trim( $values[ $k ] ) ) $filled++; }
				$translated = $filled ? LT_Segments::rebuild( $entry['source'], $values ) : '';
				$status     = ! $filled ? 'empty' : ( $filled < count( $texts ) ? 'partial' : 'translated' );
			} else {
				if ( ! is_string( $value ) ) continue;
				$translated = 'slug' === $field ? sanitize_title( $value ) : $value;
				$status     = '' === trim( $translated ) ? 'empty' : 'translated';
			}
			LT_Translation_Store::save( array( 'language_code' => $language, 'object_type' => $type, 'object_id' => $id, 'field_key' => $field, 'source_text' => $entry['source'], 'translated_text' => 'empty' === $status ? '' : $translated, 'status' => $status ) );
			$saved++;
		}
		return array( 'saved' => $saved, 'stale' => $stale );
	}

	/* ---------- Bulk actions ---------- */

	public function bulk() {
		if ( ! current_user_can( lt_capability() ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lt_bulk_nonce'] ?? '' ) ), 'lt_status_bulk' ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		$action    = sanitize_key( $_POST['bulk'] ?? '' );
		$language  = sanitize_key( $_POST['bulk_lang'] ?? '' );
		$languages = in_array( $language, self::languages(), true ) ? array( $language ) : self::languages();
		$groups    = LT_Content_Catalog::groups();
		$items     = array();
		foreach ( (array) wp_unslash( $_POST['items'] ?? array() ) as $ref ) {
			list( $group, $id ) = array_pad( explode( '|', sanitize_text_field( $ref ), 2 ), 2, 0 );
			if ( isset( $groups[ $group ] ) && absint( $id ) ) $items[] = array( $group, absint( $id ) );
		}
		$back = wp_get_referer() ?: admin_url( 'admin.php?page=lt-status' );
		if ( ! $items ) { wp_safe_redirect( add_query_arg( 'lt_notice', rawurlencode( __( 'Tick at least one line first.', 'lightweight-translation' ) ), $back ) ); exit; }

		if ( 'export' === $action ) {
			// Selected items (+ variations of products, + blocks shown inside pages) in the AI-ready file.
			$refs = array();
			foreach ( $items as $ref ) {
				$refs[] = $ref[0] . '|' . $ref[1];
				$model  = self::model( $ref[0], $ref[1] );
				if ( $model ) foreach ( $model['sections'] as $section ) foreach ( $section['entries'] as $entry ) if ( 'string' !== $entry['type'] && ( (int) $entry['id'] !== $ref[1] || $entry['type'] !== $model['item']['type'] ) ) $refs[] = ( 'term' === $entry['type'] ? $ref[0] : 'post:' . $entry['type'] ) . '|' . $entry['id'];
			}
			$refs = array_values( array_unique( $refs ) );
			$_REQUEST['ids']    = $refs;
			$_REQUEST['groups'] = array_values( array_unique( array_map( function( $r ) { return strstr( $r, '|', true ); }, $refs ) ) );
			$_REQUEST['langs']  = $languages;
			$_REQUEST['only']   = 'todo';
			LT_CSV_Export::instance()->download(); // exits
		}

		$done = 0;
		foreach ( $items as $ref ) {
			$model = self::model( $ref[0], $ref[1] );
			if ( ! $model ) continue;
			foreach ( $model['sections'] as $section ) {
				if ( empty( $section['own'] ) ) continue;
				foreach ( $section['entries'] as $entry ) foreach ( $languages as $l ) {
					$row  = LT_Translation_Store::get( $l, $entry['type'], $entry['id'], $entry['field'] );
					$live = $row && in_array( $row->status, LT_Translation_Store::LIVE_STATUSES, true ) && '' !== trim( (string) $row->translated_text );
					$data = array( 'language_code' => $l, 'object_type' => $entry['type'], 'object_id' => $entry['id'], 'field_key' => $entry['field'], 'source_text' => $entry['source'] );
					if ( 'copy' === $action && ! $live && 'slug' !== $entry['field'] ) { LT_Translation_Store::save( $data + array( 'translated_text' => $entry['source'], 'status' => 'translated' ) ); $done++; }
					if ( 'checked' === $action && $live && 'partial' !== $row->status && ! LT_Translation_Store::source_matches( $row, $entry['source'] ) ) {
						// The paragraphs still known are kept, the others take the new original.
						$translated = (string) $row->translated_text;
						if ( LT_Segments::is_long( $entry['source'] ) ) {
							$memory = LT_Segments::memory( $row->source_text, $row->translated_text );
							if ( null !== $memory ) $translated = LT_Segments::rebuild( $entry['source'], array_map( function( $t ) use ( $memory ) { return (string) LT_Segments::recall( $memory, $t ); }, LT_Segments::texts( $entry['source'] ) ) );
						}
						LT_Translation_Store::save( $data + array( 'translated_text' => $translated, 'status' => 'translated' ) );
						$done++;
					}
				}
			}
			if ( 'delete' === $action ) {
				foreach ( $model['sections'] as $section ) if ( ! empty( $section['own'] ) && $section['entries'] ) $done += LT_Translation_Reset::reset( $section['entries'][0]['type'], $section['entries'][0]['id'], 1 === count( $languages ) ? $languages[0] : '' );
			}
		}
		$messages = array( 'copy' => __( '%d empty texts now use the original.', 'lightweight-translation' ), 'checked' => __( '%d translations marked as still correct.', 'lightweight-translation' ), 'delete' => __( '%d translations deleted.', 'lightweight-translation' ) );
		wp_safe_redirect( add_query_arg( 'lt_notice', rawurlencode( sprintf( $messages[ $action ] ?? '%d', $done ) ), remove_query_arg( 'lt_notice', $back ) ) );
		exit;
	}
}
