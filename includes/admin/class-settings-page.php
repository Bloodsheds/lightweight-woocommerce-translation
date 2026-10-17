<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Light translations" settings page: tabs (Languages, Language switcher, URLs & SEO, Editor, Tools),
 * plus the "Translations" column in the pages / posts / products lists.
 */
class LT_Settings_Page {
	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

	private function __construct() {
		add_action( 'admin_post_lt_save_settings', array( $this, 'save' ) );
		add_action( 'admin_post_lt_tools', array( $this, 'tools' ) );
		add_action( 'admin_init', array( $this, 'register_list_columns' ) );
	}


	private function router() { return LT_Language_Router::instance(); }
	private function secondary() { return array_values( array_diff( $this->router()->languages(), array( $this->router()->default_language() ) ) ); }

	/** Translatable URL bases detected on this site: original => description. */
	private function known_bases() {
		$bases = array();
		$first = function( $base ) { $base = trim( (string) $base, '/' ); $part = explode( '/', $base )[0]; return ( $part && false === strpos( $part, '%' ) ) ? sanitize_title( $part ) : ''; };
		if ( function_exists( 'wc_get_permalink_structure' ) ) {
			$wc = wc_get_permalink_structure();
			foreach ( array( 'product_base' => __( 'Products', 'lightweight-translation' ), 'category_base' => __( 'Product categories', 'lightweight-translation' ), 'tag_base' => __( 'Product tags', 'lightweight-translation' ) ) as $key => $label ) { $base = $first( $wc[ $key ] ?? '' ); if ( $base ) $bases[ $base ] = $label; }
		}
		$bases[ $first( get_option( 'category_base' ) ) ?: 'category' ] = __( 'Post categories', 'lightweight-translation' );
		$bases[ $first( get_option( 'tag_base' ) ) ?: 'tag' ] = __( 'Post tags', 'lightweight-translation' );
		return $bases;
	}

	private function saved_bases( $language ) {
		$saved = (array) get_option( 'lt_url_bases', array() );
		$map   = array();
		foreach ( explode( ',', (string) ( $saved[ $language ] ?? '' ) ) as $pair ) { $parts = array_map( 'trim', explode( '=', $pair, 2 ) ); if ( 2 === count( $parts ) && $parts[0] && $parts[1] ) $map[ $parts[0] ] = $parts[1]; }
		return $map;
	}



	private function toggle( $name, $checked, $label, $help = '' ) {
		return '<label class="lt-toggle"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( (bool) $checked, true, false ) . '><span><strong>' . esc_html( $label ) . '</strong>' . ( $help ? '<br><span class="description">' . esc_html( $help ) . '</span>' : '' ) . '</span></label>';
	}

