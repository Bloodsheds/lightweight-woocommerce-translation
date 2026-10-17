<?php
defined( 'ABSPATH' ) || exit;

/**
 * Texts that never go through PHP, on any site and any plugin:
 *
 * 1. Texts written by JavaScript with wp.i18n (WooCommerce cart & checkout blocks, mini-cart, block themes,
 *    any modern plugin). While an administrator browses the site, the texts each page asks wp.i18n for are
 *    noted and listed in Interface texts › By page. On translated pages, wp.i18n answers with our
 *    translation, whatever translation files WordPress loaded.
 * 2. Texts added by hand with their exact visible wording (a custom checkout field, a label typed in a
 *    page builder, a title saved in a block…): replaced in the page once it is displayed.
 *
 * Optional integrations register their bundled text catalogs separately.
 */
class LT_Script_Texts {

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

	private function __construct() {
		add_action( 'wp_ajax_lt_seen_texts', array( $this, 'seen' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'front' ), 1 );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
	}

	private static function field( $key ) { return 'string_' . md5( $key ); }

	private static function normalize( $text ) { return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) ) ); }

	/** Text in the site language (what visitors read before translation). */
	private static function visible( $key, $domain ) {
		$parts = explode( "\x04", $key, 2 );
		return 2 === count( $parts ) ? translate_with_gettext_context( $parts[1], $parts[0], $domain ) : translate( $key, $domain );
	}

	public static function flush() { foreach ( LT_Language_Router::instance()->languages() as $language ) delete_transient( 'lt_wcjs_' . $language ); }

	/** Adds JavaScript texts: translated on the site from now on, and listed in Interface texts. @param array $texts key => [ domain, plural ] */
	private static function add( $texts, $type = '' ) {
		$js      = (array) get_option( 'lt_js_strings', array() );
		$changed = false;
		$page    = array();
		$switched = function_exists( 'switch_to_locale' ) && switch_to_locale( get_locale() ); // originals in the site language, not the admin's
		foreach ( $texts as $key => $info ) {
			list( $domain, $plural ) = $info;
			$place = (array) ( $info[2] ?? array() );
			if ( ! isset( $js[ $domain ][ $key ] ) ) { $js[ $domain ][ $key ] = (string) $plural; $changed = true; }
			$page[ self::field( $key ) ] = array( self::visible( $key, $domain ), $domain, 'j' ) + $place; // 'j': written by JavaScript, kept when the page is read again
		}
		if ( $switched ) restore_previous_locale();
		if ( $changed ) { update_option( 'lt_js_strings', $js, false ); self::flush(); }
		if ( $type ) LT_Page_Texts::add_texts( $type, $page );
		else LT_Page_Texts::register( $page );
		if ( $changed ) { // so "By plugin / source" shows them under their plugin
			$index = (array) get_option( LT_Code_Scanner::INDEX, array() );
			foreach ( $page as $field => $text ) if ( isset( $index[ $field ] ) ) $index[ $field ]['j'] = 1;
			update_option( LT_Code_Scanner::INDEX, $index, false );
		}
	}

	/* ---------- Admin ---------- */

	public function admin_init() {
		// The old "Cart & Checkout" page is now part of Interface texts › By page.
		if ( 'lt-wc-blocks' === ( $_GET['page'] ?? '' ) ) { wp_safe_redirect( admin_url( 'admin.php?page=lt-strings&page_type=checkout' ) ); exit; } // phpcs:ignore WordPress.Security.NonceVerification
		// Texts noted by the former Cart & Checkout page (0.18–0.19).
		$old = get_option( 'lt_wc_blocks_seen' );
		if ( is_array( $old ) && current_user_can( lt_capability() ) ) {
			$texts = array();
			foreach ( $old as $key => $info ) $texts[ $key ] = array( $info[0] ?? 'woocommerce', $info[1] ?? '' );
			self::add( $texts );
			foreach ( array( 'lt_wc_blocks_seen', 'lt_wc_blocks_texts', 'lt_wc_blocks_pages', 'lt_wc_blocks_scanned' ) as $option ) delete_option( $option );
		}
	}

	/** Reads the front-end files of the WooCommerce blocks (cart, checkout, mini-cart…) and adds their texts. */


	/** Texts a page asked wp.i18n for, sent by the browser while an administrator views it. */
	public function seen() {
		check_ajax_referer( 'lt_seen_texts', 'nonce' );
		if ( ! current_user_can( lt_capability() ) ) wp_send_json_error( null, 403 );
		$list = json_decode( wp_unslash( (string) ( $_POST['texts'] ?? '' ) ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $list ) ) wp_send_json_error();
		$texts = array();
		foreach ( array_slice( $list, 0, 2000 ) as $item ) {
			// wp.i18n also prepares editor/block metadata never shown to visitors.
			if ( empty( $item['w'] ) ) continue;
			$key = (string) ( $item['k'] ?? '' );
			if ( '' === $key || strlen( $key ) > 2000 || ! preg_match( '/\p{L}{2,}/u', $key ) ) continue;
			$place = isset( $item['w'] ) ? LT_Page_Places::from_path( substr( (string) $item['w'], 0, 4000 ), (string) ( $item['a'] ?? '' ), isset( $item['o'] ) ? max( 0, min( 100, (int) $item['o'] ) ) : null ) : array( 'z' => 'unseen' );
			$texts[ $key ] = array( sanitize_text_field( (string) ( $item['d'] ?? '' ) ) ?: 'default', (string) ( $item['p'] ?? '' ), $place );
		}
		self::add( $texts, sanitize_text_field( wp_unslash( (string) ( $_POST['type'] ?? '' ) ) ) );
		wp_send_json_success( array( 'count' => count( $texts ) ) );
	}

	/* ---------- Site ---------- */

	/** [ i18n => key => translation(s), dom => visible text => translation ] for one language. */
	private static function maps( $language ) {
		$cached = get_transient( 'lt_wcjs_' . $language );
		if ( is_array( $cached ) && ( $cached['_domains'] ?? '' ) === implode( ',', LT_Frontend_Filters::domains() ) ) return $cached;
		global $wpdb;
		$keys = array();
		foreach ( (array) get_option( 'lt_js_strings', array() ) as $domain => $domain_keys ) if ( LT_Frontend_Filters::domain_allowed( $domain ) ) $keys += (array) $domain_keys;
		$i18n = array();
		foreach ( $keys as $key => $plural ) {
			$single = LT_Translation_Store::string_value( $language, self::field( $key ) );
			if ( null === $single || '' === $single ) continue;
			$context = false !== strpos( $key, "\x04" ) ? substr( $key, 0, strpos( $key, "\x04" ) + 1 ) : '';
			$many    = '' !== (string) $plural ? LT_Translation_Store::string_value( $language, self::field( $context . $plural ) ) : null;
			$i18n[ $key ] = $many ? array( $single, $many ) : $single;
		}
		// Replaced in the page as displayed: only the texts added by hand (small list), so the page stays light.
		$dom   = array();
		$table = LT_Translation_Store::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT source_text, translated_text FROM $table WHERE language_code=%s AND object_type='string' AND object_id=0 AND status IN ('translated','reviewed') AND translated_text <> '' AND field_key LIKE %s", $language, 'text\\_%' ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) { $source = self::normalize( $row->source_text ); if ( '' !== $source && mb_strlen( $source ) < 300 ) $dom[ $source ] = (string) $row->translated_text; }
		$maps = array( 'i18n' => $i18n, 'dom' => $dom, '_domains' => implode( ',', LT_Frontend_Filters::domains() ) );
		set_transient( 'lt_wcjs_' . $language, $maps, 12 * HOUR_IN_SECONDS );
		return $maps;
	}

	public function front() {
		if ( is_admin() ) return;
		$router = LT_Language_Router::instance();
		if ( $router->is_secondary() ) {
			$maps = self::maps( $router->current_language() );
			if ( $maps['i18n'] ) {
				// Runs before any script uses wp.i18n: every call is answered with our translation when we have one.
				wp_add_inline_script( 'wp-hooks', '(function(h,m){if(!h)return;var o=Object.prototype.hasOwnProperty;function t(r,k,n){if(!o.call(m,k))return r;var v=m[k];return typeof v==="string"?v:(n===1?v[0]:v[1]||v[0]);}'
					. 'h.addFilter("i18n.gettext","lt",function(r,s){return t(r,s,1);});'
					. 'h.addFilter("i18n.gettext_with_context","lt",function(r,s,c){return t(r,c+"\u0004"+s,1);});'
					. 'h.addFilter("i18n.ngettext","lt",function(r,s,p,n){return t(r,s,n);});'
					. 'h.addFilter("i18n.ngettext_with_context","lt",function(r,s,p,n,c){return t(r,c+"\u0004"+s,n);});'
					. '})(window.wp&&window.wp.hooks,' . wp_json_encode( $maps['i18n'] ) . ');', 'after' );
			}
			if ( $maps['dom'] && apply_filters( 'lt_replace_visible_texts', true ) ) {
				wp_register_script( 'lt-texts', false, array(), LT_VERSION, true );
				wp_enqueue_script( 'lt-texts' );
				wp_add_inline_script( 'lt-texts', '(function(m){var o=Object.prototype.hasOwnProperty;function x(s){var k=(s||"").replace(/\s+/g," ").trim();return k&&o.call(m,k)?s.replace(s.trim(),function(){return m[k];}):null;}'
					. 'function n(t){var r=x(t.nodeValue);if(r!==null&&r!==t.nodeValue)t.nodeValue=r;}'
					. 'function a(el){["placeholder","aria-label","title"].forEach(function(at){var v=el.getAttribute&&el.getAttribute(at);if(v){var r=x(v);if(r!==null&&r!==v)el.setAttribute(at,r);}});if(el.tagName==="INPUT"&&/^(submit|button)$/.test(el.type)){var r=x(el.value);if(r!==null)el.value=r;}}'
					. 'function w(root){if(root.nodeType===3)return n(root);if(root.nodeType!==1||/^(SCRIPT|STYLE|TEXTAREA)$/.test(root.tagName))return;var it=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,null),t;while((t=it.nextNode()))n(t);a(root);root.querySelectorAll("[placeholder],[aria-label],[title],input[type=submit],input[type=button]").forEach(a);}'
					. 'function go(){w(document.body);new MutationObserver(function(l){l.forEach(function(r){if(r.type==="characterData")n(r.target);else r.addedNodes.forEach(w);});}).observe(document.body,{childList:true,subtree:true,characterData:true});}'
					. 'document.readyState==="loading"?document.addEventListener("DOMContentLoaded",go):go();})(' . wp_json_encode( $maps['dom'] ) . ');' );
			}
		}
		// Administrators: note which texts each page asks wp.i18n for (listed in Interface texts › By page).
		if ( is_user_logged_in() && current_user_can( lt_capability() ) && ! LT_Page_Texts::scan_frame() ) {
			wp_add_inline_script( 'wp-hooks', '(function(h,u,nc,ty){if(!h||!window.fetch)return;var s={},c=0;function r(k,d,p,t){d=d||"default";if(!s[k]&&c<2000){s[k]={k:k,d:d,p:p||"",t:typeof t==="string"?t:""};c++;}}'
				. 'h.addFilter("i18n.gettext","lt/seen",function(t,x,d){r(x,d,"",t);return t;},1);'
				. 'h.addFilter("i18n.gettext_with_context","lt/seen",function(t,x,ctx,d){r(ctx+"\u0004"+x,d,"",t);return t;},1);'
				. 'h.addFilter("i18n.ngettext","lt/seen",function(t,x,p,n,d){r(x,d,p,t);return t;},1);'
				. 'h.addFilter("i18n.ngettext_with_context","lt/seen",function(t,x,p,n,ctx,d){r(ctx+"\u0004"+x,d,p,t);return t;},1);'
				// Where each text is shown: the element chain (classes) and its position from the top, read in the page.
				. 'function path(e){var o=[];while(e&&e!==document.body&&o.length<25){var cl=typeof e.className==="string"?e.className.trim().split(/\s+/).slice(0,8).join("."):"";o.unshift(e.tagName.toLowerCase()+(e.id?"#"+e.id.replace(/[.>]/g,""):"")+(cl?"."+cl.replace(/>/g,""):""));e=e.parentElement;}return o.join(">");}'
				. 'function locate(l){if(!document.body)return;var n=[],w=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT,null),x;while((x=w.nextNode())){var pe=x.parentElement;if(!pe||/^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE)$/.test(pe.tagName))continue;var v=x.nodeValue.replace(/\s+/g," ").trim().toLowerCase();if(v.length>1)n.push([v,pe,""]);}'
				. 'document.querySelectorAll("[placeholder],[aria-label]").forEach(function(e){["placeholder","aria-label"].forEach(function(a){var v=e.getAttribute(a);if(v)n.push([v.toLowerCase(),e,a]);});});'
				. 'l.forEach(function(it){var q=(it.t||"").split(/%(?:\d+\$)?[sdfu]|\{[a-z_]+\}/i).sort(function(a,b){return b.length-a.length;})[0];q=(q||"").replace(/\s+/g," ").trim().toLowerCase();delete it.t;if(q.length<2)return;'
				. 'for(var i=0;i<n.length;i++){if(n[i][0].indexOf(q)!==-1){it.w=path(n[i][1]);it.a=n[i][2];it.o=Math.round(100*i/Math.max(1,n.length-1));break;}}});}'
				. 'function send(){var l=Object.keys(s).map(function(k){return s[k];});try{locate(l);}catch(e){}if(!l.length)return;s={};var f=new FormData();f.append("action","lt_seen_texts");f.append("nonce",nc);f.append("type",ty);f.append("texts",JSON.stringify(l));fetch(u,{method:"POST",body:f,credentials:"same-origin",keepalive:true}).catch(function(){});}'
				. 'window.addEventListener("load",function(){setTimeout(send,2500);});document.addEventListener("visibilitychange",function(){if(document.visibilityState==="hidden")send();});'
				. '})(window.wp&&window.wp.hooks,' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',' . wp_json_encode( wp_create_nonce( 'lt_seen_texts' ) ) . ',' . wp_json_encode( LT_Page_Texts::page_type() ) . ');', 'after' );
		}
	}
}
