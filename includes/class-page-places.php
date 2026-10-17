<?php
defined( 'ABSPATH' ) || exit;

/**
 * Where a text is on a page, in words a person understands:
 *  - zone: Header, Main content, Sidebar, Footer, Side panel / popup, Message after an action;
 *  - part: the block it belongs to (Payment, Billing details, Mini cart, Product tabs, Menu…);
 *  - kind: what it is (button, link, field label, placeholder, title…);
 *  - order: its position from the top of the page (0–100), to list texts in page order.
 * Read from the HTML of the page (texts written by PHP) or from the browser (texts written by JavaScript).
 */
class LT_Page_Places {
	const ZONES = array( 'header', 'main', 'sidebar', 'footer', 'panel', 'messages', 'unseen' );

	public static function zone_label( $zone ) {
		$labels = array(
			'header'   => __( 'Header', 'lightweight-translation' ),
			'main'     => __( 'Main content', 'lightweight-translation' ),
			'sidebar'  => __( 'Sidebar', 'lightweight-translation' ),
			'footer'   => __( 'Footer', 'lightweight-translation' ),
			'panel'    => __( 'Side panel / popup (cart, mobile menu…)', 'lightweight-translation' ),
			'messages' => __( 'Message after an action', 'lightweight-translation' ),
			'unseen'   => __( 'Not visible on the page (hidden, or shown in some cases only)', 'lightweight-translation' ),
		);
		return $labels[ $zone ] ?? $labels['unseen'];
	}

	public static function rank( $zone ) { $rank = array_search( $zone, self::ZONES, true ); return false === $rank ? count( self::ZONES ) : $rank; }

