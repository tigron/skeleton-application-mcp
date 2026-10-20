# Changelog

All notable changes to `tigron/skeleton-application-mcp` are documented here. The
released tags are `v0.0.1` and `v0.0.2`; everything below `v0.1.0` is what `main`
carries beyond `v0.0.2` and is not a release until it is tagged.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Target `v0.1.0`. The application under review (`app/mcp` of the KNX Online Shop) is
built against this state of the package, and the suites it ships with — 475 live checks
over transport, credentials, authorization, the argument check and every published
schema, plus four probes (71 + 23 + 42 + 8) — are the ones the entries below were
verified with.

### Added

- **A definition may write its own answer.** `get_mcp_definition_properties()` is still
  the one declaration — it is the `outputSchema` the client is shown — but a class can
  now return its payload as a single array from `get_mcp_definition_info()` instead of
  answering the map field by field through `get_mcp_definition_value()`. Both kinds are
  supported at once; the property-driven one is unchanged.
- **`Reducer`** (`Skeleton\Application\Mcp\Reducer`) is the single place an answer
  becomes the array a client receives. It holds what a class returned to the map that
  class declared: every declared field present, no field invented, no null where the map
  forbids one, values of the type they promise, `enum` membership respected, and an
  object accepted only as a `DefinitionInterface` (reduced) or a source to project. A
  mismatch is a **refused call** — one neutral sentence names the tool, while the class,
  the field and the value that arrived go to the error log. A payload can no longer
  disagree with its own advertised schema, and a validating client is not the party that
  discovers it.
- **One reduction per call.** `Tool::call()` reduces the answer once and keeps it;
  `Handler` wraps that array and the `teardown` event counts rows from it, so a payload
  that computes (rows off a pager, labels off a table) is built once per request rather
  than twice. `Tool::get_reduced_info()` is the seam.

- **The result is declared once.** `DefinitionInterface` and the `Definition` trait:
  `get_mcp_definition_properties()` is a map of fields — type, description, `nullable`,
  `format`, `enum`, `minimum`, `maximum`, `pattern`, `required`, `value_type`, `items` —
  and the same map draws the advertised `outputSchema` and the `structuredContent` that
  has to conform to it. `get_mcp_definition_value()` answers a field that is computed
  rather than held. The method is abstract in the trait, so a result that states no
  fields does not load.
- **A projection, not a serialisation.** A field declared to hold a Definition accepts
  an instance, an array or any object, and an object is *projected*: only the names that
  definition declares are read from it, so handing a tool a model row cannot publish a
  column nobody agreed to. Projection reads columns and never a relation, an object
  arriving where a scalar was declared is refused, and a required field the source does
  not carry is a refusal rather than a silence.
- **`outputSchema` is advertised.** `Tool::get_definition()` publishes it (and
  `annotations`) when the tool has something to say; `get_output_schema()` derives it
  from the class the `@return` of `execute()` names, and returns null — no schema — when
  that class declares none.
- **The inputSchema says what the tool enforces.** `get_input_schema()` widens a
  nullable parameter to `["<type>","null"]`, publishes each parameter's `default`, and
  merges what `get_input_constraints()` states per parameter — `minimum`, `maximum`,
  `minLength`, `maxLength`, `enum`, `pattern` — into the property. The same map is what
  the argument check enforces before `execute()` is reached, so a bound cannot be
  advertised without being enforced or the other way round.
- **Unknown and missing arguments are refused**, `-32602`, naming both the argument and
  the accepted ones. Dropping them answered as though a filter had matched everything.
- **Two kinds of security handler.** `Security::is_authenticating()`: authenticating
  handlers are combined as an **OR** (two ways to prove who you are are alternatives),
  authorizing handlers as an **AND** (every one a tool declares must agree). The verdict
  that applies to a whole request collects only the handlers **at least one tool
  declared** and only those that authenticate, so a class dropped into `security/` cannot
  become an alternative way in and an authorization rule cannot refuse an authenticated
  caller with a 401.
- **One handler instance per class per request** (`Mcp::get_security_handler()`), so a
  handler that resolves the caller pays for it once rather than once per tool.
- **`Security\ApiKey` answers five questions about itself** — `is_authenticating()`,
  `presents_credential()`, `get_refusal_code()`, `get_http_status()`,
  `get_http_challenge()` — which is how the application gives a refused credential the
  HTTP status and the `WWW-Authenticate` challenge it deserves without ever inspecting
  one. A credential that was offered and refused is challenged `error="invalid_token"`,
  one that was never offered is challenged bare (RFC 6750 §3.1). The scheme is `ApiKey`,
  not `Bearer`: there is no authorization server behind an MCP application, and a client
  that believes `Bearer` sets off looking for one.
  `get_api_keys()` and `get_digest()` are the two ways a subclass sources or stores a
  credential differently; an empty key list fails closed.
