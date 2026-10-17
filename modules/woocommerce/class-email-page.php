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

	private function settings_url( $email ) { return admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( get_class( $email ) ) ); }

	/** Original text of a setting, in the site language (not the admin's own language). */
	private function original( $email, $key ) {
		$value = (string) $email->get_option( $key );
		return '' !== trim( $value ) ? $value : LT_Email_Translations::default_text( $email, $key );
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
		$router    = LT_Language_Router::instance();
		$status    = function( $ok, $text ) { return '<li><span style="display:inline-block;width:20px;color:' . ( $ok ? '#00a32a' : '#b32d2e' ) . '">' . ( $ok ? '✓' : '✗' ) . '</span>' . $text . '</li>'; };
		echo '<style>.lt-mail-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:4px 18px 14px;margin:16px 0}.lt-mail-card ul{margin:8px 0}.lt-mail-table td{vertical-align:top}.lt-mail-table input[type=text],.lt-mail-table textarea{width:100%;border-left-width:4px}.lt-orig{color:#50575e;font-size:12px;white-space:pre-wrap}.lt-badge{display:inline-block;padding:0 7px;border-radius:9px;font-size:11px;background:#f0f0f1}.lt-badge.c{background:#e7f5ea;color:#00701a}</style>';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Customer e-mails', 'lightweight-translation' ) . '</h2>';
		if ( isset( $_GET['lt_notice'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( wp_unslash( $_GET['lt_notice'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! function_exists( 'WC' ) ) { echo '<p>' . esc_html__( 'WooCommerce is not active.', 'lightweight-translation' ) . '</p>'; return; }

		echo '<div class="lt-mail-card"><h2>' . esc_html__( 'How e-mails are translated', 'lightweight-translation' ) . '</h2><ul>';
		echo $status( true, esc_html__( 'Customer e-mails are written in the language the customer used on the site: the language of the order (saved at checkout), or of the account (saved at registration and login) for account e-mails.', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( true, esc_html__( 'Subject, heading and additional content: translate them below, per e-mail.', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( true, wp_kses_post( sprintf( __( 'Body texts (greeting, "Thanks for your order", table labels, totals, footer): translate them in <a href="%1$s">Shop texts</a>. Use the button below to find them.', 'lightweight-translation' ), esc_url( admin_url( 'admin.php?page=lt-woocommerce&area=emails' ) ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( true, esc_html__( 'Product names in the order lines (translated product titles).', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( true, esc_html__( 'E-mails added by other plugins through WooCommerce (bundles, wishlist, back-in-stock…) appear in the list below too.', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( false, esc_html__( 'E-mails sent to the shop (New order, Cancelled, Failed…) stay in the original language, for you.', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $status( false, esc_html__( 'Not included: e-mails that do not go through WooCommerce (contact forms, WordPress notifications), content made with an e-mail builder plugin (YayMail, Kadence, etc.), PDF invoices, orders placed before this version (no language saved: original language).', 'lightweight-translation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</ul><p><button type="button" class="button" id="lt-scan-emails">' . esc_html__( 'Find WooCommerce e-mail texts', 'lightweight-translation' ) . '</button> <span id="lt-scan-emails-status" class="description"></span></p></div>';

		if ( ! $languages ) { echo '<p>' . esc_html__( 'Add a secondary language in the settings first.', 'lightweight-translation' ) . '</p>'; return; }
		// This screen edits global rows (object_id=0). Load all e-mail translations once per language,
		// instead of issuing one database query for every field in every e-mail row.
		foreach ( $languages as $language ) LT_Translation_Store::prime_global( $language, 'email' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lt_save_emails' );
		echo '<input type="hidden" name="action" value="lt_save_emails"><table class="widefat striped lt-mail-table"><thead><tr><th style="width:22%">' . esc_html__( 'E-mail', 'lightweight-translation' ) . '</th><th style="width:20%">' . esc_html( LT_Language_Router::flag( $router->default_language() ) . ' ' . __( 'Original', 'lightweight-translation' ) ) . '</th>';
		foreach ( $languages as $language ) echo '<th>' . esc_html( LT_Language_Router::flag( $language ) . ' ' . LT_Language_Router::language_label( $language ) ) . '</th>';
		echo '</tr></thead><tbody>';
		$switched = get_locale() !== determine_locale() && switch_to_locale( get_locale() );
		foreach ( $this->emails() as $email ) {
			$fields   = LT_Email_Translations::template_fields( $email );
			$customer = $email->is_customer_email();
			$head     = '<strong>' . esc_html( $email->get_title() ) . '</strong><br><span class="lt-badge' . ( $customer ? ' c' : '' ) . '">' . ( $customer ? esc_html__( 'to the customer', 'lightweight-translation' ) : esc_html__( 'to the shop – not translated', 'lightweight-translation' ) ) . '</span>' . ( $email->is_enabled() ? '' : ' <span class="lt-badge">' . esc_html__( 'disabled', 'lightweight-translation' ) . '</span>' ) . '<br><a href="' . esc_url( $this->settings_url( $email ) ) . '">' . esc_html__( 'WooCommerce settings', 'lightweight-translation' ) . ' ↗</a>';
			if ( ! $customer || ! $fields ) { echo '<tr><td>' . $head . '</td><td colspan="' . esc_attr( count( $languages ) + 1 ) . '" class="description">' . ( $customer ? '' : esc_html__( 'Sent to you: kept in the original language.', 'lightweight-translation' ) ) . '</td></tr>'; continue; } // phpcs:ignore WordPress.Security.EscapeOutput
			$first = true;
			foreach ( $fields as $key => $label ) {
				$original = $this->original( $email, $key );
				echo '<tr>' . ( $first ? '<td rowspan="' . esc_attr( count( $fields ) ) . '">' . $head . '</td>' : '' ) . '<td><span class="description">' . esc_html( $label ) . '</span><div class="lt-orig">' . esc_html( $original ) . '</div></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				$first = false;
				foreach ( $languages as $language ) {
					$row   = LT_Translation_Store::get( $language, 'email', 0, 'email_' . $key . '_' . $email->id );
					$value = $row && in_array( $row->status, LT_Translation_Store::LIVE_STATUSES, true ) ? (string) $row->translated_text : '';
					$color = '' === $value ? '#d63638' : ( LT_Translation_Store::source_matches( $row, $original ) ? '#00a32a' : '#dba617' );
					$name  = '[' . esc_attr( $language ) . '][' . esc_attr( $email->id ) . '][' . esc_attr( $key ) . ']';
					echo '<td><input type="hidden" name="old' . $name . '" value="' . esc_attr( $value ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
					echo 0 === strpos( $key, 'additional_content' ) ? '<textarea rows="3" name="mail' . $name . '" style="border-left-color:' . esc_attr( $color ) . '">' . esc_textarea( $value ) . '</textarea>' : '<input type="text" name="mail' . $name . '" value="' . esc_attr( $value ) . '" style="border-left-color:' . esc_attr( $color ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
					echo '</td>';
				}
				echo '</tr>';
			}
		}
		if ( $switched ) restore_previous_locale();
		echo '</tbody></table><p>';
		submit_button( __( 'Save translations', 'lightweight-translation' ), 'primary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Keep the placeholders like {order_number}, {site_title}, {order_date} as they are. Empty = original text.', 'lightweight-translation' ) . '</span></p></form>';
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
		foreach ( (array) ( $post['mail'] ?? array() ) as $language => $by_email ) {
			$language = sanitize_key( $language );
			if ( ! in_array( $language, $languages, true ) ) continue;
			foreach ( (array) $by_email as $id => $fields ) {
				if ( ! isset( $emails[ $id ] ) ) continue;
				$allowed = LT_Email_Translations::template_fields( $emails[ $id ] );
				foreach ( (array) $fields as $key => $value ) {
					if ( ! isset( $allowed[ $key ] ) ) continue;
					$value = 0 === strpos( $key, 'additional_content' ) ? (string) $value : sanitize_text_field( (string) $value );
					if ( $value === (string) ( $post['old'][ $language ][ $id ][ $key ] ?? '' ) ) continue;
					LT_Translation_Store::save( array( 'language_code' => $language, 'object_type' => 'email', 'object_id' => 0, 'field_key' => 'email_' . $key . '_' . $id, 'source_text' => $this->original( $emails[ $id ], $key ), 'translated_text' => $value, 'status' => '' === trim( $value ) ? 'empty' : 'translated' ) );
					$saved++;
				}
			}
		}
		if ( $switched ) restore_previous_locale();
		wp_safe_redirect( add_query_arg( array( 'page' => 'lt-woocommerce', 'sub' => 'emails', 'lt_notice' => rawurlencode( sprintf( __( '%d translations saved.', 'lightweight-translation' ), $saved ) ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