	/** Blocks recognised by their CSS class (first match from the text outwards). */
	private static function parts() {
		return array(
			array( '/^(woocommerce-notices-wrapper|woocommerce-(message|error|info)|wc-block-components-notice-banner|wc-block-store-notices|woocommerce-NoticeGroup.*)$/i', __( 'Notice', 'lightweight-translation' ) ),
			array( '/^(woocommerce-checkout-payment|wc_payment_methods|payment_methods|wc-block-checkout__payment-method|wp-block-woocommerce-checkout-payment-block|wc-block-components-radio-control--payment)/i', __( 'Payment', 'lightweight-translation' ) ),
			array( '/^(woocommerce-billing-fields|wc-block-checkout__billing-fields|wp-block-woocommerce-checkout-billing-address-block)/i', __( 'Billing details', 'lightweight-translation' ) ),
			array( '/^(woocommerce-shipping-fields|woocommerce-shipping-totals|woocommerce-shipping-methods|wc-block-checkout__shipping|wp-block-woocommerce-checkout-shipping-(address|method|methods)-block|wc-block-components-shipping)/i', __( 'Shipping', 'lightweight-translation' ) ),
			array( '/^(wc-block-checkout__contact-fields|wp-block-woocommerce-checkout-contact-information-block)/i', __( 'Contact information', 'lightweight-translation' ) ),
			array( '/^(woocommerce-additional-fields|wp-block-woocommerce-checkout-order-note-block|wc-block-checkout__add-note)/i', __( 'Order notes', 'lightweight-translation' ) ),
			array( '/^(woocommerce-terms-and-conditions-wrapper|wc-block-checkout__terms|wp-block-woocommerce-checkout-terms-block)/i', __( 'Terms & conditions', 'lightweight-translation' ) ),
			array( '/^(woocommerce-form-coupon|checkout_coupon|coupon|wc-block-components-totals-coupon)/i', __( 'Coupon', 'lightweight-translation' ) ),
			array( '/^(woocommerce-checkout-review-order|wp-block-woocommerce-checkout-order-summary-block|wc-block-components-order-summary|wp-block-woocommerce-checkout-totals-block)/i', __( 'Order summary', 'lightweight-translation' ) ),
			array( '/^(wc-block-checkout__actions|place-order|wp-block-woocommerce-checkout-actions-block)/i', __( 'Place order', 'lightweight-translation' ) ),
			array( '/^(cart_totals|wc-block-cart__totals|wp-block-woocommerce-cart-totals-block|wc-block-components-totals-wrapper)/i', __( 'Cart totals', 'lightweight-translation' ) ),
			array( '/^(woocommerce-cart-form|wc-block-cart-items|wp-block-woocommerce-cart-items-block|wp-block-woocommerce-empty-cart-block|cart-empty|wc-empty-cart-message)/i', __( 'Cart', 'lightweight-translation' ) ),
			array( '/^(widget_shopping_cart|woocommerce-mini-cart|cart-widget-side|wd-cart-content|wc-block-mini-cart|wd-header-cart|mini-cart)/i', __( 'Mini cart', 'lightweight-translation' ) ),
			array( '/^(woocommerce-MyAccount-navigation)/i', __( 'Account menu', 'lightweight-translation' ) ),
			array( '/^(woocommerce-form-login|login-form|wd-login)/i', __( 'Login form', 'lightweight-translation' ) ),
			array( '/^(woocommerce-form-register|register-form)/i', __( 'Registration form', 'lightweight-translation' ) ),
			array( '/^(woocommerce-order-details|woocommerce-order-overview|woocommerce-customer-details|woocommerce-thankyou)/i', __( 'Order details', 'lightweight-translation' ) ),
			array( '/^(woocommerce-tabs|wc-tabs-wrapper|wd-single-tabs|woocommerce-Tabs-panel)/i', __( 'Product tabs', 'lightweight-translation' ) ),
			array( '/^(woocommerce-Reviews|comments-area|comment-respond|reviews)$/i', __( 'Reviews & comments', 'lightweight-translation' ) ),
			array( '/^(variations_form|variations|single_variation_wrap)/i', __( 'Product options', 'lightweight-translation' ) ),
			array( '/^(product_meta|woocommerce-product-attributes|shop_attributes)/i', __( 'Product details', 'lightweight-translation' ) ),
			array( '/^(related|upsells|cross-sells|wd-carousel-container)/i', __( 'Related products', 'lightweight-translation' ) ),
			array( '/^(woocommerce-ordering|woocommerce-result-count|wd-shop-tools|shop-loop-head|wd-filter|filters-area|wd-products-per-page|wc-block-product-filters|woocommerce-widget-layered-nav)/i', __( 'Sorting & filters', 'lightweight-translation' ) ),
			array( '/^(woocommerce-pagination|pagination|page-numbers|wd-loop-footer)$/i', __( 'Pagination', 'lightweight-translation' ) ),
			array( '/^(product-grid-item|wd-product|product-element-bottom|wc-block-grid__product|products)$/i', __( 'Product cards', 'lightweight-translation' ) ),
			array( '/^(woocommerce-breadcrumb|breadcrumbs?|wd-breadcrumbs|yoast-breadcrumbs|rank-math-breadcrumb)$/i', __( 'Breadcrumb', 'lightweight-translation' ) ),
			array( '/^(search-form|searchform|wd-search-form|woocommerce-product-search|wp-block-search|wd-search-dropdown|wd-search-full-screen)/i', __( 'Search', 'lightweight-translation' ) ),
			array( '/^(wpcf7|wpforms|gform_wrapper|mc4wp-form|newsletter|wd-subscribe|fluentform|elementor-form)/i', __( 'Form', 'lightweight-translation' ) ),
			array( '/^(cmplz-|cookie|cookies|wd-cookies-popup|cky-|borlabs)/i', __( 'Cookie banner', 'lightweight-translation' ) ),
			array( '/^(wd-header-mobile-nav|mobile-nav|wd-nav-mobile|menu-mobile|mobile-menu)/i', __( 'Mobile menu', 'lightweight-translation' ) ),
			array( '/^(wd-header-my-account|my-account-link|wd-account)/i', __( 'Account link', 'lightweight-translation' ) ),
			array( '/^(wd-header-wishlist|wishlist|wd-header-compare|compare)/i', __( 'Wishlist & compare', 'lightweight-translation' ) ),
			array( '/^(wd-social-icons|social-icons|share)/i', __( 'Social links', 'lightweight-translation' ) ),
			array( '/(^|-)(menu|nav|navigation)($|-)/i', __( 'Menu', 'lightweight-translation' ) ),
			array( '/^(widget|wp-block-widget)/i', __( 'Widget', 'lightweight-translation' ) ),
		);
	}

