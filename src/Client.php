<?php
/**
 * The Discord calls a role on purchase needs.
 *
 * @package   SugarCommerce\Discord
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Discord;

use SugarCommerce\HttpClient\Client as Http;

/**
 * Class Client
 *
 * One Discord application: a server owner invites its bot; a buyer signs
 * in with Discord once, which proves who they are and lets the bot add
 * them to the server; the bot gives them the role, and takes it back.
 *
 * What it gets right that is easy not to:
 *
 * - **The User-Agent.** Discord's API terms require bots to send
 *   `DiscordBot ($url, $version)`; a default library agent can be blocked.
 *   {@see self::user_agent()} builds one, and every bot call sends it.
 * - **An existing member.** Adding somebody already in the server is a 204
 *   that ignores the roles sent, so {@see self::grant()} then gives the
 *   role on its own.
 * - **A role already gone.** Removing a role from somebody who left, or a
 *   role that was deleted, is a 404 — and they do not have it, which is
 *   what was asked.
 * - **PKCE.** {@see self::authorize_url()} and {@see self::exchange()} take
 *   a challenge and its verifier; make them, and bind the state to the
 *   browser, with `sugarcommerce/oauth`.
 *
 * Not final: an application adds a factory from its own configuration.
 *
 * @since 1.0.0
 */
