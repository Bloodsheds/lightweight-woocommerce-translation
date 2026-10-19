<?php
defined( 'ABSPATH' ) || exit;

/** Current customer e-mail templates, including those never translated yet. */
class LT_Email_Catalog {
	private static $items;

	public static function items() {
		if ( null !== self::$items ) return self::$items;
		self::$items = array();
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) return self::$items;
		$ids = (array) get_option( 'lt_email_catalog_ids', array() );
		$old_ids = $ids;
		$next = $ids ? max( array_map( 'absint', $ids ) ) + 1 : 1;
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( ! $email->is_customer_email() || empty( $email->id ) ) continue;
			$key = sanitize_key( $email->id );
			if ( empty( $ids[ $key ] ) ) $ids[ $key ] = $next++;
			$fields = array(); $labels = array();
			foreach ( LT_Email_Translations::template_fields( $email ) as $field => $label ) {
				$source = LT_Email_Translations::original( $email, $field );
				if ( '' === trim( $source ) ) continue;
				$ref = 'email_' . $field . '_' . $key;
				$fields[ $ref ] = $source;
				$labels[ $ref ] = $label;
			}
			if ( ! $fields ) continue;
			self::$items[ (int) $ids[ $key ] ] = array(
				'type' => 'email', 'id' => 0, 'title' => $email->get_title(),
				'edit' => admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( get_class( $email ) ) ),
				'block_editor' => false, 'fields' => $fields, 'labels' => $labels,
			);
		}
		if ( $ids !== $old_ids ) update_option( 'lt_email_catalog_ids', $ids, false );
		uasort( self::$items, function( $a, $b ) { return strcasecmp( $a['title'], $b['title'] ); } );
		return self::$items;
	}

	/** null means unknown, not an empty source accepted from an imported CSV. */
	public static function source( $field ) {
		foreach ( self::items() as $item ) if ( array_key_exists( $field, $item['fields'] ) ) return $item['fields'][ $field ];
		return null;
	}
}