	/** Zone of an element chain (outermost match wins: a "header" inside the content is not the site header). */
	private static function zone( $chain ) {
		$rules = array(
			'panel'   => '/^(cart-widget-side|wd-side-hidden|wd-side-panel|side-panel|mobile-nav|wd-header-mobile-nav|offcanvas|off-canvas|popup|wd-popup|mfp-content|modal|wd-search-full-screen|cmplz-cookiebanner|cky-consent-container|wc-block-mini-cart__drawer)/i',
			'header'  => '/^(site-header|whb-header|masthead|wd-header|header-wrapper|elementor-location-header|wp-site-header|header)$/i',
			'footer'  => '/^(site-footer|footer|footer-container|wd-footer|footer-wrapper|colophon|elementor-location-footer|wp-site-footer)$/i',
			'sidebar' => '/^(sidebar|widget-area|sidebar-container|wd-sidebar|secondary)$/i',
			'main'    => '/^(site-content|main-page-wrapper|content-area|site-main|wd-content-layout|entry-content|page-content|main|content|primary)$/i',
		);
		foreach ( $chain as $el ) {
			if ( 'header' === $el[0] ) return 'header';
			if ( 'footer' === $el[0] ) return 'footer';
			if ( 'aside' === $el[0] ) return 'sidebar';
			if ( 'main' === $el[0] ) return 'main';
			$tokens = array_merge( preg_split( '/\s+/', trim( $el[1] ) ), '' !== $el[2] ? array( $el[2] ) : array() );
			foreach ( $rules as $zone => $rule ) foreach ( $tokens as $token ) if ( '' !== $token && preg_match( $rule, $token ) ) return $zone;
		}
		return 'main';
	}

