<?php
/**
 * Telling a Discord id from what gets pasted instead.
 *
 * @package   SugarCommerce\Discord
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Discord\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Discord\Snowflake;

/**
 * Class SnowflakeTest
 */
final class SnowflakeTest extends TestCase {

	/* ─── Ids ───────────────────────────────────────────────────────── */

	/**
	 * @param mixed  $value    What was stored or submitted.
	 * @param string $expected The id, or ''.
	 */
	#[DataProvider( 'values' )]
	public function test_only_an_id_is_an_id( mixed $value, string $expected ): void {
		$this->assertSame( $expected, Snowflake::of( $value ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function values(): array {
		return array(
			'seventeen digits'  => array( '12345678901234567', '12345678901234567' ),
			'twenty digits'     => array( '12345678901234567890', '12345678901234567890' ),
			'with spaces'       => array( '  123456789012345678 ', '123456789012345678' ),
			'too short'         => array( '1234567890123456', '' ),
			'too long'          => array( '123456789012345678901', '' ),
			'a role mention'    => array( '<@&123456789012345678>', '' ),
			'an invite link'    => array( 'https://discord.gg/abc', '' ),
			'a server name'     => array( 'My Server', '' ),
			'a trailing line'   => array( "123456789012345678\nx", '' ),
			'an integer'        => array( 1234567890123456789, '1234567890123456789' ),
			'an array'          => array( array( '123456789012345678' ), '' ),
			'nothing'           => array( null, '' ),
		);
	}
}
