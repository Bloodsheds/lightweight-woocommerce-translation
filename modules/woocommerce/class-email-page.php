<?php
defined( 'ABSPATH' ) || exit;

/**
 * "E-mails" page: explains what is translated in WooCommerce e-mails, and lets you translate the subject,
 * heading and additional content of every customer e-mail, with links to their WooCommerce settings.
 */
class LT_Email_Page {
	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() {
		add_action( 'admin_post_lt_save_emails', array( $this, 'save' ) );
	}

	private function languages() { $router = LT_Language_Router::instance(); return array_values( array_diff( $router->languages(), array( $router->default_language() ) ) ); }

	private function emails() {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) return array();
		$emails = WC()->mailer()->get_emails();
		uasort( $emails, function( $a, $b ) { return $a->is_customer_email() === $b->is_customer_email() ? strcasecmp( $a->get_title(), $b->get_title() ) : ( $a->is_customer_email() ? -1 : 1 ); } );
		return $emails;
	}

	/** Original text of a setting, in the site language (not the admin's own language). */
	private function original( $email, $key ) {
		return LT_Email_Translations::original( $email, $key );
	}

	public function page() {
		if ( ! current_user_can( lt_capability() ) ) return;
		// Keep old bookmarks working, but send everyone to the single WooCommerce translation workspace.
		wp_safe_redirect( add_query_arg( array( 'page' => 'lt-woocommerce', 'sub' => 'emails' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_embedded() {
		if ( ! current_user_can( lt_capability() ) ) return;
		$languages = $this->languages();
		$status    = function( $ok, $text ) { return '<li><span style="display:inline-block;width:20px;color:' . ( $ok ? '#00a32a' : '#b32d2e' ) . '">' . ( $ok ? '✓' : '✗' ) . '</span>' . $text . '</li>'; };
		echo '<style>.lt-mail-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:4px 18px 14px;margin:16px 0}.lt-mail-card ul{margin:8px 0}</style>';
		if ( isset( $_GET['lt_notice'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( wp_unslash( $_GET['lt_notice'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! function_exists( 'WC' ) ) { echo '<p>' . esc_html__( 'WooCommerce is not active.', 'lightweight-translation' ) . '</p>'; return; }

		echo '<details class="lt-mail-card"><summary style="cursor:pointer;padding:10px 0"><strong>' . esc_html__( 'How e-mails are translated', 'lightweight-translation' ) . '</strong></summary><ul>';
		echo $status( true, esc_html__( 'Customer e-mails are written in the language the customer used on the site: the language of the order (saved at checkout), or of the account (saved at registration and login) for account e-mails.', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( true, esc_html__( 'Subject, heading and additional content: translate them below, per e-mail.', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( true, wp_kses_post( sprintf( __( 'Body texts (greeting, "Thanks for your order", table labels, totals, footer): translate them in <a href="%1$s">Shop texts</a>. Use the button below to find them.', 'lightweight-translation' ), esc_url( admin_url( 'admin.php?page=lt-woocommerce&area=emails' ) ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( true, esc_html__( 'Product names in the order lines (translated product titles).', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( true, esc_html__( 'E-mails added by other plugins through WooCommerce (bundles, wishlist, back-in-stock…) appear in the list below too.', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( false, esc_html__( 'E-mails sent to the shop (New order, Cancelled, Failed…) stay in the original language, for you.', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( false, esc_html__( 'Not included: e-mails that do not go through WooCommerce (contact forms, WordPress notifications), content made with an e-mail builder plugin (YayMail, Kadence, etc.), PDF invoices, orders placed before this version (no language saved: original language).', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</ul><p><button type="button" class="button" id="lt-scan-emails">' . esc_html__( 'Find WooCommerce e-mail texts', 'lightweight-translation' ) . '</button> <span id="lt-scan-emails-status" class="description"></span></p></details>';

		if ( ! $languages ) { echo '<p>' . esc_html__( 'Add a secondary language in the settings first.', 'lightweight-translation' ) . '</p>'; return; }
		LT_Translation_Status::instance()->render_embedded( 'email', __( 'E-mails', 'lightweight-translation' ), LT_Content_Catalog::item_ids( 'email' ), array( 'page' => 'lt-woocommerce', 'sub' => 'emails' ) );
		echo '<script>(function(){var b=document.getElementById("lt-scan-emails"),st=document.getElementById("lt-scan-emails-status");if(!b)return;var nonce=' . wp_json_encode( wp_create_nonce( 'lt_scan_code' ) ) . ',url=' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',go=' . wp_json_encode( admin_url( 'admin.php?page=lt-woocommerce&area=emails' ) ) . ';
b.addEventListener("click",function(){b.disabled=true;var added=0;function step(o){fetch(url,{method:"POST",credentials:"same-origin",body:new URLSearchParams({action:"lt_scan_code",nonce:nonce,target:"plugin:woocommerce",offset:o,mode:"all",path:"emails"})}).then(function(r){return r.json();}).then(function(r){if(!r.success)throw new Error((r.data&&r.data.message)||"error");added+=r.data.added;st.textContent=r.data.offset+"/"+r.data.total;if(!r.data.done)return step(r.data.offset);st.textContent="✓ "+added;location.href=go;}).catch(function(e){st.textContent="⚠ "+e.message;b.disabled=false;});}step(0);});})();</script>';
	}

	public function save() {
		if ( ! current_user_can( lt_capability() ) || ! check_admin_referer( 'lt_save_emails' ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		$post      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$languages = $this->languages();
		$emails    = array();
		foreach ( $this->emails() as $email ) $emails[ $email->id ] = $email;
		$switched = get_locale() !== determine_locale() && switch_to_locale( get_locale() );
		$saved    = 0;
		$errors   = 0;
		foreach ( (array) ( $post['mail'] ?? array() ) as $language => $by_email ) {
			$language = sanitize_key( $language );
			if ( ! in_array( $language, $languages, true ) ) continue;
			foreach ( (array) $by_email as $id => $fields ) {
				if ( ! isset( $emails[ $id ] ) || ! $emails[ $id ]->is_customer_email() ) continue;
				$allowed = LT_Email_Translations::template_fields( $emails[ $id ] );
				foreach ( (array) $fields as $key => $value ) {
					if ( ! isset( $allowed[ $key ] ) ) continue;
					$value = 0 === strpos( $key, 'additional_content' ) ? (string) $value : sanitize_text_field( (string) $value );
					if ( $value === (string) ( $post['old'][ $language ][ $id ][ $key ] ?? '' ) ) continue;
					$result = LT_Translation_Store::save( array( 'language_code' => $language, 'object_type' => 'email', 'object_id' => 0, 'field_key' => 'email_' . $key . '_' . $id, 'source_text' => $this->original( $emails[ $id ], $key ), 'translated_text' => $value, 'status' => '' === trim( $value ) ? 'empty' : 'translated' ) );
					if ( false === $result ) $errors++; else $saved++;
				}
			}
		}
		if ( $switched ) restore_previous_locale();
		$notice = sprintf( __( '%d translations saved.', 'lightweight-translation' ), $saved );
		if ( $errors ) $notice .= ' ' . __( 'Some translations could not be saved. Keep every e-mail placeholder (for example {order_number}) exactly as in the original, then try again.', 'lightweight-translation' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'lt-woocommerce', 'sub' => 'emails', 'lt_notice' => rawurlencode( $notice ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
