<?php
/**
 * The Discord calls a role on purchase needs.
 *
 * @package   SugarCommerce\Discord
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Discord\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Discord\{Client, Identity, Joined, Permissions};
use SugarCommerce\HttpClient\{Fake, Response};

/**
 * Class ClientTest
 *
 * Against a double: nothing here reaches discord.com.
 */
final class ClientTest extends TestCase {

	private const string GUILD = '111111111111111111';
	private const string ROLE  = '222222222222222222';
	private const string USER  = '333333333333333333';
	private const string AGENT = 'DiscordBot (https://shop.example, 2.1)';

	private function client( Fake $http ): Client {
		return new Client( 'app-id', 'app-secret', 'bot-token', $http, self::AGENT );
	}

	/* ─── Links ─────────────────────────────────────────────────────── */

	public function test_the_bot_asks_only_for_what_giving_roles_needs(): void {
		parse_str( (string) parse_url( $this->client( Fake::strict() )->bot_invite_url(), PHP_URL_QUERY ), $query );

		$this->assertSame( array( 'client_id' => 'app-id', 'scope' => 'bot', 'permissions' => (string) ( 268435456 | 1 ) ), $query );
	}

	public function test_a_bot_invite_can_preselect_a_server(): void {
		parse_str( (string) parse_url( $this->client( Fake::strict() )->bot_invite_url( Permissions::MANAGE_ROLES, self::GUILD ), PHP_URL_QUERY ), $query );

		$this->assertSame( self::GUILD, $query['guild_id'] );
		$this->assertSame( '268435456', $query['permissions'] );
	}

	public function test_the_sign_in_link_asks_who_and_to_join_with_pkce(): void {
		parse_str( (string) parse_url( $this->client( Fake::strict() )->authorize_url( 'https://get.test/discord/callback', 'st', 'ch' ), PHP_URL_QUERY ), $query );

		$this->assertSame( array(
			'client_id'             => 'app-id',
			'response_type'         => 'code',
			'redirect_uri'          => 'https://get.test/discord/callback',
			'scope'                 => 'identify guilds.join',
			'state'                 => 'st',
			'code_challenge'        => 'ch',
			'code_challenge_method' => 'S256',
		), $query );
	}

	public function test_without_a_challenge_no_method_is_sent(): void {
		$this->assertStringNotContainsString( 'code_challenge', $this->client( Fake::strict() )->authorize_url( 'https://x', 'st' ) );
	}

	/* ─── Signing in ────────────────────────────────────────────────── */

	public function test_the_code_and_verifier_are_swapped_for_who_they_are(): void {
		$http = Fake::strict()
			->on( 'POST', 'https://discord.com/api/v10/oauth2/token', new Response( true, 200, '{"access_token":"user-token"}' ) )
			->on( 'GET', 'https://discord.com/api/v10/users/@me', new Response( true, 200, '{"id":"' . self::USER . '"}' ) );

		$this->assertEquals( new Identity( self::USER, 'user-token' ), $this->client( $http )->exchange( 'code', 'https://get.test/cb', 'the-verifier' ) );

		parse_str( (string) $http->sent( 'POST' )[0]['body'], $sent );

		$this->assertSame( 'the-verifier', $sent['code_verifier'] );
		$this->assertSame( 'authorization_code', $sent['grant_type'] );
		$this->assertSame( 'Bearer user-token', $http->sent( 'GET' )[0]['headers']['Authorization'] );
		$this->assertSame( self::AGENT, $http->sent( 'GET' )[0]['headers']['User-Agent'] );
	}

	public function test_a_refused_code_is_nobody(): void {
		$http = Fake::strict()->on( 'POST', '*', new Response( false, 400, '{"error":"invalid_grant"}' ) );

		$this->assertNull( $this->client( $http )->exchange( 'bad', 'https://get.test/cb' ) );
		$this->assertNull( $this->client( Fake::strict() )->exchange( '', 'https://get.test/cb' ) );
	}

	/* ─── Roles ─────────────────────────────────────────────────────── */

	public function test_a_new_member_joins_with_the_role(): void {
		$http = Fake::strict()->on( 'PUT', '*/guilds/' . self::GUILD . '/members/' . self::USER, new Response( true, 201 ) );

		$this->assertTrue( $this->client( $http )->grant( self::GUILD, self::USER, 'user-token', self::ROLE ) );

		$join = json_decode( (string) $http->sent( 'PUT' )[0]['body'], true );

		$this->assertSame( array( 'access_token' => 'user-token', 'roles' => array( self::ROLE ) ), $join );
		$this->assertSame( 'Bot bot-token', $http->sent( 'PUT' )[0]['headers']['Authorization'] );
		$this->assertSame( self::AGENT, $http->sent( 'PUT' )[0]['headers']['User-Agent'] );
	}

	/**
	 * Joining a server you are already in ignores the roles, so the role is
	 * then given on its own.
	 */
	public function test_an_existing_member_is_given_the_role_on_its_own(): void {
		$http = Fake::strict()
			->on( 'PUT', '*/members/' . self::USER . '/roles/' . self::ROLE, new Response( true, 204 ) )
			->on( 'PUT', '*/members/' . self::USER, new Response( true, 204 ) );

		$this->assertTrue( $this->client( $http )->grant( self::GUILD, self::USER, 'user-token', self::ROLE ) );
		$this->assertCount( 2, $http->sent( 'PUT' ) );
	}

