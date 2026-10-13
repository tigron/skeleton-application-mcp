# skeleton-application-mcp

Turn a Skeleton application into an **MCP (Model Context Protocol) server**: a
JSON-RPC 2.0 endpoint over HTTP that publishes *tools* an LLM client can call.

* Protocol: [modelcontextprotocol.io](https://modelcontextprotocol.io/docs/develop/build-server)
* Protocol revision spoken: **`2025-06-18`**
* Transport: stateless `POST` with a JSON body, `application/json` back
* Not included: resources, prompts, sampling, roots, elicitation, sessions, SSE,
  CORS, rate limiting

## Contents

* [Requirements](#requirements)
* [Installation](#installation)
* [A server in five minutes](#a-server-in-five-minutes)
* [How a request is handled](#how-a-request-is-handled)
* [What is in the package](#what-is-in-the-package)
* [Application layout](#application-layout)
* [Configuration](#configuration)
* [Writing a tool](#writing-a-tool)
* [Documenting parameters: inputSchema](#documenting-parameters-inputschema)
* [Documenting a result: outputSchema](#documenting-a-result-outputschema)
* [What the client receives](#what-the-client-receives)
* [Security](#security)
* [Calling the server by hand](#calling-the-server-by-hand)
* [Configuring a client](#configuring-a-client)

## Requirements

| | |
| --- | --- |
| PHP | `>= 8.1` |
| `tigron/skeleton-core` | `>= 5` |
| `phpdocumentor/reflection-docblock` | `~5.2 \|^6.0` — the parser every schema in this package is generated from |

## Installation

```sh
composer require tigron/skeleton-application-mcp
```

The repository is tagged (`v0.0.1`, `v0.0.2`); `v0.0.2` is the current content of
`main`. Prefer a version constraint over a branch, so the lock pins a release:

```json
"require": {
    "tigron/skeleton-application-mcp": "^0.0.2"
}
```

Declare it in `require`, **not** `require-dev`: every application under `app/` is
instantiated on every request, so a missing package with an `app/<name>` of this type
present takes down the whole site, not just the MCP server.

## A server in five minutes

An MCP application is a directory under `app/`, selected by hostname.

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

`app/mcp/tool/Ping.php` — the first tool:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

/**
 * Checks that the server is up and reachable.
 *
 * Call this first when anything behaves oddly: it answers without a key and
 * reports the server version.
 */
class Ping extends \Skeleton\Application\Mcp\Tool {
	/**
	 * Execute
	 *
	 * @access public
	 * @return array<string, string> Status and server metadata
	 */
	public function execute(): array {
		$config = \Skeleton\Core\Application::get()->config;

		return [
			'status' => 'ok',
			'server_name' => $config->server_name,
			'server_version' => $config->server_version,
		];
	}
}
```

And it speaks:

```sh
curl -s -X POST --data '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' https://mcp.example.org/
```

There is no routing file, no template and no language: the `method` in the JSON body
selects the action.

## How a request is handled

Two classes split the work, and the split decides where a change belongs: `Handler` is
the JSON-RPC layer — how a body becomes a structure, which requests expect an answer,
how a result is wrapped — and `Mcp` is the policy around it — which tools a caller may
see, what a bad credential is answered with, and what HTTP status the body deserves.

| Method | Result |
| --- | --- |
| `initialize` | `protocolVersion`, `capabilities.tools.listChanged` (false), `serverInfo`, and `instructions` — or `instructions_public`, for a caller with no credential (§ [Security](#security)) |
| `tools/list` | the classes under `app/<app>/tool/` **this caller may see**: `name`, `description`, `inputSchema`, plus `outputSchema` and `annotations` when the tool declares them |
| `tools/call` | resolves the tool, runs the `@security` handlers, then `execute()`, and wraps the return value |
| `ping` | `{}` |
| `notifications/*` | HTTP **202**, no body |

Anything else is `-32601`. Any verb but `POST` gets `405`, except a request for
`/.well-known/…`, which gets `404`: there is no such document on an MCP server, and
`405` would advertise one that merely wants a different verb.

**A notification is a request without an `id`**, not one whose `id` is falsy:
`"id": 0`, `"0"`, `null`, `""` and `false` are real ids and their answers come back with
those ids. OpenCode and the official TypeScript SDK both number from zero.

**Batches are refused** with one `-32600`: MCP 2025-06-18 removed them, and answering a
batch makes the server look like one that speaks a superseded revision.

### Error codes

| Code | When | Message |
| --- | --- | --- |
| `-32700` | the body is not valid JSON | `Parse error` |
| `-32600` | no `method`, or the body is not an object | `Invalid request` |
| `-32600` | the body is a JSON array (a batch) | `Batch requests are not supported by this server (MCP 2025-06-18)` |
| `-32601` | unknown method | `Method not found: <method>` |
| `-32602` | no `params.name` on `tools/call` | `tool name not provided` |
| `-32602` | no such tool | `Unknown tool <name>` |
| `-32602` | a parameter without a default was omitted | `Missing required argument <name>` |
| `-32602` | an argument that is not a parameter of `execute()` | `Unknown argument <name> for tool <tool>, accepted arguments: <list>` |
| `-32603` | any `Throwable` that is not an MCP exception | the exception message, unmodified |
| `-32001` | `Security\ApiKey` refuses the request — no key, wrong key, no keys configured | see [Security](#security) |
| `-32001` | `tools/call` names a tool that exists but carries `@security`, and the caller showed no credential | the same message as above, deliberately |

JSON-RPC codes are for transport problems. A caller mistake is not one: throw
`\Skeleton\Application\Mcp\Exception` and it arrives as a readable `isError` result.

## What is in the package

| File | Owns |
| --- | --- |
| `Mcp.php` | the application: transport, the credential decision, which tools a caller may see, the status of a refusal, the description the caller has earned |
| `Handler.php` | the JSON-RPC layer: reading a body, the notification rule, dispatch, wrapping a result |
| `Tool.php` | the base class of a tool: its name, description, `inputSchema`, `outputSchema`, `@security` handlers, and the argument check |
| `Security.php` | the base of a handler: `handle()`, plus what a refusal of this kind means to the transport |
| `Security/ApiKey.php` | the one check shipped: a static key from `mcp_api_keys` |
| `Component/Definition.php` | the base of a result: properties that are both the schema and the payload |
| `Docblock.php` | reading a docblock, once, for all of the above |
| `Util.php` | the PHP-type-to-JSON-Schema map, and the recursive glob |
| `Event/Tool.php` | the `tool` event, fired `bootstrap()`/`teardown()` around each call |
| `Exception.php`, `Jsonrpc/Exception.php` | a caller mistake, and a transport error |

## Application layout

```
app/<name>/
├── config/            required — without it the application is not loaded
├── tool/              one class per tool; sub-directories are allowed
├── component/         optional — shared code, never auto-instantiated
├── security/          optional — @security handlers
└── event/             optional — bootstrap()/teardown() around each call
```

`Mcp::get_details()` registers the autoloader namespaces `\App\<Name>\Tool\`,
`\App\<Name>\Security\` and `\App\<Name>\Component\`, so a file is its class:
`tool/Search/Organization.php` is `App\Mcp\Tool\Search\Organization`.

**Every `.php` file under `tool/` becomes a tool.** Discovery is a recursive glob and
`new $classname()` per file, with no filtering: a helper or abstract class left there is
advertised, and an abstract one makes `tools/list` fail outright. Shared code belongs in
`component/`, which is autoloaded but never instantiated.

**Never put an underscore in a class name under `tool/` or `security/`.** The autoloader
lowercases and `ucwords()`es the name, and `ucwords()` does not treat `_` as a word
boundary: `Tool\Search_Organization` is looked for as `Search_organization.php` and fails
as an opaque `-32603`. Split words into sub-directories — the package flattens them into
the advertised name with an underscore, which is the `snake_case` MCP wants, so
`tool/Search/Organization.php` publishes itself as `search_organization`. Override
`get_name()` for a name that follows neither rule.

## Configuration

Defaults are set in `Mcp::load_config()` and overridden by your config files.

| Key | Required | Default | Notes |
| --- | --- | --- | --- |
| `application_type` | yes | `\Skeleton\Application\Web` | must be `\Skeleton\Application\Mcp` or a subclass of it |
| `hostnames` | yes | `[]` | the hostname is the only thing that selects the application |
| `server_name` | | app name | reported in `initialize` |
| `server_version` | | `1.0.0` | reported in `initialize`: **bump it per deploy of the tool layer** |
| `protocol_version` | | `2025-06-18` | reported in `initialize` |
| `instructions` | | `''` | handed to the model by the client: which tools exist, what to call first, how to page, what an empty result means |
| `mcp_api_keys` | | — | read by `Security\ApiKey`; an empty list lets nobody authenticate, so every tool that carries `@security` is unreachable |

Real values belong in the environment config, which is gitignored and
deployment-managed; the committed `config/<app>.php` keeps the safe defaults such as
`'mcp_api_keys' => []`. A `config/environment-ci` file is the usual way to carry a
test key into CI.

## Writing a tool

A tool is one class extending `\Skeleton\Application\Mcp\Tool` with a public
`execute()`. Everything the client sees is derived from it:

| You write | The client sees |
| --- | --- |
| the class docblock (summary + body) | the tool `description`, which the model uses to decide whether to call it |
| `execute()` parameter names + PHP types | the `inputSchema` property names + JSON types |
| `@param string $query Some text` | that property's `description` |
| a parameter **without** a default value | it is listed in `required` |
| a **nullable** parameter (`?string`) | `["string","null"]`, and its `default` |
| `get_input_constraints()` | `minimum` / `maximum` / `enum` / `pattern` / `maxLength` on a property |
| a class named in `@return` that declares a shape | the `outputSchema` |
| `get_annotations()` | `annotations`: `title`, `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint` |
| `@security \App\Mcp\Security\X` | nothing visible: the handler runs before `execute()`, once per annotation |

The class docblock is **text the model reads**, not a comment: say when to call the
tool and what to do with the answer. Internal notes belong in the *file* docblock
(above `namespace`), which the package never reads.

`execute()` takes what it needs and returns an array with string keys —
`['orders' => [...]]`, never a bare list (see
[What the client receives](#what-the-client-receives)).

### Throwing the right thing

| You throw | The client gets |
| --- | --- |
| `\Skeleton\Application\Mcp\Exception` | `result.isError = true` with your message in `content[0].text`, which the model can read and act on |
| `\Skeleton\Application\Mcp\Jsonrpc\Exception` | a JSON-RPC error with the code you pass |
| anything else | `-32603` carrying the exception message verbatim |

That last row sends internal detail to the client, so catch what you do not intend to
say and say something deliberate instead.

### Events

`app/<name>/event/Tool.php` may define `bootstrap(Tool $tool)` and
`teardown(Tool $tool)`; they run before and after `execute()`, around the security
handlers. This is where per-call logging belongs: nothing is reported to an error
tracker on its own, because the handler turns every `Throwable` into a response.

## Documenting parameters: inputSchema

Types come from the PHP type, then the `@param` type, then `string`:

| PHP | JSON Schema |
| --- | --- |
| `int` | `integer` |
| `float` / `double` | `number` |
| `bool` | `boolean` |
| `array` | `array` |
| `object` | `object` |
| anything else | `string` |

That is `Util::to_json_type()`, the only type map in the package: `Tool::get_input_schema()`
asks it about a parameter and `Component\Definition` about a property, so the two halves of a
schema cannot disagree about one type — and a declared `outputSchema` its own payload
violates is not cosmetic, it is a tool a validating client rejects.

A `ReflectionNamedType` that allows null is published as the union `["string","null"]`, so a
client that believes the schema may send null; an optional parameter carries its `default`,
so the model need not guess whether an omitted `limit` means 10, 100 or everything.

`additionalProperties` is `false` and `Tool::call()` enforces it: an argument that is not a
parameter of `execute()` is refused with `-32602` rather than dropped, because dropping it
answers as though the filter had matched everything — a wrong result that looks like a right
one.

### Stating bounds

`get_input_constraints()` returns JSON Schema keywords per parameter name, merged into that
parameter's entry:

```php
	public function get_input_constraints(): array {
		return [
			'query' => ['maxLength' => self::MAXIMUM_QUERY_LENGTH],
			'uuid' => ['maxLength' => self::MAXIMUM_QUERY_LENGTH, 'pattern' => self::UUID_JSON_PATTERN],
			'sort' => ['enum' => ['name', 'city']],
			'limit' => ['minimum' => 1],
			'page' => ['minimum' => 1, 'maximum' => self::MAXIMUM_PAGE],
		];
	}
```

State a bound **once**: the same map is read twice per call — `get_input_schema()` publishes
it, and `Tool::call()` checks every argument against it before `execute()` is reached
(`type`, `minimum`, `maximum`, `minLength`, `maxLength`, `enum`, `pattern`) — so a tool holds
no second copy of its own floors and lengths, and the schema cannot drift from what is
enforced. Publish only what you enforce: a limit that answers 25 to a request for 100
advertises a `minimum` and no `maximum`, and says the rest in the parameter's description. A
key naming a parameter you do not have is ignored, so assert the advertised schema in a test
rather than trusting the spelling.

A `pattern` must be the ECMA-262 **source** — no `/delimiters/`, no `i` flag — because it is
published as-is and `Tool::check_argument()` wraps it for `preg_match()` itself,
case-insensitively. Put the case-insensitivity in the character classes and keep no second,
PHP-shaped copy of one expression:

```php
	private const UUID_JSON_PATTERN = '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$';
```

## Documenting a result: outputSchema

`structuredContent` is defined as a JSON **object**, and a server that advertises an
`outputSchema` MUST conform to it — a validating client may throw the whole call away
when it does not.

Declare the shape once, on the class `execute()` returns, and name that class in its
`@return`:

```php
	/**
	 * @param string $query An e-mail address or customer number
	 * @return \App\Mcp\Component\Customer\Result The matching customers
	 */
	public function execute(string $query = ''): Result {
		return Result::from_customers($customers);
	}
```

```php
namespace App\Mcp\Component\Customer;

class Result extends \Skeleton\Application\Mcp\Component\Definition {
	/**
	 * The customers that matched, ordered by name
	 *
	 * @access public
	 * @var array<int, Row> $customers
	 */
	public array $customers = [];

	/**
	 * What the search filtered on, so an empty list can be explained
	 *
	 * @access public
	 * @var ?Filters $filters
	 */
	public ?Filters $filters = null;
}
```

`Component\Definition` reads that class and produces both halves from one declaration:

| From | It derives |
| --- | --- |
| the PHP type of each public property | the JSON type; `?` becomes `["<type>","null"]` |
| `@var array<int, string>` or `string[]` | `items: {"type":"string"}` |
| `@var array<int, Row>` or a property typed `Row` | a nested object schema, taken from `Row` |
| the first line of the property docblock | the `description` the client reads |
| every public property, in declaration order | the `required` list and the keys of the payload |

Two rules follow from that:

* **Give every property a default** (`= null`, `= []`, `= 0`): the payload is built by
  reading the declared properties, so a field a factory forgot answers with the `null` the
  schema already promises rather than an uninitialized-property error.
* **A property is required unless an override says otherwise**, and the payload always
  carries it — so a declared field cannot go missing quietly.

What a type cannot express goes in `get_definition_overrides()`, keyed per property:
`format`, `enum`, `minimum`, `maximum`, `pattern`, `type`, `nullable`, `items`,
`description`, and `required` (`false` makes it optional). It is the escape hatch, not
boilerplate — a component that needs nothing beyond its types returns the base `[]` — and a
description belongs in the property docblock, not in the map as well. An array whose element
type cannot be resolved gets **no `items` keyword**: "an array of anything" is true, while
`{"type":"string"}` about an array of objects is a lie a validating client rejects.

```php
	public function get_definition_overrides(): array {
		return [
			'uuid' => [ 'format' => 'uuid' ],
			'sort' => [ 'enum' => [ 'name', 'city' ], 'required' => false ],
		];
	}
```

A tool whose output is not worth modelling can override `get_output_schema()` and return
any schema array; the default returns `null`, which advertises none.

## What the client receives

| `execute()` returns | `result.content[0].text` | `result.structuredContent` |
| --- | --- | --- |
| an array with string keys | the pretty-printed JSON | the same value |
| an object | the pretty-printed JSON | the same value |
| a string | the string | *absent* |
| throws `Mcp\Exception` | your message | *absent*, `isError: true` |

Return an array with keys and keep any list inside a named property: the protocol
forbids a list as `structuredContent`. `Handler::wrap_result()` does not send one — a
tool that returns a bare list loses the field, keeps the identical JSON in
`content[0].text`, and writes a line in the error log naming the tool, so the client
still gets an answer and somebody can still find out which tool was wrong.

A result that declares its own shape (a `Component\Definition`) is reduced through
`get_definition_info()`, so the keys of the payload and the keys of the advertised
`outputSchema` come from the same list of properties and cannot drift apart.

## Security

A tool opts in per annotation on `execute()`. Each `@security` line is instantiated and
its `handle()` runs before the call, and the annotation decides one more thing: whether
the tool is admitted to exist for a caller who showed no credential.

```php
	/**
	 * @param string $uuid The customer to look up
	 * @security \Skeleton\Application\Mcp\Security\ApiKey
	 */
	public function execute(string $uuid): array { }
```

**`@security` is the whole policy.** A tool that carries it is private — absent from
`tools/list` for a caller without a valid key, and a call to it by name is answered as the
authentication failure it is, not as `Unknown tool`. A tool that declares nothing is
public, listed and callable without a key, which is how a liveness probe stays reachable to
somebody whose key is the thing under suspicion. There is no second list of tools to keep in
step, and the application asks a tool nothing it has not said about itself.

The catalogue is therefore a function of the credential: a client that lists before it
authenticates caches a short list, and nothing will tell it better — `listChanged` is
`false` because the capability promises a
`notifications/tools/list_changed`, which a server that only ever answers a request cannot
send. Say it where a client reads it before caching — `instructions`/`instructions_public`,
and the description of a public tool.

`Security\ApiKey` is the only check the package ships:

* accepts the key in `Authorization: Bearer <key>`, falling back to `X-Api-Key: <key>`
  **only when there is no `Authorization` header at all** — a second header never replaces
  the credential in the first, and not every client can add custom headers;
* reads `$_SERVER['HTTP_*']` first, falling back to `getallheaders()`, because Apache does
  not always forward `Authorization` to PHP;
* compares each configured key with `hash_equals()`, one at a time, so the running time
  does not reveal which one matched;
* reads the keys from the property named by `CONFIG_KEY` (`mcp_api_keys`) and **fails
  closed**: an empty list refuses every request with `No MCP API key is configured on this
  server`, so a deploy that forgot its configuration exposes no secured tool rather than
  exposing it to everyone;
* answers four questions for the application, which never inspects a credential itself
  because only the handler knows what one looks like: `presents_credential()` (was one
  offered at all — the visitor and the failure want different answers),
  `get_refusal_code()`, `get_http_status()` (401) and `get_http_challenge()`. The base
  defaults to "a credential was offered, my refusal means nothing to the transport, no
  challenge", so an unknown handler is refused rather than waved through, and stays at 200
  until it says otherwise;
* challenges the two refusals separately, per RFC 6750 §3.1: `ApiKey realm="…"` when
  nothing was offered, `…, error="invalid_token"` when a key was offered and refused —
  never `error="invalid_request"`, which describes a malformed *token* request in a flow
  this class does not implement. The scheme is `ApiKey`, not `Bearer`, deliberately: a
  client that believes `Bearer` starts RFC 9728 discovery and finds no authorization server
  at the end of that road. Only the realm comes from configuration
  (`mcp_auth_challenge_realm`); the class assembles the rest, because an application
  hand-writing the whole string is how an `error=` parameter ends up in the wrong place;
* refuses with `-32001` (`ERROR_CODE_UNAUTHORIZED`) and one of three messages: no key
  configured, no key provided, invalid key.

To source keys elsewhere — a table, so calls are attributable per client and one can be
revoked alone — subclass it and override `get_api_keys()`; the header parsing and the
comparison are already done.

For anything other than a static key, extend `\Skeleton\Application\Mcp\Security` and
implement `handle()`: throw `\Skeleton\Application\Mcp\Jsonrpc\Exception` with a code of
your choosing to refuse, return normally to admit.

Authentication is not authorisation and neither is throttling: rate limiting and
per-client quotas are not part of this package and belong to the deployment.

## Calling the server by hand

```sh
U=https://mcp.example.org/
KEY=…

curl -s -X POST --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' $U
curl -s -X POST --data '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' $U
curl -s -X POST --data '{"jsonrpc":"2.0","id":3,"method":"ping"}' $U
curl -s -X POST --data '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"ping","arguments":{}}}' $U
curl -s -X POST -H "X-Api-Key: $KEY" --data '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"find_customer","arguments":{"query":"example@example.org"}}}' $U
```

Test the error paths as deliberately as the happy ones: a wrong tool name, an unknown
argument, a missing required argument, a call without a key, a batch of two (expect one
`-32600`, not two answers), a notification (expect `202` and no body), a `GET` (expect
`405`), a `GET` of `/.well-known/oauth-protected-resource` (expect `404`), a malformed
body (`-32700`).

Do not pipe a body into the front controller to test: `php://input` is empty under the
CLI SAPI, so that always answers `-32700`. Speak real HTTP.

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

OpenCode needs OAuth switched off, because a remote server defaults to a flow this
package does not implement:

```jsonc
{
	"mcp": {
		"servers": {
			"example": {
				"type": "remote",
				"url": "https://mcp.example.org/",
				"oauth": false,
				"headers": { "X-Api-Key": "{env:MCP_API_KEY}" }
			}
		}
	}
}
```

A Streamable HTTP client probes with a `GET` expecting an event stream and gets `405`.
That is allowed: the protocol also permits a session-less server to answer each `POST`
with a single `application/json` response. Browser-based clients do not work: no CORS
headers, no `OPTIONS` handling.
