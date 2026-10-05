<?php
/**
 * What adding somebody to a server came to.
 *
 * @package   SugarCommerce\Discord
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Discord;

/**
 * Enum Joined
 *
 * Discord's "add guild member" answers 201 when it added them — with the
 * roles you sent — and 204 when they were already a member, in which case
 * it ignores the roles entirely. Reading both as "done" leaves an existing
 * member without the role they paid for.
 *
 * @since 1.0.0
 */
enum Joined {

	/**
	 * Added to the server, with the roles sent.
	 */
	case Added;

	/**
	 * Already a member: nothing changed, roles included.
	 */
	case AlreadyMember;

	/**
	 * Refused, or Discord could not be reached.
	 */
	case Failed;

	/**
	 * From the status Discord answered with.
	 *
	 * @since 1.0.0
	 *
	 * @param int $status HTTP status.
	 *
	 * @return self
	 */
	public static function from_status( int $status ): self {
		return match ( $status ) {
			201     => self::Added,
			204     => self::AlreadyMember,
			default => self::Failed,
		};
	}
}
