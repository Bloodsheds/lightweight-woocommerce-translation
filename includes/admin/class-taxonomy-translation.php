<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Taxonomies & attributes" page (like WPML's Taxonomy translation): one tab per taxonomy
 * (product categories, tags, attribute values, post categories…) plus product attribute names,
 * with every term and its translations editable in a single table.
 */
class LT_Taxonomy_Translation {
	const PER_PAGE = 40;

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 19 );
		add_action( 'admin_post_lt_save_taxonomy', array( $this, 'save' ) );
	}

	public function menu() { add_submenu_page( 'lt-settings', __( 'Categories & attributes', 'lightweight-translation' ), __( 'Categories & attributes', 'lightweight-translation' ), lt_capability(), 'lt-taxonomies', array( $this, 'page' ) ); }

	private function languages() { $router = LT_Language_Router::instance(); return array_values( array_diff( $router->languages(), array( $router->default_language() ) ) ); }

	/** Tab key => label: tax:{taxonomy} and attribute. */
	private function tabs() {
		$tabs = array();
		foreach ( LT_Content_Catalog::groups() as $key => $label ) if ( 0 === strpos( $key, 'tax:' ) || 'attribute' === $key ) $tabs[ $key ] = $label;
		// Product categories, tags and attributes first, like WPML.
		uksort( $tabs, function( $a, $b ) {
			$order = array( 'tax:product_cat' => 0, 'tax:product_tag' => 1, 'attribute' => 2 );
			$ra = $order[ $a ] ?? ( 0 === strpos( $a, 'tax:pa_' ) ? 3 : 4 );
			$rb = $order[ $b ] ?? ( 0 === strpos( $b, 'tax:pa_' ) ? 3 : 4 );
			return $ra === $rb ? strcmp( $a, $b ) : $ra - $rb;
		} );
		return $tabs;
	}

	/** Rows of the current tab: [id, depth, title, edit url, fields => [field => source]]. */
	private function rows( $tab, $search ) {
		$rows = array();
		if ( 'attribute' === $tab ) {
			foreach ( function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array() as $attribute ) {
				if ( $search && false === stripos( $attribute->attribute_label, $search ) ) continue;
				$rows[] = array( 'type' => 'attribute', 'id' => absint( $attribute->attribute_id ), 'depth' => 0, 'title' => $attribute->attribute_label, 'meta' => 'pa_' . $attribute->attribute_name, 'edit' => admin_url( 'edit.php?post_type=product&page=product_attributes&edit=' . absint( $attribute->attribute_id ) ), 'fields' => array( 'label' => $attribute->attribute_label ) );
			}
			return $rows;
		}
		$taxonomy = substr( $tab, 4 );
		$terms    = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'search' => $search ) );
		if ( is_wp_error( $terms ) ) return $rows;
		$add = function( WP_Term $term, $depth ) use ( &$rows ) {
			$rows[] = array( 'type' => 'term', 'id' => $term->term_id, 'depth' => $depth, 'title' => $term->name, 'meta' => $term->slug . ' · ' . sprintf( _n( '%d item', '%d items', $term->count, 'lightweight-translation' ), $term->count ), 'edit' => get_edit_term_link( $term->term_id, $term->taxonomy ), 'fields' => array( 'term_name' => $term->name, 'slug' => $term->slug, 'term_description' => $term->description ) );
		};
		if ( $search || ! is_taxonomy_hierarchical( $taxonomy ) ) { foreach ( $terms as $term ) $add( $term, 0 ); return $rows; }
		// Parent → children order with indentation.
		$children = array();
		foreach ( $terms as $term ) $children[ (int) $term->parent ][] = $term;
		$walk = function( $parent, $depth ) use ( &$walk, &$children, $add ) { foreach ( $children[ $parent ] ?? array() as $term ) { $add( $term, $depth ); $walk( $term->term_id, $depth + 1 ); } };
		$walk( 0, 0 );
		return $rows;
	}

	private function status_color( $status ) { return array( 'translated' => '#00a32a', 'outdated' => '#dba617', 'missing' => '#d63638' )[ $status ] ?? '#c3c4c7'; }

	public function page() {
		if ( ! current_user_can( lt_capability() ) ) return;
		$tabs      = $this->tabs();
		$languages = $this->languages();
		$tab       = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) $tab = (string) key( $tabs );
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$only_todo = ! empty( $_GET['todo'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$paged     = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification

		echo '<style>
.lt-tax-table td,.lt-tax-table th{vertical-align:top}
.lt-tax-table input[type=text],.lt-tax-table textarea{width:100%;border-left-width:4px}
.lt-tax-table details{margin-top:4px}.lt-tax-table summary{cursor:pointer;color:#2271b1;font-size:12px}
.lt-tax-table .lt-orig{font-weight:600}.lt-tax-table .lt-meta{color:#646970;font-size:12px}
.lt-tax-legend span{display:inline-block;width:10px;height:10px;border-radius:2px;margin:0 4px 0 12px;vertical-align:middle}
</style>';
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Categories & attributes', 'lightweight-translation' ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d translations saved.', 'lightweight-translation' ), absint( $_GET['saved'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $languages ) { echo '<p>' . esc_html__( 'Add a secondary language in the settings first.', 'lightweight-translation' ) . '</p></div>'; return; }

		echo '<nav class="nav-tab-wrapper" style="margin-bottom:12px">';
		foreach ( $tabs as $key => $label ) echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => 'lt-taxonomies', 'tab' => $key ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
		echo '</nav>';

		echo '<form method="get" style="margin:0 0 12px"><input type="hidden" name="page" value="lt-taxonomies"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search…', 'lightweight-translation' ) . '"> <label><input type="checkbox" name="todo" value="1" ' . checked( $only_todo, true, false ) . '> ' . esc_html__( 'Only untranslated or outdated', 'lightweight-translation' ) . '</label> ';
		submit_button( __( 'Filter', 'lightweight-translation' ), 'secondary', '', false );
		echo ' <span class="lt-tax-legend"><span style="background:#00a32a"></span>' . esc_html__( 'translated', 'lightweight-translation' ) . '<span style="background:#dba617"></span>' . esc_html__( 'outdated', 'lightweight-translation' ) . '<span style="background:#d63638"></span>' . esc_html__( 'missing', 'lightweight-translation' ) . '</span></form>';

		$rows = $this->rows( $tab, $search );
		if ( $only_todo ) {
			$rows = array_values( array_filter( $rows, function( $row ) use ( $languages ) {
				foreach ( $languages as $language ) foreach ( $row['fields'] as $field => $source ) {
					if ( '' === trim( (string) $source ) || 'slug' === $field ) continue;
					list( $status ) = LT_Content_Catalog::field_status( $row['type'], $row['id'], $field, $source, $language );
					if ( 'translated' !== $status ) return true;
				}
				return false;
			} ) );
		}
		$total = count( $rows );
		$rows  = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		if ( ! $rows ) { echo '<p>' . esc_html__( 'Nothing to translate here.', 'lightweight-translation' ) . '</p></div>'; return; }

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lt_save_taxonomy' );
		echo '<input type="hidden" name="action" value="lt_save_taxonomy"><input type="hidden" name="redirect" value="' . esc_attr( remove_query_arg( 'saved', wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) . '">';
		echo '<table class="widefat striped lt-tax-table"><thead><tr><th style="width:22%">' . esc_html( LT_Language_Router::flag( LT_Language_Router::instance()->default_language() ) . ' ' . __( 'Original', 'lightweight-translation' ) ) . '</th>';
		foreach ( $languages as $language ) echo '<th>' . esc_html( LT_Language_Router::flag( $language ) . ' ' . LT_Language_Router::language_label( $language ) ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td><div class="lt-orig" style="padding-left:' . esc_attr( 14 * $row['depth'] ) . 'px">' . ( $row['depth'] ? '— ' : '' ) . '<a href="' . esc_url( $row['edit'] ) . '">' . esc_html( $row['title'] ) . '</a></div><div class="lt-meta">' . esc_html( $row['meta'] ) . '</div></td>';
			foreach ( $languages as $language ) {
				echo '<td>';
				$main = 'attribute' === $row['type'] ? 'label' : 'term_name';
				foreach ( $row['fields'] as $field => $source ) {
					if ( $field !== $main ) continue;
					echo $this->input( $row, $language, $field, $source );
				}
				if ( 'term' === $row['type'] ) {
					echo '<details><summary>' . esc_html__( 'Slug & description', 'lightweight-translation' ) . '</summary>';
					echo $this->input( $row, $language, 'slug', $row['fields']['slug'] );
					echo $this->input( $row, $language, 'term_description', $row['fields']['term_description'], true );
					echo '</details>';
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table><p>';
		submit_button( __( 'Save translations', 'lightweight-translation' ), 'primary', 'submit', false );
		echo '</p></form>';
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">' . esc_html__( 'Tip: Light translations › Import / Export lets you translate all of these at once with an AI.', 'lightweight-translation' ) . '</p></div>';
	}

	private function input( $row, $language, $field, $source, $textarea = false ) {
		list( $status, $value ) = LT_Content_Catalog::field_status( $row['type'], $row['id'], $field, $source, $language );
		if ( 'missing' === $status && 'slug' === $field ) $status = '';
		if ( '' === trim( (string) $source ) && 'missing' === $status ) $status = '';
		$name  = 'lt_tt[' . esc_attr( $language ) . '][' . esc_attr( $row['type'] ) . '][' . absint( $row['id'] ) . '][' . esc_attr( $field ) . ']';
		$style = 'border-left-color:' . $this->status_color( $status );
		$place = 'slug' === $field ? __( 'translated-slug', 'lightweight-translation' ) : wp_html_excerpt( (string) $source, 60, '…' );
		$title = 'outdated' === $status ? __( 'The original changed since this translation: check it, then save.', 'lightweight-translation' ) : '';
		$html  = '<input type="hidden" name="lt_tt_old' . substr( $name, 5 ) . '" value="' . esc_attr( $value ) . '">';
		if ( $textarea ) return $html . '<textarea rows="2" name="' . $name . '" placeholder="' . esc_attr( $place ) . '" style="' . esc_attr( $style ) . ';margin-top:4px" title="' . esc_attr( $title ) . '">' . esc_textarea( $value ) . '</textarea>';
		return $html . '<input type="text" name="' . $name . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $place ) . '" style="' . esc_attr( $style ) . ';margin-top:4px" title="' . esc_attr( $title ) . '">';
	}

	public function save() {
		if ( ! current_user_can( lt_capability() ) || ! check_admin_referer( 'lt_save_taxonomy' ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		$languages = $this->languages();
		$new       = (array) wp_unslash( $_POST['lt_tt'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$old       = (array) wp_unslash( $_POST['lt_tt_old'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$saved     = 0;
		foreach ( $new as $language => $types ) {
			$language = sanitize_key( $language );
			if ( ! in_array( $language, $languages, true ) || ! is_array( $types ) ) continue;
			foreach ( $types as $type => $objects ) {
				$type = sanitize_key( $type );
				if ( ! in_array( $type, array( 'term', 'attribute' ), true ) || ! is_array( $objects ) ) continue;
				foreach ( $objects as $id => $fields ) {
					foreach ( (array) $fields as $field => $value ) {
						$field = sanitize_key( $field );
						$value = 'slug' === $field ? sanitize_title( (string) $value ) : ( 'term_description' === $field ? (string) $value : sanitize_text_field( (string) $value ) );
						$before = (string) ( $old[ $language ][ $type ][ $id ][ $field ] ?? '' );
						$source = LT_Content_Catalog::current_source( $type, absint( $id ), $field );
						if ( null === $source ) continue;
						if ( $value === $before ) continue; // Untouched field (an outdated translation stays outdated until edited).
						LT_Translation_Store::save( array( 'language_code' => $language, 'object_type' => $type, 'object_id' => absint( $id ), 'field_key' => $field, 'source_text' => $source, 'translated_text' => $value, 'status' => '' === trim( $value ) ? 'empty' : 'translated' ) );
						$saved++;
					}
				}
			}
		}
		$redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : admin_url( 'admin.php?page=lt-taxonomies' );
		wp_safe_redirect( add_query_arg( 'saved', $saved, $redirect ) );
		exit;
	}
}
