<?php
/**
 * Discord permission bits.
 *
 * @package   SugarCommerce\Discord
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Discord;

/**
 * Class Permissions
 *
 * The bits a bot that hands out roles may need, by name, so an invite link
 * does not carry a magic number nobody can review. Ask for the least that
 * works: a bot invited with Administrator is a server takeover waiting for
 * its token to leak.
 *
 * Giving a role needs Manage Roles, and the bot's own highest role must sit
 * above the role it gives — Discord refuses otherwise, and no permission
 * bit changes that. Adding somebody to a server with their token needs
 * Create Instant Invite.
 *
 * @since 1.0.0
 */
final readonly class Permissions {

	/**
	 * Create an invite; required to add a member with their token.
	 */
	public const int CREATE_INSTANT_INVITE = 1 << 0;

	/**
	 * Remove a member.
	 */
	public const int KICK_MEMBERS = 1 << 1;

	/**
	 * Everything. Never ask for it.
	 */
	public const int ADMINISTRATOR = 1 << 3;

	/**
	 * Read messages in channels; most bots need it to be seen at all.
	 */
	public const int VIEW_CHANNEL = 1 << 10;

	/**
	 * Give and take roles below the bot's own.
	 */
	public const int MANAGE_ROLES = 1 << 28;

	/**
	 * What a bot that gives roles on purchase needs: Manage Roles, and
	 * Create Instant Invite for adding a buyer who is not in the server yet.
	 */
	public const int ROLE_GIVER = self::MANAGE_ROLES | self::CREATE_INSTANT_INVITE;

	/**
	 * Whether a set of bits includes a permission.
	 *
	 * @since 1.0.0
	 *
	 * @param int $bits       A permission set.
	 * @param int $permission One of the constants.
	 *
	 * @return bool
	 */
	public static function has( int $bits, int $permission ): bool {
		return $permission === ( $bits & $permission );
	}
}
