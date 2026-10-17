<?php
defined( 'ABSPATH' ) || exit;

/**
	 * "Content" — organised by the part of the site it belongs to:
	 *  - left: WordPress, WooCommerce, Menus, and custom content;
 *  - right: the chosen type organised like on the site (pages as a tree, products under their category with
 *    their variations, menu links under their menu). Clicking a line opens the translation panel right here
 *    (LT_Status_Panel); lines can be ticked for bulk actions (export for AI, copy original, …).
 * A page counts as translated only when the blocks and texts shown inside it are translated too.
 */
class LT_Translation_Status {
	const PER_PAGE = 50;

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() { add_action( 'admin_menu', array( $this, 'menu' ), 19 ); }

	public function menu() { add_submenu_page( 'lt-settings', __( 'Content', 'lightweight-translation' ), __( 'Content', 'lightweight-translation' ), lt_capability(), 'lt-status', array( $this, 'page' ) ); }

	private function languages() { $router = LT_Language_Router::instance(); return array_values( array_diff( $router->languages(), array( $router->default_language() ) ) ); }

	private function url( $args = array() ) { return add_query_arg( array_merge( array( 'page' => 'lt-status' ), array_filter( $args, 'strlen' ) ), admin_url( 'admin.php' ) ); }

	/** Content is separated by what it belongs to; collected interface texts stay with their source screens. */
	private function groups( $source = '' ) {
		$groups = LT_Content_Catalog::groups();
		unset( $groups['string'], $groups['post:product_variation'] );
		if ( '' === $source ) return $groups;
		foreach ( $groups as $group => $label ) {
			if ( $this->group_source( $group ) !== $source ) unset( $groups[ $group ] );
		}
		return $groups;
	}

	private function group_source( $group ) { return LT_Admin_Navigation::group_source( $group ); }

	/** Section of the left column a content type belongs to. */
	private function section( $group ) {
		list( $kind, $name ) = array_pad( explode( ':', $group, 2 ), 2, '' );
		if ( 'menu' === $kind ) return 'menus';
		if ( 'attribute' === $kind || 'post:product' === $group ) return 'shop';
		if ( 'tax' === $kind ) {
			if ( 0 === strpos( $name, 'pa_' ) || 0 === strpos( $name, 'product_' ) ) return 'shop';
			$tax = function_exists( 'get_taxonomy' ) ? get_taxonomy( $name ) : null;
			return ( $tax && in_array( 'product', (array) $tax->object_type, true ) ) ? 'shop' : 'website';
		}
		if ( in_array( $name, LT_Content_Catalog::EXTRA_POST_TYPES, true ) && 'portfolio' !== $name ) return 'blocks';
		return 'website';
	}

	private function sections() {
		return array(
			'website' => __( 'WordPress', 'lightweight-translation' ),
			'shop'    => __( 'WooCommerce', 'lightweight-translation' ),
			'menus'   => __( 'Menus', 'lightweight-translation' ),
			'blocks'  => __( 'Blocks & layouts', 'lightweight-translation' ),
		);
	}

	/** Object type stored in the translation table for a group. */
	private function object_type( $group ) {
		list( $kind, $name ) = array_pad( explode( ':', $group, 2 ), 2, '' );
		if ( 'post' === $kind ) return $name;
		if ( 'tax' === $kind ) return 'term';
		return 'menu' === $kind ? 'nav_menu_item' : $kind;
	}

	/** Rows of some items of one group, with their state per language (todo / update / done). */
	private function rows( $group, $ids, $languages ) {
		$rows = array();
		if ( ! $ids ) return $rows;
		// Load everything in a few queries instead of one per item.
		if ( 0 === strpos( $group, 'post:' ) || 'menu' === $group ) { if ( function_exists( '_prime_post_caches' ) ) _prime_post_caches( $ids, true, true ); }
		elseif ( 0 === strpos( $group, 'tax:' ) && function_exists( '_prime_term_caches' ) ) _prime_term_caches( $ids, true );
		foreach ( $ids as $id ) {
			$item = LT_Content_Catalog::item( $group, $id );
			if ( ! $item || ! $item['fields'] ) continue;
			$counts = LT_Content_Catalog::item_progress( $item, $languages );
			// A page is translated only when what is shown inside it is translated too.
			$extras = LT_Status_Panel::extras( $group, $id );
			if ( $extras ) {
				foreach ( $extras['items'] as $ref ) {
					$block = LT_Content_Catalog::item( $ref[0], $ref[1] );
					if ( $block && $block['fields'] ) foreach ( LT_Content_Catalog::item_progress( $block, $languages ) as $language => $p ) foreach ( array( 'translated', 'outdated', 'missing' ) as $k ) $counts[ $language ][ $k ] += $p[ $k ];
				}
				foreach ( $languages as $language ) foreach ( $extras['texts'] as $field => $text ) $counts[ $language ][ null !== LT_Translation_Store::string_lookup( $language, $field, $text[0] ) ? 'translated' : 'missing' ]++;
			}
			$states = array();
			foreach ( $counts as $language => $p ) {
				$states[ $language ] = array( 'state' => $p['missing'] ? 'todo' : ( $p['outdated'] ? 'update' : 'done' ), 'done' => $p['translated'], 'total' => $p['translated'] + $p['outdated'] + $p['missing'] );
			}
			$rows[ (int) $id ] = array( 'group' => $group, 'id' => (int) $id, 'item' => array( 'edit' => $item['edit'], 'block_editor' => $item['block_editor'] ), 'title' => (string) $item['title'], 'states' => $states, 'parent' => 0, 'bucket' => '', 'children' => array(), 'unread' => $extras && ! $extras['read'] );
		}
		return $rows;
	}

