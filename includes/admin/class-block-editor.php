<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gutenberg "in place" translation.
 *
 * The editor loads the translated version of the page directly in the canvas: pick a language,
 * click any text, type the translation, press "Save translation". The WordPress post itself
 * (original language) is never modified while a secondary language is active.
 */
class LT_Block_Editor {
	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

	private function __construct() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'assets' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	private function editor_post() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$post    = $post_id ? get_post( $post_id ) : get_post();
		return $post instanceof WP_Post ? $post : null;
	}

	/** Translatable fields of a post, with their current original value. */
	private function fields( WP_Post $post ) {
		$fields = array( 'title' => $post->post_title, 'content' => $post->post_content, 'slug' => $post->post_name );
		if ( post_type_supports( $post->post_type, 'excerpt' ) ) $fields['excerpt'] = $post->post_excerpt;
		return $fields;
	}

	/** What the editor must display for one language (null = not translated yet → the original is copied). */
	private function editor_translation( WP_Post $post, $language ) {
		$out = array( 'title' => null, 'content' => null, 'excerpt' => null, 'slug' => '', 'stale' => false );
		foreach ( $this->fields( $post ) as $field => $source ) {
			$row = LT_Translation_Store::get_translated( $language, $post->post_type, $post->ID, $field );
			if ( ! $row ) continue;
			$out[ $field ] = in_array( $field, array( 'content', 'excerpt' ), true ) ? LT_Content_Merger::from_row( (string) $source, $row ) : $row->translated_text;
			if ( 'slug' !== $field && ! LT_Translation_Store::source_matches( $row, $source ) ) $out['stale'] = true;
		}
		if ( null !== $out['content'] ) {
			// The editor must always use the current original structure. Only translated text is injected.
			$out['content'] = LT_Content_Merger::merge( $post->post_content, $out['content'] );
		}
		if ( null !== $out['excerpt'] ) $out['excerpt'] = LT_Content_Merger::merge( $post->post_excerpt, $out['excerpt'] );
		if ( null === $out['content'] ) {
			// Legacy data from v0.3 (one row per block attribute).
			$legacy = LT_Content_Merger::merge_rows( $post->post_content, $language, $post->post_type, $post->ID );
			if ( $legacy !== $post->post_content ) $out['content'] = $legacy;
		}
		return $out;
	}

	public function assets() {
		$post = $this->editor_post();
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) return;
		if ( function_exists( 'use_block_editor_for_post' ) && ! use_block_editor_for_post( $post ) ) return;

		$router    = LT_Language_Router::instance();
		$default   = $router->default_language();
		$languages = $router->languages();
		if ( count( $languages ) < 2 ) return;

		$translations = array();
		foreach ( $languages as $language ) if ( $language !== $default ) $translations[ $language ] = $this->editor_translation( $post, $language );

		$initial = isset( $_GET['lt_lang'] ) ? sanitize_key( wp_unslash( $_GET['lt_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $translations[ $initial ] ) ) $initial = '';

		wp_enqueue_script( 'lt-block-editor', plugins_url( 'assets/js/block-editor.js', LT_FILE ), array( 'wp-api-fetch', 'wp-blocks', 'wp-components', 'wp-data', 'wp-dom-ready', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-notices', 'wp-plugins' ), LT_VERSION, true );
		wp_add_inline_script( 'lt-block-editor', 'window.LTBlockTranslation = ' . wp_json_encode( array(
			'postId'          => $post->ID,
			'restPath'        => '/light-translation/v1/post/' . $post->ID . '/translation',
			'defaultLanguage' => $default,
			'initialLanguage' => $initial,
			'supportsExcerpt' => post_type_supports( $post->post_type, 'excerpt' ),
			'isFrontPage'     => LT_Language_Router::is_front_page_post( $post ),
			'languages'       => array_map( function( $language ) { return array( 'code' => $language, 'label' => LT_Language_Router::language_label( $language ), 'flag' => LT_Language_Router::flag( $language ) ); }, $languages ),
			'translations'    => (object) $translations,
		) ) . ';', 'before' );
		if ( function_exists( 'wp_set_script_translations' ) ) wp_set_script_translations( 'lt-block-editor', 'lightweight-translation', LT_DIR . 'languages' );
	}

	public function routes() {
		register_rest_route( 'light-translation/v1', '/post/(?P<id>\d+)/translation', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => function( $request ) { return current_user_can( 'edit_post', absint( $request['id'] ) ); },
			'callback'            => array( $this, 'save_translation' ),
		) );
		register_rest_route( 'light-translation/v1', '/post/(?P<id>\d+)/translation/reset', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => function( $request ) { return current_user_can( 'edit_post', absint( $request['id'] ) ); },
			'callback'            => array( $this, 'reset_translation' ),
		) );
	}

	public function save_translation( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );
		if ( ! $post ) return new WP_Error( 'lt_post_missing', __( 'Post not found.', 'lightweight-translation' ), array( 'status' => 404 ) );
		$router   = LT_Language_Router::instance();
		$language = sanitize_key( (string) $request->get_param( 'language' ) );
		if ( $language === $router->default_language() || ! in_array( $language, $router->languages(), true ) ) return new WP_Error( 'lt_language_invalid', __( 'Invalid translation language.', 'lightweight-translation' ), array( 'status' => 400 ) );

		// Sources always come from the database (never from the browser) so the hash matches what visitors see.
		foreach ( $this->fields( $post ) as $field => $source ) {
			$value = $request->get_param( $field );
			if ( null === $value || is_array( $value ) ) continue;
			// REST parameters are NOT slashed: no wp_unslash() here, it would corrupt block attributes (<…).
			$value = 'slug' === $field ? sanitize_title( $value ) : (string) $value;
			if ( in_array( $field, array( 'content', 'excerpt' ), true ) ) {
				// Never store structural changes made while translating. The original owns all layout and settings.
				$value = LT_Content_Merger::merge( (string) $source, $value );
			}
			if ( 'slug' !== $field && $value === $source ) $value = ''; // Unchanged copy of the original = not translated.
			LT_Translation_Store::save( array(
				'language_code'   => $language,
				'object_type'     => $post->post_type,
				'object_id'       => $post->ID,
				'field_key'       => $field,
				'source_text'     => $source,
				'translated_text' => $value,
				'status'          => '' === trim( $value ) ? 'empty' : 'translated',
			) );
		}
		return rest_ensure_response( array( 'saved' => true, 'translation' => $this->editor_translation( $post, $language ) ) );
	}

	/** Deletes the translation of a post for one language (or all with language=all). */
	public function reset_translation( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );
		if ( ! $post ) return new WP_Error( 'lt_post_missing', __( 'Post not found.', 'lightweight-translation' ), array( 'status' => 404 ) );
		$router   = LT_Language_Router::instance();
		$language = sanitize_key( (string) $request->get_param( 'language' ) );
		if ( 'all' !== $language && ( $language === $router->default_language() || ! in_array( $language, $router->languages(), true ) ) ) return new WP_Error( 'lt_language_invalid', __( 'Invalid translation language.', 'lightweight-translation' ), array( 'status' => 400 ) );
		$count = LT_Translation_Reset::reset( $post->post_type, $post->ID, 'all' === $language ? '' : $language );
		return rest_ensure_response( array( 'deleted' => $count ) );
	}
}
