<?php
defined( 'ABSPATH' ) || exit;

/** Uses the core term editor's shared UI and storage, with WooCommerce-specific hooks. */
class LT_WooCommerce_Attribute_Editor extends LT_Term_Editor {
	public function __construct() {
		add_action( 'woocommerce_after_edit_attribute_fields', array( $this, 'attribute_edit_fields' ) );
		add_action( 'woocommerce_after_add_attribute_fields', array( $this, 'attribute_add_fields' ) );
		add_action( 'woocommerce_attribute_added', array( $this, 'save_attribute' ) );
		add_action( 'woocommerce_attribute_updated', array( $this, 'save_attribute' ) );
	}
	public function attribute_edit_fields() {
		$id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<tr class="lt-hidden-row" style="display:none"><td colspan="2">' . $this->container( 'lt_attr', array( 'label' => '#attribute_label' ), $this->stored( 'attribute', $id, array( 'label' ) ), 'lt_save_attribute', 'lt_attribute_nonce' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function attribute_add_fields() {
		echo '<div style="display:none">' . $this->container( 'lt_attr', array( 'label' => '#attribute_label' ), $this->stored( 'attribute', 0, array( 'label' ) ), 'lt_save_attribute', 'lt_attribute_nonce' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function save_attribute( $attribute_id ) {
		if ( empty( $_POST['lt_attribute_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lt_attribute_nonce'] ) ), 'lt_save_attribute' ) || ! current_user_can( 'manage_product_terms' ) || empty( $_POST['lt_attr'] ) ) return;
		$attribute = function_exists( 'wc_get_attribute' ) ? wc_get_attribute( absint( $attribute_id ) ) : null;
		if ( ! $attribute ) return;
		$this->store( 'attribute', absint( $attribute_id ), (array) wp_unslash( $_POST['lt_attr'] ), array( 'label' => $attribute->name ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
}