	/** Most urgent state of a list of rows (todo > update > done). */
	private function worst( $rows ) {
		$worst = 'done';
		foreach ( $rows as $row ) foreach ( $row['states'] as $st ) {
			if ( 'todo' === $st['state'] ) return 'todo';
			if ( 'update' === $st['state'] ) $worst = 'update';
		}
		return $worst;
	}

	/** Variations of some products, folded inside them. */
	private function attach_variations( &$rows, $languages ) {
		if ( ! $rows || ! function_exists( 'get_posts' ) ) return;
		$parents = array();
		foreach ( get_posts( array( 'post_type' => 'product_variation', 'post_status' => LT_Content_Catalog::POST_STATUSES, 'numberposts' => -1, 'post_parent__in' => array_keys( $rows ), 'fields' => 'id=>parent', 'orderby' => 'menu_order', 'order' => 'ASC', 'suppress_filters' => true ) ) as $variation ) $parents[ (int) $variation->ID ] = (int) $variation->post_parent;
		foreach ( $this->rows( 'post:product_variation', array_keys( $parents ), $languages ) as $vid => $variation ) {
			$parent = $parents[ $vid ];
			if ( ! isset( $rows[ $parent ] ) ) continue;
			$prefix = $rows[ $parent ]['title'] . ' → ';
			if ( 0 === strpos( $variation['title'], $prefix ) ) $variation['title'] = substr( $variation['title'], strlen( $prefix ) );
			$rows[ $parent ]['children'][] = $variation;
		}
	}

	/**
	 * The chosen type, organised like on the site.
	 * @return array Units (one per top-level line): row + its variations, its heading (category / menu) and depth.
	 */
	private function organise( $group, $languages ) {
		// The list (titles, order, headings) is kept until an item of this type is added, moved or renamed;
		// a saved translation only refreshes the states, read from the cached counts.
		$cache_key = 'lt_list_' . md5( LT_VERSION . '|' . $group . '|' . implode( ',', $languages ) . '|' . determine_locale() . '|' . get_current_user_id() . '|' . LT_Content_Catalog::list_revision( $group ) );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) return $this->fresh_states( $group, $cached, $languages );
		list( $kind, $name ) = array_pad( explode( ':', $group, 2 ), 2, '' );
		$rows = $this->rows( $group, LT_Content_Catalog::item_ids( $group ), $languages );

		// Parents (pages, categories…) and headings (product category, menu).
		$tree = ( 'post' === $kind && function_exists( 'is_post_type_hierarchical' ) && is_post_type_hierarchical( $name ) ) || ( 'tax' === $kind && function_exists( 'is_taxonomy_hierarchical' ) && is_taxonomy_hierarchical( $name ) );
		foreach ( $rows as $id => &$row ) {
			if ( $tree && 'post' === $kind ) { $post = get_post( $id ); $row['parent'] = $post ? (int) $post->post_parent : 0; }
			if ( $tree && 'tax' === $kind ) { $term = get_term( $id ); $row['parent'] = ( $term && ! is_wp_error( $term ) ) ? (int) $term->parent : 0; }
			if ( 'menu' === $group && false !== strpos( $row['title'], ' → ' ) ) list( $row['bucket'], $row['title'] ) = explode( ' → ', $row['title'], 2 );
			if ( 'post:product' === $group ) $row['bucket'] = $this->product_category( $id );
		}
		unset( $row );
		if ( 'post:product' === $group ) $this->attach_variations( $rows, $languages );

