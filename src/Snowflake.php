<?php
/**
 * Discord ids, checked.
 *
 * @package   SugarCommerce\Discord
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Discord;

/**
 * Class Snowflake
 *
 * Every Discord id — a server, a role, a user — is a snowflake: 17 to 20
 * digits. What a seller pastes into a settings form is often something
 * else — a server's name, an invite link, a role mention like `<@&123…>` —
 * and that is better refused when it is saved than sent to Discord on a
 * buyer's click, where it fails with nobody watching.
 *
 * Kept as a string: a snowflake overflows a 32-bit integer and loses digits
 * as a float, so one that went through a number is a different id.
 *
 * @since 1.0.0
 */
final readonly class Snowflake {

	/**
	 * A Discord id, or '' when the value is not one.
	 *
	 * Surrounding whitespace is forgiven; anything else is not.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Stored or submitted value.
	 *
	 * @return string
	 */
	public static function of( mixed $value ): string {
		$value = trim( is_scalar( $value ) ? (string) $value : '' );

		return 1 === preg_match( '/^\d{17,20}$/D', $value ) ? $value : '';
	}
}
