<?php
defined( 'ABSPATH' ) || exit;

/** Keep gettext identities separate across text domains, including wp.i18n contexts. */
class LT_String_Keys {
	public static function field( $domain, $msgid ) {
		return 'string_' . md5( (string) $domain . "\0" . (string) $msgid );
	}

	public static function legacy( $msgid ) { return 'string_' . md5( (string) $msgid ); }
}
