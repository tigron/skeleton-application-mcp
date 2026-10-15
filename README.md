# skeleton-application-mcp

Turn a [Skeleton](https://github.com/tigron/skeleton-core) application into an **MCP
(Model Context Protocol) server**: a JSON-RPC 2.0 endpoint over HTTP that publishes
*tools* an LLM client can call.

You write one class per tool with a public `execute()`, and one class per answer with a
map of fields. Everything the client is told — the description, the `inputSchema`, the
`outputSchema` — is derived from what you already wrote, and every answer is checked
against the schema it advertises before it leaves the server.

* Protocol: [What is MCP](https://modelcontextprotocol.io/docs/2026-07-28/getting-started/intro) ·
  [Tools](https://modelcontextprotocol.io/specification/2025-06-18/server/tools) ·
  [Build a server](https://modelcontextprotocol.io/docs/2026-07-28/develop/build-server)
* Revision spoken: **`2025-06-18`** ([versioning](https://modelcontextprotocol.io/docs/2026-07-28/learn/versioning))
* Transport: stateless `POST` with a JSON body, `application/json` back
  ([transports](https://modelcontextprotocol.io/specification/2025-06-18/basic/transports))
* Not included: resources, prompts, sampling, roots, elicitation, sessions, SSE, CORS,
  rate limiting, and the OAuth flow of
  [Authorization](https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization)
  — this server authenticates with an API key instead, and says so in its own words

## Contents

* [Requirements](#requirements)
* [Installation](#installation)
* [A server in five minutes](#a-server-in-five-minutes)
* [How a request is handled](#how-a-request-is-handled)
* [What is in the package](#what-is-in-the-package)
* [Application layout](#application-layout)
* [Configuration](#configuration)
* [Writing a tool](#writing-a-tool)
* [Declaring a result](#declaring-a-result)
* [Security](#security)
* [Events](#events)
* [Calling the server by hand](#calling-the-server-by-hand)
* [Configuring a client](#configuring-a-client)

## Requirements

| | |
| --- | --- |
| PHP | `>= 8.1` |
| `tigron/skeleton-core` | `>= 5` |
| `phpdocumentor/reflection-docblock` | `~5.2 \|^6.0` — the parser every schema here is generated from |

## Installation

```sh
composer require tigron/skeleton-application-mcp
```

Prefer a version constraint over a branch, so the lock pins a release rather than
whatever `main` happened to hold the day it was written — see
[CHANGELOG.md](CHANGELOG.md). Declare it in `require`, **not** `require-dev`: every
application under `app/` is instantiated on every request, so a missing package with an
`app/<name>` of this type present takes down the site, not just the MCP server.

## A server in five minutes

An MCP application is a directory under `app/`, selected by hostname. There is no routing
file, no template and no language: the `method` in the JSON body selects the action.

```
app/mcp/
├── config/
│   ├── hostnames.php
│   └── mcp.php
└── tool/
    └── Ping.php
```

`app/mcp/config/hostnames.php` — this is what makes it an MCP server:

```php
<?php
return [
    'application_type' => '\Skeleton\Application\Mcp',
    'hostnames' => ['mcp.example.org'],
];
```

`app/mcp/config/mcp.php` — what the client is told about the server:

```php
<?php
return [
    'server_name' => 'Example MCP',
    'server_version' => '1.0.0',
    'protocol_version' => '2025-06-18',
    'instructions' => 'Read-only access to the example catalogue.',
    'mcp_api_keys' => [],
];
```

`app/mcp/tool/Ping.php` — the tool:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

/**
 * Checks that the server is up, and which build of it you are talking to.
 *
 * Call this first when anything behaves oddly: it answers without a key.
 */
class Ping extends \Skeleton\Application\Mcp\Tool {
	/**
	 * Execute
	 *
	 * @access public
	 * @param bool $verbose Also report the protocol revision
	 * @return \App\Mcp\Definition\Ping Status and server metadata
	 */
	public function execute(bool $verbose = false): \App\Mcp\Definition\Ping {
		return new \App\Mcp\Definition\Ping(
			\Skeleton\Core\Application::get()->config,
			$verbose
		);
	}

	/**
	 * Tell the client what kind of call this is
	 *
	 * @access public
	 * @return array<string, bool> $annotations
	 */
	public function get_annotations(): array {
		return [ 'readOnlyHint' => true ];
	}
}
```

`app/mcp/definition/Ping.php` — and the answer, which declares its fields and then says
what they hold:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Definition;

/**
 * The answer of one ping.
 */
class Ping implements \Skeleton\Application\Mcp\DefinitionInterface {
	use \Skeleton\Application\Mcp\Definition;

	/**
	 * The config this answer describes
	 *
	 * @access private
	 */
	private ?\Skeleton\Core\Config $config = null;

	/**
	 * Whether the caller also asked for the protocol revision
	 *
	 * @access private
	 */
	private bool $verbose = false;

	/**
	 * Hold the server metadata to describe
	 *
	 * The argument defaults because tools/list builds an empty instance of every answer
	 * to draw its schema from — see Declaring a result.
	 *
	 * @access public
	 * @param ?\Skeleton\Core\Config $config The server's own config
	 * @param bool $verbose Also report the protocol revision
	 */
	public function __construct(?\Skeleton\Core\Config $config = null, bool $verbose = false) {
		$this->config = $config;
		$this->verbose = $verbose;
	}

	/**
	 * Declare the fields this answer publishes — the schema the client is shown
	 *
	 * @access public
	 * @return array<string, array<string, mixed>> $properties Field name => its keywords
	 */
	public function get_mcp_definition_properties(): array {
		return [
			'status' => [ 'type' => 'string', 'description' => 'Always ok: this answered' ],
			'server_version' => [ 'type' => 'string', 'description' => 'Bump it per deploy of the tool layer' ],
			'protocol_version' => [ 'type' => 'string', 'nullable' => true, 'required' => false, 'description' => 'The revision spoken, when the caller asked for it' ],
		];
	}

	/**
	 * Say what those fields hold
	 *
	 * @access public
	 * @return array<string, mixed> $info The answer to hand to the client
	 */
	public function get_mcp_definition_info(): array {
		$config = $this->config;

		if ($config === null) {
			return [];
		}

		$info = [
			'status' => 'ok',
			'server_version' => (string)$config->server_version,
		];

		if ($this->verbose === true) {
			$info['protocol_version'] = (string)$config->protocol_version;
		}

		return $info;
	}
}
```

And it speaks:

```sh
curl -s -X POST --data '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' https://mcp.example.org/
```

## How a request is handled

`Handler` is the JSON-RPC layer (a body becomes a structure, the envelope is checked, a
result is wrapped) and `Skeleton\Application\Mcp` is the policy around it (which tools a
caller may see, what a bad credential is answered with, what HTTP status the body
deserves).

| Method | Result |
| --- | --- |
| `initialize` | `protocolVersion`, `capabilities.tools.listChanged` (false), `serverInfo`, `instructions` — or `instructions_public` for a caller with no credential |
| `tools/list` | the classes under `app/<app>/tool/` **this caller may see** |
| `tools/call` | resolves the tool, runs its `@security` handlers, checks the arguments, calls `execute()`, reduces the answer against its declaration |
| `ping` | `{}` |
| `notifications/*` | HTTP **202**, no body |

Anything else is `-32601`; any verb but `POST` is `405`; a request for `/.well-known/…`
is `404` (there is no such document here, and `405` would claim one that only wants a
different verb). A notification is a request **without an `id`** — `"id": 0`, `"0"`,
`null`, `""` and `false` are real ids and are answered. Batch requests are refused: the
revision this server speaks removed them
([tools](https://modelcontextprotocol.io/specification/2025-06-18/server/tools)).

### Error codes

| Code | When |
| --- | --- |
| `-32700` | the body is not valid JSON |
| `-32600` | not an object, or `method`/`id` is not of a usable type, or the body is a batch |
| `-32601` | unknown method |
| `-32602` | `params`/`name`/`arguments` of the wrong shape, no such tool, a missing required argument, an argument `execute()` does not have |
| `-32603` | `Internal error` — anything that was not said on purpose. The message, class, file and line go to the error log, never to the client |
| `-32001` | a credential was refused, or a protected tool was called with no credential at all. Answered with HTTP `401` and a `WWW-Authenticate` challenge |

Protocol errors and tool errors are different things, and the protocol says so
([error handling](https://modelcontextprotocol.io/specification/2025-06-18/server/tools#error-handling)):
throw `\Skeleton\Application\Mcp\Exception` for "the caller got this wrong" and it arrives
as a readable `isError: true` result the model can act on.

## What is in the package

| File | Owns |
| --- | --- |
| `Mcp.php` | the application: transport, the credential decision, which tools a caller may see, the status of a refusal |
| `Handler.php` | the JSON-RPC layer: reading and checking a body, the notification rule, dispatch, wrapping a result |
| `Tool.php` | the base class of a tool: name, description, `inputSchema`, `outputSchema`, handlers, the argument check, and the one reduction every consumer shares |
| `Definition.php` | the trait: the map is the schema, the answer is held to it, values are coerced, nested and projected |
| `DefinitionInterface.php` | what the package asks of a result |
| `Reducer.php` | the single reduction: decide what a class answered, hold it to its own map, refuse what does not match |
| `Security.php`, `Security/ApiKey.php` | the base of a handler, and the one check shipped |
| `Docblock.php` | reading a docblock, once, for all of the above |
| `Util.php` | the PHP-type-to-JSON-Schema map, and the recursive glob |
| `Event/Tool.php` | `bootstrap()` and `teardown()` around each call |
| `Exception.php`, `Jsonrpc/Exception.php` | a caller mistake, and a transport error |

## Application layout

```
app/<name>/
├── config/            required — without it the application is not loaded
├── tool/              one class per tool; sub-directories are allowed
├── definition/        optional — the shapes the tools return
├── helper/            optional — traits and interfaces tools use
├── security/          optional — @security handlers
└── event/             optional — bootstrap()/teardown() around each call
```

Two traps, both from the autoloader: **every `.php` under `tool/` becomes a tool**
(discovery is a glob with no filtering, so a helper left there is advertised, and an
abstract one makes `tools/list` fail), and **a class name under `tool/` or `security/`
may not contain an underscore** — the autoloader `ucwords()`es the name and `_` is not a
word boundary, so `Search_Organization` is looked for as `Search_organization.php`.
Split words into sub-directories instead: `tool/Search/Organization.php` publishes itself
as `search_organization`, which is the `snake_case` MCP asks for
([SEP-986](https://modelcontextprotocol.io/seps/986-specify-format-for-tool-names)).

## Configuration

Defaults are set in `Mcp::load_config()` and overridden by your config files.

| Key | Default | Notes |
| --- | --- | --- |
| `application_type` | — | must be `\Skeleton\Application\Mcp` or a subclass |
| `hostnames` | `[]` | the hostname is the only thing that selects the application |
| `server_name`, `server_version`, `protocol_version` | app name, `1.0.0`, `2025-06-18` | reported in `initialize`; **bump `server_version` per deploy of the tool layer** |
| `instructions` | `''` | handed to the model by the client: which tools exist, how to page, what an empty result means |
| `instructions_public` | `''` | what a caller with no credential is told instead |
| `mcp_api_keys` | — | read by `Security\ApiKey`; an empty list lets nobody in |
| `mcp_auth_challenge_realm` | `MCP` | the realm named in the `WWW-Authenticate` challenge |

Real keys belong in the environment config, which is gitignored and deployment-managed;
the committed `config/<app>.php` keeps the safe default `'mcp_api_keys' => []`.

## Writing a tool

A tool is one class extending `\Skeleton\Application\Mcp\Tool` with a public `execute()`:

| You write | The client sees |
| --- | --- |
| the class docblock | the tool `description` — the text a model reads before deciding to call it |
| `execute()` parameters and PHP types | the `inputSchema` property names and JSON types |
| `@param string $query Some text` | that property's `description` |
| a parameter without a default | it is listed in `required` |
| a nullable parameter | `["string","null"]`, and its `default` |
| `get_input_constraints()` | `minimum` / `maximum` / `enum` / `pattern` / `maxLength` — the same map the package enforces before `execute()` runs |
| `get_annotations()` | `title`, `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint` |
| `@return \App\Mcp\Definition\X` | the `outputSchema` X declares — **full namespace**, or nothing is advertised |

The class docblock is text the model reads, not a comment: say when to call the tool and
what to do with the answer. Internal notes belong in the *file* docblock, above
`namespace`, which the package never reads.

## Declaring a result

`structuredContent` is defined as a JSON object, and a server that advertises an
`outputSchema` MUST conform to it — a validating client rejects what does not. So the
shape is declared once, in `get_mcp_definition_properties()`, and that map is both the
schema the client is shown and the law the answer is held to:

| Keyword | What it does |
| --- | --- |
| `type` | required; the JSON type (`Util::to_json_type()` maps PHP types to it) |
| `nullable` | `true` publishes `["<type>","null"]` and lets the field answer null |
| `required` | `false` keeps the key out of the schema's `required` list |
| `description` | the text the client reads beside the field |
| `format`, `enum`, `minimum`, `maximum`, `pattern` | passed through to the client, and `enum` is enforced on the way out |
| `value_type` | the Definition an object, or an array of objects, holds |
| `items` | what the elements of a list are, for a list of scalars |

An entry that carries a keyword the trait does not read is a refusal naming the class,
the field and the keyword: a word nothing reads is a sentence said to nobody.

Two ways to answer, and a class picks one:

```php
	// 1. authored — one readable array, in the order a human reads the thing
	public function get_mcp_definition_info(): array {
		return [ 'status' => 'ok', 'server_version' => (string)$this->config->server_version ];
	}

	// 2. property-driven — declare public properties and the trait builds it for you
	protected function get_mcp_definition_value(string $field): mixed {
		return $this->$field ?? null;   // the default; usually you write nothing at all
	}
```

Either way the answer goes through `Reducer::reduce()` **once per call** — the handler
and the audit log read the same array, and a payload that computes is not built twice for
the benefit of a log line that only counts it — and it is checked against the map first:
a declared field missing from the answer, a field it invented, a null where the map
forbids one, an `enum` value the map does not name, or a model where a scalar was
promised is a **refused call** (`isError: true`, one neutral sentence naming the tool)
with the class, the field and the value that arrived written to the error log. A payload
that disagrees with its own schema is the bug this exists to make impossible, and a
client is the worst party to find out.

One asymmetry, stated because it is deliberate: a **scalar of the wrong type** is refused
in an authored answer and only *logged* in a property-driven one (`cast it, or author the
answer`). Rows arrive from a database as strings, and a rule that refused them today
would break tools that have been answering correctly for months. Author the answer, and
that same value becomes a refusal — which is the direction the log line is asking for.

A field that declares a `value_type` may be handed an instance of that Definition, an
array, or any object — an object is *projected*, which means only the names the target
map declares are read from it, and never a magic relation. `$result->rows = $pager->items`
is therefore legal: a column nobody agreed to publish cannot leave, because nothing asked
for it.

## Security

A tool opts in per annotation on `execute()`. Each `@security` line resolves to one
instance per class per request, and its `handle()` runs before the call:

```php
	/**
	 * @security \Skeleton\Application\Mcp\Security\ApiKey   ← staff: reads everything
	 * @security \App\Mcp\Security\Bearer                    ← a token: one organization
	 * @security \App\Mcp\Security\Access                    ← the row-by-row judgement
	 */
	public function execute(string $uuid): Result { }
```

* **`@security` is the whole policy.** A tool that carries it is private: absent from
  `tools/list` for a caller with no valid credential, and a call to it by name is refused
  as the authentication failure it is, not as `Unknown tool`. A tool that declares nothing
  is public, which is how a liveness probe stays reachable to somebody whose key is the
  thing under suspicion.
* **Handlers come in two kinds**, and `is_authenticating()` says which. Authenticating
  handlers are an **OR** — two ways to prove who you are are alternatives — and only the
  ones **at least one tool declared** are asked, so a file dropped into `security/` can
  never become a door. Authorizing handlers are an **AND**, they run only after a
  credential admitted the caller, and they are kept out of the request-wide verdict so a
  tool that asks for more cannot 401 somebody who authenticated perfectly well.
* **`Security\ApiKey`** accepts the key in `Authorization: Bearer <key>`, falling back to
  `X-Api-Key` only when there is no `Authorization` header at all; compares each
  configured key with `hash_equals()`; and **fails closed** — an empty list refuses
  everybody, so a deploy that forgot its configuration exposes nothing. It answers five
  questions about itself (`is_authenticating()`, `presents_credential()`,
  `get_refusal_code()`, `get_http_status()`, `get_http_challenge()`) which is how the
  application gives a refused credential a 401 and a challenge without ever inspecting
  one. The scheme is `ApiKey`, not `Bearer`: there is no authorization server behind an
  MCP application, and a client that believes `Bearer` starts
  [discovery](https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization#authorization-server-discovery)
  and finds nothing at the end of that road.
* To source keys elsewhere — a table, so calls are attributable and one client can be
  revoked alone — subclass `ApiKey` and override `get_api_keys()`, and `get_digest()` too
  if the table stores hashes. Nothing else needs restating.
* Authentication is not authorisation, and neither is throttling: rate limiting and
  per-client quotas belong to the
  [deployment](https://modelcontextprotocol.io/specification/2025-06-18/server/tools#security-considerations).

## Events

`app/<name>/event/Tool.php` may define `bootstrap(Tool $tool)` and
`teardown(Tool $tool, array $outcome)`; the outcome carries `ok`, `error_code`,
`error_message`, `duration_ms`, `row_count` and the `arguments`. **`teardown()` fires on
every path out of the call**, refusals included, because the calls worth auditing are
precisely the ones that produced no answer. Nothing reaches an error tracker on its own:
unexpected failures are logged by the handler, and this is where per-call logging belongs.

## Calling the server by hand

```sh
U=https://mcp.example.org/
KEY=…
rpc () { curl -s -X POST -H "X-Api-Key: $KEY" --data "$1" "$U"; }

rpc '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
rpc '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
rpc '{"jsonrpc":"2.0","id":3,"method":"ping"}'
rpc '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"ping","arguments":{}}}'
```

Test the error paths as deliberately as the happy ones — a wrong tool name, an unknown
argument, a call without a key, a batch (one `-32600`, not two answers), a notification
(`202`, no body), a `GET` (`405`), a `GET` of `/.well-known/…` (`404`), a malformed body
(`-32700`), and a `method` that is not a string — and expect the answers to name no class,
no file and no line. Do not pipe a body into the front controller to test: `php://input`
is empty under the CLI SAPI. Speak real HTTP, or drive it with the
[MCP Inspector](https://modelcontextprotocol.io/docs/2026-07-28/tools/inspector).

## Configuring a client

```json
{
	"mcpServers": {
		"example": {
			"type": "http",
			"url": "https://mcp.example.org/",
			"headers": { "X-Api-Key": "<a key from mcp_api_keys>" }
		}
	}
}
```

OpenCode needs OAuth switched off, because a remote server defaults to a flow this package
does not implement:

```jsonc
{
	"mcp": {
		"servers": {
			"example": { "type": "remote", "url": "https://mcp.example.org/", "oauth": false, "headers": { "X-Api-Key": "{env:MCP_API_KEY}" } }
		}
	}
}
```

A Streamable HTTP client probes with a `GET` expecting an event stream and gets `405`.
That is allowed: the protocol also permits a session-less server to answer each `POST`
with a single `application/json` response. Browser-based clients do not work: no CORS,
no `OPTIONS`.
