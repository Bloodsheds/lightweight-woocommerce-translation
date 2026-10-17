<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Coverage & help" page: what the plugin translates, where to do it (direct links), what is partial
 * and what is not included. Useful for whoever manages the site.
 */
class LT_Help_Page {
	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() { add_action( 'admin_menu', array( $this, 'menu' ), 22 ); }

	public function menu() { add_submenu_page( 'lt-settings', __( 'Coverage & help', 'lightweight-translation' ), __( 'Coverage & help', 'lightweight-translation' ), lt_capability(), 'lt-help', array( $this, 'page' ) ); }

	private function rows() {
		$a    = function( $page ) { return admin_url( $page ); };
		$wc   = function_exists( 'WC' );
		$rows = array(
			__( 'Content', 'lightweight-translation' ) => array(
				array( 'ok', __( 'Pages & posts (block editor)', 'lightweight-translation' ), __( 'Open the page › "Language / Translation" panel › choose a language › click the text and type › "Save translation". Title, content, excerpt, URL slug.', 'lightweight-translation' ), array( __( 'Pages', 'lightweight-translation' ) => $a( 'edit.php?post_type=page' ), __( 'Posts', 'lightweight-translation' ) => $a( 'edit.php' ) ) ),
				array( 'ok', __( 'Pages built with the classic editor or WPBakery', 'lightweight-translation' ), __( '"Language" selector above the fields + Translations box below the editor.', 'lightweight-translation' ), array() ),
				array( 'ok', __( 'Products', 'lightweight-translation' ), __( 'Title, description, short description, URL slug, theme product tabs (WoodMart). Variation descriptions. Language selector on the product edit screen.', 'lightweight-translation' ), $wc ? array( __( 'Products', 'lightweight-translation' ) => $a( 'edit.php?post_type=product' ) ) : array() ),
				array( 'ok', __( 'Categories, tags, attribute values', 'lightweight-translation' ), __( 'Name, slug, description: "Language" selector at the top of the category form (also when creating), or everything in one table.', 'lightweight-translation' ), array( __( 'Taxonomies & attributes', 'lightweight-translation' ) => $a( 'admin.php?page=lt-taxonomies' ) ) + ( $wc ? array( __( 'Product categories', 'lightweight-translation' ) => $a( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ) : array() ) ),
				array( 'ok', __( 'Product attribute names', 'lightweight-translation' ), __( 'Products › Attributes (Language selector), or the Taxonomies & attributes page.', 'lightweight-translation' ), $wc ? array( __( 'Attributes', 'lightweight-translation' ) => $a( 'edit.php?post_type=product&page=product_attributes' ) ) : array() ),
				array( 'ok', __( 'Gutenberg blocks, synced patterns (reusable blocks)', 'lightweight-translation' ), __( 'Blocks of a page: translated in the page itself (Language panel). Synced patterns are translated once, in the pattern, and everywhere they are used.', 'lightweight-translation' ), array( __( 'Patterns', 'lightweight-translation' ) => $a( 'edit.php?post_type=wp_block' ) ) ),
				array( 'ok', __( 'Theme content types (HTML blocks, slides, popups…)', 'lightweight-translation' ), __( 'Like pages (content).', 'lightweight-translation' ), post_type_exists( 'cms_block' ) ? array( __( 'HTML blocks', 'lightweight-translation' ) => $a( 'edit.php?post_type=cms_block' ) ) : array() ),
				array( 'ok', __( 'Menus', 'lightweight-translation' ), __( 'Links to a page, product or category follow its translation automatically. Custom labels: CSV export "Menu links".', 'lightweight-translation' ), array( __( 'Menus', 'lightweight-translation' ) => $a( 'nav-menus.php' ), __( 'Import / Export', 'lightweight-translation' ) => $a( 'admin.php?page=lt-export' ) ) ),
				array( 'partial', __( 'Product variation names', 'lightweight-translation' ), __( 'Built from the translated product title + attribute values.', 'lightweight-translation' ), array() ),
				array( 'no', __( 'Pages built with Elementor', 'lightweight-translation' ), __( 'Elementor stores its content outside the page text: not translated.', 'lightweight-translation' ), array() ),
				array( 'no', __( 'Product attributes created inside a single product (not global)', 'lightweight-translation' ), __( 'Use global attributes (Products › Attributes) to translate them.', 'lightweight-translation' ), array() ),
			),
			__( 'Shop texts & messages', 'lightweight-translation' ) => array(
				array( 'ok', __( 'WooCommerce, theme and plugin texts', 'lightweight-translation' ), __( 'Standard WooCommerce texts are ready by source and section. Use Check the site to find wording specific to your pages and open its translation.', 'lightweight-translation' ), array( __( 'Interface texts', 'lightweight-translation' ) => $a( 'admin.php?page=lt-strings' ), __( 'Check the site', 'lightweight-translation' ) => $a( 'admin.php?page=lt-scan' ) ) ),
				array( 'ok', __( 'Customer e-mails', 'lightweight-translation' ), __( 'In the language of the order / account. Subject, heading, additional content, body texts, product names.', 'lightweight-translation' ), array( __( 'E-mails', 'lightweight-translation' ) => $a( 'admin.php?page=lt-woocommerce&sub=emails' ) ) ),
				array( 'ok', __( 'Texts displayed by JavaScript (WooCommerce cart & checkout blocks, mini-cart, block themes, modern plugins)', 'lightweight-translation' ), __( 'WooCommerce script texts are included in the standard catalog. Site-specific JavaScript texts can be found by checking the relevant public pages.', 'lightweight-translation' ), array( __( 'Interface texts', 'lightweight-translation' ) => $a( 'admin.php?page=lt-strings' ), __( 'Check the site', 'lightweight-translation' ) => $a( 'admin.php?page=lt-scan' ) ) ),
				array( 'ok', __( 'Any other visible text (custom form fields, page builder labels…)', 'lightweight-translation' ), __( 'Add it on its page with its exact wording: it is replaced on the site.', 'lightweight-translation' ), array() ),
				array( 'ok', __( 'Site texts: theme options, payment & shipping methods, checkout terms', 'lightweight-translation' ), __( 'Options your theme and plugins declare translatable in their wpml-config.xml (copyright, cookie notice…), payment methods (title, description, instructions), shipping methods, checkout privacy / terms texts, store notice, site title: read automatically, under WooCommerce › Shop settings and Theme › Theme options.', 'lightweight-translation' ), array( __( 'Shop settings', 'lightweight-translation' ) => $a( 'admin.php?page=lt-woocommerce&sub=settings' ), __( 'Theme options', 'lightweight-translation' ) => $a( 'admin.php?page=lt-theme&sub=settings' ) ) ),
				array( 'partial', __( 'Other theme options (header builder texts…)', 'lightweight-translation' ), __( 'Only the options the theme declares translatable are included. Tip: put other texts in an HTML block or a widget.', 'lightweight-translation' ), array() ),
				array( 'ok', __( 'Widgets (sidebars, shop filters, footer)', 'lightweight-translation' ), __( 'Titles ("Filter by price", "Stock status"…), Text and Custom HTML widgets, block widgets: read automatically, under Theme › Widgets.', 'lightweight-translation' ), array( __( 'Widget texts', 'lightweight-translation' ) => $a( 'admin.php?page=lt-theme&sub=widgets' ), __( 'Widgets', 'lightweight-translation' ) => $a( 'widgets.php' ) ) ),
				array( 'no', __( 'Customer content (reviews, order notes) and PDF invoices', 'lightweight-translation' ), __( 'Shown as written.', 'lightweight-translation' ), array() ),
			),
			__( 'URLs & SEO', 'lightweight-translation' ) => array(
				array( 'ok', __( 'Language URLs, translated slugs and URL words', 'lightweight-translation' ), __( '/fr/, /en/… Translated slug of every item, translated words like product-category.', 'lightweight-translation' ), array( __( 'URL settings', 'lightweight-translation' ) => $a( 'admin.php?page=lt-preferences#urls' ) ) ),
				array( 'ok', __( 'hreflang, canonical, <html lang>', 'lightweight-translation' ), __( 'Added automatically (canonical left to Yoast / Rank Math when present).', 'lightweight-translation' ), array() ),
				array( 'ok', __( 'Language switcher & first-visit redirection', 'lightweight-translation' ), __( 'Menu item or shortcode; redirection by browser language, cache-proof.', 'lightweight-translation' ), array( __( 'Switcher settings', 'lightweight-translation' ) => $a( 'admin.php?page=lt-preferences#switcher' ) ) ),
				array( 'partial', __( 'SEO titles & meta descriptions (Yoast / Rank Math)', 'lightweight-translation' ), __( 'Titles built from the page title are translated; custom SEO descriptions typed in Yoast / Rank Math are not editable per language yet.', 'lightweight-translation' ), array() ),
				array( 'ok', __( 'Site search (WordPress, WooCommerce and theme live searches)', 'lightweight-translation' ), __( 'On /fr/, the search also looks in the translated titles, contents and descriptions.', 'lightweight-translation' ), array() ),
				array( 'no', __( 'XML sitemaps', 'lightweight-translation' ), __( 'Sitemaps list the original URLs (Google finds the translations through hreflang).', 'lightweight-translation' ), array() ),
			),
			__( 'Tools', 'lightweight-translation' ) => array(
				array( 'ok', __( 'Translate from the site: 🌐 Translate menu', 'lightweight-translation' ), __( 'In the admin bar of any page: translate this page / product / category, or the texts displayed by this page (buttons, messages, widgets, site texts).', 'lightweight-translation' ), array() ),
				array( 'ok', __( 'Find any text', 'lightweight-translation' ), __( 'One search in pages, products, categories, menus, interface texts and e-mails, in every language.', 'lightweight-translation' ), array( __( 'Find a text', 'lightweight-translation' ) => $a( 'admin.php?page=lt-find' ) ) ),
				array( 'ok', __( 'Progress per content type and language', 'lightweight-translation' ), '', array( __( 'Translation status', 'lightweight-translation' ) => $a( 'admin.php?page=lt-status' ) ) ),
				array( 'ok', __( 'Translate everything at once (AI or translator)', 'lightweight-translation' ), __( 'Export what is missing, translate the CSV, import it back.', 'lightweight-translation' ), array( __( 'Import / Export', 'lightweight-translation' ) => $a( 'admin.php?page=lt-export' ) ) ),
				array( 'ok', __( '"Translations" column in Pages / Posts / Products lists', 'lightweight-translation' ), '', array() ),
				array( 'ok', __( 'Debug mode & diagnostic report', 'lightweight-translation' ), __( '🐞 menu in the admin bar on the site (language, URL, translation status of the page, cache), report to copy.', 'lightweight-translation' ), array( __( 'Debug', 'lightweight-translation' ) => $a( 'admin.php?page=lt-preferences#tools' ) ) ),
				array( 'ok', __( 'Reset a translation / clear the page cache', 'lightweight-translation' ), __( 'Reset buttons on every page, product and category; page cache cleared for every language automatically.', 'lightweight-translation' ), array() ),
				array( 'ok', __( 'Hidden language while you translate it', 'lightweight-translation' ), __( 'Not in the switcher nor in Google; editors can preview it at /xx/.', 'lightweight-translation' ), array( __( 'Languages', 'lightweight-translation' ) => $a( 'admin.php?page=lt-preferences#languages' ) ) ),
			),
		);
		return $rows;
	}

	public function page() {
		if ( ! current_user_can( lt_capability() ) ) return;
		$icons = array( 'ok' => array( '✓', '#00a32a', __( 'Translated', 'lightweight-translation' ) ), 'partial' => array( '◐', '#dba617', __( 'Partial', 'lightweight-translation' ) ), 'no' => array( '✗', '#b32d2e', __( 'Not included', 'lightweight-translation' ) ) );
		echo '<style>.lt-help td{vertical-align:top}.lt-help .ic{font-size:18px;font-weight:700;width:28px;text-align:center}.lt-help .links a{display:inline-block;margin:0 8px 4px 0}</style>';
		echo '<div class="wrap lt-help"><h1>' . esc_html__( 'Coverage & help', 'lightweight-translation' ) . '</h1><p>' . esc_html__( 'What Light translations translates on this site, where to do it, and what is not included.', 'lightweight-translation' ) . '</p><p>';
		foreach ( $icons as $icon ) echo '<span style="margin-right:16px"><strong style="color:' . esc_attr( $icon[1] ) . '">' . esc_html( $icon[0] ) . '</strong> ' . esc_html( $icon[2] ) . '</span>';
		echo '</p>';
		foreach ( $this->rows() as $section => $rows ) {
			echo '<h2>' . esc_html( $section ) . '</h2><table class="widefat striped"><tbody>';
			foreach ( $rows as $row ) {
				list( $state, $title, $how, $links ) = $row;
				echo '<tr><td class="ic" style="color:' . esc_attr( $icons[ $state ][1] ) . '" title="' . esc_attr( $icons[ $state ][2] ) . '">' . esc_html( $icons[ $state ][0] ) . '</td><td style="width:30%"><strong>' . esc_html( $title ) . '</strong></td><td>' . esc_html( $how ) . '</td><td class="links" style="width:26%">';
				foreach ( $links as $label => $url ) echo '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