	/** Block the text belongs to (innermost match). */
	private static function part( $chain ) {
		$parts = self::parts();
		for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
			$tokens = array_filter( array_merge( preg_split( '/\s+/', trim( $chain[ $i ][1] ) ), array( $chain[ $i ][2] ) ) );
			foreach ( $parts as $part ) foreach ( $tokens as $token ) if ( preg_match( $part[0], $token ) ) return $part[1];
			foreach ( $tokens as $token ) {
				// Any WooCommerce block: its own name ("wp-block-woocommerce-product-price" → "Product price").
				if ( preg_match( '/^wp-block-woocommerce-([a-z0-9-]+?)(-block)?$/', $token, $m ) ) return ucfirst( str_replace( '-', ' ', $m[1] ) );
			}
			if ( 'nav' === $chain[ $i ][0] ) return __( 'Menu', 'lightweight-translation' );
		}
		foreach ( $chain as $el ) if ( 'form' === $el[0] ) return __( 'Form', 'lightweight-translation' );
		return '';
	}

	/** What the text is, from its element (and attribute). */
	private static function kind( $chain, $attribute = '' ) {
		if ( 'placeholder' === $attribute ) return __( 'field placeholder', 'lightweight-translation' );
		if ( in_array( $attribute, array( 'title', 'aria-label' ), true ) ) return __( 'tooltip / screen reader', 'lightweight-translation' );
		for ( $i = count( $chain ) - 1; $i >= max( 0, count( $chain ) - 3 ); $i-- ) {
			$tag = $chain[ $i ][0];
			if ( 'button' === $tag || 'value' === $attribute || preg_match( '/(^|\s)(button|btn)(\s|$|-)/', $chain[ $i ][1] ) ) return __( 'button', 'lightweight-translation' );
			if ( 'label' === $tag || 'legend' === $tag ) return __( 'field label', 'lightweight-translation' );
			if ( preg_match( '/^h[1-6]$/', $tag ) ) return __( 'title', 'lightweight-translation' );
			if ( 'option' === $tag ) return __( 'choice in a list', 'lightweight-translation' );
			if ( 'th' === $tag ) return __( 'table header', 'lightweight-translation' );
			if ( 'a' === $tag ) return __( 'link', 'lightweight-translation' );
		}
		return '';
	}

	/** @param array $chain [ [tag, classes, id] ] from the outermost element to the text. */
	public static function describe( $chain, $attribute = '', $order = null ) {
		$out = array( 'z' => self::zone( $chain ), 'c' => self::part( $chain ), 'k' => self::kind( $chain, $attribute ) );
		if ( null !== $order ) $out['o'] = (int) $order;
		return array_filter( $out, function( $v ) { return '' !== $v; } );
	}

	/** Chain sent by the browser: "div.wrapper#id>form.checkout>button.btn" (outermost first). */
	public static function from_path( $path, $attribute = '', $order = null ) {
		$chain = array();
		foreach ( array_slice( explode( '>', (string) $path ), -25 ) as $part ) {
			if ( ! preg_match( '/^([a-z][a-z0-9:-]*)(?:#([^.]*))?((?:\.[^.]+)*)$/i', trim( $part ), $m ) ) continue;
			$chain[] = array( strtolower( $m[1] ), trim( str_replace( '.', ' ', $m[3] ) ), (string) $m[2] );
		}
		return $chain ? self::describe( $chain, sanitize_key( $attribute ), $order ) : array( 'z' => 'unseen' );
	}

	private static function norm( $text ) { return mb_strtolower( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ); }

	/** Longest fixed part of a text (around %s / {name} placeholders), lower case. */
	public static function needle( $source ) {
		$plain = preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $source ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$parts = preg_split( '/%(?:\d+\$)?[sdfu]|\{[a-z_]+\}/i', $plain );
		usort( $parts, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		return mb_strtolower( trim( (string) $parts[0] ) );
	}

	/**
	 * Finds where each text is in the HTML of a page.
	 * @param array $sources field => original text
	 * @return array field => [ z, c?, k?, o ]
	 */
	public static function locate( $html, $sources ) {
		if ( '' === (string) $html || ! $sources ) return array();
		$void   = array_flip( array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' ) );
		$stack  = array();
		$nodes  = array(); // [ text, chain, attribute ]
		preg_match_all( '#<!--.*?-->|<(script|style|noscript|template|svg|textarea)\b[^>]*>.*?</\1\s*>|<(/?)([a-zA-Z][\w:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>|[^<]+#s', (string) $html, $tokens, PREG_SET_ORDER );
		foreach ( $tokens as $t ) {
			if ( ! isset( $t[3] ) || '' === $t[3] ) {
				if ( '<' !== $t[0][0] && preg_match( '/\p{L}/u', $t[0] ) ) $nodes[] = array( self::norm( $t[0] ), $stack, '' );
				continue;
			}
			$tag = strtolower( $t[3] );
			if ( '/' === $t[2] ) {
				for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) if ( $stack[ $i ][0] === $tag ) { $stack = array_slice( $stack, 0, $i ); break; }
				continue;
			}
			$attrs = $t[4] ?? '';
			$class = preg_match( '/\bclass\s*=\s*["\']([^"\']*)["\']/i', $attrs, $m ) ? $m[1] : '';
			$id    = preg_match( '/\bid\s*=\s*["\']([^"\']*)["\']/i', $attrs, $m ) ? $m[1] : '';
			$el    = array( $tag, $class, $id );
			if ( preg_match_all( '/\b(placeholder|aria-label|title)\s*=\s*"([^"]*\p{L}[^"]*)"/iu', $attrs, $am, PREG_SET_ORDER ) ) foreach ( $am as $a ) $nodes[] = array( "\n" . self::norm( $a[2] ) . "\n", array_merge( $stack, array( $el ) ), strtolower( $a[1] ) );
			if ( 'input' === $tag && preg_match( '/\btype\s*=\s*["\']?(submit|button)/i', $attrs ) && preg_match( '/\bvalue\s*=\s*"([^"]*\p{L}[^"]*)"/iu', $attrs, $vm ) ) $nodes[] = array( "\n" . self::norm( $vm[1] ) . "\n", array_merge( $stack, array( $el ) ), 'value' );
			if ( ! isset( $void[ $tag ] ) && '/' !== substr( rtrim( $attrs ), -1 ) ) $stack[] = $el;
		}
		if ( ! $nodes ) return array();
		// One searchable text, with where each element starts.
		$stream = '';
		$starts = array();
		foreach ( $nodes as $i => $node ) { $starts[ $i ] = strlen( $stream ); $stream .= $node[0]; }
		$last = count( $nodes ) - 1;
		$out  = array();
		foreach ( $sources as $field => $source ) {
			$needle = self::needle( $source );
			if ( mb_strlen( $needle ) < 2 ) continue;
			$at = strpos( $stream, $needle );
			if ( false === $at ) continue;
			$lo = 0; $hi = $last; // node holding that position
			while ( $lo < $hi ) { $mid = (int) ceil( ( $lo + $hi ) / 2 ); if ( $starts[ $mid ] <= $at ) $lo = $mid; else $hi = $mid - 1; }
			$out[ $field ] = self::describe( $nodes[ $lo ][1], $nodes[ $lo ][2], $last ? (int) round( 100 * $lo / $last ) : 0 );
		}
		return $out;
	}

	/** Short description for the list: "Main content › Payment · button". */
	public static function summary( $place, $with_zone = true ) {
		$bits = array();
		if ( $with_zone && ! empty( $place['z'] ) ) $bits[] = self::zone_label( $place['z'] );
		if ( ! empty( $place['c'] ) ) $bits[] = $place['c'];
		$text = implode( ' › ', $bits );
		if ( ! empty( $place['k'] ) ) $text .= ( '' !== $text ? ' · ' : '' ) . $place['k'];
		return $text;
	}

	/** Words for the block shown after an AJAX action (add to cart, checkout update…). */
	public static function action_label( $action ) {
		$labels = array(
			'add_to_cart'             => __( 'Add to cart', 'lightweight-translation' ),
			'woocommerce_add_to_cart' => __( 'Add to cart', 'lightweight-translation' ),
			'woodmart_ajax_add_to_cart' => __( 'Add to cart', 'lightweight-translation' ),
			'remove_from_cart'        => __( 'Mini cart', 'lightweight-translation' ),
			'get_refreshed_fragments' => __( 'Mini cart', 'lightweight-translation' ),
			'update_order_review'     => __( 'Checkout (refresh)', 'lightweight-translation' ),
			'checkout'                => __( 'Checkout (place order)', 'lightweight-translation' ),
			'apply_coupon'            => __( 'Coupon', 'lightweight-translation' ),
			'remove_coupon'           => __( 'Coupon', 'lightweight-translation' ),
			'update_shipping_method'  => __( 'Shipping', 'lightweight-translation' ),
		);
		$action = sanitize_key( (string) $action );
		if ( '' === $action ) return '';
		return $labels[ $action ] ?? ucfirst( str_replace( array( '_', '-' ), ' ', preg_replace( '/^(woocommerce|woodmart|wc)_/', '', $action ) ) );
	}
}