readonly class Client {

	/**
	 * Discord's API, pinned to a version.
	 */
	public const string API = 'https://discord.com/api/v10';

	/**
	 * Where people go to authorise an application.
	 */
	public const string AUTHORIZE = 'https://discord.com/oauth2/authorize';

	/**
	 * What a buyer is asked for: who they are, and leave to be added to a
	 * server they are not in yet.
	 */
	public const array SCOPES = array( 'identify', 'guilds.join' );

	/**
	 * Hold one application's credentials and the HTTP client to call with.
	 *
	 * @since 1.0.0
	 *
	 * @param string $client_id     Application id.
	 * @param string $client_secret Application secret.
	 * @param string $bot_token     The application's bot token.
	 * @param Http   $http          HTTP client. Substitute `HttpClient\Fake` in tests.
	 * @param string $user_agent    `DiscordBot ($url, $version)`; see user_agent().
	 */
	public function __construct(
		protected string $client_id,
		#[\SensitiveParameter] protected string $client_secret,
		#[\SensitiveParameter] protected string $bot_token,
		protected Http $http = new Http(),
		protected string $user_agent = 'DiscordBot (https://github.com/sugarcommerce/discord, 1.0.0)',
	) {}

	/**
	 * The User-Agent Discord asks bots to send.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url     Your application's site.
	 * @param string $version Your application's version.
	 *
	 * @return string `DiscordBot ($url, $version)`.
	 */
	public static function user_agent( string $url, string $version ): string {
		// Parentheses and commas would break Discord's format; control
		// characters would break the header itself.
		$clean = static fn ( string $part ): string => (string) preg_replace( '/[(),\x00-\x1F\x7F]/', '', $part );

		return 'DiscordBot (' . $clean( $url ) . ', ' . $clean( $version ) . ')';
	}

	/**
	 * What var_dump() and print_r() show: the credentials, redacted.
	 *
	 * #[\SensitiveParameter] keeps the secrets out of stack traces; this
	 * keeps them out of a debug dump of the client itself.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function __debugInfo(): array {
		return array(
			'client_id'     => $this->client_id,
			'client_secret' => '' === $this->client_secret ? '' : '[redacted]',
			'bot_token'     => '' === $this->bot_token ? '' : '[redacted]',
			'user_agent'    => $this->user_agent,
		);
	}

	/**
	 * Whether all three credentials are set.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function configured(): bool {
		return '' !== $this->client_id && '' !== $this->client_secret && '' !== $this->bot_token;
	}

	/**
	 * Where a server owner goes to add the bot.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $permissions Bits from {@see Permissions}.
	 * @param string $guild       A server to preselect, or ''.
	 *
	 * @return string
	 */
	public function bot_invite_url( int $permissions = Permissions::ROLE_GIVER, string $guild = '' ): string {
		return self::AUTHORIZE . '?' . http_build_query( array_filter( array(
			'client_id'   => $this->client_id,
			'scope'       => 'bot',
			'permissions' => (string) $permissions,
			'guild_id'    => $guild,
		), static fn ( string $value ): bool => '' !== $value ) );
	}

	/**
	 * Where a buyer goes to say who they are on Discord.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $redirect  Your callback URL, as registered.
	 * @param string   $state     State, bound to this browser.
	 * @param string   $challenge PKCE challenge (S256), or '' to send none.
	 * @param string[] $scopes    What to ask for.
	 *
	 * @return string
	 */
	public function authorize_url( string $redirect, string $state, string $challenge = '', array $scopes = self::SCOPES ): string {
		return self::AUTHORIZE . '?' . http_build_query( array_filter( array(
			'client_id'             => $this->client_id,
			'response_type'         => 'code',
			'redirect_uri'          => $redirect,
			'scope'                 => implode( ' ', $scopes ),
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => '' === $challenge ? '' : 'S256',
		), static fn ( string $value ): bool => '' !== $value ) );
	}

	/**
	 * Swap the callback's code for the buyer's token, and ask who they are.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code     The code from the callback.
	 * @param string $redirect The same callback URL as the authorisation.
	 * @param string $verifier PKCE verifier, or '' when no challenge was sent.
	 *
	 * @return Identity|null Null when Discord refused the code or would not
	 *                       say who it was for.
	 */
	#[\NoDiscard( 'Null means nobody was proved.' )]
	public function exchange( string $code, string $redirect, #[\SensitiveParameter] string $verifier = '' ): ?Identity {
		if ( '' === $code ) {
			return null;
		}

		$response = $this->http->post(
			self::API . '/oauth2/token',
			http_build_query( array_filter( array(
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $redirect,
				'code_verifier' => $verifier,
			), static fn ( string $value ): bool => '' !== $value ) ),
			array( 'Content-Type' => 'application/x-www-form-urlencoded', 'User-Agent' => $this->user_agent )
		);

		$token = $response->ok ? self::field( $response->json(), 'access_token' ) : '';

		if ( '' === $token ) {
			return null;
		}

		$me   = $this->http->get( self::API . '/users/@me', array( 'Authorization' => 'Bearer ' . $token, 'User-Agent' => $this->user_agent ) );
		$user = Snowflake::of( $me->ok ? self::field( $me->json(), 'id' ) : '' );

		return '' === $user ? null : new Identity( $user, $token );
	}

	/**
	 * Add somebody to a server, with roles.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $guild Server id.
	 * @param string   $user  Their user id.
	 * @param string   $token Their access token, with `guilds.join`.
	 * @param string[] $roles Role ids to join with.
	 *
	 * @return Joined Added; AlreadyMember, when the roles were ignored; or
	 *                Failed, including when an id is not a snowflake.
	 */
	public function add_member( string $guild, string $user, #[\SensitiveParameter] string $token, array $roles = array() ): Joined {
		if ( ! self::ids( $guild, $user, ...$roles ) ) {
			return Joined::Failed;
		}

		$response = $this->http->put(
			self::API . '/guilds/' . rawurlencode( $guild ) . '/members/' . rawurlencode( $user ),
			(string) json_encode( array( 'access_token' => $token ) + ( $roles ? array( 'roles' => array_values( $roles ) ) : array() ) ),
			$this->bot() + array( 'Content-Type' => 'application/json' )
		);

		return Joined::from_status( $response->status );
	}

	/**
	 * Give a member a role.
	 *
	 * @since 1.0.0
	 *
	 * @param string $guild Server id.
	 * @param string $user  Their user id.
	 * @param string $role  Role id.
	 *
	 * @return bool Whether they have it now. False, without a call, when an
	 *              id is not a snowflake.
	 */
	public function add_role( string $guild, string $user, string $role ): bool {
		if ( ! self::ids( $guild, $user, $role ) ) {
			return false;
		}

		return $this->http->put( $this->role_url( $guild, $user, $role ), '', $this->bot() )->ok;
	}

	/**
	 * Take a role away. The member stays in the server.
	 *
	 * @since 1.0.0
	 *
	 * @param string $guild Server id.
	 * @param string $user  Their user id.
	 * @param string $role  Role id.
	 *
	 * @return bool True when it is gone, including when it already was.
	 *              False, without a call, when an id is not a snowflake.
	 */
	#[\NoDiscard( 'False means they may still have the role.' )]
	public function remove_role( string $guild, string $user, string $role ): bool {
		if ( ! self::ids( $guild, $user, $role ) ) {
			return false;
		}

		$response = $this->http->delete( $this->role_url( $guild, $user, $role ), $this->bot() );

		// 404: they left, or the role was deleted. Either way they do not
		// have it, which is what was asked.
		return $response->ok || 404 === $response->status;
	}

	/**
	 * Put somebody in the server with a role, whether or not they are in it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $guild Server id.
	 * @param string $user  Their user id.
	 * @param string $token Their access token, with `guilds.join`.
	 * @param string $role  Role id.
	 *
	 * @return bool Whether they have the role now.
	 */
	#[\NoDiscard( 'False means they did not get the role.' )]
	public function grant( string $guild, string $user, #[\SensitiveParameter] string $token, string $role ): bool {
		return match ( $this->add_member( $guild, $user, $token, array( $role ) ) ) {
			Joined::Added         => true,
			Joined::AlreadyMember => $this->add_role( $guild, $user, $role ),
			Joined::Failed        => false,
		};
	}

	/**
	 * Whether every id is a snowflake.
	 *
	 * The ids go into the path of a bot call, and rawurlencode() leaves a
	 * `..` alone: a user id of `..` would turn "remove this member's role"
	 * into DELETE /guilds/{guild}/roles/{role} — deleting the role for
	 * everybody — once the dot segment is resolved.
	 *
	 * @param string ...$ids Discord ids.
	 *
	 * @return bool
	 */
	private static function ids( string ...$ids ): bool {
		foreach ( $ids as $id ) {
			if ( $id !== Snowflake::of( $id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A string field of a decoded JSON object, or ''.
	 *
	 * @param mixed  $json Decoded response body.
	 * @param string $key  Field name.
	 *
	 * @return string
	 */
	private static function field( mixed $json, string $key ): string {
		$value = is_array( $json ) ? ( $json[ $key ] ?? '' ) : '';

		return is_string( $value ) || is_int( $value ) ? (string) $value : '';
	}

	/**
	 * A member's role, as a URL.
	 *
	 * @param string $guild Server id.
	 * @param string $user  User id.
	 * @param string $role  Role id.
	 *
	 * @return string
	 */
	private function role_url( string $guild, string $user, string $role ): string {
		return self::API . '/guilds/' . rawurlencode( $guild ) . '/members/' . rawurlencode( $user ) . '/roles/' . rawurlencode( $role );
	}

	/**
	 * The bot's headers.
	 *
	 * @return array<string, string>
	 */
	private function bot(): array {
		return array( 'Authorization' => 'Bot ' . $this->bot_token, 'User-Agent' => $this->user_agent );
	}
}
