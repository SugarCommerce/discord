<?php
/**
 * Who signed in with Discord.
 *
 * @package   SugarCommerce\Discord
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Discord;

/**
 * Class Identity
 *
 * The user's id, which is what to keep — it is how a role is taken back
 * later — and their access token, which is not. The token is what lets a
 * bot add them to a server they are not in (the `guilds.join` scope); use
 * it for that, once, and let it go.
 *
 * @since 1.0.0
 */
final readonly class Identity {

	/**
	 * @since 1.0.0
	 *
	 * @param string $user  Their Discord user id (a snowflake).
	 * @param string $token Their access token. Do not store it.
	 */
	public function __construct(
		public string $user,
		#[\SensitiveParameter] public string $token,
	) {}
}