- **The catalogue depends on the credential.** A tool that carries `@security` is absent
  from `tools/list` for a caller without a valid credential, and calling it by name is
  answered as the authentication failure it is rather than as `Unknown tool`. A tool that
  declares nothing is public, which is how a liveness probe stays reachable to somebody
  whose key is the thing under suspicion. `instructions_public` is what such a caller is
  told about the server instead of `instructions`.
- **The `tool` event sees every call, including the ones that failed.**
  `teardown(Tool $tool, array $outcome)` fires on every path out of `Tool::call()` with
  `ok`, `error_code`, `error_message`, `duration_ms`, `row_count` and the `arguments`,
  because the calls worth auditing are precisely the ones that produced no answer.
- **The application reports the exchange** to an event that cannot read the stream
  itself: `Mcp::$request`, `Mcp::$jsonrpc_method` and `Mcp::$response`, since
  `php://input` is spent by the time anything else asks.
- **`Util::to_json_type()`** is the one map from a PHP type to a JSON Schema type, read
  by both halves of the contract.
- **`Docblock`** reads a docblock once, for the description of a tool, its parameters,
  its return and its `@security` tags. Tags the server *acts* on are read from the raw
  text, which cannot fail, so a malformed block cannot silently open a guarded tool.
- **A request for `/.well-known/…` gets 404, and anything but `POST` gets 405**, from
  the application rather than from the webserver.

### Changed

- **One law for both kinds of definition.** The trait's property-driven loop returns raw
  values now, exactly as an authored `get_mcp_definition_info()` does, so `Reducer` is the
  only place an answer becomes JSON. Until now the two paths were held to different rules —
  a wrong scalar type was refused in one and merely logged in the other, which made the
  guarantee depend on which style an author happened to pick. `Reducer::conform()`,
  `Reducer::is_authored()` and `Definition::value_for_property()` were the scaffolding of
  that split and are gone with it.

- **A projected source is judged by the target's map, not by the target's code.**
  `project()` no longer assigns onto the target and asks it to answer; it reads the
  declared names into one array and runs that through the same verification. The map is
  the authority for what a projection publishes, which is what it always promised and
  never enforced.
- `coerce()` is public, so a nested definition reaches the same rules as a top-level one,
  and takes the tool's name with it so a refusal in a child says which call produced it.
- Class names in refusals are made printable: an anonymous class name carries NUL bytes,
  and `error_log()` cut the message at them — which removed exactly the field, type and
  value the line existed to report. Anonymous definitions are how a probe and a test are
  written, so that was not a corner case.

- **A request is checked before anything reads it.** `method` must be a non-empty string,
  `id` a scalar, `params` an object when present, and on `tools/call` `name` a non-empty
  string and `arguments` an object. Each of those was a typed parameter one step further
  down, and a `TypeError` from PHP names the class, the method, the parameter and the
  file: a mistyped field used to be answered with a tour of the implementation, an HTML
  error page, or both.
- **`-32603` says `Internal error`** and nothing else to the client. The message, class,
  file and line of the exception go to the error log. `Mcp\Exception` and
  `Jsonrpc\Exception` still carry their author's words, because those are said on
  purpose.
- **A notification is a request without an `id`**, not one whose `id` is falsy: `"id": 0`,
  `"0"`, `null`, `""` and `false` are answered with those ids. Testing the id for
  emptiness turned the first request of any client that numbers from zero into a
  notification, and that client waited forever for a reply the server had thrown away.
- **The response is encoded with `JSON_INVALID_UTF8_SUBSTITUTE`**, in the envelope and in
  the result: a stray byte from a legacy column used to make `json_encode()` answer false
  and the client receive an empty body with a 200.
- `declare(strict_types=1)` in every file, and the members of every class in the order
  the coding standard asks for: constants, properties, public, protected, private, then
  the statics.

### Removed

- `Handler::handle()` — the unused twin of `handle_request(Handler::decode())`. The
  application reads the body itself, because it has to look at it before it decides what
  a caller is owed, and two entry points meant two ways for the reading to disagree.
- `Mcp::get_security()` — the catalogue of every class under `security/`. Nothing asked
  for it, and a method that instantiates handlers nobody declared is the exact shape the
  credential split exists to forbid.
- `Mcp::instantiate_all()` in favour of `instantiate_tools()`: security handlers are not
  discovered by walking a directory, they are the ones the tools named.
- `Mcp\Component\Definition` and `Mcp\Component\Serializable`, the base class and the
  interface of the previous attempt at declaring a result. The trait replaced them, and a
  class could not use both.
- The property-driven fallback for the output schema. It was a migration path, and a
  second way to declare a shape is a second way to get it wrong quietly: an array of
  objects published through reflection loses its element schema and nothing complains.

## [0.0.2] - 2026-07-24

The server application: `initialize`, `tools/list`, `tools/call`, `ping` and
notifications over a stateless `POST`; the `Tool` base class with its name, description
and `inputSchema`; `Security\ApiKey`; and the `tool` event.

## [0.0.1] - 2026-07-24

First package.