	public function page() {
		if ( ! current_user_can( lt_capability() ) ) return;
		$router    = $this->router();
		$default   = $router->default_language();
		$secondary = $this->secondary();
		$catalog   = LT_Language_Router::catalog();
		// Alphabetical order (accents ignored): language grid, cards and tables.
		$sort_key  = function( $name ) { $name = strtolower( function_exists( 'remove_accents' ) ? remove_accents( $name ) : $name ); return ( preg_match( '/^[a-z]/', $name ) ? '0' : '1' ) . $name; }; // Latin names A–Z, then the other scripts
		uasort( $catalog, function( $a, $b ) use ( $sort_key ) { return strcmp( $sort_key( $a[0] ), $sort_key( $b[0] ) ); } );
		usort( $secondary, function( $a, $b ) use ( $sort_key ) { return strcmp( $sort_key( LT_Language_Router::language_label( $a ) ), $sort_key( LT_Language_Router::language_label( $b ) ) ); } );
		$labels    = (array) get_option( 'lt_language_labels', array() );
		$hidden    = (array) get_option( 'lt_hidden_languages', array() );

		$this->styles();
		echo '<div class="wrap lt-settings-page"><h1>' . esc_html__( 'Settings', 'lightweight-translation' ) . ' <span class="lt-version">v' . esc_html( LT_VERSION ) . '</span> <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=lt-help' ) ) . '">' . esc_html__( 'What is translated?', 'lightweight-translation' ) . '</a></h1>';
		if ( isset( $_GET['lt_notice'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( wp_unslash( $_GET['lt_notice'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification

		$tabs = array( 'languages' => __( 'Languages', 'lightweight-translation' ), 'switcher' => __( 'Language switcher', 'lightweight-translation' ), 'urls' => __( 'Visitors & SEO', 'lightweight-translation' ), 'tools' => __( 'Maintenance', 'lightweight-translation' ) );
		echo '<nav class="nav-tab-wrapper lt-tabs">';
		foreach ( $tabs as $key => $label ) echo '<a href="#' . esc_attr( $key ) . '" class="nav-tab" data-tab="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</a>';
		echo '</nav>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="lt-settings-form">';
		wp_nonce_field( 'lt_save_settings' );
		echo '<input type="hidden" name="action" value="lt_save_settings"><input type="hidden" name="tab" id="lt-current-tab" value="languages">';

		/* ---------- Languages ---------- */
		echo '<section class="lt-panel" data-panel="languages"><h2>' . esc_html__( 'Original language', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'The language your content is written in. It is served without prefix (example.com/), the others under example.com/fr/, example.com/en/…', 'lightweight-translation' ) . '</p>';
		echo '<select name="default" id="lt-default">';
		foreach ( $catalog as $code => $entry ) echo '<option value="' . esc_attr( $code ) . '" ' . selected( $default, $code, false ) . '>' . esc_html( $entry[1] . ' ' . $entry[0] . ' (' . $code . ')' ) . '</option>';
		if ( ! isset( $catalog[ $default ] ) ) echo '<option value="' . esc_attr( $default ) . '" selected>' . esc_html( strtoupper( $default ) ) . '</option>';
		echo '</select> <span class="description lt-warn" id="lt-default-warning" hidden>⚠ ' . esc_html__( 'Changing the original language after translating makes existing translations belong to the wrong language.', 'lightweight-translation' ) . '</span>';

		echo '<h2>' . esc_html__( 'Translation languages', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'Tick the languages your visitors can choose.', 'lightweight-translation' ) . '</p><div class="lt-lang-grid">';
		foreach ( $catalog as $code => $entry ) echo '<label class="lt-lang' . ( in_array( $code, $secondary, true ) ? ' is-on' : '' ) . '" data-code="' . esc_attr( $code ) . '"><input type="checkbox" name="secondary[]" value="' . esc_attr( $code ) . '" ' . checked( in_array( $code, $secondary, true ), true, false ) . '> <span class="lt-flag">' . esc_html( $entry[1] ) . '</span> ' . esc_html( $entry[0] ) . ' <code>' . esc_html( $code ) . '</code></label>';
		echo '</div><p><label>' . esc_html__( 'Other codes (comma separated):', 'lightweight-translation' ) . ' <input type="text" name="secondary_custom" class="regular-text" value="' . esc_attr( implode( ',', array_diff( $secondary, array_keys( $catalog ) ) ) ) . '" placeholder="lb, mt"></label></p>';

		if ( $secondary ) {
			echo '<h2>' . esc_html__( 'Language options', 'lightweight-translation' ) . '</h2><table class="widefat striped lt-lang-table"><thead><tr><th>' . esc_html__( 'Language', 'lightweight-translation' ) . '</th><th>' . esc_html__( 'Name shown to visitors', 'lightweight-translation' ) . '</th><th>' . esc_html__( 'Visibility', 'lightweight-translation' ) . '</th><th>' . esc_html__( 'Address', 'lightweight-translation' ) . '</th></tr></thead><tbody>';
			foreach ( $secondary as $code ) {
				echo '<tr><td><strong>' . esc_html( LT_Language_Router::flag( $code ) . ' ' . strtoupper( $code ) ) . '</strong></td>';
				echo '<td><input type="text" name="labels[' . esc_attr( $code ) . ']" value="' . esc_attr( $labels[ $code ] ?? '' ) . '" placeholder="' . esc_attr( $catalog[ $code ][0] ?? strtoupper( $code ) ) . '"></td>';
				echo '<td><select name="visibility[' . esc_attr( $code ) . ']"><option value="public">' . esc_html__( 'Public', 'lightweight-translation' ) . '</option><option value="hidden" ' . selected( in_array( $code, $hidden, true ), true, false ) . '>' . esc_html__( 'Hidden – in preparation (editors only)', 'lightweight-translation' ) . '</option></select></td>';
				echo '<td><code>' . esc_html( trailingslashit( home_url( $code ) ) ) . '</code></td></tr>';
			}
			echo '</tbody></table><p class="description">' . esc_html__( '"Hidden": the language is not in the switcher nor in hreflang tags, and visitors are sent back to the original. Logged-in editors can still open it to check the translation before publishing it.', 'lightweight-translation' ) . '</p>';
		}
		echo '<h2>' . esc_html__( 'Admin lists', 'lightweight-translation' ) . '</h2>';
		echo $this->toggle( 'list_column', get_option( 'lt_list_column', 1 ), __( 'Show a "Translations" column in Pages, Posts and Products lists', 'lightweight-translation' ), __( 'One flag per language: green translated, orange outdated, red missing. Click to translate.', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</section>';

		/* ---------- Switcher ---------- */
		echo '<section class="lt-panel" data-panel="switcher"><h2>' . esc_html__( 'Appearance', 'lightweight-translation' ) . '</h2><div class="lt-two"><div>';
		echo $this->toggle( 'show_flag', get_option( 'lt_selector_show_flag', 1 ), __( 'Show flags', 'lightweight-translation' ) );
		echo $this->toggle( 'show_name', get_option( 'lt_selector_show_name', 1 ), __( 'Show language names', 'lightweight-translation' ), __( 'Otherwise the code is shown (FR, EN…).', 'lightweight-translation' ) );
		echo $this->toggle( 'include_default', get_option( 'lt_selector_include_default', 1 ), __( 'Include the original language', 'lightweight-translation' ) );
		echo '<p><label><strong>' . esc_html__( 'Name format', 'lightweight-translation' ) . '</strong><br><select name="label_mode" id="lt-label-mode"><option value="native" ' . selected( get_option( 'lt_language_label_mode', 'native' ), 'native', false ) . '>' . esc_html__( 'Native name (Français, English)', 'lightweight-translation' ) . '</option><option value="code" ' . selected( get_option( 'lt_language_label_mode', 'native' ), 'code', false ) . '>' . esc_html__( 'ISO code (FR, EN)', 'lightweight-translation' ) . '</option></select></label></p>';
		echo '</div><div><strong>' . esc_html__( 'Preview', 'lightweight-translation' ) . '</strong><div class="lt-preview" id="lt-preview"></div></div></div>';
		echo '<h2>' . esc_html__( 'Where to display it', 'lightweight-translation' ) . '</h2><ol class="lt-steps"><li>' . wp_kses_post( sprintf( __( '<strong>In a menu</strong> (recommended): <a href="%s">Appearance › Menus</a>, box "Language selector" › Add to menu. Then choose a dropdown or a bulleted list in the menu item settings.', 'lightweight-translation' ), esc_url( admin_url( 'nav-menus.php' ) ) ) ) . '</li><li>' . esc_html__( 'Anywhere else (widget, block, header builder): paste this shortcode', 'lightweight-translation' ) . ' <code id="lt-shortcode">[lt_language_switcher]</code> <button type="button" class="button button-small" data-copy="lt-shortcode">' . esc_html__( 'Copy', 'lightweight-translation' ) . '</button><br><span class="description">' . esc_html__( 'Plain dropdown:', 'lightweight-translation' ) . ' <code>[lt_language_switcher style="plain"]</code> · ' . esc_html__( 'Bulleted list (for a footer):', 'lightweight-translation' ) . ' <code>[lt_language_switcher style="list"]</code></span></li></ol></section>';

		/* ---------- URLs & SEO ---------- */
		$bases = $this->known_bases();
		echo '<section class="lt-panel" data-panel="urls"><h2>' . esc_html__( 'Visitors', 'lightweight-translation' ) . '</h2>';
		echo $this->toggle( 'sticky', get_option( 'lt_sticky_language', 1 ), __( 'Keep visitors in the language they chose', 'lightweight-translation' ), __( 'After choosing a language, a visitor who lands on a page of the original language (Back button, old link, e-mail, search result) sees it in his language. Editors are never redirected.', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $this->toggle( 'redirect', get_option( 'lt_auto_redirect', 0 ), __( 'Redirect first-time visitors to their browser language', 'lightweight-translation' ), __( 'Only on the first visit; afterwards the language they choose is remembered.', 'lightweight-translation' ) );
		echo '<h2>' . esc_html__( 'Search engines', 'lightweight-translation' ) . '</h2>';
		$seo_plugin = defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
		echo $this->toggle( 'seo_tags', get_option( 'lt_seo_tags', 1 ), __( 'Add hreflang tags (and canonical)', 'lightweight-translation' ), $seo_plugin ? __( 'An SEO plugin is active: it keeps its canonical, this plugin only adds hreflang.', 'lightweight-translation' ) : __( 'Tells Google which page is the translation of which.', 'lightweight-translation' ) );
		if ( $secondary ) {
			echo '<h2>' . esc_html__( 'Translated URL words', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'Example: /fr/product-category/gloves/ → /fr/categorie-produit/gants/ (the last word is the translated slug of the item itself). Leave empty to keep the original word. Only lowercase letters, numbers and hyphens.', 'lightweight-translation' ) . '</p>';
			echo '<table class="widefat striped lt-bases"><thead><tr><th>' . esc_html__( 'Original word', 'lightweight-translation' ) . '</th>';
			foreach ( $secondary as $code ) echo '<th>' . esc_html( LT_Language_Router::flag( $code ) . ' ' . strtoupper( $code ) ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $bases as $base => $label ) {
				echo '<tr><td><code>' . esc_html( $base ) . '</code><br><span class="description">' . esc_html( $label ) . '</span></td>';
				foreach ( $secondary as $code ) { $map = $this->saved_bases( $code ); echo '<td><input type="text" name="bases[' . esc_attr( $code ) . '][' . esc_attr( $base ) . ']" value="' . esc_attr( $map[ $base ] ?? '' ) . '" placeholder="' . esc_attr( $base ) . '"></td>'; }
				echo '</tr>';
			}
			// Words added by hand: one line each, like the detected ones (+ a button to add more).
			$extra = array();
			foreach ( $secondary as $code ) foreach ( $this->saved_bases( $code ) as $original => $translated ) if ( ! isset( $bases[ $original ] ) ) $extra[ $original ][ $code ] = $translated;
			$extra[''] = array(); // one empty line ready to fill
			foreach ( $extra as $original => $values ) {
				echo '<tr class="lt-base-extra"><td><input type="text" name="extra_word[]" value="' . esc_attr( $original ) . '" placeholder="' . esc_attr__( 'original word, e.g. shop', 'lightweight-translation' ) . '" style="font-family:monospace"></td>';
				foreach ( $secondary as $code ) echo '<td><input type="text" name="extra_tr[' . esc_attr( $code ) . '][]" value="' . esc_attr( $values[ $code ] ?? '' ) . '"></td>';
				echo '</tr>';
			}
			echo '</tbody></table><p><button type="button" class="button" id="lt-add-base">+ ' . esc_html__( 'Add a URL word', 'lightweight-translation' ) . '</button> <span class="description">' . esc_html__( 'Any word of your addresses (e.g. the shop page "shop"). Empty the original word to remove a line.', 'lightweight-translation' ) . '</span></p>';
			echo '<script>document.getElementById("lt-add-base").addEventListener("click",function(){var rows=document.querySelectorAll(".lt-base-extra"),last=rows[rows.length-1],row=last.cloneNode(true);row.querySelectorAll("input").forEach(function(i){i.value="";});last.parentNode.appendChild(row);row.querySelector("input").focus();});</script>';
		}
		echo '</section>';

		/* ---------- Editor ---------- */

		echo '<div class="lt-savebar" id="lt-savebar">';
		submit_button( __( 'Save settings', 'lightweight-translation' ), 'primary', 'submit', false );
		echo '</div></form>';

		/* ---------- Tools (separate forms) ---------- */
		global $wpdb;
		$counts = $wpdb->get_results( 'SELECT language_code, COUNT(*) AS total, SUM(status IN (\'translated\',\'reviewed\')) AS live FROM ' . LT_Translation_Store::table() . ' GROUP BY language_code' ); // phpcs:ignore WordPress.DB
		echo '<section class="lt-panel" data-panel="tools"><h2>' . esc_html__( 'Stored translations', 'lightweight-translation' ) . '</h2><table class="widefat striped" style="max-width:520px"><thead><tr><th>' . esc_html__( 'Language', 'lightweight-translation' ) . '</th><th>' . esc_html__( 'Translations', 'lightweight-translation' ) . '</th><th>' . esc_html__( 'Rows', 'lightweight-translation' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $counts as $row ) echo '<tr><td>' . esc_html( LT_Language_Router::flag( $row->language_code ) . ' ' . strtoupper( $row->language_code ) ) . ( in_array( $row->language_code, $router->languages(), true ) ? '' : ' <span class="lt-pill">' . esc_html__( 'not active', 'lightweight-translation' ) . '</span>' ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->live ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->total ) ) . '</td></tr>';
		if ( ! $counts ) echo '<tr><td colspan="3">' . esc_html__( 'No translation yet.', 'lightweight-translation' ) . '</td></tr>';
		echo '</tbody></table>';
		$tool = function( $action, $label, $help, $class = 'button', $extra = '', $confirm = '' ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lt-tool"' . ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>';
			wp_nonce_field( 'lt_tools' );
			echo '<input type="hidden" name="action" value="lt_tools"><input type="hidden" name="tool" value="' . esc_attr( $action ) . '">' . $extra . '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button><p class="description">' . esc_html( $help ) . '</p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};
		echo '<h2>' . esc_html__( 'Maintenance', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'Use these actions to refresh caches, fix URLs or remove data you no longer need.', 'lightweight-translation' ) . '</p>';
		$tool( 'flush', __( 'Refresh language URLs', 'lightweight-translation' ), __( 'If a /fr/… page shows a 404 after changing languages or URL words.', 'lightweight-translation' ) );
		$tool( 'purge_cache', __( 'Clear the page cache (all languages)', 'lightweight-translation' ), __( 'If a translated page (/fr/…) still shows old content or old links: clears LiteSpeed / Hostinger, WP Rocket, W3 Total Cache or WP Super Cache. Done automatically for a page when it or its translation is saved.', 'lightweight-translation' ) );
		$tool( 'cache', __( 'Recalculate translation status', 'lightweight-translation' ), __( 'Clears the cached progress figures.', 'lightweight-translation' ) );
		$tool( 'orphans', __( 'Delete orphan translations', 'lightweight-translation' ), __( 'Removes translations of pages, products, categories or attributes that no longer exist.', 'lightweight-translation' ), 'button', '', __( 'Delete translations of deleted content?', 'lightweight-translation' ) );
		$options = '<select name="language">';
		foreach ( (array) $counts as $row ) if ( $row->language_code !== $router->default_language() ) $options .= '<option value="' . esc_attr( $row->language_code ) . '">' . esc_html( LT_Language_Router::flag( $row->language_code ) . ' ' . strtoupper( $row->language_code ) ) . '</option>';
		$options .= '</select> ';
		// Debug.
		$debug = (bool) get_option( 'lt_debug_mode', 0 );
		echo '<h2>' . esc_html__( 'Debug', 'lightweight-translation' ) . '</h2>';
		$tool( 'debug_toggle', $debug ? __( 'Turn debug mode off', 'lightweight-translation' ) : __( 'Turn debug mode on', 'lightweight-translation' ), __( 'Adds a 🐞 menu in the admin bar of every page of the site (administrators only): detected language, URL analysis, translation status of the page (translated / outdated / missing for each field), search matches, page cache, other-language URLs.', 'lightweight-translation' ), $debug ? 'button button-primary' : 'button' );
		echo '<p>';
		foreach ( $router->languages() as $language ) echo '<a class="button button-small" target="_blank" href="' . esc_url( add_query_arg( 'lt_debug', 1, home_url( $language === $router->default_language() ? '/' : '/' . $language . '/' ) ) ) . '">' . esc_html( LT_Language_Router::flag( $language ) . ' ' . sprintf( __( 'Open %s with debug', 'lightweight-translation' ), strtoupper( $language ) ) ) . ' ↗</a> ';
		echo '</p><p><strong>' . esc_html__( 'Diagnostic report', 'lightweight-translation' ) . '</strong> <span class="description">' . esc_html__( '(copy it and send it when something does not work)', 'lightweight-translation' ) . '</span></p><textarea id="lt-diagnostic" readonly rows="14" class="large-text code" onclick="this.select()">' . esc_textarea( $this->diagnostics() ) . '</textarea><p><button type="button" class="button" data-copy="lt-diagnostic">' . esc_html__( 'Copy', 'lightweight-translation' ) . '</button></p>';

		echo '<h2 class="lt-danger-title">' . esc_html__( 'Danger zone', 'lightweight-translation' ) . '</h2>';
		if ( $counts ) $tool( 'delete_language', __( 'Delete all translations of this language', 'lightweight-translation' ), __( 'Irreversible. Download a full backup first (below).', 'lightweight-translation' ), 'button button-link-delete', $options, __( 'Delete ALL translations of this language? This cannot be undone.', 'lightweight-translation' ) );
		LT_Data_Lifecycle::reset_form();
		LT_Data_Lifecycle::backup_section();
		echo '</section></div>';
		$this->script( $catalog );
	}

	/** Plain-text report of everything useful to understand a problem. */
	private function diagnostics() {
		global $wpdb, $wp_version;
		$router = $this->router();
		$theme  = wp_get_theme();
		$rules  = (array) get_option( 'rewrite_rules', array() );
		$rule   = '';
		foreach ( array_keys( $rules ) as $regex ) if ( false !== strpos( $regex, implode( '|', array_map( 'preg_quote', $router->languages() ) ) ) ) { $rule = $regex; break; }
		$table  = LT_Translation_Store::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		$counts = $exists ? $wpdb->get_results( "SELECT language_code, object_type, COUNT(*) AS n FROM $table WHERE status IN ('translated','reviewed') GROUP BY language_code, object_type" ) : array(); // phpcs:ignore WordPress.DB
		$by     = array();
		foreach ( (array) $counts as $c ) $by[] = $c->language_code . '/' . $c->object_type . '=' . $c->n;
		$plugins = array();
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) { $data = get_plugins()[ $file ] ?? null; $plugins[] = $data ? $data['Name'] . ' ' . $data['Version'] : $file; }
		$lines = array(
			'Light translations ' . LT_VERSION . ' · WordPress ' . $wp_version . ' · WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '-' ) . ' · PHP ' . PHP_VERSION,
			'Theme: ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $theme->parent() ? ' (child of ' . $theme->parent()->get( 'Name' ) . ' ' . $theme->parent()->get( 'Version' ) . ')' : '' ),
			'Home: ' . home_url( '/' ) . ' · permalinks: ' . ( get_option( 'permalink_structure' ) ?: 'plain (language URLs need pretty permalinks!)' ),
			'Languages: original=' . $router->default_language() . ' · translations=' . implode( ',', $this->secondary() ) . ' · hidden=' . implode( ',', (array) get_option( 'lt_hidden_languages', array() ) ),
			'URL words: ' . wp_json_encode( get_option( 'lt_url_bases', array() ) ),
			'Front page: show_on_front=' . get_option( 'show_on_front' ) . ' page_on_front=' . get_option( 'page_on_front' ) . ' page_for_posts=' . get_option( 'page_for_posts' ),
			'Rewrite rule: ' . ( $rule ? 'OK ' . $rule : 'MISSING → Tools › "Refresh language URLs"' ),
			'Table ' . $table . ': ' . ( $exists ? 'OK · ' . ( $by ? implode( ', ', $by ) : 'empty' ) : 'MISSING (deactivate / reactivate the plugin)' ),
			'Options: redirect=' . get_option( 'lt_auto_redirect', 0 ) . ' seo_tags=' . get_option( 'lt_seo_tags', 1 ) . ' list_column=' . get_option( 'lt_list_column', 1 ) . ' collect=' . get_option( 'lt_collect_strings', 0 ) . ' verify_until=' . ( (int) get_option( 'lt_verify_until', 0 ) > time() ? gmdate( 'Y-m-d', (int) get_option( 'lt_verify_until' ) ) : 'off' ) . ' debug=' . get_option( 'lt_debug_mode', 0 ),
			'Text domains: ' . implode( ', ', LT_Frontend_Filters::domains() ) . ' · scanned/collected texts: ' . count( (array) get_option( 'lt_string_index', array() ) ),
			'Page cache: ' . implode( ', ', array_keys( array_filter( array( 'LiteSpeed' => defined( 'LSCWP_V' ), 'WP Rocket' => defined( 'WP_ROCKET_VERSION' ), 'W3 Total Cache' => defined( 'W3TC' ), 'WP Super Cache' => function_exists( 'wp_cache_clear_cache' ) ) ) ) ) . ' · object cache: ' . ( wp_using_ext_object_cache() ? 'yes' : 'no' ),
			'WP_DEBUG: ' . ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'on' : 'off' ) . ' · locale: ' . get_locale(),
			'Active plugins: ' . implode( ', ', $plugins ),
		);
		return implode( "\n", $lines );
	}

	private function styles() {
		echo '<style>
.lt-settings-page .lt-version{font-size:12px;color:#646970;font-weight:400;vertical-align:middle}
.lt-cards{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0 8px}
.lt-search{display:flex;align-items:center;gap:8px;max-width:760px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:8px 12px;margin:8px 0 20px}.lt-search input[type=search]{flex:1;border:0;box-shadow:none;font-size:15px;padding:6px}.lt-search .dashicons{color:#8c8f94}
.lt-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;min-width:170px}
.lt-card-label{color:#50575e;font-size:13px}.lt-card-value{font-size:22px;font-weight:600;margin:4px 0}
.lt-progress{height:6px;background:#f0f0f1;border-radius:3px;overflow:hidden;margin-bottom:6px}.lt-progress span{display:block;height:100%;background:#00a32a}
.lt-pill{display:inline-block;background:#f0f0f1;border-radius:10px;padding:0 7px;font-size:11px;color:#50575e}
.lt-quick .button .dashicons{font-size:16px;line-height:1.8;width:16px;height:16px}
.lt-panel{display:none;background:#fff;border:1px solid #dcdcde;border-top:0;padding:4px 20px 20px}.lt-panel.is-active{display:block}
.lt-panel h2{margin-top:24px}
.lt-lang-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px;margin:10px 0}
.lt-lang{border:1px solid #dcdcde;border-radius:6px;padding:7px 10px;cursor:pointer;background:#fff}.lt-lang.is-on{border-color:#2271b1;background:#f0f6fc}.lt-lang.is-default{opacity:.45;pointer-events:none}
.lt-lang code{font-size:11px;float:right;background:none;color:#646970}.lt-flag{font-size:16px}
.lt-toggle{display:flex;gap:10px;align-items:flex-start;margin:10px 0}.lt-toggle input{margin-top:3px}
.lt-two{display:grid;grid-template-columns:minmax(260px,1fr) minmax(260px,1fr);gap:24px}
.lt-preview{margin-top:8px;padding:16px;border:1px dashed #c3c4c7;border-radius:8px;background:#f6f7f7}
.lt-preview .lt-pv-current{display:inline-flex;gap:6px;align-items:center;border:1px solid #c3c4c7;border-radius:6px;padding:6px 10px;background:#fff}
.lt-preview ul{margin:6px 0 0;padding:6px;background:#fff;border:1px solid #dcdcde;border-radius:6px;display:inline-block;min-width:140px}.lt-preview li{margin:0;padding:4px 6px}
.lt-bases input,.lt-lang-table input{width:100%}
.lt-savebar{position:sticky;bottom:0;background:#fff;border:1px solid #dcdcde;border-top:0;padding:12px 20px;z-index:5}
.lt-savebar.is-hidden{display:none}
.lt-tool{margin:12px 0}.lt-tool .description{margin:4px 0 0}
.lt-danger-title{color:#b32d2e}.lt-warn{color:#b32d2e}
@media (max-width:782px){.lt-two{grid-template-columns:1fr}}
</style>';
	}

	private function script( $catalog ) {
		$data = array( 'catalog' => $catalog, 'default' => $this->router()->default_language(), 'labels' => (array) get_option( 'lt_language_labels', array() ) );
		echo '<script>(function(){var D=' . wp_json_encode( $data ) . ';
var tabs=document.querySelectorAll(".lt-tabs .nav-tab"),panels=document.querySelectorAll(".lt-panel"),bar=document.getElementById("lt-savebar"),cur=document.getElementById("lt-current-tab");
function show(t){if(!document.querySelector(\'.lt-panel[data-panel="\'+t+\'"]\'))t="languages";tabs.forEach(function(a){a.classList.toggle("nav-tab-active",a.dataset.tab===t);});panels.forEach(function(p){p.classList.toggle("is-active",p.dataset.panel===t);});bar.classList.toggle("is-hidden",t==="tools");cur.value=t;try{history.replaceState(null,"","#"+t);}catch(e){}}
tabs.forEach(function(a){a.addEventListener("click",function(e){e.preventDefault();show(a.dataset.tab);});});
var q=new URLSearchParams(location.search);show((location.hash||"").slice(1)||q.get("tab")||"languages");
var def=document.getElementById("lt-default"),warn=document.getElementById("lt-default-warning"),orig=def.value;
function syncDefault(){document.querySelectorAll(".lt-lang").forEach(function(l){var on=l.dataset.code===def.value;l.classList.toggle("is-default",on);if(on){l.querySelector("input").checked=false;l.classList.remove("is-on");}});warn.hidden=def.value===orig;preview();}
def.addEventListener("change",syncDefault);
document.querySelectorAll(".lt-lang input").forEach(function(i){i.addEventListener("change",function(){i.parentNode.classList.toggle("is-on",i.checked);preview();});});
function name(c){var mode=document.getElementById("lt-label-mode").value;if(mode==="code")return c.toUpperCase();return D.labels[c]||(D.catalog[c]?D.catalog[c][0]:c.toUpperCase());}
function flag(c){return D.catalog[c]?D.catalog[c][1]:"🌐";}
function label(c){var f=document.querySelector("[name=show_flag]").checked,n=document.querySelector("[name=show_name]").checked;return (f?flag(c)+" ":"")+(n?name(c):c.toUpperCase());}
function preview(){var p=document.getElementById("lt-preview");if(!p)return;var d=def.value,langs=[d];document.querySelectorAll(".lt-lang input:checked").forEach(function(i){langs.push(i.value);});
if(!document.querySelector("[name=include_default]").checked)langs=langs.filter(function(c){return c!==d;});
var current=langs[0]||d;p.innerHTML="";var c=document.createElement("span");c.className="lt-pv-current";c.textContent=label(current)+" ▾";p.appendChild(c);var ul=document.createElement("ul");langs.filter(function(x){return x!==current;}).forEach(function(x){var li=document.createElement("li");li.textContent=label(x);ul.appendChild(li);});p.appendChild(document.createElement("br"));if(ul.children.length)p.appendChild(ul);}
document.querySelectorAll("[name=show_flag],[name=show_name],[name=include_default],#lt-label-mode").forEach(function(i){i.addEventListener("change",preview);});
document.querySelectorAll("[data-copy]").forEach(function(b){b.addEventListener("click",function(){var t=document.getElementById(b.dataset.copy).textContent;(navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(function(){b.textContent="✓";},function(){});});});
syncDefault();
})();</script>';
	}

	public function save() {
		if ( ! current_user_can( lt_capability() ) || ! check_admin_referer( 'lt_save_settings' ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$before  = array( get_option( 'lt_default_language', 'pt' ), get_option( 'lt_secondary_languages', 'fr,en' ), get_option( 'lt_url_bases', array() ) );
		$default = sanitize_key( $post['default'] ?? 'pt' ) ?: 'pt';
		$codes   = array_map( 'sanitize_key', (array) ( $post['secondary'] ?? array() ) );
		foreach ( explode( ',', (string) ( $post['secondary_custom'] ?? '' ) ) as $code ) $codes[] = sanitize_key( trim( $code ) );
		$codes = array_values( array_unique( array_filter( $codes, function( $code ) use ( $default ) { return $code && $code !== $default && strlen( $code ) <= 12; } ) ) );
		update_option( 'lt_default_language', $default );
		update_option( 'lt_secondary_languages', implode( ',', $codes ) );

		$labels = array(); $hidden = array();
		foreach ( $codes as $code ) {
			$label = sanitize_text_field( $post['labels'][ $code ] ?? '' );
			if ( '' !== $label ) $labels[ $code ] = $label;
			if ( 'hidden' === ( $post['visibility'][ $code ] ?? '' ) ) $hidden[] = $code;
		}
		update_option( 'lt_language_labels', $labels );
		update_option( 'lt_hidden_languages', $hidden );

		foreach ( array( 'show_flag' => 'lt_selector_show_flag', 'show_name' => 'lt_selector_show_name', 'include_default' => 'lt_selector_include_default', 'redirect' => 'lt_auto_redirect', 'sticky' => 'lt_sticky_language', 'seo_tags' => 'lt_seo_tags', 'list_column' => 'lt_list_column' ) as $field => $option ) update_option( $option, empty( $post[ $field ] ) ? 0 : 1 );
		update_option( 'lt_language_label_mode', 'code' === ( $post['label_mode'] ?? '' ) ? 'code' : 'native' );

		if ( isset( $post['bases'] ) || isset( $post['bases_extra'] ) || isset( $post['extra_word'] ) ) {
			$bases = array();
			foreach ( $codes as $code ) {
				$pairs = array();
				foreach ( (array) ( $post['bases'][ $code ] ?? array() ) as $original => $translated ) { $original = sanitize_title( $original ); $translated = sanitize_title( $translated ); if ( $original && $translated && $original !== $translated ) $pairs[ $original ] = $translated; }
				foreach ( (array) ( $post['extra_word'] ?? array() ) as $i => $original ) { $original = sanitize_title( $original ); $translated = sanitize_title( (string) ( $post['extra_tr'][ $code ][ $i ] ?? '' ) ); if ( $original && $translated && $original !== $translated ) $pairs[ $original ] = $translated; }
				foreach ( explode( ',', (string) ( $post['bases_extra'][ $code ] ?? '' ) ) as $pair ) { $parts = array_map( 'trim', explode( '=', $pair, 2 ) ); if ( 2 === count( $parts ) && sanitize_title( $parts[0] ) && sanitize_title( $parts[1] ) ) $pairs[ sanitize_title( $parts[0] ) ] = sanitize_title( $parts[1] ); }
				$bases[ $code ] = implode( ', ', array_map( function( $o, $t ) { return $o . '=' . $t; }, array_keys( $pairs ), $pairs ) );
			}
			update_option( 'lt_url_bases', $bases );
		}
		if ( array( get_option( 'lt_default_language' ), get_option( 'lt_secondary_languages' ), get_option( 'lt_url_bases', array() ) ) !== $before ) update_option( 'lt_flush_rewrite_rules', 1 );
		// Progress is counted again only when the languages change (other settings do not affect it).
		if ( array( get_option( 'lt_default_language' ), get_option( 'lt_secondary_languages' ) ) !== array_slice( $before, 0, 2 ) ) LT_Content_Catalog::flush_summary();
		$tab = sanitize_key( $post['tab'] ?? 'languages' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'lt-preferences', 'lt_notice' => rawurlencode( __( 'Settings saved.', 'lightweight-translation' ) ) ), admin_url( 'admin.php' ) ) . '#' . $tab );
		exit;
	}

	public function tools() {
		if ( ! current_user_can( lt_capability() ) || ! check_admin_referer( 'lt_tools' ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		global $wpdb;
		$table  = LT_Translation_Store::table();
		$tool   = sanitize_key( $_POST['tool'] ?? '' );
		$notice = '';
		switch ( $tool ) {
			case 'flush':
				flush_rewrite_rules( false );
				$notice = __( 'Language URLs refreshed.', 'lightweight-translation' );
				break;
			case 'debug_toggle':
				$on = ! get_option( 'lt_debug_mode', 0 );
				update_option( 'lt_debug_mode', $on ? 1 : 0 );
				$notice = $on ? __( 'Debug mode on: open the site, a 🐞 menu appears in the admin bar.', 'lightweight-translation' ) : __( 'Debug mode off.', 'lightweight-translation' );
				break;
			case 'purge_cache':
				LT_Translation_Reset::purge_all();
				$notice = __( 'Page cache cleared (all languages).', 'lightweight-translation' );
				break;
			case 'cache':
				LT_Content_Catalog::flush_summary();
				$notice = __( 'Translation status will be recalculated.', 'lightweight-translation' );
				break;
			case 'orphans':
				$deleted  = (int) $wpdb->query( "DELETE t FROM $table t LEFT JOIN {$wpdb->posts} p ON p.ID = t.object_id WHERE t.object_type NOT IN ('term','attribute','string','email') AND p.ID IS NULL" ); // phpcs:ignore WordPress.DB
				$deleted += (int) $wpdb->query( "DELETE FROM $table WHERE object_type = 'revision'" ); // phpcs:ignore WordPress.DB
				$deleted += (int) $wpdb->query( "DELETE t FROM $table t LEFT JOIN {$wpdb->terms} x ON x.term_id = t.object_id WHERE t.object_type = 'term' AND x.term_id IS NULL" ); // phpcs:ignore WordPress.DB
				$attributes = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $attributes ) ) === $attributes ) $deleted += (int) $wpdb->query( "DELETE t FROM $table t LEFT JOIN $attributes a ON a.attribute_id = t.object_id WHERE t.object_type = 'attribute' AND a.attribute_id IS NULL" ); // phpcs:ignore WordPress.DB
				LT_Content_Catalog::flush_summary();
				$notice = sprintf( __( '%d orphan translations deleted.', 'lightweight-translation' ), $deleted );
				break;
			case 'delete_language':
				$language = sanitize_key( $_POST['language'] ?? '' );
				if ( $language && $language !== $this->router()->default_language() ) {
					$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE language_code = %s", $language ) ); // phpcs:ignore WordPress.DB
					LT_Content_Catalog::flush_summary();
					$notice = sprintf( __( '%1$d translations deleted for %2$s.', 'lightweight-translation' ), $deleted, strtoupper( $language ) );
				}
				break;
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'lt-preferences', 'lt_notice' => rawurlencode( $notice ?: __( 'Nothing done.', 'lightweight-translation' ) ) ), admin_url( 'admin.php' ) ) . '#tools' );
		exit;
	}

	/* ---------- "Translations" column in lists ---------- */

	public function register_list_columns() {
		if ( ! get_option( 'lt_list_column', 1 ) || ! $this->secondary() ) return;
		foreach ( array( 'page', 'post', 'product' ) as $type ) {
			if ( ! post_type_exists( $type ) ) continue;
			add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ), 20 );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		}
	}

	public function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( in_array( $key, array( 'title', 'name' ), true ) ) $out['lt_translations'] = '<span class="dashicons dashicons-translation" title="' . esc_attr__( 'Translations', 'lightweight-translation' ) . '"></span>';
		}
		if ( ! isset( $out['lt_translations'] ) ) $out['lt_translations'] = '<span class="dashicons dashicons-translation"></span>';
		return $out;
	}

	public function render_column( $column, $post_id ) {
		if ( 'lt_translations' !== $column ) return;
		$item = LT_Content_Catalog::item( 'post:' . get_post_type( $post_id ), $post_id );
		if ( ! $item || ! $item['fields'] ) { echo '—'; return; }
		foreach ( LT_Content_Catalog::item_progress( $item, $this->secondary() ) as $language => $p ) {
			$state = $p['missing'] ? 'missing' : ( $p['outdated'] ? 'outdated' : 'ok' );
			$color = array( 'ok' => '#00a32a', 'outdated' => '#dba617', 'missing' => '#d63638' )[ $state ];
			$title = strtoupper( $language ) . ': ' . ( 'ok' === $state ? __( 'translated', 'lightweight-translation' ) : implode( ', ', $p['fields'] ) );
			$url   = $item['block_editor'] ? add_query_arg( 'lt_lang', $language, $item['edit'] ) : $item['edit'];
			echo '<a href="' . esc_url( $url ) . '" title="' . esc_attr( $title ) . '" style="display:inline-block;margin:0 3px 3px 0;padding:1px 5px;border-radius:4px;font-size:11px;text-decoration:none;border:1px solid ' . esc_attr( $color ) . ';color:' . esc_attr( $color ) . '">' . esc_html( LT_Language_Router::flag( $language ) . ' ' . strtoupper( $language ) ) . '</a>';
		}
	}
}
