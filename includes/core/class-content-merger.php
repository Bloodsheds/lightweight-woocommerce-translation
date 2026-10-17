<?php
defined( 'ABSPATH' ) || exit;

/** Keeps the source block structure and replaces only text-like block values. */
class LT_Content_Merger {
	/** Unchanged passages survive edits and reordering; changed source passages never reuse an old translation. */
	public static function from_row( $source, $row ) {
		if ( ! $row ) return $source;
		$key = hash( 'sha256', LT_VERSION . "\0" . $source . "\0" . ( $row->source_text ?? '' ) . "\0" . $row->translated_text );
		static $local = array();
		if ( array_key_exists( $key, $local ) ) return $local[ $key ];
		$cached = wp_cache_get( $key, 'lt_content' );
		if ( is_string( $cached ) ) return $local[ $key ] = $cached;
		if ( LT_Translation_Store::source_matches( $row, $source ) ) $result = self::merge( $source, $row->translated_text );
		else {
			$memory = LT_Segments::memory( (string) ( $row->source_text ?? '' ), (string) $row->translated_text, true );
			$translations = array();
			foreach ( LT_Segments::texts( $source ) as $i => $text ) $translations[ $i ] = LT_Segments::recall( (array) $memory, $text ) ?? '';
			$result = self::merge( $source, LT_Segments::rebuild( $source, $translations ) );
		}
		wp_cache_set( $key, $result, 'lt_content', HOUR_IN_SECONDS );
		return $local[ $key ] = $result;
	}
	public static function text_key( $key ) { return in_array( strtolower( (string) $key ), array( 'content', 'text', 'title', 'description', 'caption', 'alt', 'label', 'placeholder', 'heading', 'subtitle', 'subtext', 'buttontext', 'linktext', 'quote' ), true ); }
	public static function field_key( $path, $key ) { return 'block_' . implode( '_', (array) $path ) . '_attr_' . sanitize_key( $key ); }
	private static function attrs( $source, $translated ) {
		if ( ! is_array( $source ) || ! is_array( $translated ) ) return $source;
		foreach ( $source as $key => $value ) {
			if ( ! array_key_exists( $key, $translated ) ) continue;
			if ( self::text_key( $key ) && is_string( $value ) && is_string( $translated[ $key ] ) ) $source[ $key ] = $translated[ $key ];
			elseif ( is_array( $value ) && is_array( $translated[ $key ] ) ) $source[ $key ] = self::attrs( $value, $translated[ $key ] );
		}
		return $source;
	}
	private static function inline( $source, $translated ) {
		$source_tokens = preg_split( '/(<[^>]*>)/', (string) $source, -1, PREG_SPLIT_DELIM_CAPTURE );
		$translated_tokens = preg_split( '/(<[^>]*>)/', (string) $translated, -1, PREG_SPLIT_DELIM_CAPTURE );
		$source_text = array_values( array_filter( array_keys( $source_tokens ), function( $index ) use ( $source_tokens ) { return '' !== $source_tokens[ $index ] && '<' !== substr( $source_tokens[ $index ], 0, 1 ); } ) );
		$translated_text = array_values( array_filter( array_keys( $translated_tokens ), function( $index ) use ( $translated_tokens ) { return '' !== $translated_tokens[ $index ] && '<' !== substr( $translated_tokens[ $index ], 0, 1 ); } ) );
		if ( count( $source_text ) !== count( $translated_text ) ) return $source;
		foreach ( $source_text as $position => $source_index ) $source_tokens[ $source_index ] = $translated_tokens[ $translated_text[ $position ] ];
		return implode( '', $source_tokens );
	}
	private static function block( $source, $translated, &$changed ) {
		if ( ! is_array( $source ) || ! is_array( $translated ) || ( $source['blockName'] ?? '' ) !== ( $translated['blockName'] ?? '' ) ) return $source;
		$original_attrs = (array) ( $source['attrs'] ?? array() );
		$source['attrs'] = self::attrs( $original_attrs, (array) ( $translated['attrs'] ?? array() ) );
		if ( $source['attrs'] !== $original_attrs ) $changed = true;
		$source_inner = (array) ( $source['innerContent'] ?? array() );
		$translated_inner = (array) ( $translated['innerContent'] ?? array() );
		foreach ( $source_inner as $index => $content ) if ( null !== $content && isset( $translated_inner[ $index ] ) && null !== $translated_inner[ $index ] ) { $merged = self::inline( $content, $translated_inner[ $index ] ); if ( $merged !== $content ) $changed = true; $source['innerContent'][ $index ] = $merged; }
		foreach ( (array) ( $source['innerBlocks'] ?? array() ) as $index => $child ) if ( isset( $translated['innerBlocks'][ $index ] ) ) $source['innerBlocks'][ $index ] = self::block( $child, $translated['innerBlocks'][ $index ], $changed );
		return $source;
	}
	private static function block_rows( $source, $path, $rows, $language, $type, $id, &$changed ) {
		if ( ! is_array( $source ) ) return $source;
		foreach ( (array) ( $source['attrs'] ?? array() ) as $key => $value ) {
			$field = self::field_key( $path, $key );
			if ( self::text_key( $key ) && is_string( $value ) && isset( $rows[ $field ] ) ) {
				$translated = LT_Translation_Store::translate( $language, $type, $id, $field, $value );
				if ( $translated !== $value ) { $source['attrs'][ $key ] = $translated; $changed = true; }
			}
		}
		foreach ( (array) ( $source['innerBlocks'] ?? array() ) as $index => $child ) $source['innerBlocks'][ $index ] = self::block_rows( $child, array_merge( $path, array( $index ) ), $rows, $language, $type, $id, $changed );
		return $source;
	}
	public static function merge_rows( $source, $language, $type, $id ) {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) return $source;
		global $wpdb;
		$stored = LT_Translation_Store::rows( $wpdb->prepare( 'WHERE language_code=%s AND object_type=%s AND object_id=%d AND field_key LIKE %s', $language, $type, $id, 'block\_%' ) );
		$rows = array();
		foreach ( (array) $stored as $row ) $rows[ $row->field_key ] = $row;
		if ( ! $rows ) return $source;
		$blocks = parse_blocks( (string) $source );
		$changed = false;
		foreach ( $blocks as $index => $block ) $blocks[ $index ] = self::block_rows( $block, array( $index ), $rows, $language, $type, $id, $changed );
		return $changed ? serialize_blocks( $blocks ) : $source;
	}
	public static function merge( $source, $translated ) {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) || ! is_string( $translated ) || '' === trim( $translated ) ) return $source;
		$source_blocks = parse_blocks( (string) $source );
		$translated_blocks = parse_blocks( $translated );
		if ( count( $source_blocks ) !== count( $translated_blocks ) ) return $source;
		$merged = array();
		$changed = false;
		foreach ( $source_blocks as $index => $block ) $merged[] = self::block( $block, $translated_blocks[ $index ] ?? array(), $changed );
		return $changed ? serialize_blocks( $merged ) : $source;
	}
}
