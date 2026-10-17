<?php
defined( 'ABSPATH' ) || exit;

/**
 * Interface texts. Sorted by source in the WooCommerce, Theme and Plugins sections (see LT_Sources), and
 * page by page in "Interface texts", organised by source or optional page view:
 *  - Translate: overview by SOURCE (Site texts, Widgets, theme, WooCommerce, each plugin, WordPress, manual),
 *    with progress per language, then the list of texts of a source with filters and bulk actions;
 *  - By page: the texts each page of the site displays (noted while an administrator browses);
 *  - Add texts: manual add, texts typed in settings, code scan, translated text domains.
 */
class LT_String_Translation {
	const PER_PAGE         = 30;
	const PER_PAGE_CHOICES = array( 20, 30, 50, 100, 200, 500 );

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

	private $slug   = 'lt-strings'; // admin page being shown
	private $all    = null;         // field key => original text
	private $shared = array();      // keys hidden because WooCommerce / the theme has the same text

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_menu', array( $this, 'section_menu' ), 11 );
		add_action( 'admin_post_lt_strings', array( $this, 'handle' ) );
		add_action( 'admin_post_lt_string_row', array( $this, 'handle_row' ) );
		add_filter( 'lt_string_view_args', array( $this, 'filter_theme_area' ), 20, 3 );
		add_filter( 'lt_string_tags', array( $this, 'theme_area_tag' ), 20, 4 );
		add_filter( 'lt_string_export_args', array( $this, 'theme_export_args' ) );
		add_filter( 'lt_export_string_include', array( $this, 'theme_export_include' ), 20, 4 );
	}

	public function menu() { add_submenu_page( 'lt-settings', __( 'Interface texts', 'lightweight-translation' ), __( 'Interface texts', 'lightweight-translation' ), lt_capability(), 'lt-strings', array( $this, 'page' ) ); }

	/** One menu entry per source: WooCommerce (when active), the theme, the other plugins. */
	public function section_menu() {
		if ( function_exists( 'WC' ) ) add_submenu_page( 'lt-settings', 'WooCommerce', 'WooCommerce', lt_capability(), 'lt-woocommerce', array( $this, 'page_woocommerce' ) );
		add_submenu_page( 'lt-settings', __( 'Theme', 'lightweight-translation' ), __( 'Theme', 'lightweight-translation' ), lt_capability(), 'lt-theme', array( $this, 'page_theme' ) );
		add_submenu_page( 'lt-settings', __( 'Plugins', 'lightweight-translation' ), __( 'Plugins', 'lightweight-translation' ), lt_capability(), 'lt-plugins', array( $this, 'page_plugins' ) );
	}
	public function page_woocommerce() { $this->section_page( 'woocommerce' ); }
	public function page_theme() { $this->section_page( 'theme' ); }
	public function page_plugins() { $this->section_page( 'plugins' ); }

	private function languages() { $router = LT_Language_Router::instance(); return array_values( array_diff( $router->languages(), array( $router->default_language() ) ) ); }
	private function index() { return (array) get_option( LT_Code_Scanner::INDEX, array() ); }
	private function url( $args = array() ) { return add_query_arg( array_merge( array( 'page' => 'lt-strings' ), $args ), admin_url( 'admin.php' ) ); }

	/** IN (…) clause for a list of field keys. */
	private function in_keys( $keys ) { return 'field_key IN (' . implode( ',', array_map( function( $key ) { return "'" . esc_sql( sanitize_key( $key ) ) . "'"; }, $keys ) ) . ')'; }

	/** Source key of a text: its text domain, "site", "widgets", "manual" or "unknown". */
	private function source_of( $field, $index ) {
		if ( 0 === strpos( $field, 'text_' ) ) return 'manual';
		return ( isset( $index[ $field ]['d'] ) && '' !== $index[ $field ]['d'] ) ? $index[ $field ]['d'] : 'unknown';
	}

	/** Labels and kind of every source: key => [label, kind]. */
	private function source_labels() {
		$labels = array(
			'site'    => array( __( 'Site texts – theme options & WooCommerce settings', 'lightweight-translation' ), __( 'Settings', 'lightweight-translation' ) ),
			'widgets' => array( __( 'Widgets', 'lightweight-translation' ), __( 'Widgets', 'lightweight-translation' ) ),
			'default' => array( 'WordPress', __( 'WordPress', 'lightweight-translation' ) ),
			'manual'  => array( __( 'Added by hand', 'lightweight-translation' ), __( 'Manual', 'lightweight-translation' ) ),
			'unknown' => array( __( 'Unknown origin (collected before v0.9)', 'lightweight-translation' ), '—' ),
		);
		foreach ( LT_Code_Scanner::targets() as $key => $target ) {
			if ( isset( $labels[ $target['domain'] ] ) ) continue;
			$group = 0 === strpos( $key, 'plugin:' ) ? LT_Sources::plugin_group( substr( $key, 7 ), $target['label'] ) : ( 0 === strpos( $key, 'theme:' ) ? 'theme' : '' );
			if ( 'woocommerce' === $group ) $kind = 'WooCommerce';
			elseif ( 'theme' === $group ) $kind = __( 'Theme', 'lightweight-translation' );
			else $kind = __( 'Plugin', 'lightweight-translation' );
			$labels[ $target['domain'] ] = array( $target['label'], $kind );
		}
		return $labels;
	}

	private function label( $source ) { $labels = $this->source_labels(); return $labels[ $source ][0] ?? $source; }

	/** Overview numbers: source => [total, seen, ignored, translated[lang]]. */
	private function overview_data() {
		global $wpdb;
		$table = LT_Translation_Store::table();
		$index = $this->index();
		$seen  = (array) get_option( 'lt_string_seen', array() );
		$keys  = $wpdb->get_col( "SELECT DISTINCT field_key FROM $table WHERE object_type='string' AND object_id=0" ); // phpcs:ignore WordPress.DB
		$done  = array();
		foreach ( (array) $wpdb->get_results( "SELECT language_code, field_key FROM $table WHERE object_type='string' AND object_id=0 AND status IN ('translated','reviewed') AND translated_text <> ''" ) as $row ) $done[ $row->language_code ][ $row->field_key ] = true; // phpcs:ignore WordPress.DB
		$data = array();
		foreach ( $keys as $field ) {
			$source = $this->source_of( $field, $index );
			if ( ! isset( $data[ $source ] ) ) $data[ $source ] = array( 'total' => 0, 'seen' => 0, 'ignored' => 0, 'translated' => array() );
			if ( ! empty( $index[ $field ]['x'] ) ) { $data[ $source ]['ignored']++; continue; }
			$data[ $source ]['total']++;
			if ( isset( $seen[ $field ] ) ) $data[ $source ]['seen']++;
			foreach ( $this->languages() as $language ) if ( isset( $done[ $language ][ $field ] ) ) $data[ $source ]['translated'][ $language ] = ( $data[ $source ]['translated'][ $language ] ?? 0 ) + 1;
		}
		$order = array( 'site' => 0, 'widgets' => 1, 'woocommerce' => 3 );
		foreach ( LT_Frontend_Filters::default_domains() as $domain ) if ( ! isset( $order[ $domain ] ) && 'default' !== $domain ) $order[ $domain ] = 2; // the theme
		uksort( $data, function( $a, $b ) use ( $order ) { $ra = $order[ $a ] ?? ( in_array( $a, array( 'default', 'manual', 'unknown' ), true ) ? 9 : 5 ); $rb = $order[ $b ] ?? ( in_array( $b, array( 'default', 'manual', 'unknown' ), true ) ? 9 : 5 ); return $ra === $rb ? strcmp( $a, $b ) : $ra - $rb; } );
		return $data;
	}

	private function query( $args ) {
		global $wpdb;
		$table     = LT_Translation_Store::table();
		$languages = $this->languages();
		$index     = $this->index();
		$where     = "object_type='string' AND object_id=0";
		$params    = array();
		$source    = $args['source'];
		$keys      = null;
		$restrict  = function( $list ) use ( &$keys ) { $keys = null === $keys ? array_values( $list ) : array_values( array_intersect( $keys, $list ) ); };
		if ( 'manual' === $source ) $where .= " AND field_key LIKE 'text\\_%'";
		elseif ( 'unknown' === $source ) {
			$where .= " AND field_key NOT LIKE 'text\\_%'";
			if ( $index ) $where .= ' AND NOT ' . $this->in_keys( array_keys( $index ) );
		} elseif ( '' !== $source ) $restrict( array_keys( array_filter( $index, function( $e ) use ( $source ) { return ( $e['d'] ?? '' ) === $source; } ) ) );
		if ( '' !== ( $args['group'] ?? '' ) ) $restrict( $this->group_keys( $args['group'], $args['sub'] ?? '', empty( $args['shared'] ) ) );
		if ( 'messages' === $args['kind'] ) $restrict( array_keys( array_filter( $index, function( $e ) { return 'm' === ( $e['t'] ?? '' ); } ) ) );
		if ( 'emails' === $args['kind'] ) $restrict( array_keys( array_filter( $index, function( $e ) { return ! empty( $e['e'] ); } ) ) );
		if ( null !== ( $args['keys'] ?? null ) ) $restrict( $args['keys'] );
		if ( $args['seen'] ) $restrict( array_keys( (array) get_option( 'lt_string_seen', array() ) ) );
		$ignored = array_keys( array_filter( $index, function( $e ) { return ! empty( $e['x'] ); } ) );
		if ( $args['ignored'] ) $restrict( $ignored );
		elseif ( $ignored ) $where .= ' AND NOT ' . $this->in_keys( $ignored );
		if ( null !== $keys ) {
			if ( ! $keys ) return array( array(), array(), 0 );
			$where .= ' AND ' . $this->in_keys( $keys );
		}
		if ( '' !== $args['search'] ) { $where .= ' AND (source_text LIKE %s OR translated_text LIKE %s)'; $like = '%' . $wpdb->esc_like( $args['search'] ) . '%'; $params[] = $like; $params[] = $like; }
		$having = '';
		if ( $args['todo'] && $languages ) {
			$having = ' HAVING COUNT(DISTINCT CASE WHEN status IN (\'translated\',\'reviewed\') AND translated_text <> \'\' AND language_code IN (' . implode( ',', array_fill( 0, count( $languages ), '%s' ) ) . ') THEN language_code END) < %d';
			$params = array_merge( $params, $languages, array( count( $languages ) ) );
		}
		// The same original text used in several places (plugins, contexts, added by hand…) is ONE line:
		// translated once, saved everywhere it is used.
		$inner = "SELECT MIN(field_key) AS field_key, GROUP_CONCAT(DISTINCT field_key) AS group_keys, MAX(source_text) AS source, MIN(id) AS first_id FROM $table WHERE $where GROUP BY BINARY TRIM(source_text)" . $having;
		if ( isset( $args['sort'] ) && is_array( $args['sort'] ) ) {
			// Texts of one page (a few hundred at most): sorted here, in page order / A → Z / by plugin.
			$rows  = (array) $wpdb->get_results( $params ? $wpdb->prepare( $inner, $params ) : $inner ); // phpcs:ignore WordPress.DB
			$total = count( $rows );
			$sort  = $args['sort'];
			foreach ( $rows as $row ) {
				$row->sort = null;
				foreach ( explode( ',', (string) ( $row->group_keys ?? $row->field_key ) ) as $key ) if ( isset( $sort[ $key ] ) && ( null === $row->sort || strcmp( $sort[ $key ], $row->sort ) < 0 ) ) $row->sort = $sort[ $key ];
				$row->sort = (string) ( $row->sort ?? '~' );
			}
			usort( $rows, function( $a, $b ) { return strcmp( $a->sort, $b->sort ) ?: strcasecmp( (string) $a->source, (string) $b->source ); } );
			$rows = array_slice( $rows, ( $args['paged'] - 1 ) * $args['per_page'], $args['per_page'] );
		} else {
			$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM ($inner) x", $params ) : "SELECT COUNT(*) FROM ($inner) x" ); // phpcs:ignore WordPress.DB
			$rows  = $wpdb->get_results( $wpdb->prepare( $inner . ' ORDER BY first_id DESC LIMIT %d OFFSET %d', array_merge( $params, array( $args['per_page'], ( $args['paged'] - 1 ) * $args['per_page'] ) ) ) ); // phpcs:ignore WordPress.DB
		}
		$translations = array();
		if ( $rows ) {
			$list = array();
			foreach ( $rows as $row ) { $row->keys = array_values( array_filter( explode( ',', (string) ( $row->group_keys ?? $row->field_key ) ) ) ) ?: array( $row->field_key ); $list = array_merge( $list, $row->keys ); }
			$found = $wpdb->get_results( $wpdb->prepare( "SELECT language_code, field_key, translated_text, status FROM $table WHERE object_type='string' AND object_id=0 AND field_key IN (" . implode( ',', array_fill( 0, count( $list ), '%s' ) ) . ')', $list ) ); // phpcs:ignore WordPress.DB
			$by_key = array();
			foreach ( $found as $row ) if ( in_array( $row->status, LT_Translation_Store::LIVE_STATUSES, true ) && '' !== (string) $row->translated_text ) $by_key[ $row->field_key ][ $row->language_code ] = $row->translated_text;
			foreach ( $rows as $row ) foreach ( $row->keys as $key ) foreach ( (array) ( $by_key[ $key ] ?? array() ) as $language => $text ) if ( ! isset( $translations[ $row->field_key ][ $language ] ) ) $translations[ $row->field_key ][ $language ] = $text;
		}
		return array( $rows, $translations, $total );
	}

	/** Text domains loaded on this request (WooCommerce, theme, plugins…), to help fill the setting. */
	private function loaded_domains() {
		global $l10n;
		$domains = array_keys( (array) $l10n );
		if ( class_exists( 'WP_Translation_Controller' ) ) {
			$controller = WP_Translation_Controller::get_instance();
			if ( method_exists( $controller, 'get_loaded_translations' ) ) { try { $domains = array_merge( $domains, array_keys( (array) $controller->get_loaded_translations() ) ); } catch ( Throwable $e ) {} }
		}
		$domains = array_unique( array_filter( array_map( 'strval', $domains ) ) );
		sort( $domains );
		return $domains;
	}

	private function form_open( $do, $extra = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		wp_nonce_field( 'lt_strings' );
		echo '<input type="hidden" name="action" value="lt_strings"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
	}

	public function page() {
		if ( ! current_user_can( lt_capability() ) ) return;
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$this->styles();
		echo '<div class="wrap"><h1>' . esc_html__( 'Texts by source', 'lightweight-translation' ) . '</h1><p class="description" style="margin:2px 0 0">' . esc_html__( 'Choose where a text comes from, then filter by its place or function.', 'lightweight-translation' ) . '</p>';
		if ( isset( $_GET['lt_notice'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( wp_unslash( $_GET['lt_notice'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $this->languages() ) { echo '<p>' . esc_html__( 'Add a secondary language in the settings first.', 'lightweight-translation' ) . '</p></div>'; return; }
		echo '<div class="lt-top"><form method="get"><input type="hidden" name="page" value="lt-strings"><input type="search" name="s" value="' . esc_attr( isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' ) . '" placeholder="' . esc_attr__( 'Search a text in the whole site…', 'lightweight-translation' ) . '"> <button class="button">' . esc_html__( 'Search', 'lightweight-translation' ) . '</button></form>'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<a class="button" href="' . esc_url( $this->url( array( 'tab' => 'find' ) ) . '#lt-add' ) . '">+ ' . esc_html__( 'Add a text', 'lightweight-translation' ) . '</a> <a class="button-link" href="' . esc_url( $this->url( array( 'tab' => 'find' ) ) ) . '">⚙ ' . esc_html__( 'Tools', 'lightweight-translation' ) . '</a></div>';

		if ( 'find' === $tab ) {
			echo '<p><a href="' . esc_url( $this->url() ) . '">← ' . esc_html__( 'Back to the texts', 'lightweight-translation' ) . '</a></p>';
			$this->tab_find();
			echo '</div>';
			$this->script();
			return;
		}
		// Start with sources; browsing a page is an optional way to narrow the list.
		$chosen = false;
		foreach ( array( 's', 'source', 'ignored', 'key', 'page_type', 'view' ) as $arg ) if ( isset( $_GET[ $arg ] ) && '' !== $_GET[ $arg ] ) $chosen = true; // phpcs:ignore WordPress.Security.NonceVerification
		list( $map, $common ) = LT_Page_Texts::groups();
		if ( ! $chosen ) $_GET['view'] = 'plugins'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'pages' === ( $_GET['view'] ?? '' ) && empty( $_GET['page_type'] ) && $map ) $_GET['page_type'] = $common ? 'common' : (string) key( $map ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'plugins' === ( $_GET['view'] ?? '' ) ) {
			echo '<div class="lt-main lt-source-overview"><p class="lt-main-actions"><a class="button" href="' . esc_url( $this->url( array( 'view' => 'pages' ) ) ) . '">' . esc_html__( 'Browse texts by page', 'lightweight-translation' ) . '</a> <a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=lt-scan' ) ) . '">' . esc_html__( 'Check the site', 'lightweight-translation' ) . '</a></p>';
			$this->overview();
			echo '</div></div>';
			$this->script();
			return;
		}
		if ( 'pages' === ( $_GET['view'] ?? '' ) && ! $map && ! isset( $_GET['s'] ) && empty( $_GET['source'] ) ) {
			echo '<section class="lt-surface"><h2>' . esc_html__( 'No page texts collected yet', 'lightweight-translation' ) . '</h2><p>' . esc_html__( 'Check the site to find texts that are specific to your public pages. Standard WooCommerce and plugin texts are available by source.', 'lightweight-translation' ) . '</p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=lt-scan' ) ) . '">' . esc_html__( 'Check the site', 'lightweight-translation' ) . '</a></section></div>';
			$this->script();
			return;
		}

		echo '<div class="lt-layout"><aside class="lt-side">';
		$this->sidebar( $map, $common );
		echo '</aside><div class="lt-main">';
		$this->tab_translate();
		echo '</div></div></div>';
		$this->script();
	}

	private function styles() {
		echo '<style>.lt-str-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin:16px 0}.lt-str-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:4px 18px 16px}.lt-chip{display:inline-block;margin:0 4px 4px 0;padding:1px 8px;border:1px solid #c3c4c7;border-radius:10px;font-size:12px;cursor:pointer;background:#f6f7f7}.lt-str-table td{vertical-align:top}.lt-str-table input[type=text],.lt-str-table textarea{width:100%;border-left-width:4px}.lt-src{white-space:pre-wrap;word-break:break-word;font-size:14px}.lt-tag{font-size:11px;color:#646970}.lt-zone-row td{background:#f0f6fc!important;font-weight:600;color:#1d2327;font-size:13px;padding:8px 10px!important}.lt-place-tag{color:#2c3338;font-weight:500}.lt-bar{height:6px;background:#f0d0d0;border-radius:3px;overflow:hidden;min-width:90px;margin:3px 0}.lt-bar span{display:block;height:100%;background:#00a32a}.lt-ov td{vertical-align:middle}.lt-bulk{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:8px 0}.lt-str-table tr.is-checked td,.lt-str-table tr.is-checked th{background:#f0f6fc}.lt-save{position:sticky;bottom:0;background:#f0f0f1;border-top:1px solid #dcdcde;padding:8px 0;z-index:5}'
			. '.lt-top{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:10px 0 16px}.lt-top form{display:flex;gap:6px;flex:1;min-width:260px;max-width:520px}.lt-top input[type=search]{flex:1}'
			. '.lt-layout{display:grid;grid-template-columns:270px minmax(0,1fr);gap:22px;align-items:start}.lt-side{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:6px 0 10px;position:sticky;top:46px;max-height:calc(100vh - 70px);overflow:auto}'
			. '.lt-side h4{margin:14px 16px 4px;font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#646970}.lt-side a.lt-place{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 16px;color:#1d2327;text-decoration:none;border-left:3px solid transparent}.lt-side a.lt-place:hover{background:#f6f7f7}.lt-side a.is-active{background:#f0f6fc;border-left-color:#2271b1;font-weight:600}.lt-side a.is-new{color:#8c8f94}.lt-side a.lt-place-child{padding-left:28px;font-size:13px;color:#50575e}'
			. '.lt-count{font-size:11px;font-weight:400;border-radius:9px;padding:1px 7px;background:#f0f0f1;color:#50575e;white-space:nowrap}.lt-count.todo{background:#fcf0f1;color:#b32d2e}.lt-count.done{background:#edfaef;color:#00701a}'
			. '.lt-read{margin:6px 16px 4px}.lt-read .button{width:100%;text-align:center}.lt-read p{margin:6px 0 0;font-size:11px;color:#646970}.lt-main h2:first-child{margin-top:0}@media(max-width:960px){.lt-layout{grid-template-columns:1fr}.lt-side{position:static;max-height:none}}</style>';
	}

	/** WooCommerce, Theme or Plugins page: the texts of that source, split in tabs. */
	private function section_page( $section ) {
		if ( ! current_user_can( lt_capability() ) ) return;
		$this->slug = 'lt-' . $section;
		$sub   = isset( $_GET['sub'] ) ? sanitize_key( $_GET['sub'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$group = 'plugins' === $section ? ( isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '' ) : $section; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'plugins' !== $section && ! in_array( $sub, array( 'texts', 'settings', 'widgets', 'emails' ), true ) ) $sub = 'texts';
		$this->styles();
		echo '<div class="wrap">';
		if ( 'plugins' === $section ) LT_Sources::header( $section, $sub );
		else echo '<h1>' . esc_html( 'woocommerce' === $section ? 'WooCommerce' : __( 'Theme', 'lightweight-translation' ) ) . '</h1>';
		if ( isset( $_GET['lt_notice'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( wp_unslash( $_GET['lt_notice'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $this->languages() ) { echo '<p>' . esc_html__( 'Add a secondary language in the settings first.', 'lightweight-translation' ) . '</p></div>'; return; }
		if ( 'plugins' === $section && '' === $group ) {
			// Same layout as WooCommerce and the theme: the plugins on the left, their progress on the right.
			echo '<div class="lt-layout"><aside class="lt-side" aria-label="' . esc_attr__( 'Plugins', 'lightweight-translation' ) . '">';
			$this->section_sidebar( $section, $sub, $group );
			echo '</aside><div class="lt-main">';
			echo '<p class="description" style="margin:0 0 12px">' . esc_html__( 'Texts specific to other installed plugins are listed here. WooCommerce and the active theme have their own sections; when an extension reuses their texts, its translation is shared there.', 'lightweight-translation' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=lt-strings&tab=find#lt-plugin-scan' ) ) . '">' . esc_html__( 'Find texts in an installed plugin', 'lightweight-translation' ) . '</a></p>';
			$this->sources_table( false, true );
			echo '</div></div></div>';
			return;
		}
		$has_sidebar = true;
		if ( $has_sidebar ) {
			echo '<div class="lt-layout"><aside class="lt-side" aria-label="' . esc_attr__( 'Translation sections', 'lightweight-translation' ) . '">';
			$this->section_sidebar( $section, $sub, $group );
			echo '</aside><div class="lt-main">';
		}
		if ( 'plugins' === $section ) echo '<h2 style="margin:0 0 8px">' . esc_html( LT_Sources::label( $group ) ) . '</h2>';
		$help = array(
			'woocommerce:texts'    => __( 'Buttons, cart, checkout, account, notices and messages written by WooCommerce.', 'lightweight-translation' ),
			'woocommerce:settings' => __( 'Texts typed in the WooCommerce settings: payment and shipping methods, store notice, checkout terms.', 'lightweight-translation' ),
			'theme:texts'          => __( 'Texts written by the theme and its companion plugin (header, footer, product page, popups…).', 'lightweight-translation' ),
			'theme:settings'       => __( 'Texts typed in the theme options: copyright, cookie notice, empty cart text…', 'lightweight-translation' ),
			'theme:widgets'        => __( 'Titles and texts of the widgets of your sidebars and footer.', 'lightweight-translation' ),
		);
		if ( isset( $help[ $section . ':' . $sub ] ) ) echo '<p class="description" style="margin:0 0 12px">' . esc_html( $help[ $section . ':' . $sub ] ) . '</p>';
		if ( in_array( $sub, array( 'settings', 'widgets' ), true ) ) echo '<p class="description" style="margin:0 0 12px">' . wp_kses_post( sprintf( __( 'To find new texts entered in settings or widgets, use <a href="%s">Find setting texts</a> in Tools.', 'lightweight-translation' ), esc_url( admin_url( 'admin.php?page=lt-strings&tab=find#lt-settings-texts' ) ) ) ) . '</p>';
		if ( 'theme' === $section ) {
			$theme = wp_get_theme();
			$name = (string) $theme->get( 'Name' );
			echo '<p class="description" style="margin:0 0 12px">' . esc_html( sprintf( __( 'Source: active theme — %s. Use “Theme area” to narrow the list.', 'lightweight-translation' ), $name ) ) . '</p>';
			$this->theme_location_refresh();
		}
		$_GET['group'] = $group; // phpcs:ignore WordPress.Security.NonceVerification
		$_GET['sub']   = $sub; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'woocommerce' === $section && 'emails' === $sub ) LT_Email_Page::instance()->render_embedded();
		else $this->tab_translate();
		if ( $has_sidebar ) echo '</div></div>';
		echo '</div>';
	}

	/** WordPress-style source navigation shared by WooCommerce and theme translations. */
	private function section_sidebar( $section, $sub, $group = '' ) {
		if ( 'plugins' === $section ) {
			echo '<h4>' . esc_html__( 'Plugins', 'lightweight-translation' ) . '</h4>';
			echo '<a class="lt-place' . ( '' === $group ? ' is-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=lt-plugins' ) ) . '"' . ( '' === $group ? ' aria-current="page"' : '' ) . '><span>' . esc_html__( 'All plugins', 'lightweight-translation' ) . '</span></a>';
			$items = array();
			foreach ( $this->index() as $field => $entry ) {
				$source = LT_Sources::group_of( $field, $entry );
				if ( 0 === strpos( $source, 'plugin:' ) ) $items[ $source ] = LT_Sources::label( $source );
			}
			if ( $group && 0 === strpos( $group, 'plugin:' ) && ! isset( $items[ $group ] ) ) $items[ $group ] = LT_Sources::label( $group );
			uasort( $items, 'strcasecmp' );
			if ( ! $items ) echo '<p class="lt-side-foot">' . esc_html__( 'No other plugin texts have been collected yet. Use “Find texts in an installed plugin” to detect them.', 'lightweight-translation' ) . '</p>';
			foreach ( $items as $source => $label ) {
				$link = LT_Sources::url( $source );
				echo '<a class="lt-place' . ( $source === $group ? ' is-active' : '' ) . '" href="' . esc_url( $link ) . '"' . ( $source === $group ? ' aria-current="page"' : '' ) . '><span>' . esc_html( $label ) . '</span></a>';
			}
			return;
		}
		LT_Admin_Navigation::source_sidebar( $section );
	}

	/** Older scans did not keep file locations; offer a safe, translation-preserving refresh. */
	private function theme_location_refresh() {
		if ( 'texts' !== sanitize_key( $_GET['sub'] ?? 'texts' ) ) return; // phpcs:ignore WordPress.Security.NonceVerification
		$index = $this->index();
		$missing = false;
		foreach ( $index as $field => $entry ) if ( 'theme' === LT_Sources::group_of( $field, $entry ) && empty( $entry['p'] ) ) { $missing = true; break; }
		if ( ! $missing ) return;
		$targets = array();
		foreach ( LT_Code_Scanner::targets() as $key => $target ) {
			$group = 0 === strpos( $key, 'plugin:' ) ? LT_Sources::plugin_group( substr( $key, 7 ), $target['label'] ) : ( 0 === strpos( $key, 'theme:' ) ? 'theme' : '' );
			if ( 'theme' === $group && ! empty( $target['active'] ) ) $targets[] = array( 'key' => $key, 'label' => $target['label'] );
		}
		if ( ! $targets ) return;
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Some older theme texts do not have a saved file location yet, so they appear under Other theme texts.', 'lightweight-translation' ) . ' <button type="button" class="button" id="lt-theme-location-refresh">' . esc_html__( 'Find their theme areas', 'lightweight-translation' ) . '</button> <span id="lt-theme-location-status" aria-live="polite"></span><br><span class="description">' . esc_html__( 'This reads the active theme and its companion plugin in the background. Existing translations are preserved.', 'lightweight-translation' ) . '</span></p></div>';
		$config = array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'lt_scan_code' ), 'targets' => $targets, 'error' => __( 'Could not refresh theme locations. Try again from the same page.', 'lightweight-translation' ), 'done' => __( 'Theme locations updated. Reloading…', 'lightweight-translation' ) );
		echo '<script>(function(){var b=document.getElementById("lt-theme-location-refresh"),st=document.getElementById("lt-theme-location-status"),c=' . wp_json_encode( $config ) . ';if(!b)return;var i=0;b.addEventListener("click",function(){b.disabled=true;function step(offset){var t=c.targets[i];st.textContent=t.label+"… "+offset;fetch(c.ajax,{method:"POST",credentials:"same-origin",body:new URLSearchParams({action:"lt_scan_code",nonce:c.nonce,target:t.key,offset:offset,mode:"all"})}).then(function(r){return r.json();}).then(function(r){if(!r.success)throw new Error();if(!r.data.done)return step(r.data.offset);i++;if(i<c.targets.length)return step(0);st.textContent=c.done;window.location.reload();}).catch(function(){st.textContent=c.error;b.disabled=false;});}step(0);});})();</script>';
	}

	/** Stable location filter shared by theme text lists, based on the source file path. */
	public static function theme_areas() {
		return array(
			'header' => __( 'Header & navigation', 'lightweight-translation' ),
			'footer' => __( 'Footer', 'lightweight-translation' ),
			'catalog' => __( 'Shop & catalog', 'lightweight-translation' ),
			'product' => __( 'Product page', 'lightweight-translation' ),
			'cart' => __( 'Cart', 'lightweight-translation' ),
			'checkout' => __( 'Checkout', 'lightweight-translation' ),
			'account' => __( 'Customer account', 'lightweight-translation' ),
			'layout' => __( 'Layouts & blocks', 'lightweight-translation' ),
			'popup' => __( 'Popups & banners', 'lightweight-translation' ),
			'widget' => __( 'Widgets', 'lightweight-translation' ),
			'other' => __( 'Other theme texts', 'lightweight-translation' ),
		);
	}

	private function theme_area( $entry ) {
		$path = strtolower( (string) ( $entry['p'] ?? '' ) );
		if ( preg_match( '#footer|copyright#', $path ) ) return 'footer';
		if ( preg_match( '#header|navigation|mega.?menu|menu/#', $path ) ) return 'header';
		if ( preg_match( '#checkout#', $path ) ) return 'checkout';
		if ( preg_match( '#cart|mini.?cart#', $path ) ) return 'cart';
		if ( preg_match( '#myaccount|my-account|account#', $path ) ) return 'account';
		if ( preg_match( '#single.product|product.page|product-page|product/#', $path ) ) return 'product';
		if ( preg_match( '#archive|catalog|shop|category|loop/#', $path ) ) return 'catalog';
		if ( preg_match( '#widget#', $path ) ) return 'widget';
		if ( preg_match( '#popup|banner|floating#', $path ) ) return 'popup';
		if ( preg_match( '#layout|block|template|builder|section#', $path ) ) return 'layout';
		return 'other';
	}

	public function theme_area_filter( $args ) {
		if ( 'theme' !== ( $args['group'] ?? '' ) || 'texts' !== ( $args['sub'] ?? '' ) ) return;
		$current = sanitize_key( $_GET['theme_area'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<label>' . esc_html__( 'Theme area', 'lightweight-translation' ) . ' <select name="theme_area" onchange="this.form.submit()"><option value="">' . esc_html__( 'All theme areas', 'lightweight-translation' ) . '</option>';
		foreach ( self::theme_areas() as $key => $label ) echo '<option value="' . esc_attr( $key ) . '"' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>';
		echo '</select></label> ';
	}

	public function filter_theme_area( $args, $texts, $index ) {
		if ( 'theme' !== ( $args['group'] ?? '' ) || 'texts' !== ( $args['sub'] ?? '' ) ) return $args;
		$area = sanitize_key( $_GET['theme_area'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( self::theme_areas()[ $area ] ) ) return $args;
		$keys = array();
		foreach ( $texts as $field => $text ) if ( 'theme' === LT_Sources::group_of( $field, $index[ $field ] ?? array() ) && $this->theme_area( $index[ $field ] ?? array() ) === $area ) $keys[] = $field;
		$args['keys'] = null === $args['keys'] ? $keys : array_values( array_intersect( $args['keys'], $keys ) );
		return $args;
	}

	public function theme_area_tag( $tags, $row, $entry, $args ) {
		if ( 'theme' === ( $args['group'] ?? '' ) && 'texts' === ( $args['sub'] ?? '' ) ) $tags[] = esc_html( self::theme_areas()[ $this->theme_area( $entry ) ] );
		return $tags;
	}

	public function theme_export_args( $args ) {
		if ( 'lt-theme' === ( $_GET['page'] ?? '' ) && 'texts' === ( $_GET['sub'] ?? 'texts' ) ) $args['string_theme_area'] = sanitize_key( $_GET['theme_area'] ?? '' );
		return $args;
	}

	public function theme_export_include( $include, $field, $entry, $request ) {
		if ( ! $include || 'theme' !== ( $request['string_group'] ?? '' ) ) return $include;
		$area = sanitize_key( $request['string_theme_area'] ?? '' );
		return ! isset( self::theme_areas()[ $area ] ) || $this->theme_area( (array) $entry ) === $area;
	}

	/* ---------- Sources ---------- */

	/** Every interface text: field key => original wording (one query per request). */
	private function all_texts() {
		if ( null !== $this->all ) return $this->all;
		global $wpdb;
		$this->all = array();
		foreach ( (array) $wpdb->get_results( 'SELECT field_key, MAX(source_text) AS source FROM ' . LT_Translation_Store::table() . " WHERE object_type='string' AND object_id=0 GROUP BY field_key" ) as $row ) $this->all[ $row->field_key ] = trim( (string) $row->source ); // phpcs:ignore WordPress.DB
		return $this->all;
	}

	/** Originals of the WooCommerce and theme texts: other plugins reusing them share their translation. */
	private function base_texts() {
		$index = $this->index();
		$base  = array();
		foreach ( $this->all_texts() as $field => $text ) if ( '' !== $text && in_array( LT_Sources::group_of( $field, $index[ $field ] ?? null ), LT_Sources::BASE, true ) ) $base[ $text ] = true;
		return $base;
	}

	/** Keys of a source (and of one part of it); with $hide_shared, the texts WooCommerce or the theme already has are left out (in $this->shared). */
	private function group_keys( $group, $part = '', $hide_shared = false ) {
		$index = $this->index();
		$base  = $hide_shared && ! in_array( $group, LT_Sources::BASE, true ) ? $this->base_texts() : array();
		$keys  = array();
		$this->shared = array();
		foreach ( $this->all_texts() as $field => $text ) {
			$entry = $index[ $field ] ?? null;
			if ( LT_Sources::group_of( $field, $entry ) !== $group || ( '' !== $part && LT_Sources::part_of( $entry ) !== $part ) ) continue;
			if ( isset( $base[ $text ] ) ) $this->shared[] = $field;
			else $keys[] = $field;
		}
		return $keys;
	}

	/** Numbers of every source: group => [total, shared, translated[lang]]. */
	private function sources_data() {
		global $wpdb;
		$index = $this->index();
		$base  = $this->base_texts();
		$done  = array();
		foreach ( (array) $wpdb->get_results( 'SELECT language_code, field_key FROM ' . LT_Translation_Store::table() . " WHERE object_type='string' AND object_id=0 AND status IN ('translated','reviewed') AND translated_text <> ''" ) as $row ) $done[ $row->language_code ][ $row->field_key ] = true; // phpcs:ignore WordPress.DB
		$data = array();
		foreach ( $this->all_texts() as $field => $text ) {
			$entry = $index[ $field ] ?? null;
			if ( ! empty( $entry['x'] ) ) continue;
			$group = LT_Sources::group_of( $field, $entry );
			if ( ! isset( $data[ $group ] ) ) $data[ $group ] = array( 'total' => 0, 'shared' => 0, 'translated' => array() );
			if ( ! in_array( $group, LT_Sources::BASE, true ) && isset( $base[ $text ] ) ) { $data[ $group ]['shared']++; continue; }
			$data[ $group ]['total']++;
			foreach ( $this->languages() as $language ) if ( isset( $done[ $language ][ $field ] ) ) $data[ $group ]['translated'][ $language ] = ( $data[ $group ]['translated'][ $language ] ?? 0 ) + 1;
		}
		// WooCommerce, the theme, the active plugins A → Z, the switched-off ones, WordPress, the rest.
		$rank = function( $group ) { return 'woocommerce' === $group ? 0 : ( 'theme' === $group ? 1 : ( 'wordpress' === $group ? 4 : ( 'other' === $group ? 5 : ( LT_Sources::active( $group ) ? 2 : 3 ) ) ) ); };
		uksort( $data, function( $a, $b ) use ( $rank ) { return ( $rank( $a ) - $rank( $b ) ) ?: strcasecmp( LT_Sources::label( $a ), LT_Sources::label( $b ) ); } );
		return $data;
	}

	/** Table of the sources with their progress per language (Plugins page, Overview & settings). */
	public function sources_table( $with_styles = false, $plugins_only = false ) {
		$data      = $this->sources_data();
		$languages = $this->languages();
		if ( $with_styles ) $this->styles();
		if ( ! $data ) { echo '<div class="lt-str-card"><p>' . wp_kses_post( sprintf( __( 'No text yet. Use <a href="%s">Scan the site</a> or open the pages of your site while logged in.', 'lightweight-translation' ), esc_url( admin_url( 'admin.php?page=lt-scan' ) ) ) ) . '</p></div>'; return; }
		echo '<table class="widefat striped lt-ov"><thead><tr><th>' . esc_html__( 'Source', 'lightweight-translation' ) . '</th><th>' . esc_html__( 'Texts', 'lightweight-translation' ) . '</th>';
		foreach ( $languages as $language ) echo '<th>' . esc_html( LT_Language_Router::flag( $language ) . ' ' . strtoupper( $language ) ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		$rendered = false;
		foreach ( $data as $group => $d ) {
			if ( $plugins_only && 0 !== strpos( $group, 'plugin:' ) ) continue;
			if ( ! $d['total'] && ! $d['shared'] ) continue;
			$rendered = true;
			$link = LT_Sources::url( $group );
			$note = $d['shared'] ? '<br><span class="lt-tag">' . esc_html( sprintf( _n( '+ %d text shared with WooCommerce or the theme (translated there)', '+ %d texts shared with WooCommerce or the theme (translated there)', $d['shared'], 'lightweight-translation' ), $d['shared'] ) ) . '</span>' : '';
			$off  = LT_Sources::active( $group ) ? '' : ' <span class="lt-tag">(' . esc_html__( 'switched off', 'lightweight-translation' ) . ')</span>';
			echo '<tr><td><a href="' . esc_url( $link ) . '"><strong>' . esc_html( LT_Sources::label( $group ) ) . '</strong></a>' . $off . $note . '</td><td>' . esc_html( number_format_i18n( $d['total'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			foreach ( $languages as $language ) {
				$n   = $d['translated'][ $language ] ?? 0;
				$pct = $d['total'] ? floor( 100 * $n / $d['total'] ) : 100;
				echo '<td><div class="lt-bar"><span style="width:' . esc_attr( $pct ) . '%"></span></div><span class="lt-tag">' . esc_html( $n . ' / ' . $d['total'] ) . '</span></td>';
			}
			echo '<td style="white-space:nowrap"><a class="button button-small button-primary" href="' . esc_url( add_query_arg( 'todo', 1, $link ) ) . '">' . esc_html__( 'Translate', 'lightweight-translation' ) . '</a> <a class="button button-small" href="' . esc_url( $this->export_url( '', '', $group ) ) . '">CSV</a></td></tr>';
		}
		echo '</tbody></table>';
		if ( $plugins_only && ! $rendered ) echo '<p>' . esc_html__( 'No other plugin texts have been collected yet. Use “Find texts in an installed plugin” to detect them.', 'lightweight-translation' ) . '</p>';
	}

	/** First visit: nothing read yet. */
	private function welcome() {
		echo '<div class="lt-str-card"><h2>' . esc_html__( 'Start with the texts already available', 'lightweight-translation' ) . '</h2><p>' . esc_html__( 'Choose a source below to translate its texts. Check the site when you want to find the wording specific to your own pages.', 'lightweight-translation' ) . '</p></div>';
	}

	/** Left column: the places of the site, with what is left to translate. */
	private function sidebar( $map, $common ) {
		global $wpdb;
		$languages = $this->languages();
		$index     = $this->index();
		$standard  = LT_Page_Texts::standard_pages();
		$done      = array();
		foreach ( (array) $wpdb->get_results( 'SELECT field_key, COUNT(DISTINCT language_code) AS n FROM ' . LT_Translation_Store::table() . " WHERE object_type='string' AND object_id=0 AND status IN ('translated','reviewed') AND translated_text <> '' GROUP BY field_key" ) as $row ) if ( (int) $row->n >= count( $languages ) ) $done[ $row->field_key ] = true; // phpcs:ignore WordPress.DB
		$current = array( 'page_type' => sanitize_text_field( wp_unslash( $_GET['page_type'] ?? '' ) ), 'source' => sanitize_text_field( wp_unslash( $_GET['source'] ?? '' ) ), 'view' => sanitize_key( $_GET['view'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$link = function( $label, $args, $count = null, $todo = 0, $extra = '' ) use ( $current ) {
			$active = ( isset( $args['page_type'] ) && $args['page_type'] === $current['page_type'] ) || ( isset( $args['source'] ) && $args['source'] === $current['source'] && '' === $current['page_type'] ) || ( isset( $args['view'] ) && $args['view'] === $current['view'] );
			$badge  = null === $count ? '' : ( ! $count ? '<span class="lt-count">0</span>' : ( $todo ? '<span class="lt-count todo">' . esc_html( sprintf( __( '%d to do', 'lightweight-translation' ), $todo ) ) . '</span>' : '<span class="lt-count done">✓ ' . esc_html( number_format_i18n( $count ) ) . '</span>' ) );
			echo '<a class="lt-place' . ( $active ? ' is-active' : '' ) . $extra . '" href="' . esc_url( $this->url( $args ) ) . '"><span>' . esc_html( $label ) . '</span>' . $badge . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};
		$todo_of = function( $keys ) use ( $done, $index ) { $keys = array_filter( $keys, function( $field ) use ( $index ) { return empty( $index[ $field ]['x'] ); } ); $todo = 0; foreach ( $keys as $field ) if ( ! isset( $done[ $field ] ) ) $todo++; return array( count( $keys ), $todo ); };

		echo '<div class="lt-read"><a class="button" href="' . esc_url( $this->url( array( 'view' => 'plugins' ) ) ) . '">' . esc_html__( 'Browse by source', 'lightweight-translation' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=lt-scan' ) ) . '">' . esc_html__( 'Check the site', 'lightweight-translation' ) . '</a><p>' . esc_html__( 'Find and translate the texts shown on your public pages.', 'lightweight-translation' ) . '</p></div>';

		// Pages of the site.
		echo '<h4>' . esc_html__( 'On your pages', 'lightweight-translation' ) . '</h4>';
		if ( $common ) { list( $n, $todo ) = $todo_of( array_keys( $common ) ); $link( __( 'Everywhere (header, footer, menus)', 'lightweight-translation' ), array( 'page_type' => 'common' ), $n, $todo ); }
		foreach ( array_values( array_unique( array_merge( array_keys( $standard ), array_keys( $map ) ) ) ) as $type ) {
			if ( 'messages' === $type ) continue;
			if ( ! isset( $map[ $type ] ) ) { echo '<a class="lt-place is-new" href="' . esc_url( $standard[ $type ] ) . '" target="_blank" title="' . esc_attr__( 'Not read yet: open it once (or “Read all pages”)', 'lightweight-translation' ) . '"><span>' . esc_html( LT_Page_Texts::label( $type ) ) . '</span><span class="lt-count">' . esc_html__( 'not read', 'lightweight-translation' ) . ' ↗</span></a>'; continue; }
			list( $n, $todo ) = $todo_of( array_keys( array_diff_key( (array) $map[ $type ]['keys'], $common ) ) );
			$link( LT_Page_Texts::label( $type ), array( 'page_type' => $type ), $n, $todo );
		}
		// Other places.
		echo '<h4>' . esc_html__( 'Elsewhere', 'lightweight-translation' ) . '</h4>';
		if ( isset( $map['messages'] ) ) { list( $n, $todo ) = $todo_of( array_keys( (array) $map['messages']['keys'] ) ); $link( LT_Page_Texts::label( 'messages' ), array( 'page_type' => 'messages' ), $n, $todo ); }
		$link( __( 'Added by hand', 'lightweight-translation' ), array( 'source' => 'manual' ) );
		// The same texts, sorted by source.
		echo '<h4>' . esc_html__( 'By source', 'lightweight-translation' ) . '</h4>';
		$go = function( $label, $url ) { echo '<a class="lt-place" href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span><span class="lt-count">→</span></a>'; };
		if ( function_exists( 'WC' ) ) { $go( 'WooCommerce', LT_Sources::url( 'woocommerce' ) ); $go( __( 'E-mails', 'lightweight-translation' ), admin_url( 'admin.php?page=lt-woocommerce&sub=emails' ) ); }
		$go( LT_Sources::label( 'theme' ), LT_Sources::url( 'theme' ) );
		$go( __( 'Plugins', 'lightweight-translation' ), admin_url( 'admin.php?page=lt-plugins' ) );
	}

	/* ---------- Tab: list of texts ---------- */

	private function tab_translate() {
		$args = array(
			'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'source'    => isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'kind'      => isset( $_GET['kind'] ) ? sanitize_key( $_GET['kind'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'todo'      => ! empty( $_GET['todo'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'seen'      => ! empty( $_GET['seen'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'ignored'   => ! empty( $_GET['ignored'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'paged'     => max( 1, absint( $_GET['paged'] ?? 1 ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'onpage'    => isset( $_GET['onpage'] ) ? sanitize_key( $_GET['onpage'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'page_type' => isset( $_GET['page_type'] ) ? sanitize_text_field( wp_unslash( $_GET['page_type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'key'       => isset( $_GET['key'] ) ? sanitize_key( $_GET['key'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'group'     => isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'sub'       => isset( $_GET['sub'] ) ? sanitize_key( $_GET['sub'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'shared'    => ! empty( $_GET['shared'] ),
			'area'      => sanitize_key( $_GET['area'] ?? '' ),
			'view'      => sanitize_key( $_GET['view'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification
			'keys'      => null,
		);
		if ( '' !== $args['key'] ) $args['keys'] = array( $args['key'] );
		if ( '' !== $args['page_type'] ) {
			$page = LT_Page_Texts::type_data( $args['page_type'] );
			if ( ! $page ) { echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This page has not been read yet: open it on the site while logged in (or use “Read all pages”).', 'lightweight-translation' ) . '</p></div>'; $args['page_type'] = ''; }
			else {
				// Where on the page / which block: counted for the filters, then applied.
				$args['zone']  = isset( $_GET['zone'] ) ? sanitize_key( $_GET['zone'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				$args['part']  = isset( $_GET['part'] ) ? sanitize_text_field( wp_unslash( $_GET['part'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				$args['order'] = isset( $_GET['order'] ) && in_array( $_GET['order'], array( 'page', 'az', 'plugin' ), true ) ? sanitize_key( $_GET['order'] ) : 'page'; // phpcs:ignore WordPress.Security.NonceVerification
				$args['zones'] = array();
				$args['parts'] = array();
				foreach ( (array) $page['texts'] as $text ) {
					$z = $text['z'] ?? 'none';
					$args['zones'][ $z ] = ( $args['zones'][ $z ] ?? 0 ) + 1;
					if ( ! empty( $text['c'] ) ) $args['parts'][ $text['c'] ] = ( $args['parts'][ $text['c'] ] ?? 0 ) + 1;
				}
				if ( '' !== $args['zone'] ) $page['texts'] = array_filter( (array) $page['texts'], function( $text ) use ( $args ) { return ( $text['z'] ?? 'none' ) === $args['zone']; } );
				if ( '' !== $args['part'] ) $page['texts'] = array_filter( (array) $page['texts'], function( $text ) use ( $args ) { return ( $text['c'] ?? '' ) === $args['part']; } );
				list( $args['keys'], $args['disabled'] ) = LT_Page_Texts::prepare( $page );
				$args['page'] = $page;
				// Order of the lines: as on the page (zone, then top to bottom), A → Z, or by plugin.
				$index = $this->index();
				$args['sort'] = array();
				foreach ( (array) $page['texts'] as $field => $text ) {
					$value = 'page' === $args['order'] ? sprintf( '%02d%03d', LT_Page_Places::rank( $text['z'] ?? 'unseen' ) + ( isset( $text['z'] ) ? 0 : 1 ), (int) ( $text['o'] ?? 999 ) ) : ( 'plugin' === $args['order'] ? $this->label( $this->source_of( $field, $index ) ) : '' );
					$args['sort'][ sanitize_key( $field ) ] = $value;
					$args['sort'][ 'text_' . md5( (string) $text[0] ) ] = $value;
				}
			}
		}
		$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : absint( get_user_option( 'lt_strings_per_page' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $per_page, self::PER_PAGE_CHOICES, true ) ) $per_page = 100;
		if ( isset( $_GET['per_page'] ) ) update_user_option( get_current_user_id(), 'lt_strings_per_page', $per_page ); // phpcs:ignore WordPress.Security.NonceVerification
		$args['per_page'] = $per_page;
		$args = apply_filters( 'lt_string_view_args', $args, $this->all_texts(), $this->index() );
		if ( '' === $args['group'] && '' === $args['source'] && '' === $args['search'] && '' === $args['kind'] && ! $args['seen'] && ! $args['ignored'] && null === $args['keys'] ) { $this->overview(); return; }
		$this->texts( $args );
	}

	private function overview() {
		$data      = $this->overview_data();
		$languages = $this->languages();
		$labels    = $this->source_labels();
		echo '<h2 style="margin:0 0 8px">' . esc_html__( 'Texts by source', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'Choose WordPress, WooCommerce, the theme or a plugin to see its texts and translation progress.', 'lightweight-translation' ) . '</p>';
		if ( ! $data ) { echo '<div class="lt-str-card"><p>' . wp_kses_post( sprintf( __( 'No text yet. Go to <a href="%s">Add texts</a> to scan your plugins, theme options and widgets.', 'lightweight-translation' ), esc_url( $this->url( array( 'tab' => 'find' ) ) ) ) ) . '</p></div>'; return; }
		echo '<table class="widefat striped lt-ov"><thead><tr><th>' . esc_html__( 'Source', 'lightweight-translation' ) . '</th><th>' . esc_html__( 'Type', 'lightweight-translation' ) . '</th><th>' . esc_html__( 'Texts', 'lightweight-translation' ) . '</th>';
		foreach ( $languages as $language ) echo '<th>' . esc_html( LT_Language_Router::flag( $language ) . ' ' . strtoupper( $language ) ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		$ignored = 0;
		foreach ( $data as $source => $d ) {
			$ignored += $d['ignored'];
			if ( ! $d['total'] ) continue;
			echo '<tr><td><a href="' . esc_url( $this->url( array( 'source' => $source, 'todo' => 1 ) ) ) . '"><strong>' . esc_html( $labels[ $source ][0] ?? $source ) . '</strong></a>' . ( 'site' === $source ? '<br><span class="lt-tag">' . esc_html__( 'payment & shipping methods, checkout terms, store notice, theme options…', 'lightweight-translation' ) . '</span>' : '' ) . '</td><td class="lt-tag">' . esc_html( $labels[ $source ][1] ?? '' ) . '</td><td>' . esc_html( number_format_i18n( $d['total'] ) ) . '</td>';
			foreach ( $languages as $language ) {
				$n   = $d['translated'][ $language ] ?? 0;
				$pct = $d['total'] ? floor( 100 * $n / $d['total'] ) : 0;
				echo '<td><div class="lt-bar"><span style="width:' . esc_attr( $pct ) . '%"></span></div><span class="lt-tag">' . esc_html( $n . ' / ' . $d['total'] ) . '</span></td>';
			}
			echo '<td style="white-space:nowrap"><a class="button button-small button-primary" href="' . esc_url( $this->url( array( 'source' => $source, 'todo' => 1 ) ) ) . '">' . esc_html__( 'Translate', 'lightweight-translation' ) . '</a> <a class="button button-small" href="' . esc_url( $this->url( array( 'source' => $source ) ) ) . '">' . esc_html__( 'All', 'lightweight-translation' ) . '</a> <a class="button button-small" href="' . esc_url( $this->export_url( $source, '' ) ) . '">CSV</a></td></tr>';
		}
		echo '</tbody></table>';
		if ( $ignored ) echo '<p class="lt-tag"><a href="' . esc_url( $this->url( array( 'ignored' => 1 ) ) ) . '">' . esc_html( sprintf( __( '%d ignored texts', 'lightweight-translation' ), $ignored ) ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Performance: only translated texts are loaded on the site, from a cache (no database query on most pages). Untranslated or ignored texts cost nothing.', 'lightweight-translation' ) . '</p>';
	}

	private function export_url( $source, $kind, $group = '' ) {
		return wp_nonce_url( add_query_arg( apply_filters( 'lt_string_export_args', array_filter( array( 'action' => 'lt_export', 'groups[]' => 'string', 'only' => 'todo', 'string_source' => $source, 'string_kind' => $kind, 'string_group' => $group ) ) ), admin_url( 'admin-post.php' ) ), 'lt_export' );
	}

	private function texts( $args ) {
		$languages = $this->languages();
		$index     = $this->index();
		$seen_keys = (array) get_option( 'lt_string_seen', array() );
		$where     = LT_Page_Texts::where_map();
		list( $rows, $translations, $total ) = $this->query( $args );
		$source = $args['source'];
		if ( '' !== $source && empty( $args['page'] ) ) echo '<h2 style="margin:0 0 8px">' . esc_html( $this->label( $source ) ) . '</h2>';
		elseif ( '' !== $args['search'] && empty( $args['page'] ) ) echo '<h2 style="margin:0 0 8px">' . esc_html( sprintf( __( 'Search: “%s”', 'lightweight-translation' ), $args['search'] ) ) . '</h2>';
		if ( ! empty( $args['page'] ) ) $this->onpage_header( $args );

		// Filters: the usual ones visible, the rest folded.
		$keep = '';
		foreach ( array( 'onpage', 'page_type', 'key', 'group', 'sub', 'area' ) as $arg ) if ( '' !== ( $args[ $arg ] ?? '' ) ) $keep .= '<input type="hidden" name="' . esc_attr( $arg ) . '" value="' . esc_attr( $args[ $arg ] ) . '">';
		if ( 'theme' === $args['group'] && '' !== ( $_GET['theme_area'] ?? '' ) ) $keep .= '<input type="hidden" name="theme_area" value="' . esc_attr( sanitize_key( $_GET['theme_area'] ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $args['shared'] ) ) $keep .= '<input type="hidden" name="shared" value="1">';
		echo '<form method="get" style="margin:0 0 6px"><input type="hidden" name="page" value="' . esc_attr( $this->slug ) . '">' . $keep . '<input type="search" name="s" value="' . esc_attr( $args['search'] ) . '" placeholder="' . esc_attr__( 'Search…', 'lightweight-translation' ) . '"> <label><input type="checkbox" name="todo" value="1" ' . checked( $args['todo'], true, false ) . ' onchange="this.form.submit()"> ' . esc_html__( 'Untranslated only', 'lightweight-translation' ) . '</label> <label>' . esc_html__( 'Show', 'lightweight-translation' ) . ' <select name="per_page" onchange="this.form.submit()">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( self::PER_PAGE_CHOICES as $choice ) echo '<option value="' . esc_attr( $choice ) . '" ' . selected( $args['per_page'], $choice, false ) . '>' . esc_html( $choice ) . '</option>';
		echo '</select></label> ';
		do_action( 'lt_string_filters', $args );
		if ( ! empty( $args['page'] ) && isset( $args['zones'] ) ) {
			echo '<label>' . esc_html__( 'Where', 'lightweight-translation' ) . ' <select name="zone" onchange="this.form.submit()"><option value="">' . esc_html__( 'Whole page', 'lightweight-translation' ) . '</option>';
			foreach ( array_merge( LT_Page_Places::ZONES, array( 'none' ) ) as $zone ) if ( ! empty( $args['zones'][ $zone ] ) || $zone === $args['zone'] ) echo '<option value="' . esc_attr( $zone ) . '" ' . selected( $args['zone'], $zone, false ) . '>' . esc_html( ( 'none' === $zone ? __( 'Not located yet (read the page again)', 'lightweight-translation' ) : LT_Page_Places::zone_label( $zone ) ) . ' (' . (int) ( $args['zones'][ $zone ] ?? 0 ) . ')' ) . '</option>';
			echo '</select></label> ';
			if ( $args['parts'] || '' !== $args['part'] ) {
				ksort( $args['parts'] );
				echo '<label>' . esc_html__( 'Block', 'lightweight-translation' ) . ' <select name="part" onchange="this.form.submit()"><option value="">' . esc_html__( 'All blocks', 'lightweight-translation' ) . '</option>';
				foreach ( $args['parts'] as $part => $n ) echo '<option value="' . esc_attr( $part ) . '" ' . selected( $args['part'], $part, false ) . '>' . esc_html( $part . ' (' . (int) $n . ')' ) . '</option>';
				echo '</select></label> ';
			}
			echo '<label>' . esc_html__( 'Order', 'lightweight-translation' ) . ' <select name="order" onchange="this.form.submit()">';
			foreach ( array( 'page' => __( 'As on the page', 'lightweight-translation' ), 'az' => __( 'A → Z', 'lightweight-translation' ), 'plugin' => __( 'By plugin', 'lightweight-translation' ) ) as $order => $name ) echo '<option value="' . esc_attr( $order ) . '" ' . selected( $args['order'], $order, false ) . '>' . esc_html( $name ) . '</option>';
			echo '</select></label> ';
		}
		submit_button( __( 'Filter', 'lightweight-translation' ), 'secondary', '', false );
		echo ' <span class="description">' . esc_html( sprintf( _n( '%d text', '%d texts', $total, 'lightweight-translation' ), $total ) ) . '</span>';
		$more = '' !== $source || $args['ignored'];
		echo '<details style="margin-top:6px"' . ( $more ? ' open' : '' ) . '><summary style="cursor:pointer" class="lt-tag">' . esc_html__( 'More filters', 'lightweight-translation' ) . '</summary><p>' . ( '' === ( $args['group'] ?? '' ) ? $this->source_select( $source ) . ' ' : '' ) . '<label><input type="checkbox" name="ignored" value="1" ' . checked( $args['ignored'], true, false ) . '> ' . esc_html__( 'Not translated on purpose', 'lightweight-translation' ) . '</label> · <a href="' . esc_url( $this->export_url( $source, '', $args['group'] ?? '' ) ) . '">' . esc_html__( 'Export the untranslated ones (CSV)', 'lightweight-translation' ) . '</a></p></details></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $this->shared && '' !== ( $args['group'] ?? '' ) && empty( $args['shared'] ) ) echo '<p class="lt-tag" style="font-size:13px;margin:4px 0 8px">' . esc_html( sprintf( _n( '%d more text is the same as a WooCommerce or theme text: it uses that translation, so it is not listed here.', '%d more texts are the same as WooCommerce or theme texts: they use that translation, so they are not listed here.', count( $this->shared ), 'lightweight-translation' ), count( $this->shared ) ) ) . ' <a href="' . esc_url( add_query_arg( 'shared', 1 ) ) . '">' . esc_html__( 'Show them anyway', 'lightweight-translation' ) . '</a></p>';
		if ( ! $rows ) { echo '<p>' . esc_html( $args['todo'] ? __( 'Everything here is translated. 🎉', 'lightweight-translation' ) : __( 'Nothing here.', 'lightweight-translation' ) ) . '</p>'; if ( '' !== $args['page_type'] ) $this->add_form( $args ); return; }

		$this->form_open( 'save', ' id="lt-str-form"' );
		echo '<input type="hidden" name="redirect" value="' . esc_attr( remove_query_arg( 'lt_notice', wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) . '">';
		$bulk = function() use ( $args ) {
			echo '<div class="lt-bulk"><span class="lt-tag" style="font-size:13px">' . esc_html__( 'Selected lines:', 'lightweight-translation' ) . '</span>';
			echo '<button class="button" name="bulk" value="clear" data-confirm="' . esc_attr__( 'Remove the translations of the selected texts? The original text is shown again.', 'lightweight-translation' ) . '">' . esc_html__( 'Remove translation', 'lightweight-translation' ) . '</button>';
			if ( $args['ignored'] ) echo '<button class="button" name="bulk" value="unignore">' . esc_html__( 'Translate again', 'lightweight-translation' ) . '</button>';
			else echo '<button class="button" name="bulk" value="skip" data-confirm="' . esc_attr__( 'Keep the selected texts in the original language? Their translation is removed and they leave the lists.', 'lightweight-translation' ) . '">' . esc_html__( 'Don’t translate', 'lightweight-translation' ) . '</button>';
			echo '<button class="button button-link-delete" name="bulk" value="delete" data-confirm="' . esc_attr__( 'Delete the selected texts and their translations? They come back if a page shows them again: to keep a text untranslated for good, use “Don’t translate”.', 'lightweight-translation' ) . '">' . esc_html__( 'Delete', 'lightweight-translation' ) . '</button></div>';
		};
		$bulk();
		echo '<table class="widefat striped lt-str-table"><thead><tr><td class="check-column"><input type="checkbox" id="lt-check-all"></td><th style="width:32%">' . esc_html( LT_Language_Router::flag( LT_Language_Router::instance()->default_language() ) . ' ' . __( 'Original', 'lightweight-translation' ) ) . '</th>';
		foreach ( $languages as $language ) echo '<th>' . esc_html( LT_Language_Router::flag( $language ) . ' ' . LT_Language_Router::language_label( $language ) ) . '</th>';
		echo '</tr></thead><tbody>';
		$zone_row = null;
		$cols     = count( $languages ) + 2;
		foreach ( $rows as $row ) {
			$entry = $index[ $row->field_key ] ?? array();
			$tags  = array();
			// Where it is on this page and which block it belongs to.
			$place = null;
			if ( ! empty( $args['page'] ) ) {
				foreach ( $row->keys ?? array( $row->field_key ) as $key ) if ( isset( $args['page']['texts'][ $key ] ) && is_array( $args['page']['texts'][ $key ] ) ) { $place = $args['page']['texts'][ $key ]; break; }
				if ( 'page' === ( $args['order'] ?? '' ) ) {
					$zone = $place['z'] ?? 'none';
					if ( $zone !== $zone_row ) { $zone_row = $zone; echo '<tr class="lt-zone-row"><td colspan="' . (int) $cols . '">' . esc_html( 'none' === $zone ? __( 'Not located yet (open the page again to place them)', 'lightweight-translation' ) : LT_Page_Places::zone_label( $zone ) ) . '</td></tr>'; }
				}
				$summary = $place ? LT_Page_Places::summary( $place, 'page' !== ( $args['order'] ?? '' ) ) : '';
				if ( '' !== $summary ) $tags[] = '<strong class="lt-place-tag">📍 ' . esc_html( $summary ) . '</strong>';
			}
			// Where it is displayed, then where it comes from.
			if ( empty( $args['page'] ) && ! empty( $where[ $row->field_key ] ) ) {
				$on = array_map( array( 'LT_Page_Texts', 'label' ), array_slice( $where[ $row->field_key ], 0, 3 ) );
				$tags[] = '📍 ' . esc_html( implode( ', ', $on ) . ( count( $where[ $row->field_key ] ) > 3 ? ' +' . ( count( $where[ $row->field_key ] ) - 3 ) : '' ) );
			}
			$tags[] = esc_html( $this->label( $this->source_of( $row->field_key, $index ) ) );
			$tags = apply_filters( 'lt_string_tags', $tags, $row, $entry, $args );
			if ( 'm' === ( $entry['t'] ?? '' ) ) $tags[] = '<strong style="color:#b32d2e">' . esc_html__( 'message', 'lightweight-translation' ) . '</strong>';
			if ( ! empty( $entry['x'] ) ) $tags[] = esc_html__( 'not translated on purpose', 'lightweight-translation' );
			$siblings = array_values( array_diff( $row->keys ?? array(), array( $row->field_key ) ) );
			if ( $siblings ) $tags[] = '<span title="' . esc_attr__( 'The same text is used in several places: translated once for all of them.', 'lightweight-translation' ) . '">×' . ( count( $siblings ) + 1 ) . '</span>';
			$long = strlen( (string) $row->source ) > 70;
			echo '<tr><th class="check-column"><input type="checkbox" class="lt-key" name="keys[]" value="' . esc_attr( implode( ',', $row->keys ?? array( $row->field_key ) ) ) . '">' . ( $siblings ? '<input type="hidden" name="grp[' . esc_attr( $row->field_key ) . ']" value="' . esc_attr( implode( ',', $siblings ) ) . '">' : '' ) . '</th><td><div class="lt-src">' . esc_html( $row->source ) . '</div><span class="lt-tag">' . implode( ' · ', $tags ) . '</span>' . ( ! empty( $entry['p'] ) ? '<details class="lt-text-details"><summary>' . esc_html__( 'Source details', 'lightweight-translation' ) . '</summary><code>' . esc_html( $entry['p'] ) . '</code></details>' : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			foreach ( $languages as $language ) {
				$value = $translations[ $row->field_key ][ $language ] ?? '';
				$name  = '[' . esc_attr( $language ) . '][' . esc_attr( $row->field_key ) . ']';
				$style = 'border-left-color:' . ( '' === $value ? '#d63638' : '#00a32a' );
				echo '<td>';
				echo $long ? '<textarea rows="2" name="str' . $name . '" data-old="' . esc_attr( $value ) . '" style="' . esc_attr( $style ) . '">' . esc_textarea( $value ) . '</textarea>' : '<input type="text" name="str' . $name . '" value="' . esc_attr( $value ) . '" data-old="' . esc_attr( $value ) . '" style="' . esc_attr( $style ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		$bulk();
		echo '<div class="lt-save">';
		submit_button( __( 'Save translations', 'lightweight-translation' ), 'primary', 'submit', false );
		echo ' &nbsp; <span class="description">' . esc_html__( 'Keep placeholders like %s, %1$s or {name} unchanged. Empty = the original text is shown.', 'lightweight-translation' ) . '</span></div></form>';
		$pages = (int) ceil( $total / $args['per_page'] );
		if ( $pages > 1 ) echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $args['paged'], 'total' => $pages ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( '' !== $args['page_type'] ) $this->add_form( $args );
		?>
<script>
(function(){
	var form=document.getElementById('lt-str-form');if(!form)return;
	var all=document.getElementById('lt-check-all');
	function paint(){form.querySelectorAll('.lt-key').forEach(function(c){c.closest('tr').classList.toggle('is-checked',c.checked);});}
	all.addEventListener('change',function(){form.querySelectorAll('.lt-key').forEach(function(c){c.checked=all.checked;});paint();});
	form.addEventListener('change',function(e){if(e.target.classList.contains('lt-key'))paint();});
	form.querySelectorAll('input[data-old],textarea[data-old]').forEach(function(i){i.addEventListener('input',function(){i.style.borderLeftColor=i.value.trim()?'#00a32a':'#d63638';});});
	var clicked=null;form.querySelectorAll('button[name=bulk]').forEach(function(b){b.addEventListener('click',function(e){if(!form.querySelector('.lt-key:checked')){e.preventDefault();alert(<?php echo wp_json_encode( __( 'Tick the texts first (checkbox on the left).', 'lightweight-translation' ) ); ?>);return;}if(b.dataset.confirm&&!confirm(b.dataset.confirm)){e.preventDefault();return;}clicked=b;});});
	// Only the changed translations are sent (servers refuse very big forms).
	form.addEventListener('submit',function(){form.querySelectorAll('input[data-old],textarea[data-old]').forEach(function(i){if(i.value===i.dataset.old)i.disabled=true;});});
})();
</script>
		<?php
	}

	/** "A text of this page is missing": add it with its exact wording (custom fields, page builders, scripts…). */
	private function add_form( $args ) {
		echo '<details class="lt-str-card" style="margin:18px 0;padding:8px 18px" id="lt-add-here"><summary style="cursor:pointer;font-weight:600">' . esc_html__( '+ A text of this page is missing? Add it', 'lightweight-translation' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Copy it exactly as it is displayed in the original language (a form field, a label typed in a page builder, a text shown by a script…). It is translated everywhere it appears.', 'lightweight-translation' ) . '</p>';
		$this->form_open( 'add' );
		echo '<input type="hidden" name="page_type" value="' . esc_attr( $args['page_type'] ) . '"><input type="hidden" name="redirect" value="' . esc_attr( remove_query_arg( 'lt_notice', wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) . '">';
		echo '<table class="widefat lt-str-table" style="border:0"><tr><td style="width:32%"><input type="text" name="original" required placeholder="' . esc_attr__( 'Text as displayed', 'lightweight-translation' ) . '" style="border-left-color:#c3c4c7"></td>';
		foreach ( $this->languages() as $language ) echo '<td><input type="text" name="add[' . esc_attr( $language ) . ']" placeholder="' . esc_attr( LT_Language_Router::flag( $language ) . ' ' . LT_Language_Router::language_label( $language ) ) . '"></td>';
		echo '</tr></table><p>';
		submit_button( __( 'Add', 'lightweight-translation' ), 'secondary', 'submit', false );
		echo '</p></form></details>';
	}

	/** Content shown on the page (the page itself, theme blocks, synced patterns…), with its translation state. */
	private function page_items( $items ) {
		$rows = array();
		foreach ( (array) $items as $ref ) {
			list( $type, $id ) = array_pad( explode( ':', (string) $ref, 2 ), 2, 0 );
			if ( 'term' === $type ) { $term = get_term( (int) $id ); $group = $term instanceof WP_Term ? 'tax:' . $term->taxonomy : ''; }
			else $group = 'post:' . $type;
			$item = $group ? LT_Content_Catalog::item( $group, (int) $id ) : null;
			if ( ! $item || ! $item['fields'] ) continue;
			$rows[] = array( $group, $item );
		}
		if ( ! $rows ) return;
		$languages = $this->languages();
		$groups    = LT_Content_Catalog::groups();
		echo '<h3 style="margin:14px 0 6px">' . esc_html__( 'Content shown on this page', 'lightweight-translation' ) . '</h3><table class="widefat striped" style="margin-bottom:18px"><tbody>';
		foreach ( $rows as list( $group, $item ) ) {
			echo '<tr><td style="width:40%"><strong>' . esc_html( $item['title'] ) . '</strong> <span class="lt-tag">' . esc_html( $groups[ $group ] ?? $group ) . '</span></td>';
			foreach ( $languages as $language ) {
				$missing = 0; $outdated = 0;
				foreach ( $item['fields'] as $field => $source ) { $row = LT_Translation_Store::get_translated( $language, $item['type'], $item['id'], $field ); if ( ! $row ) $missing++; elseif ( ! LT_Translation_Store::source_matches( $row, $source ) ) $outdated++; }
				$state = $missing === count( $item['fields'] ) ? '<span style="color:#b32d2e">' . esc_html__( 'not translated', 'lightweight-translation' ) . '</span>' : ( $missing || $outdated ? '<span style="color:#996800">' . esc_html( $outdated ? __( 'to update', 'lightweight-translation' ) : __( 'partly', 'lightweight-translation' ) ) . '</span>' : '<span style="color:#00a32a">✓</span>' );
				echo '<td>' . esc_html( LT_Language_Router::flag( $language ) ) . ' ' . $state . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			$link = $item['edit'] ? ( $item['block_editor'] ? add_query_arg( 'lt_lang', $languages[0], $item['edit'] ) : $item['edit'] . ( 0 === strpos( $group, 'post:' ) ? '#lt-translations' : '' ) ) : '';
			echo '<td>' . ( $link ? '<a class="button button-small button-primary" href="' . esc_url( $link ) . '">' . esc_html__( 'Translate', 'lightweight-translation' ) . '</a>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h3 style="margin:14px 0 6px">' . esc_html__( 'Texts of this page', 'lightweight-translation' ) . '</h3>';
	}

	/** Header of the texts of one page: which page, and the texts that cannot be translated yet (plugin switched off). */
	private function onpage_header( $args ) {
		$page = $args['page'];
		$url  = $page['url'] ?? '';
		echo '<h2 style="margin:4px 0 8px">' . esc_html( $page['title'] ?: $url ) . ( $url ? ' <a class="button button-small" href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'Open the page', 'lightweight-translation' ) . ' ↗</a>' : '' ) . '</h2>';
		if ( ! empty( $page['items'] ) ) $this->page_items( $page['items'] );
		if ( 'common' !== $args['page_type'] && '' !== $args['page_type'] ) echo '<p class="lt-tag" style="font-size:13px;margin:0 0 8px">' . wp_kses_post( sprintf( __( 'Header, footer and menu texts are in <a href="%1$s">Every page</a>. The content of the page itself is translated in <a href="%2$s">Pages &amp; products</a>.', 'lightweight-translation' ), esc_url( $this->url( array( 'page_type' => 'common' ) ) ), esc_url( admin_url( 'admin.php?page=lt-status' ) ) ) ) . '</p>';
		if ( ! empty( $args['disabled'] ) ) {
			$names = array_slice( array_keys( $args['disabled'] ), 0, 8 );
			echo '<div class="notice notice-warning inline" style="margin:6px 0 12px"><p>' . esc_html( sprintf( _n( '%1$d more text comes from plugins / themes not enabled for translation: %2$s.', '%1$d more texts come from plugins / themes not enabled for translation: %2$s.', array_sum( $args['disabled'] ), 'lightweight-translation' ), array_sum( $args['disabled'] ), implode( ', ', $names ) ) ) . ' ';
			$this->form_open( 'add_domains', ' style="display:inline"' );
			echo '<input type="hidden" name="domains" value="' . esc_attr( implode( ',', array_keys( $args['disabled'] ) ) ) . '"><input type="hidden" name="redirect" value="' . esc_attr( remove_query_arg( 'lt_notice', wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) . '">';
			submit_button( __( 'Show them too', 'lightweight-translation' ), 'small', 'submit', false );
			echo '</form></p></div>';
		}
	}

	private function source_select( $current ) {
		$counts = array();
		foreach ( $this->index() as $field => $entry ) { $d = $this->source_of( $field, array( $field => $entry ) ); $counts[ $d ] = ( $counts[ $d ] ?? 0 ) + 1; }
		$html = '<select name="source"><option value="">' . esc_html__( 'All sources', 'lightweight-translation' ) . '</option>';
		foreach ( $counts as $domain => $count ) $html .= '<option value="' . esc_attr( $domain ) . '" ' . selected( $current, $domain, false ) . '>' . esc_html( $this->label( $domain ) . ' (' . $count . ')' ) . '</option>';
		$html .= '<option value="manual" ' . selected( $current, 'manual', false ) . '>' . esc_html( $this->label( 'manual' ) ) . '</option><option value="unknown" ' . selected( $current, 'unknown', false ) . '>' . esc_html( $this->label( 'unknown' ) ) . '</option>';
		return $html . '</select>';
	}

	/* ---------- Tab: Add texts ---------- */

	private function tab_find() {
		echo '<h2 style="margin:0">' . esc_html__( 'Tools', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'Texts appear by themselves, page by page, as soon as a page is opened. These tools are only for a text you cannot find.', 'lightweight-translation' ) . '</p>';
		echo '<div class="lt-str-cards">';
		// Add by hand.
		echo '<div class="lt-str-card" id="lt-add"><h2>' . esc_html__( 'Add a text by hand', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'Copy it exactly as it is displayed on the site in the original language.', 'lightweight-translation' ) . '</p>';
		$this->form_open( 'add' );
		echo '<p><input type="text" name="original" class="large-text" required placeholder="' . esc_attr__( 'Text as displayed', 'lightweight-translation' ) . '"></p>';
		foreach ( $this->languages() as $language ) echo '<p><input type="text" name="add[' . esc_attr( $language ) . ']" class="large-text" placeholder="' . esc_attr( LT_Language_Router::flag( $language ) . ' ' . LT_Language_Router::language_label( $language ) ) . '"></p>';
		submit_button( __( 'Add', 'lightweight-translation' ), 'primary', 'submit', false );
		echo '</form></div>';
		// Settings texts.
		echo '<div class="lt-str-card" id="lt-settings-texts"><h2>' . esc_html__( 'Texts typed in settings', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'Site title and tagline, widget titles, the options your theme and plugins declare translatable (copyright, cookie notice…), and with WooCommerce the payment & shipping methods, checkout terms and store notice.', 'lightweight-translation' ) . '</p>';
		$this->form_open( 'site' );
		submit_button( __( 'Find them', 'lightweight-translation' ), 'secondary', 'submit', false );
		echo '</form></div></div>';

		// Advanced: code scan + which plugins are translated.
			echo '<details class="lt-str-card" id="lt-plugin-scan" style="padding:10px 18px"><summary style="cursor:pointer;font-weight:600">' . esc_html__( 'Advanced', 'lightweight-translation' ) . '</summary>';
		echo '<h3>' . esc_html__( 'Read the code of a plugin or theme', 'lightweight-translation' ) . '</h3><p class="description">' . esc_html__( 'Lists in advance the texts a plugin can show to visitors (including rare error messages). Admin screens are ignored. Nothing is modified.', 'lightweight-translation' ) . '</p><div style="columns:3 260px;margin:10px 0">';
		foreach ( LT_Code_Scanner::targets() as $target_key => $target ) if ( $target['active'] ) echo '<label style="display:block;margin:0 0 6px"><input type="checkbox" class="lt-scan-target" value="' . esc_attr( $target_key ) . '"> ' . esc_html( $target['label'] ) . '</label>';
		echo '</div><p><button type="button" class="button" id="lt-scan-start">' . esc_html__( 'Read selected', 'lightweight-translation' ) . '</button> <span id="lt-scan-status" class="description"></span></p><div style="height:6px;background:#f0f0f1;border-radius:4px;overflow:hidden;max-width:520px"><span id="lt-scan-bar" style="display:block;height:100%;width:0;background:#2271b1;transition:width .2s"></span></div>';
		echo '<script>window.LTScan=' . wp_json_encode( array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'lt_scan_code' ), 'done' => __( 'Done: %d new texts found. Reloading…', 'lightweight-translation' ), 'scanning' => __( 'Reading %1$s… %2$d/%3$d files', 'lightweight-translation' ), 'reload' => admin_url( 'admin.php?page=lt-plugins' ) ) ) . ';</script>';
		$this->form_open( 'cleanup', ' onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Remove every untranslated text that no page of the site shows? Translated texts and texts added by hand are kept.', 'lightweight-translation' ) ) ) . ')"' );
		echo '<h3>' . esc_html__( 'Clean up', 'lightweight-translation' ) . '</h3><p class="description">' . esc_html__( 'After reading the site pages: remove untranslated texts that are not shown to visitors. They can be collected again later if a page starts using them.', 'lightweight-translation' ) . '</p>';
		submit_button( __( 'Remove the texts no page shows', 'lightweight-translation' ), 'secondary', 'submit', false );
		echo '</form>';
		$this->form_open( 'settings' );
		echo '<h3>' . esc_html__( 'Plugins and themes translated', 'lightweight-translation' ) . '</h3><p><input type="text" name="domains" id="lt-domains" class="large-text" value="' . esc_attr( implode( ', ', LT_Frontend_Filters::domains() ) ) . '"><span class="description">' . esc_html__( 'Their text domains, comma separated ("*" = all). Texts of other plugins are not translated (and cost nothing). Pages list the missing ones with a button to add them. Click to add:', 'lightweight-translation' ) . '</span><br>';
		foreach ( $this->loaded_domains() as $domain ) echo '<span class="lt-chip" data-domain="' . esc_attr( $domain ) . '">' . esc_html( $domain ) . '</span>';
		echo '</p>';
		submit_button( __( 'Save', 'lightweight-translation' ), 'secondary', 'submit', false );
		echo '</form></details>';
	}

	private function script() {
		echo '<script>(function(){var section=document.getElementById("lt-plugin-scan");if(section&&location.hash==="#lt-plugin-scan")section.open=true;var b=document.getElementById("lt-scan-start");if(!b||!window.LTScan)return;var st=document.getElementById("lt-scan-status"),bar=document.getElementById("lt-scan-bar");
function fmt(s,a){var i=0;return s.replace(/%(\\d\\$)?[sd]/g,function(){return a[i++];});}
b.addEventListener("click",function(){var targets=[].slice.call(document.querySelectorAll(".lt-scan-target:checked")).map(function(c){return {key:c.value,label:c.parentNode.textContent.trim()};});if(!targets.length)return;
var mode="smart",added=0,t=0;b.disabled=true;
function step(offset){var target=targets[t];var body=new URLSearchParams({action:"lt_scan_code",nonce:LTScan.nonce,target:target.key,offset:offset,mode:mode,reset:"1"});
fetch(LTScan.ajax,{method:"POST",credentials:"same-origin",body:body}).then(function(r){return r.json();}).then(function(r){if(!r.success)throw new Error((r.data&&r.data.message)||"error");var d=r.data;added+=d.added;
var part=d.total?d.offset/d.total:1;bar.style.width=Math.round(100*(t+part)/targets.length)+"%";st.textContent=fmt(LTScan.scanning,[target.label,d.offset,d.total]);
if(!d.done)return step(d.offset);t++;if(t<targets.length)return step(0);st.textContent=fmt(LTScan.done,[added]);bar.style.width="100%";setTimeout(function(){location.href=LTScan.reload;},1200);}).catch(function(e){st.textContent="⚠ "+e.message;b.disabled=false;});}
step(0);});})();</script>';
		echo '<script>document.querySelectorAll(".lt-chip").forEach(function(c){c.addEventListener("click",function(){var i=document.getElementById("lt-domains"),l=i.value.split(",").map(function(x){return x.trim();}).filter(Boolean);if(l.indexOf(c.dataset.domain)<0)l.push(c.dataset.domain);i.value=l.join(", ");});});</script>';
	}

	/* ---------- Actions ---------- */

	/** Titles and texts of the widgets placed in sidebars. @return int */
	public static function collect_widgets() {
		$router    = LT_Language_Router::instance();
		$languages = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		$index     = (array) get_option( LT_Code_Scanner::INDEX, array() );
		$found     = LT_Frontend_Filters::widget_texts();
		foreach ( $found as $text ) {
			$field = 'string_' . md5( LT_Frontend_Filters::widget_key( $text ) );
			foreach ( $languages as $language ) LT_Translation_Store::collect_empty( $language, $field, $text );
			$index[ $field ] = array( 'd' => 'widgets', 't' => 'l', 's' => 'widgets', 'a' => 'f' ) + ( isset( $index[ $field ]['x'] ) ? array( 'x' => 1 ) : array() );
		}
		update_option( LT_Code_Scanner::INDEX, $index, false );
		LT_Content_Catalog::strings_changed();
		return count( $found );
	}

	/** Deletes texts completely: every language row, their source/ignore info, "seen" mark and caches. */
	public static function delete_texts( $keys ) {
		global $wpdb;
		$keys = array_values( array_filter( array_map( 'sanitize_key', (array) $keys ) ) );
		if ( ! $keys ) return 0;
		$table = LT_Translation_Store::table();
		$count = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE object_type='string' AND object_id=0 AND field_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys ) ); // phpcs:ignore WordPress.DB
		$index = (array) get_option( LT_Code_Scanner::INDEX, array() );
		$seen  = (array) get_option( 'lt_string_seen', array() );
		foreach ( $keys as $key ) unset( $index[ $key ], $seen[ $key ] );
		update_option( LT_Code_Scanner::INDEX, $index, false );
		update_option( 'lt_string_seen', $seen, false );
		$map = (array) get_option( LT_Page_Texts::MAP, array() );
		$hit = false;
		foreach ( $map as $type => $entry ) foreach ( $keys as $key ) if ( isset( $map[ $type ]['keys'][ $key ] ) ) { unset( $map[ $type ]['keys'][ $key ] ); $hit = true; }
		if ( $hit ) update_option( LT_Page_Texts::MAP, $map, false );
		foreach ( LT_Language_Router::instance()->languages() as $language ) { delete_transient( 'lt_strings_' . $language ); delete_transient( 'lt_wcjs_' . $language ); }
		LT_Content_Catalog::strings_changed();
		return $count;
	}

	/** One-click actions on a single text: clear its translations, ignore / stop ignoring, delete. */
	public function handle_row() {
		if ( ! current_user_can( lt_capability() ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		check_admin_referer( 'lt_string_row' );
		global $wpdb;
		$key      = sanitize_key( wp_unslash( $_GET['key'] ?? '' ) );
		$do       = sanitize_key( $_GET['do'] ?? '' );
		$redirect = wp_validate_redirect( rawurldecode( wp_unslash( $_GET['redirect'] ?? '' ) ), $this->url() );
		$notice   = __( 'Nothing done.', 'lightweight-translation' );
		if ( $key ) {
			$table = LT_Translation_Store::table();
			switch ( $do ) {
				case 'delete':
					self::delete_texts( array( $key ) );
					$notice = __( 'Text deleted.', 'lightweight-translation' );
					break;
				case 'clear':
					$wpdb->query( $wpdb->prepare( "UPDATE $table SET translated_text='', status='empty' WHERE object_type='string' AND object_id=0 AND field_key=%s", $key ) ); // phpcs:ignore WordPress.DB
					foreach ( LT_Language_Router::instance()->languages() as $language ) delete_transient( 'lt_strings_' . $language );
					LT_Content_Catalog::strings_changed();
					$notice = __( 'Translation cleared: the original text is shown again.', 'lightweight-translation' );
					break;
				case 'ignore':
				case 'unignore':
					$index = (array) get_option( LT_Code_Scanner::INDEX, array() );
					if ( 'ignore' === $do ) { $index[ $key ] = ( $index[ $key ] ?? array( 'd' => '' ) ); $index[ $key ]['x'] = 1; } else unset( $index[ $key ]['x'] );
					update_option( LT_Code_Scanner::INDEX, $index, false );
					$notice = 'ignore' === $do ? __( 'Text ignored (hidden from lists and exports).', 'lightweight-translation' ) : __( 'Text no longer ignored.', 'lightweight-translation' );
					break;
			}
		}
		wp_safe_redirect( add_query_arg( 'lt_notice', rawurlencode( $notice ), $redirect ) );
		exit;
	}

	public function handle() {
		if ( ! current_user_can( lt_capability() ) || ! check_admin_referer( 'lt_strings' ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		global $wpdb;
		$table     = LT_Translation_Store::table();
		$post      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$languages = $this->languages();
		$notice    = '';
		$redirect  = $this->url();
		switch ( $post['do'] ?? '' ) {
			case 'settings':
				$domains = array_filter( array_map( function( $d ) { return preg_replace( '/[^A-Za-z0-9_\-\*\.]/', '', trim( $d ) ); }, explode( ',', (string) ( $post['domains'] ?? '' ) ) ) );
				update_option( 'lt_string_domains', implode( ', ', array_unique( $domains ) ) ?: implode( ', ', LT_Frontend_Filters::default_domains() ) );
				$notice   = __( 'Settings saved.', 'lightweight-translation' );
				$redirect = $this->url( array( 'tab' => 'find' ) );
				break;
			case 'add_domains':
				$domains = LT_Frontend_Filters::domains();
				foreach ( explode( ',', (string) ( $post['domains'] ?? '' ) ) as $domain ) { $domain = preg_replace( '/[^A-Za-z0-9_\-\.]/', '', trim( $domain ) ); if ( '' !== $domain ) $domains[] = $domain; }
				update_option( 'lt_string_domains', implode( ', ', array_unique( $domains ) ) );
				$notice   = __( 'Enabled: their texts are now in the list.', 'lightweight-translation' );
				$redirect = wp_validate_redirect( (string) ( $post['redirect'] ?? '' ), $this->url() );
				break;
			case 'site':
				$count    = LT_Admin_Texts::collect() + self::collect_widgets();
				$notice   = sprintf( __( '%d texts typed in settings and widgets found.', 'lightweight-translation' ), $count );
				$redirect = $this->url( array( 'source' => 'site' ) );
				break;
			case 'cleanup':
				$map = (array) get_option( LT_Page_Texts::MAP, array() );
				if ( ! $map ) { $notice = __( 'Check the site first to collect texts specific to your pages.', 'lightweight-translation' ); break; }
				$shown = array();
				foreach ( $map as $entry ) foreach ( array_keys( (array) ( $entry['keys'] ?? array() ) ) as $field ) $shown[ $field ] = true;
				$untranslated = $wpdb->get_col( "SELECT field_key FROM $table WHERE object_type='string' AND object_id=0 AND field_key NOT LIKE 'text\\_%' GROUP BY field_key HAVING SUM(status IN ('translated','reviewed') AND translated_text <> '') = 0" ); // phpcs:ignore WordPress.DB
				$index = $this->index();
				$drop  = array_values( array_filter( (array) $untranslated, function( $field ) use ( $shown, $index ) { return ! isset( $shown[ $field ] ) && empty( $index[ $field ]['x'] ) && empty( $index[ $field ]['catalog'] ); } ) );
				foreach ( array_chunk( $drop, 500 ) as $chunk ) self::delete_texts( $chunk );
				$notice   = sprintf( _n( '%d text removed.', '%d texts removed.', count( $drop ), 'lightweight-translation' ), count( $drop ) );
				$redirect = $this->url();
				break;
			case 'add':
				$original = trim( preg_replace( '/\s+/u', ' ', (string) ( $post['original'] ?? '' ) ) );
				if ( '' === $original ) break;
				$field = 'text_' . md5( $original );
				foreach ( $languages as $language ) {
					$value = trim( (string) ( $post['add'][ $language ] ?? '' ) );
					LT_Translation_Store::save( array( 'language_code' => $language, 'object_type' => 'string', 'object_id' => 0, 'field_key' => $field, 'source_text' => $original, 'translated_text' => $value, 'status' => '' === $value ? 'empty' : 'translated' ) );
				}
				$type = sanitize_text_field( (string) ( $post['page_type'] ?? '' ) );
				if ( '' !== $type ) LT_Page_Texts::add_texts( $type, array( $field => array( $original, 'manual' ) ) );
				$notice   = __( 'Text added.', 'lightweight-translation' );
				$redirect = '' !== $type ? wp_validate_redirect( (string) ( $post['redirect'] ?? '' ), $this->url( array( 'page_type' => $type ) ) ) : $this->url( array( 'source' => 'manual' ) );
				break;
			case 'purge':
				$source = sanitize_text_field( (string) ( $post['source'] ?? '' ) );
				$seen   = isset( $post['unseen'] ) ? (array) get_option( 'lt_string_seen', array() ) : null;
				$before = $this->index();
				$after  = LT_Code_Scanner::purge( $before, function( $entry, $field ) use ( $source, $seen ) {
					return ( $entry['d'] ?? '' ) === $source && ( null === $seen || ! isset( $seen[ $field ] ) ); // "never seen by visitors" only, when asked
				} );
				update_option( LT_Code_Scanner::INDEX, $after, false );
				LT_Content_Catalog::strings_changed();
				$notice   = sprintf( __( '%d untranslated texts deleted.', 'lightweight-translation' ), count( $before ) - count( $after ) );
				$redirect = $this->url( array( 'source' => $source ) );
				break;
			case 'save':
				$saved = 0;
				$bulk  = sanitize_key( $post['bulk'] ?? '' );
				$keys  = array();
				foreach ( (array) ( $post['keys'] ?? array() ) as $value ) $keys = array_merge( $keys, explode( ',', (string) $value ) ); // a line can stand for several identical texts
				$keys  = array_values( array_unique( array_filter( array_map( 'sanitize_key', $keys ) ) ) );
				$skip  = in_array( $bulk, array( 'delete', 'clear', 'skip' ), true ) ? $keys : array();
				foreach ( (array) ( $post['str'] ?? array() ) as $language => $fields ) {
					$language = sanitize_key( $language );
					if ( ! in_array( $language, $languages, true ) ) continue;
					foreach ( (array) $fields as $field => $value ) {
						$field = sanitize_key( $field );
						$value = (string) $value;
						if ( in_array( $field, $skip, true ) || ( isset( $post['old'] ) && $value === (string) ( $post['old'][ $language ][ $field ] ?? '' ) ) ) continue;
						$group = array_merge( array( $field ), array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $post['grp'][ $field ] ?? '' ) ) ) ) );
						foreach ( $group as $key ) { // same text used elsewhere: same translation
							$source = $wpdb->get_var( $wpdb->prepare( "SELECT source_text FROM $table WHERE object_type='string' AND object_id=0 AND field_key=%s LIMIT 1", $key ) ); // phpcs:ignore WordPress.DB
							if ( null === $source ) continue;
							LT_Translation_Store::save( array( 'language_code' => $language, 'object_type' => 'string', 'object_id' => 0, 'field_key' => $key, 'source_text' => $source, 'translated_text' => $value, 'status' => '' === trim( $value ) ? 'empty' : 'translated' ) );
						}
						$saved++;
					}
				}
				$done = 0;
				if ( $keys && 'delete' === $bulk ) {
					$done = self::delete_texts( $keys );
				} elseif ( $keys && in_array( $bulk, array( 'clear', 'skip' ), true ) ) {
					// Remove the translations (every language): the original text is shown again.
					$wpdb->query( $wpdb->prepare( "UPDATE $table SET translated_text='', status='empty' WHERE object_type='string' AND object_id=0 AND field_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys ) ); // phpcs:ignore WordPress.DB
					foreach ( LT_Language_Router::instance()->languages() as $language ) { delete_transient( 'lt_strings_' . $language ); delete_transient( 'lt_wcjs_' . $language ); }
					if ( 'skip' === $bulk ) { $index = $this->index(); foreach ( $keys as $field ) { $index[ $field ] = $index[ $field ] ?? array( 'd' => '' ); $index[ $field ]['x'] = 1; } update_option( LT_Code_Scanner::INDEX, $index, false ); }
					$done = count( $keys );
				} elseif ( $keys && in_array( $bulk, array( 'ignore', 'unignore' ), true ) ) {
					$index = $this->index();
					foreach ( $keys as $field ) { if ( 'ignore' === $bulk ) { $index[ $field ] = ( $index[ $field ] ?? array( 'd' => '' ) ) + array(); $index[ $field ]['x'] = 1; } else unset( $index[ $field ]['x'] ); $done++; }
					update_option( LT_Code_Scanner::INDEX, $index, false );
				}
				LT_Content_Catalog::strings_changed();
				$labels = array( 'clear' => __( '%d translations removed.', 'lightweight-translation' ), 'skip' => __( '%d texts kept in the original language.', 'lightweight-translation' ), 'delete' => __( '%d texts deleted.', 'lightweight-translation' ), 'unignore' => __( '%d texts can be translated again.', 'lightweight-translation' ), 'ignore' => __( '%d texts kept in the original language.', 'lightweight-translation' ) );
				$notice = trim( ( $saved ? sprintf( _n( '%d translation saved.', '%d translations saved.', $saved, 'lightweight-translation' ), $saved ) : '' ) . ' ' . ( $bulk && isset( $labels[ $bulk ] ) ? sprintf( $labels[ $bulk ], 'delete' === $bulk ? count( $keys ) : $done ) : '' ) );
				$redirect = ! empty( $post['redirect'] ) ? esc_url_raw( $post['redirect'] ) : $redirect;
				break;
		}
		wp_safe_redirect( add_query_arg( 'lt_notice', rawurlencode( $notice ?: __( 'Nothing done.', 'lightweight-translation' ) ), $redirect ) );
		exit;
	}
}
