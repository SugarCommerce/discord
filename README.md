# Discord

The Discord calls for giving a role on purchase: a bot invite link, user sign-in with PKCE, adding a member with a role, and taking the role back, with the `DiscordBot` User-Agent the API requires.

## Why

"Buyers get the Supporters role" is four calls, and the obvious version of each has a gap:

- **Discord requires a bot User-Agent.** Its API terms ask for `DiscordBot ($url, $version)`; a library's default agent can be blocked without warning.
- **Adding an existing member ignores the roles.** "Add guild member" answers 204 for somebody already in the server and drops the `roles` you sent, so a buyer who was already a member gets nothing unless the role is then given on its own.
- **A role that is already gone is a 404.** Somebody who left, or a deleted role: they do not have it, which is what removal asked for. Treating it as a failure retries for ever.
- **The buyer's token is for one thing.** It lets the bot add them to a server (`guilds.join`). Keep their user id; let the token go.
- **Invite links with magic numbers.** `permissions=8` is Administrator. Name the bits.

## Features

- 🤖 **Bot invite links** — with `Permissions::ROLE_GIVER` (Manage Roles + Create Instant Invite) by default.
- 🔑 **Sign-in with PKCE** — `authorize_url()` takes the challenge, `exchange()` the verifier, and answers with an `Identity`.
- 🎭 **Roles** — `grant()` joins and gives the role whether or not they are a member; `remove_role()` counts 404 as removed.
- 🏷️ **`Joined`** — Discord's two successes, read apart.
- 🪪 **`DiscordBot` User-Agent** on every call, built by `Client::user_agent( $url, $version )`.
- ❄️ **`Snowflake::of()`** — a server, role or user id, or `''` for whatever was pasted instead: a name, an invite link, a role mention. Every bot call checks its ids the same way and sends nothing for one that is not, so an id of `..` cannot turn "remove this member's role" into "delete the role".

## Requirements

PHP 8.5+, [`sugarcommerce/http-client`](https://github.com/sugarcommerce/http-client).

## Installation

```bash
composer require sugarcommerce/discord
```

## Usage

```php
use SugarCommerce\Discord\Client;

$discord = new Client(
    $client_id,
    $client_secret,
    $bot_token,
    user_agent: Client::user_agent( 'https://shop.example', '1.0' ),
);

// For the server owner:
$discord->bot_invite_url();

// For the buyer — the state and PKCE pair from sugarcommerce/oauth:
$url = $discord->authorize_url( $callback, $out->state, $out->challenge );

// In the callback:
$who = $discord->exchange( $_GET['code'], $callback, $trip->verifier );   // ?Identity

if ( null !== $who && $discord->grant( $guild, $who->user, $who->token, $role ) ) {
    // Keep $who->user; never $who->token.
}

// When the purchase is undone:
$discord->remove_role( $guild, $user_id, $role );
```

Check ids when they are saved, not when a buyer clicks:

```php
use SugarCommerce\Discord\Snowflake;

$role = Snowflake::of( $_POST['role'] );   // '' for "<@&1234…>", "VIP", a link
```

The bot's own highest role must sit above the role it gives, or Discord refuses whatever the permissions say.

`Client` is not final, so an application can add a factory from its own configuration.

## Testing

```bash
composer install
composer test
```

37 tests, all against `HttpClient\Fake` — nothing reaches Discord. The invite link's permission bits; the sign-in link with and without PKCE; the code and verifier exchange; a refused code; a new member joining with the role; an existing member given the role on its own; a refused join; removal with 404 as success; the User-Agent's shape on every call; the `#[\NoDiscard]` results; and which pasted values `Snowflake::of()` takes for an id.

## License

GPL-2.0-or-later