	public function test_a_refused_join_gives_nothing(): void {
		$http = Fake::strict()->on( 'PUT', '*', new Response( false, 403 ) );

		$this->assertFalse( $this->client( $http )->grant( self::GUILD, self::USER, 'user-token', self::ROLE ) );
		$this->assertCount( 1, $http->sent( 'PUT' ) );
	}

	/**
	 * @return array<string, array{int, bool}>
	 */
	public static function removals(): array {
		return array(
			'removed'               => array( 204, true ),
			'left, or role deleted' => array( 404, true ),
			'refused'               => array( 403, false ),
			'unreachable'           => array( 500, false ),
		);
	}

	#[DataProvider( 'removals' )]
	public function test_removing_a_role_already_gone_counts_as_removed( int $status, bool $removed ): void {
		$http = Fake::strict()->on( 'DELETE', '*/roles/' . self::ROLE, new Response( $status < 300, $status ) );

		$this->assertSame( $removed, $this->client( $http )->remove_role( self::GUILD, self::USER, self::ROLE ) );
	}

	/**
	 * rawurlencode() leaves `..` alone, and a resolved `/members/../roles/R`
	 * is DELETE /guilds/G/roles/R: the role deleted for everybody.
	 */
	public function test_an_id_that_is_not_a_snowflake_is_never_sent(): void {
		$http   = Fake::strict();
		$client = $this->client( $http );

		$this->assertFalse( $client->remove_role( self::GUILD, '..', self::ROLE ) );
		$this->assertFalse( $client->add_role( self::GUILD, '..', self::ROLE ) );
		$this->assertFalse( $client->grant( '..', self::USER, 'user-token', self::ROLE ) );
		$this->assertSame( Joined::Failed, $client->add_member( self::GUILD, self::USER, 'user-token', array( '../..' ) ) );
		$this->assertSame( array(), $http->sent() );
	}

	public function test_an_id_that_is_not_a_snowflake_proves_nobody(): void {
		$http = Fake::strict()
			->on( 'POST', '*', new Response( true, 200, '{"access_token":"user-token"}' ) )
			->on( 'GET', '*', new Response( true, 200, '{"id":".."}' ) );

		$this->assertNull( $this->client( $http )->exchange( 'code', 'https://get.test/cb' ) );
	}

	public function test_a_token_that_is_not_a_string_proves_nobody(): void {
		$http = Fake::strict()->on( 'POST', '*', new Response( true, 200, '{"access_token":["x"]}' ) );

		$this->assertNull( $this->client( $http )->exchange( 'code', 'https://get.test/cb' ) );
	}

	public function test_joined_reads_discords_two_successes_apart(): void {
		$this->assertSame( Joined::Added, Joined::from_status( 201 ) );
		$this->assertSame( Joined::AlreadyMember, Joined::from_status( 204 ) );
		$this->assertSame( Joined::Failed, Joined::from_status( 0 ) );
	}

	/* ─── The User-Agent ────────────────────────────────────────────── */

	public function test_the_user_agent_is_the_one_discord_asks_for(): void {
		$this->assertSame( 'DiscordBot (https://shop.example, 2.1)', Client::user_agent( 'https://shop.example', '2.1' ) );
		$this->assertSame( 'DiscordBot (https://a.example, 1)', Client::user_agent( 'https://a.example,(', '1)' ), 'Nothing can break out of the brackets.' );
		$this->assertMatchesRegularExpression( '/^DiscordBot \(\S+, \S+\)$/', ( new \ReflectionClass( Client::class ) )->getConstructor()?->getParameters()[4]->getDefaultValue() );
	}

	public function test_the_user_agent_cannot_carry_a_line_break(): void {
		$this->assertSame( 'DiscordBot (https://a.exampleX-Evil: 1, 1)', Client::user_agent( "https://a.example\r\nX-Evil: 1", "1\n" ) );
	}

	/* ─── Secrets ───────────────────────────────────────────────────── */

	public function test_a_dump_does_not_show_the_secrets(): void {
		$dump = print_r( $this->client( Fake::strict() ), true ) . print_r( new Identity( self::USER, 'user-token' ), true );

		$this->assertStringNotContainsString( 'app-secret', $dump );
		$this->assertStringNotContainsString( 'bot-token', $dump );
		$this->assertStringNotContainsString( 'user-token', $dump );
		$this->assertStringContainsString( self::USER, $dump );
	}

	/* ─── Permissions and configuration ─────────────────────────────── */

	public function test_permission_bits_are_discords(): void {
		$this->assertSame( 268435457, Permissions::ROLE_GIVER );
		$this->assertTrue( Permissions::has( Permissions::ROLE_GIVER, Permissions::MANAGE_ROLES ) );
		$this->assertFalse( Permissions::has( Permissions::ROLE_GIVER, Permissions::ADMINISTRATOR ) );
	}

	public function test_configured_needs_all_three(): void {
		$this->assertTrue( $this->client( Fake::strict() )->configured() );
		$this->assertFalse( ( new Client( 'id', 'secret', '' ) )->configured() );
	}

	/* ─── Results that must be checked ──────────────────────────────── */

	/**
	 * @return array<string, array{string}>
	 */
	public static function must_check(): array {
		return array( 'exchange' => array( 'exchange' ), 'grant' => array( 'grant' ), 'remove_role' => array( 'remove_role' ) );
	}

	#[DataProvider( 'must_check' )]
	public function test_the_result_must_be_used( string $method ): void {
		$attributes = ( new \ReflectionMethod( Client::class, $method ) )->getAttributes( \NoDiscard::class );

		$this->assertCount( 1, $attributes, $method . ' lost #[\NoDiscard].' );
		$this->assertNotSame( '', $attributes[0]->newInstance()->message );
	}
}
