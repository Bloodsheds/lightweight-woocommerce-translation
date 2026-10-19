<?php
defined( 'ABSPATH' ) || exit;

/** Text-only HTML mapping: no image alt text, ARIA metadata, scripts, user values or form configuration. */
class LT_Public_HTML {
	public static function map( $html, $callback ) {
		$stack = array();
		$void = array_flip( array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' ) );
		return preg_replace_callback( '~<!--.*?-->|<(script|style|noscript|template|svg)\b[^>]*>.*?</\1\s*>|<(/?)([a-zA-Z][\w:-]*)((?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*)>|[^<]+|<~s', function( $m ) use ( &$stack, $void, $callback ) {
			$token = $m[0];
			if ( ! isset( $m[3] ) || '' === $m[3] ) {
				if ( '<' === $token[0] || ( $stack && end( $stack )['skip'] ) ) return $token;
				$text = html_entity_decode( $token, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( ! preg_match( '/\p{L}{2}/u', $text ) ) return $token;
				$out = call_user_func( $callback, $text, '', $stack );
				return $out === $text ? $token : esc_html( $out );
			}
			$tag = strtolower( $m[3] );
			if ( '/' === $m[2] ) {
				for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) if ( $stack[ $i ]['tag'] === $tag ) { $stack = array_slice( $stack, 0, $i ); break; }
				return $token;
			}
			$attrs = array();
			preg_match_all( '~([\w:-]+)\s*=\s*("([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))~s', $m[4], $matches, PREG_SET_ORDER );
			foreach ( $matches as $attr ) $attrs[ strtolower( $attr[1] ) ] = html_entity_decode( '"' === $attr[2][0] ? ( $attr[3] ?? '' ) : ( "'" === $attr[2][0] ? ( $attr[4] ?? '' ) : ( $attr[5] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$class = $attrs['class'] ?? '';
			$skip = ( $stack && end( $stack )['skip'] ) || in_array( $attrs['id'] ?? '', array( 'wpadminbar', 'lt-check-bar', 'lt-check-detail' ), true ) || preg_match( '/(?:^|\s)(?:screen-reader-text|sr-only|visually-hidden|edit-link|post-edit-link|wd-logo|site-logo|custom-logo-link|lt-language-switcher-wrap|lt-language-switcher-list|lt-menu-switcher-item|lt-language-switcher-mobile)(?:\s|$)/', $class ) || 'true' === ( $attrs['aria-hidden'] ?? '' );
			$node = array( 'tag' => $tag, 'class' => $class, 'id' => $attrs['id'] ?? '', 'skip' => (bool) $skip );
			if ( ! $skip ) {
				$tooltip = in_array( $tag, array( 'a', 'button', 'input', 'abbr' ), true ) || preg_match( '/(?:^|\s)(?:wd-tooltip|has-tooltip)(?:\s|$)/', $class );
				$button = 'input' === $tag && in_array( strtolower( $attrs['type'] ?? '' ), array( 'submit', 'button', 'reset' ), true );
				$token = preg_replace_callback( '~(?<![\w:-])([\w:-]+)\s*=\s*("([^"]*)"|\x27([^\x27]*)\x27)~i', function( $a ) use ( $tag, $tooltip, $button, $callback, $stack, $node ) {
					$attribute = strtolower( $a[1] );
					if ( ! in_array( $attribute, array( 'placeholder', 'title', 'value' ), true ) ) return $a[0];
					if ( ( 'placeholder' === $attribute && ! in_array( $tag, array( 'input', 'textarea' ), true ) ) || ( 'title' === $attribute && ! $tooltip ) || ( 'value' === $attribute && ! $button ) ) return $a[0];
					$text = html_entity_decode( '"' === $a[2][0] ? ( $a[3] ?? '' ) : ( $a[4] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$out = call_user_func( $callback, $text, $attribute, array_merge( $stack, array( $node ) ) );
					return $out === $text ? $a[0] : $a[1] . '="' . esc_attr( $out ) . '"';
				}, $token );
			}
			if ( 'textarea' === $tag ) $node['skip'] = true;
			if ( ! isset( $void[ $tag ] ) && '/' !== substr( rtrim( $m[4] ), -1 ) ) $stack[] = $node;
			return $token;
		}, (string) $html );
	}
}
