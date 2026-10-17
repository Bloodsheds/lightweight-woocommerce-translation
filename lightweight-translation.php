<?php
/**
 * Plugin Name: Lightweight Translation
 * Description: Simple, lightweight translations for WordPress, with optional WooCommerce and theme integrations.
 * Version: 0.46.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: lightweight-translation
 */
defined( 'ABSPATH' ) || exit;

define( 'LT_VERSION', '0.46.0' );
define( 'LT_FILE', __FILE__ );
define( 'LT_DIR', plugin_dir_path( __FILE__ ) );

/** Classes load when used; optional integrations have their own directories. */
spl_autoload_register( function( $class ) {
	if ( 0 !== strpos( $class, 'LT_' ) || ! preg_match( '/^LT_[A-Za-z_]+$/', $class ) ) return;
	$modules = array(
		'LT_WooCommerce_Module' => 'woocommerce/class-woocommerce-module.php',
		'LT_WooCommerce_Attribute_Editor' => 'woocommerce/class-woocommerce-attribute-editor.php',
		'LT_WooCommerce_Frontend' => 'woocommerce/class-woocommerce-frontend.php',
		'LT_WooCommerce_Text_Groups' => 'woocommerce/class-woocommerce-text-groups.php',
		'LT_WooCommerce_Catalog' => 'woocommerce/class-woocommerce-catalog.php',
		'LT_Email_Translations' => 'woocommerce/class-email-translations.php',
		'LT_Email_Page' => 'woocommerce/class-email-page.php',
		'LT_WoodMart_Module' => 'woodmart/class-woodmart-module.php',
		'LT_WoodMart_Compat' => 'woodmart/class-woodmart-compat.php',
		'LT_Product_Bundles_Module' => 'product-bundles/class-product-bundles-module.php',
	);
	$file = isset( $modules[ $class ] ) ? LT_DIR . 'modules/' . $modules[ $class ] : LT_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', substr( $class, 3 ) ) ) . '.php';
	if ( is_readable( $file ) ) require_once $file;
} );

function lt_capability() {
	return (string) apply_filters( 'lt_capability', class_exists( 'WooCommerce', false ) ? 'manage_woocommerce' : 'manage_options' );
}
function lt_activate() {
	LT_Translation_Store::install();
	update_option( 'lt_flush_rewrite_rules', 1 );
}
function lt_deactivate() { flush_rewrite_rules(); }
register_activation_hook( __FILE__, 'lt_activate' );
register_deactivation_hook( __FILE__, 'lt_deactivate' );

add_action( 'plugins_loaded', function() {
	if ( get_option( 'lt_version' ) !== LT_VERSION ) {
		LT_Translation_Store::install();
		// Existing menu selectors used the plugin's boxed dropdown by default; use the theme-native menu now.
		global $wpdb;
		$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'theme' ), array( 'meta_key' => '_lt_switcher_style', 'meta_value' => 'boxed' ), array( '%s' ), array( '%s', '%s' ) );
		// Existing rows and keys are retained; no translation is reset on upgrade.
		update_option( 'lt_version', LT_VERSION );
		update_option( 'lt_flush_rewrite_rules', 1 );
	}
	LT_Language_Router::instance();
	// Frontend translation filters can run thousands of times per request (especially gettext).
	// Keep them out of wp-admin; none of the admin screens are translated by the visitor-language layer.
	if ( ! is_admin() || wp_doing_ajax() ) {
		LT_Frontend_Filters::instance();
		LT_Admin_Texts::instance();
	}
	LT_Script_Texts::instance();
	LT_Block_Editor::instance(); // Registers REST routes as well as editor hooks.
	LT_Translation_Reset::instance();
	if ( ! is_admin() ) LT_Page_Texts::instance(); // Public pages only; admin screens are never page-scan targets.
	LT_Modules::boot();
	if ( is_admin() ) {
		foreach ( array( 'LT_Admin_Navigation', 'LT_Detected_Text', 'LT_Settings_Page', 'LT_Admin_Product_Metabox', 'LT_Find_Text', 'LT_Site_Scan', 'LT_Term_Editor', 'LT_String_Translation', 'LT_Code_Scanner', 'LT_Help_Page', 'LT_CSV_Export', 'LT_Data_Lifecycle', 'LT_Translation_Status', 'LT_Status_Panel', 'LT_Taxonomy_Translation', 'LT_CSV_Import' ) as $class ) $class::instance();
	} else {
		LT_Site_Scan::instance(); // The visual checker is restricted to authorized editors.
	}
}, 20 );

add_shortcode( 'lt_language_switcher', array( 'LT_Language_Router', 'switcher' ) );

add_action( 'init', function() { load_plugin_textdomain( 'lightweight-translation', false, dirname( plugin_basename( LT_FILE ) ) . '/languages' ); } );