		// Tree order: each parent followed by its children (the order of the site is kept).
		$kids = array();
		foreach ( $rows as $id => $row ) $kids[ isset( $rows[ $row['parent'] ] ) ? $row['parent'] : 0 ][] = $id;
		$units = array();
		$walk  = function( $parent, $depth ) use ( &$walk, &$units, $kids, $rows ) {
			foreach ( $kids[ $parent ] ?? array() as $id ) {
				$units[] = array( 'row' => $rows[ $id ], 'depth' => $depth, 'parent_title' => $parent ? $rows[ $parent ]['title'] : '' );
				$walk( $id, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		// Headings in alphabetical order ("without category" last), keeping the tree order inside each heading.
		if ( 'post:product' === $group || 'menu' === $group ) {
			$order = array();
			foreach ( $units as $i => $unit ) $order[ $unit['row']['bucket'] ][] = $i;
			uksort( $order, function( $a, $b ) { return ( '' === (string) $a ) - ( '' === (string) $b ) ?: strcasecmp( (string) $a, (string) $b ); } );
			$sorted = array();
			foreach ( $order as $indexes ) foreach ( $indexes as $i ) $sorted[] = $units[ $i ];
			$units = $sorted;
		}
		foreach ( $units as &$unit ) $unit['state'] = $this->worst( array_merge( array( $unit['row'] ), $unit['row']['children'] ) );
		unset( $unit );
		set_transient( $cache_key, $units, HOUR_IN_SECONDS );
		return $units;
	}

	/** Current state of every line, from the counts kept up to date item by item (pages also count what is shown inside: kept as built). */
	private function fresh_states( $group, $units, $languages ) {
		if ( 'post:page' === $group ) return $units;
		$states = array();
		$apply  = function( &$row ) use ( &$states, $languages ) {
			if ( ! isset( $states[ $row['group'] ] ) ) $states[ $row['group'] ] = LT_Content_Catalog::item_states( $row['group'], $languages );
			$counts = $states[ $row['group'] ][ $row['id'] ] ?? null;
			if ( ! is_array( $counts ) ) return;
			foreach ( $languages as $language ) {
				list( $translated, $outdated, $missing ) = $counts[ $language ] ?? array( 0, 0, 0 );
				$row['states'][ $language ] = array( 'state' => $missing ? 'todo' : ( $outdated ? 'update' : 'done' ), 'done' => $translated, 'total' => $translated + $outdated + $missing );
			}
		};
		foreach ( $units as &$unit ) {
			$apply( $unit['row'] );
			foreach ( $unit['row']['children'] as &$child ) $apply( $child );
			unset( $child );
			$unit['state'] = $this->worst( array_merge( array( $unit['row'] ), $unit['row']['children'] ) );
		}
		unset( $unit );
		return $units;
	}

	/** The category a product is filed under: the SEO "primary" one when set, otherwise the first one. */
	private function product_category( $id ) {
		$terms = function_exists( 'get_the_terms' ) ? get_the_terms( $id, 'product_cat' ) : array();
		if ( ! $terms || is_wp_error( $terms ) ) return '';
		$primary = (int) get_post_meta( $id, '_yoast_wpseo_primary_product_cat', true ) ?: (int) get_post_meta( $id, 'rank_math_primary_product_cat', true );
		foreach ( $terms as $term ) if ( $primary && (int) $term->term_id === $primary ) return $term->name;
		usort( $terms, function( $a, $b ) { return strcasecmp( $a->name, $b->name ); } );
		return $terms[0]->name;
	}

	/** What is left per type, for the left column (cached summary, refreshed when a translation is saved). */
	private function todo_by_group( $languages, $groups = null ) {
		$out = array();
		foreach ( LT_Content_Catalog::summary( $languages, false, $groups ) as $group => $data ) {
			$complete = $data['items'];
			foreach ( $data['languages'] as $l ) $complete = min( $complete, $l['complete_items'] );
			$out[ $group ] = array( 'items' => $data['items'], 'todo' => $data['items'] - $complete );
		}
		return $out;
	}

	private function assets( $languages ) {
		$css = LT_DIR . 'assets/css/status.css';
		$js  = LT_DIR . 'assets/js/status.js';
		if ( is_readable( $css ) ) echo '<style>' . file_get_contents( $css ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions
		$config = array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'lt_panel' ),
			'lang'  => in_array( sanitize_key( $_GET['lang'] ?? '' ), $languages, true ) ? sanitize_key( $_GET['lang'] ) : $languages[0],
			'i18n'  => array(
				'loading' => __( 'Loading…', 'lightweight-translation' ),
				'saving'  => __( 'Saving…', 'lightweight-translation' ),
				'unsaved' => __( 'You have unsaved translations. Leave anyway?', 'lightweight-translation' ),
				'error'   => __( 'Could not save. Check your connection and try again.', 'lightweight-translation' ),
				'reading' => __( 'Reading the page…', 'lightweight-translation' ),
				'last'    => __( 'That was the last one of this list.', 'lightweight-translation' ),
				'pick'    => __( 'Tick at least one line first.', 'lightweight-translation' ),
				'confirm' => __( 'Delete the translations of the ticked lines?', 'lightweight-translation' ),
			),
		);
		echo '<script>window.ltStatus=' . wp_json_encode( $config ) . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( is_readable( $js ) ) echo '<script>' . file_get_contents( $js ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions
	}

	public function page() {
		if ( ! current_user_can( lt_capability() ) ) return;
		$languages = $this->languages();
		$requested_source = sanitize_key( $_GET['source'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$requested_group = sanitize_text_field( wp_unslash( $_GET['group'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$source = in_array( $requested_source, array( 'pages', 'menus', 'woocommerce', 'theme', 'plugins', 'other' ), true ) ? $requested_source : ( '' !== $requested_group ? $this->group_source( $requested_group ) : 'pages' );
		echo '<div class="wrap lt-status">';
		$source_titles = array( 'pages' => __( 'Pages & posts', 'lightweight-translation' ), 'menus' => __( 'Menus', 'lightweight-translation' ), 'woocommerce' => 'WooCommerce', 'theme' => __( 'Theme content', 'lightweight-translation' ), 'other' => __( 'Other content', 'lightweight-translation' ) );
		// The global navigation already identifies the source. Avoid a third, nested tab bar here.
		echo '<h1>' . esc_html( $source_titles[ $source ] ?? __( 'Translations', 'lightweight-translation' ) ) . '</h1>';
		echo '<p class="description" style="margin:2px 0 10px">' . esc_html__( 'Choose an item from this source to translate it. Select several items for a bulk action.', 'lightweight-translation' ) . '</p>';
		if ( 'pages' === $source ) echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=lt-strings&view=pages' ) ) . '">' . esc_html__( 'Translate texts found on public pages', 'lightweight-translation' ) . '</a></p>';
		if ( isset( $_GET['lt_notice'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['lt_notice'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $languages ) { echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Add a secondary language in the settings first.', 'lightweight-translation' ) . '</p></div></div>'; return; }

		$groups = $this->groups( $source );
		$todo   = $this->todo_by_group( $languages, array_keys( $groups ) );
		$groups = array_filter( $groups, function( $group ) use ( $todo ) { return ! empty( $todo[ $group ]['items'] ); }, ARRAY_FILTER_USE_KEY );
		if ( ! $groups && in_array( $source, array( 'woocommerce', 'theme' ), true ) ) { echo '<div class="lt-layout"><aside class="lt-side">'; LT_Admin_Navigation::source_sidebar( $source, $todo ); echo '</aside><div class="lt-main"><p>' . esc_html__( 'Nothing to translate yet.', 'lightweight-translation' ) . '</p></div></div></div>'; return; }
		if ( ! $groups ) { echo '<p>' . esc_html__( 'Nothing to translate yet.', 'lightweight-translation' ) . '</p></div>'; return; }

		// Left column grouped in sections; open the first type with something left (or the first one).
		$sections   = $this->sections();
		$by_section = array();
		foreach ( $groups as $group => $label ) $by_section[ $this->section( $group ) ][ $group ] = $label;
		$by_section = array_filter( array_merge( array_fill_keys( array_keys( $sections ), array() ), $by_section ) );
		$type = isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $groups[ $type ] ) ) {
			$type = '';
			foreach ( $by_section as $list ) foreach ( $list as $group => $label ) if ( '' === $type && $todo[ $group ]['todo'] ) $type = $group;
			if ( '' === $type ) { $first = reset( $by_section ); $type = key( $first ); }
		}

		echo '<div class="lt-layout"><aside class="lt-side">';
		// WooCommerce and the theme: their content and their texts in one column, the same on all their screens.
		if ( in_array( $source, array( 'woocommerce', 'theme' ), true ) ) { $_GET['group'] = $type; LT_Admin_Navigation::source_sidebar( $source, $todo ); $by_section = array(); } // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( $by_section as $section => $list ) {
			echo '<h4>' . esc_html( $sections[ $section ] ) . '</h4>';
			foreach ( $list as $group => $label ) {
				$t     = $todo[ $group ];
				$badge = $t['todo'] ? '<span class="lt-count todo">' . esc_html( sprintf( __( '%d to do', 'lightweight-translation' ), $t['todo'] ) ) . '</span>' : '<span class="lt-count done">✓ ' . esc_html( number_format_i18n( $t['items'] ) ) . '</span>';
				echo '<a class="lt-place' . ( $group === $type ? ' is-active' : '' ) . '" href="' . esc_url( $this->url( array( 'group' => $group ) ) ) . '"><span>' . esc_html( $label ) . '</span>' . $badge . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '</aside><div class="lt-main">';
		$this->type_view( $type, $groups[ $type ], $languages );
		echo '</div></div>';
		echo '<div class="lt-drawer" hidden><div class="lt-drawer-bg"></div><div class="lt-drawer-box" role="dialog" aria-modal="true"></div></div>';
		echo '</div>';
		$this->assets( $languages );
	}

	/** Right side: one content type, organised. */
	private function type_view( $type, $label, $languages ) {
		$units  = $this->organise( $type, $languages );
		$search = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $_GET['todo'] ) ) $status = 'todo'; // phpcs:ignore WordPress.Security.NonceVerification -- old links
		if ( ! in_array( $status, array( 'todo', 'update', 'done' ), true ) ) $status = '';
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification

		// Header: the type, how far each language is, and a button straight to the next thing to translate.
		$all_rows = array();
		foreach ( $units as $unit ) { $all_rows[] = $unit['row']; foreach ( $unit['row']['children'] as $child ) $all_rows[] = $child; }
		$next = null;
		foreach ( $units as $unit ) if ( 'done' !== $unit['state'] ) { $next = $unit['row']; break; }
		echo '<div class="lt-head"><div><h2>' . esc_html( $label ) . '</h2><div class="lt-progress">';
		foreach ( $languages as $language ) {
			$done = 0;
			foreach ( $all_rows as $row ) if ( 'done' === $row['states'][ $language ]['state'] ) $done++;
			$pct = $all_rows ? (int) floor( 100 * $done / count( $all_rows ) ) : 100;
			echo '<div><strong>' . esc_html( LT_Language_Router::flag( $language ) . ' ' . LT_Language_Router::language_label( $language ) ) . '</strong><span class="lt-bar"><i style="width:' . (int) $pct . '%"></i></span>' . esc_html( sprintf( __( '%1$d / %2$d translated', 'lightweight-translation' ), $done, count( $all_rows ) ) ) . '</div>';
		}
		echo '</div></div>';
		if ( $next ) echo '<button type="button" class="button button-primary lt-open" data-group="' . esc_attr( $next['group'] ) . '" data-id="' . (int) $next['id'] . '">' . esc_html__( 'Start translating', 'lightweight-translation' ) . ' →</button>';
		echo '</div>';

		// Import / export of this list as a table (CSV for Excel, Google Sheets or an AI), right here.
		$export_groups = 'post:product' === $type ? array( $type, 'post:product_variation' ) : array( $type );
		$export        = add_query_arg( array( 'action' => 'lt_export', 'groups' => $export_groups, 'only' => 'todo', '_wpnonce' => wp_create_nonce( 'lt_export' ) ), admin_url( 'admin-post.php' ) );
		echo '<details class="lt-io"><summary><span class="dashicons dashicons-media-spreadsheet"></span> ' . esc_html__( 'Translate as a table: export / import (Excel, Google Sheets, ChatGPT…)', 'lightweight-translation' ) . '</summary><div class="lt-io-body">';
		echo '<div><h4>1. ' . esc_html__( 'Export', 'lightweight-translation' ) . '</h4><p><a class="button" href="' . esc_url( $export ) . '">' . esc_html( sprintf( __( 'Download everything left to translate in “%s”', 'lightweight-translation' ), $label ) ) . '</a></p><p class="description">' . esc_html__( 'Or tick lines below and choose “Export for AI translation” in the bulk actions. One row per text: fill the “translated_text” column.', 'lightweight-translation' ) . '</p></div>';
		echo '<div><h4>2. ' . esc_html__( 'Import', 'lightweight-translation' ) . '</h4><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lt_import', 'lt_import_nonce', false );
		echo '<input type="hidden" name="action" value="lt_import"><input type="hidden" name="skip_empty" value="1"><input type="hidden" name="back" value="' . esc_attr( $this->url( array( 'group' => $type ) ) ) . '"><input type="file" name="lt_csv" accept=".csv,text/csv" required> <input type="submit" class="button button-primary" value="' . esc_attr__( 'Import the file', 'lightweight-translation' ) . '"></form><p class="description">' . esc_html__( 'Empty cells are ignored.', 'lightweight-translation' ) . '</p></div>';
		echo '</div></details>';

		// Search: keep a product when one of its variations matches (and show them).
		$open = array();
		if ( '' !== $search ) {
			$units = array_values( array_filter( $units, function( $unit ) use ( $search, &$open ) {
				if ( false !== mb_stripos( wp_strip_all_tags( $unit['row']['title'] ), $search ) ) return true;
				foreach ( $unit['row']['children'] as $child ) if ( false !== mb_stripos( wp_strip_all_tags( $child['title'] ), $search ) ) { $open[ $unit['row']['id'] ] = true; return true; }
				return false;
			} ) );
		}

		// Views: To translate / To check / Translated (a product counts with its variations).
		$counts = array( '' => count( $units ), 'todo' => 0, 'update' => 0, 'done' => 0 );
		foreach ( $units as $unit ) $counts[ $unit['state'] ]++;
		$views = array( '' => __( 'All', 'lightweight-translation' ), 'todo' => __( 'To translate', 'lightweight-translation' ), 'update' => __( 'To check', 'lightweight-translation' ), 'done' => __( 'Translated', 'lightweight-translation' ) );
		$links = array();
		foreach ( $views as $key => $view ) {
			if ( $key && ! $counts[ $key ] && $status !== $key ) continue;
			$links[] = '<li><a href="' . esc_url( $this->url( array( 'group' => $type, 's' => $search, 'status' => $key ) ) ) . '"' . ( $status === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $view ) . ' <span class="count">(' . (int) $counts[ $key ] . ')</span></a>';
		}
		echo '<ul class="subsubsub">' . implode( ' |</li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( $status ) $units = array_values( array_filter( $units, function( $unit ) use ( $status ) { return $unit['state'] === $status; } ) );
		$total = count( $units );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$heading_size = array();
		foreach ( $units as $unit ) $heading_size[ $unit['row']['bucket'] ] = ( $heading_size[ $unit['row']['bucket'] ] ?? 0 ) + 1;
		$units = array_slice( $units, ( min( $paged, $pages ) - 1 ) * self::PER_PAGE, self::PER_PAGE );

		echo '<form method="get" class="lt-search-form"><input type="hidden" name="page" value="lt-status"><input type="hidden" name="group" value="' . esc_attr( $type ) . '">' . ( $status ? '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">' : '' );
		echo '<p class="search-box"><label class="screen-reader-text" for="lt-search">' . esc_html__( 'Search', 'lightweight-translation' ) . '</label><input type="search" id="lt-search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( sprintf( __( 'Search in %s…', 'lightweight-translation' ), $label ) ) . '"> <input type="submit" class="button" value="' . esc_attr__( 'Search', 'lightweight-translation' ) . '"></p></form>';

		// Bulk actions (POST to admin-post.php).
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lt-bulk-form"><input type="hidden" name="action" value="lt_status_bulk">';
		wp_nonce_field( 'lt_status_bulk', 'lt_bulk_nonce', false );
		wp_nonce_field( 'lt_export', '_wpnonce', true ); // the AI export uses the Import / Export download
		echo '<div class="tablenav top"><div class="alignleft actions bulkactions"><label class="screen-reader-text" for="lt-bulk">' . esc_html__( 'Bulk actions', 'lightweight-translation' ) . '</label><select name="bulk" id="lt-bulk"><option value="">' . esc_html__( 'Bulk actions', 'lightweight-translation' ) . '</option>';
		echo '<option value="export">' . esc_html__( 'Export for AI translation (file)', 'lightweight-translation' ) . '</option>';
		echo '<option value="copy">' . esc_html__( 'Fill empty translations with the original', 'lightweight-translation' ) . '</option>';
		echo '<option value="checked">' . esc_html__( 'Mark edited originals as still correct', 'lightweight-translation' ) . '</option>';
		echo '<option value="delete">' . esc_html__( 'Delete the translations', 'lightweight-translation' ) . '</option></select> ';
		echo '<select name="bulk_lang" aria-label="' . esc_attr__( 'Language', 'lightweight-translation' ) . '">' . ( count( $languages ) > 1 ? '<option value="">' . esc_html__( 'All languages', 'lightweight-translation' ) . '</option>' : '' );
		foreach ( $languages as $language ) echo '<option value="' . esc_attr( $language ) . '">' . esc_html( LT_Language_Router::language_label( $language ) ) . '</option>';
		echo '</select> <input type="submit" class="button action" value="' . esc_attr__( 'Apply', 'lightweight-translation' ) . '"></div>';
		$this->pagination( $total, $pages, $paged );
		echo '</div>';

		$cols = count( $languages ) + 2;
		echo '<table class="wp-list-table widefat fixed striped table-view-list lt-status-list"><thead><tr><td class="manage-column column-cb check-column"><input type="checkbox" class="lt-check-all" aria-label="' . esc_attr__( 'Select all', 'lightweight-translation' ) . '"></td><th scope="col" class="manage-column column-title column-primary">' . esc_html__( 'Title', 'lightweight-translation' ) . '</th>';
		foreach ( $languages as $language ) echo '<th scope="col" class="manage-column column-lang" title="' . esc_attr( LT_Language_Router::language_label( $language ) ) . '">' . esc_html( LT_Language_Router::flag( $language ) . ' ' . strtoupper( $language ) ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( ! $units ) echo '<tr class="no-items"><td colspan="' . (int) $cols . '">' . esc_html( 'todo' === $status ? __( 'Everything is translated here.', 'lightweight-translation' ) : __( 'No items found.', 'lightweight-translation' ) ) . '</td></tr>';

		$headings = 'post:product' === $type || 'menu' === $type;
		$heading  = null;
		$flat     = '' !== $search || '' !== $status; // filtered: no tree, but say where the item is
		foreach ( $units as $unit ) {
			$row = $unit['row'];
			if ( $headings && $row['bucket'] !== $heading ) {
				$heading = $row['bucket'];
				$name    = '' !== $heading ? $heading : ( 'menu' === $type ? __( 'Menu', 'lightweight-translation' ) : __( 'Without category', 'lightweight-translation' ) );
				echo '<tr class="lt-heading"><td colspan="' . (int) $cols . '">' . ( 'menu' === $type ? '☰ ' : '' ) . esc_html( $name ) . '<span class="lt-count">' . (int) $heading_size[ $heading ] . '</span></td></tr>';
			}
			echo $this->unit_html( $row, $languages, $flat ? 0 : $unit['depth'], $flat ? $unit['parent_title'] : '', isset( $open[ $row['id'] ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		echo '<div class="tablenav bottom">';
		$this->pagination( $total, $pages, $paged );
		echo '</div></form>';
		echo '<p class="lt-legend"><span><span class="dashicons dashicons-yes done"></span>' . esc_html__( 'Translated', 'lightweight-translation' ) . '</span><span><span class="dashicons dashicons-warning update"></span>' . esc_html__( 'To check: the original was edited after it was translated', 'lightweight-translation' ) . '</span><span><span class="dashicons dashicons-plus todo"></span>' . esc_html__( 'To translate (with what is already done, e.g. 3/5)', 'lightweight-translation' ) . '</span></p>';
	}

	private function pagination( $total, $pages, $paged ) {
		echo '<div class="tablenav-pages' . ( $pages < 2 ? ' one-page' : '' ) . '"><span class="displaying-num">' . esc_html( sprintf( _n( '%s item', '%s items', $total, 'lightweight-translation' ), number_format_i18n( $total ) ) ) . '</span>';
		if ( $pages > 1 ) echo ' <span class="pagination-links">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages, 'prev_text' => '‹', 'next_text' => '›' ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div><br class="clear">';
	}

	/** Fresh markup of one line (and its variations), after a save in the panel. */
	public function row_markup( $group, $id, $depth = 0 ) {
		$languages = $this->languages();
		$rows      = $this->rows( $group, array( (int) $id ), $languages );
		if ( ! $rows ) return '';
		if ( 'post:product' === $group ) $this->attach_variations( $rows, $languages );
		$row = reset( $rows );
		if ( 'menu' === $group && false !== strpos( $row['title'], ' → ' ) ) $row['title'] = substr( $row['title'], strpos( $row['title'], ' → ' ) + 5 );
		return $this->unit_html( $row, $languages, $depth, '', false );
	}

	/** A line and its folded variations. */
	private function unit_html( $row, $languages, $depth, $parent_title, $open ) {
		$html = $this->row_html( $row, $languages, $depth, $parent_title, 0, $open );
		foreach ( $row['children'] as $child ) $html .= $this->row_html( $child, $languages, 0, '', $row['id'], $open );
		return $html;
	}

	/** One line: tick box, title (indented under its parent) and one icon per language — all open the panel. */
	private function row_html( $row, $languages, $depth, $parent_title, $variation_of, $open ) {
		$item  = $row['item'];
		// Variations are translated in the panel of their product.
		$group = $variation_of ? 'post:product' : $row['group'];
		$id    = $variation_of ? (int) $variation_of : (int) $row['id'];
		$data  = ' data-group="' . esc_attr( $group ) . '" data-id="' . $id . '"';
		$title = esc_html( wp_html_excerpt( $row['title'], 90, '…' ) );
		$h     = '<tr' . ( $variation_of ? ' class="lt-variation lt-var-' . (int) $variation_of . '"' . ( $open ? '' : ' hidden' ) : ' data-key="' . esc_attr( $row['group'] . '|' . $row['id'] ) . '" data-depth="' . (int) $depth . '"' ) . '>';
		$h    .= $variation_of ? '<td></td>' : '<th scope="row" class="check-column"><input type="checkbox" name="items[]" value="' . esc_attr( $row['group'] . '|' . $row['id'] ) . '" aria-label="' . esc_attr( wp_strip_all_tags( $row['title'] ) ) . '"></th>';
		$h    .= '<td class="title column-title column-primary">';
		if ( $depth ) $h .= '<span class="lt-indent">' . esc_html( str_repeat( '— ', $depth ) ) . '</span>';
		$h .= $variation_of ? '<span class="lt-indent">↳ </span><a href="#" class="lt-open lt-var-title"' . $data . '>' . $title . '</a>' : '<strong><a class="row-title lt-open" href="#"' . $data . '>' . $title . '</a></strong>';
		if ( $parent_title ) $h .= ' <span class="lt-in">' . esc_html( sprintf( __( 'in %s', 'lightweight-translation' ), $parent_title ) ) . '</span>';
		if ( ! empty( $row['unread'] ) ) $h .= ' <span class="lt-count" title="' . esc_attr__( 'Open it once (or use “Read this page” in the panel) to also count the blocks and texts shown inside.', 'lightweight-translation' ) . '">' . esc_html__( 'page not read yet', 'lightweight-translation' ) . '</span>';
		if ( $row['children'] ) {
			$left = 0;
			foreach ( $row['children'] as $child ) if ( 'done' !== $this->worst( array( $child ) ) ) $left++;
			$h .= ' <button type="button" class="lt-toggle" data-id="' . (int) $row['id'] . '" aria-expanded="' . ( $open ? 'true' : 'false' ) . '"><span class="lt-arrow">' . ( $open ? '▾' : '▸' ) . '</span> ' . esc_html( sprintf( _n( '%d variation', '%d variations', count( $row['children'] ), 'lightweight-translation' ), count( $row['children'] ) ) ) . ( $left ? ' <span class="lt-count todo">' . esc_html( sprintf( __( '%d to do', 'lightweight-translation' ), $left ) ) . '</span>' : ' <span class="lt-count done">✓</span>' ) . '</button>';
		}
		if ( ! $variation_of && $item['edit'] ) $h .= '<div class="row-actions"><a href="#" class="lt-open"' . $data . '>' . esc_html__( 'Translate here', 'lightweight-translation' ) . '</a>' . ( 0 === strpos( $row['group'], 'post:' ) ? ' | <a href="' . esc_url( $item['block_editor'] ? add_query_arg( 'lt_lang', $languages[0], $item['edit'] ) : $item['edit'] . '#lt-translations' ) . '">' . esc_html__( 'Translate in the editor', 'lightweight-translation' ) . '</a>' : '' ) . ' | <a href="' . esc_url( $item['edit'] ) . '">' . esc_html__( 'Edit original', 'lightweight-translation' ) . '</a></div>';
		$h .= '</td>';
		foreach ( $languages as $language ) {
			$st   = $row['states'][ $language ];
			$name = LT_Language_Router::language_label( $language );
			if ( 'done' === $st['state'] ) { $icon = 'dashicons-yes'; $extra = ''; $tip = sprintf( __( 'Translated in %s', 'lightweight-translation' ), $name ); }
			elseif ( 'update' === $st['state'] ) { $icon = 'dashicons-warning'; $extra = ''; $tip = sprintf( __( '%s: the original was edited, check the translation', 'lightweight-translation' ), $name ); }
			else { $icon = 'dashicons-plus'; $extra = $st['done'] ? $st['done'] . '/' . $st['total'] : ''; $tip = sprintf( __( 'Translate into %s', 'lightweight-translation' ), $name ); }
			$h .= '<td class="column-lang"><a href="#" class="lt-st lt-open ' . esc_attr( $st['state'] ) . '"' . $data . ' data-lang="' . esc_attr( $language ) . '" title="' . esc_attr( $tip ) . '"><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>' . esc_html( $extra ) . '<span class="screen-reader-text">' . esc_html( $tip ) . '</span></a></td>';
		}
		return $h . '</tr>';
	}

	/** Field names a person understands. */
	public function field_label( $field ) {
		$field  = preg_replace( '/ \(outdated\)$/', '', (string) $field );
		$labels = array( 'title' => __( 'title', 'lightweight-translation' ), 'content' => __( 'content', 'lightweight-translation' ), 'excerpt' => __( 'excerpt', 'lightweight-translation' ), 'short_description' => __( 'short description', 'lightweight-translation' ), 'slug' => __( 'URL', 'lightweight-translation' ), 'term_name' => __( 'name', 'lightweight-translation' ), 'term_description' => __( 'description', 'lightweight-translation' ), 'label' => __( 'name', 'lightweight-translation' ) );
		if ( isset( $labels[ $field ] ) ) return $labels[ $field ];
		if ( preg_match( '/^woodmart_tab_(title|content)_(\d+)$/', $field, $m ) ) return sprintf( __( 'tab %d', 'lightweight-translation' ), $m[2] );
		return str_replace( '_', ' ', $field );
	}
}
