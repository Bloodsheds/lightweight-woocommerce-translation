<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cuts a long content (Gutenberg blocks, HTML, shortcodes) into paragraphs a person can translate one by one,
 * and puts the translated paragraphs back in place: block comments, tags and shortcodes are never touched.
 * Inline formatting (links, bold…) stays inside its paragraph.
 */
class LT_Segments {
	const INLINE = 'a|abbr|b|bdi|bdo|br|cite|code|data|del|dfn|em|font|i|ins|kbd|mark|q|s|samp|small|span|strong|sub|sup|time|u|var';

	/** @return array [ 'tokens' => [ [ 'r', raw ] | [ 's', index ] ], 'segs' => [ index => text ] ] */
	public static function split( $html ) {
		$pieces = preg_split( '#(<!--.*?-->|</?[a-zA-Z][^>]*>|\[/?[a-zA-Z_][^\]]*\])#s', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$tokens = array();
		$segs   = array();
		$buffer = '';
		$flush  = function() use ( &$buffer, &$tokens, &$segs ) {
			if ( '' === $buffer ) return;
			if ( preg_match( '/\p{L}/u', strip_tags( $buffer ) ) && preg_match( '/^(\s*)(.*?)(\s*)$/su', $buffer, $m ) ) {
				if ( '' !== $m[1] ) $tokens[] = array( 'r', $m[1] );
				list( $before, $core, $after ) = self::peel( $m[2] );
				if ( '' !== $before ) $tokens[] = array( 'r', $before );
				$segs[]   = $core;
				$tokens[] = array( 's', count( $segs ) - 1 );
				if ( '' !== $after ) $tokens[] = array( 'r', $after );
				if ( '' !== $m[3] ) $tokens[] = array( 'r', $m[3] );
			} else {
				$tokens[] = array( 'r', $buffer );
			}
			$buffer = '';
		};
		foreach ( $pieces as $i => $piece ) {
			if ( '' === $piece ) continue;
			if ( 0 === $i % 2 || preg_match( '#^</?(?:' . self::INLINE . ')\b#i', $piece ) ) { $buffer .= $piece; continue; }
			$flush();
			$tokens[] = array( 'r', $piece );
		}
		$flush();
		return array( 'tokens' => $tokens, 'segs' => $segs );
	}

	/** A paragraph that is all inside one link / bold…: the wrapping tags stay out of it ("<a href=…>Shop</a>" → "Shop"). */
	private static function peel( $text ) {
		$before = '';
		$after  = '';
		while ( preg_match( '#^(<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>)(.*)(</\2\s*>)$#s', $text, $m ) ) {
			// The opening tag must close at the very end (not "<a>One</a> and <a>Two</a>").
			$depth = 0;
			$ok    = true;
			preg_match_all( '#<(/?)' . preg_quote( $m[2], '#' ) . '\b[^>]*>#i', $m[3], $tags, PREG_SET_ORDER );
			foreach ( $tags as $tag ) { $depth += '/' === $tag[1] ? -1 : 1; if ( $depth < 0 ) { $ok = false; break; } }
			if ( ! $ok || 0 !== $depth || ! preg_match( '/\p{L}/u', strip_tags( $m[3] ) ) ) break;
			$before .= $m[1];
			$after   = $m[4] . $after;
			$text    = $m[3];
		}
		return array( $before, $text, $after );
	}

	/**
	 * Tags inside a paragraph shown as short numbered markers ("<a href=…>Shop</a>" → "<a1>Shop</a1>"),
	 * so the translator only moves markers; the real tags are put back when saving.
	 */
	public static function shorten( $text ) {
		$n   = 0;
		$open = array();
		return preg_replace_callback( '#<(/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(/?)>#', function( $m ) use ( &$n, &$open ) {
			$tag = strtolower( $m[2] );
			if ( '/' === $m[1] ) { $num = array_pop( $open[ $tag ] ); return '</' . $tag . ( $num ?: '' ) . '>'; }
			$n++;
			if ( 'br' === $tag || '/' === $m[3] ) return '<' . $tag . $n . '/>';
			$open[ $tag ][] = $n;
			return '<' . $tag . $n . '>';
		}, (string) $text );
	}

	/** Puts the real tags of the original back in place of the short markers of a translation. */
	public static function expand( $original, $translation ) {
		$tags = array();
		$n    = 0;
		$open = array();
		preg_replace_callback( '#<(/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(/?)>#', function( $m ) use ( &$tags, &$n, &$open ) {
			$tag = strtolower( $m[2] );
			if ( '/' === $m[1] ) { $num = array_pop( $open[ $tag ] ); $tags[ '</' . $tag . ( $num ?: '' ) . '>' ] = $m[0]; return ''; }
			$n++;
			if ( 'br' === $tag || '/' === $m[3] ) { $tags[ '<' . $tag . $n . '/>' ] = $m[0]; return ''; }
			$open[ $tag ][] = $n;
			$tags[ '<' . $tag . $n . '>' ] = $m[0];
			return '';
		}, (string) $original );
		if ( ! $tags ) return (string) $translation;
		// Markers typed with another case or spacing are accepted too.
		return preg_replace_callback( '#<\s*(/?)\s*([a-zA-Z]+)\s*(\d+)\s*(/?)\s*>#', function( $m ) use ( $tags ) {
			$key = '<' . $m[1] . strtolower( $m[2] ) . $m[3] . $m[4] . '>';
			return $tags[ $key ] ?? $m[0];
		}, (string) $translation );
	}

	/** Paragraphs of a content (inner tags as short markers). */
	public static function texts( $html ) { $split = self::split( $html ); return array_map( array( __CLASS__, 'shorten' ), $split['segs'] ); }

	/** The content with each paragraph replaced by its translation (short markers or real tags; empty = original kept). */
	public static function rebuild( $html, $translations ) {
		$split = self::split( $html );
		$out   = '';
		foreach ( $split['tokens'] as $token ) {
			if ( 'r' === $token[0] ) { $out .= $token[1]; continue; }
			$t    = (string) ( $translations[ $token[1] ] ?? '' );
			$out .= '' !== trim( $t ) ? self::expand( $split['segs'][ $token[1] ], $t ) : $split['segs'][ $token[1] ];
		}
		return $out;
	}

	private static function key( $text ) { return md5( preg_replace( '/\s+/u', ' ', trim( (string) $text ) ) ); }

	/**
	 * Translation of every paragraph of an old original, read from the old translation (same structure needed).
	 * @return array|null [ key of the original paragraph => translated paragraph ], null when the structures differ.
	 */
	public static function memory( $old_source, $old_translation, $skip_identical = false ) {
		$a = self::texts( $old_source );
		$b = self::texts( $old_translation );
		if ( count( $a ) !== count( $b ) ) return null;
		$map = array(); $ambiguous = array();
		foreach ( $a as $i => $text ) {
			if ( $skip_identical && trim( $text ) === trim( $b[ $i ] ) ) continue; // left in the original language
			$key = self::key( $text );
			if ( isset( $ambiguous[ $key ] ) ) continue;
			if ( isset( $map[ $key ] ) && $map[ $key ] !== $b[ $i ] ) { unset( $map[ $key ] ); $ambiguous[ $key ] = true; continue; }
			$map[ $key ] = $b[ $i ];
		}
		return $map;
	}

	/** Translation of one paragraph from a memory (null when unknown). */
	public static function recall( $memory, $text ) { $key = self::key( $text ); return isset( $memory[ $key ] ) ? $memory[ $key ] : null; }

	/** Does this field read better as paragraphs than as one box? */
	public static function is_long( $text ) { return (bool) preg_match( '#<(?:!--|/?[a-zA-Z])|\[/?[a-zA-Z_]#', (string) $text ) && count( self::texts( $text ) ) > 1; }
}
