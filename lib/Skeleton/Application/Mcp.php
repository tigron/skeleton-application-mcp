<?php

declare(strict_types=1);

/**
 * Skeleton Core Application class for MCP
 *
 * The application owns everything around the handler: which tools a caller may see,
 * what a credential that does not check out is answered with, how the HTTP status
 * relates to the JSON-RPC body, and what the server says about itself to somebody who
 * has not authenticated. None of that is a policy an application should have to write,
 * which is why it lives here rather than in a project's subclass of this class.
 *
 * @author Gerry Demaret <gerry@tigron.be>
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application;

class Mcp extends \Skeleton\Core\Application {
	/**
	 * Tool path
	 *
	 * @access public
	 */
	public ?string $tool_path = null;

	/**
	 * Tool namespace
	 *
	 * @access public
	 */
	public ?string $tool_namespace = null;

	/**
	 * Security path
	 *
	 * @access public
	 */
	public ?string $security_path = null;

	/**
	 * Security namespace
	 *
	 * @access public
	 */
	public ?string $security_namespace = null;

	/**
	 * Definition path
	 *
	 * @access public
	 */
	public ?string $definition_path = null;

	/**
	 * Definition namespace
	 *
	 * @access public
	 */
	public ?string $definition_namespace = null;

	/**
	 * Helper path
	 *
	 * @access public
	 */
	public ?string $helper_path = null;

	/**
	 * Helper namespace
	 *
	 * @access public
	 */
	public ?string $helper_namespace = null;

	/**
	 * The decoded request body, as `run()` read it
	 *
	 * The body can only be read from the stream once, and the application is the one
	 * that reads it — so an event that logs the request asks here rather than reading
	 * `php://input` a second time and getting nothing.
	 *
	 * @access public
	 */
	public mixed $request = null;

	/**
	 * The JSON-RPC method this request carries, if any
	 *
	 * @access public
	 */
	public ?string $jsonrpc_method = null;

	/**
	 * The response this request was answered with, if one was sent
	 *
	 * The same reason as the request: the application is the only place that sees both
	 * ends of the exchange, and an event that logs the request asks here rather than
	 * guessing from the status line.
	 *
	 * @var ?array<string, mixed> $response
	 * @access public
	 */
	public ?array $response = null;

	/**
	 * The security handlers of this application, one instance per class per request
	 *
	 * A handler that resolves who is calling pays two queries to do it. Instantiated
	 * per tool, the same handler would ask that question once per tool of the same
	 * request, which is how a credential check becomes the cost of a page. Keyed by
	 * class name, in the order the classes were first seen.
	 *
	 * @var array<string, \Skeleton\Application\Mcp\Security> $security_instances
	 * @access private
	 */
	private array $security_instances = [];

	/**
	 * The handler classes at least one tool declares
	 *
	 * Only these are asked whether the caller is authenticated. A class sitting in
	 * `security/` that no tool named decides nothing: while the credential verdict was
	 * an AND, a stray handler could only lock people out — a failure a suite notices —
	 * and under an OR it would be an alternative way in.
	 *
	 * @var ?array<string, bool> $declared_security_classes
	 * @access private
	 */
	private ?array $declared_security_classes = null;

	/**
	 * The tools of this application, instantiated once per request
	 *
	 * The listing, the lookup by name and the resolution of the declared security
	 * handlers all want the same set, and each walk means reading and parsing the
	 * docblock of every tool again — which is precisely the work the memos in Tool
	 * and in the Definition trait exist to avoid, if the instances survive long
	 * enough to benefit from them.
	 *
	 * @var ?array<int, \Skeleton\Application\Mcp\Tool> $tools
	 * @access private
	 */
	private ?array $tools = null;

	/**
	 * Whether the request offered a credential of any kind
	 *
	 * null until asked: the answer belongs to one request, and it is the difference
	 * between a visitor and a failure.
	 *
	 * @access private
	 */
	private ?bool $credential_presented = null;

	/**
	 * Whether this request carries a credential at least one handler accepts
	 *
	 * null until asked, for the same reason, and asked by the listing, the tool
	 * lookup, the refusal and the description of the server alike. "At least one",
	 * because the handlers in this question are the authenticating ones and two ways
	 * to prove who you are are alternatives: see get_credential_handlers().
	 *
	 * @access private
	 */
	private ?bool $credential_valid = null;

	/**
	 * Run the application
	 *
	 * The order of what happens here is the policy, so it is written once, in one
	 * method, in the order a request meets it: transport, credential, description,
	 * protocol shape, dispatch, status.
	 *
	 * @access public
	 */
	public function run(): void {
		// A request for a well-known document is not this endpoint answered with the
		// wrong verb: there is no such document here, and 405 would advertise one that
		// only wants a different method — which is exactly what a client doing OAuth
		// discovery needs somebody to believe. Answered without a JSON body, because
		// the answer is the framework's status page and not a JSON-RPC response.
		// The second argument keeps the answer inside this method: Status would
		// otherwise exit the process, and run() has no business ending a request it
		// did not begin.
		if ($this->is_well_known_request() === true) {
			\Skeleton\Core\Http\Status::code_404('', false);

			return;
		}

		$http_method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

		if ($http_method !== 'POST') {
			\Skeleton\Core\Http\Status::code_405('', false);

			return;
		}

		// response is always JSON from here on: every answer below is a JSON-RPC body
		header('Content-Type: application/json');

		// Read the body once and let everything below look at the same structure: the
		// refusal has to answer the request the caller actually sent, with its id, and
		// the handler has to see the same interpretation of it we did.
		$raw = (string)file_get_contents('php://input');
		$request = \Skeleton\Application\Mcp\Handler::decode($raw);

		// The stream is spent: everything after this point that wants to know what was
		// asked reads it off the application, which is where it was read.
		$this->request = $request;
		$this->jsonrpc_method = $this->get_jsonrpc_method($request);

		// A caller who offered a credential they cannot back up is refused here, on
		// every method, before the handler is reached: they are mid-authentication and
		// get one uniform answer, so nothing about the request can be confirmed to
		// them. A caller who offered nothing is a visitor rather than a failure, and
		// the public view of the server is theirs. A notification carries no answer to
		// withhold, so it keeps its 202 in both cases.
		$refusal = $this->refuse_bad_credential($request);

		if ($refusal !== null) {
			$this->send($refusal, true);

			return;
		}

		$this->describe_only_what_is_public();

		$handler = new \Skeleton\Application\Mcp\Handler($this);
		$response = $handler->handle_request($request);

		// JSON-RPC needs an HTTP 202 Accepted if no outputs
		if ($response === null) {
			http_response_code(202);

			return;
		}

		$this->send($response, $this->is_auth_failure($response));
	}

	/**
	 * Get the tools this caller may see
	 *
	 * A tool that guards itself with @security is not here for a caller who showed no
	 * credential: the listing does not contain it, so nobody discovers a private tool
	 * by reading the catalogue. Calling one by name is a different question, and
	 * get_tool() answers it as an authentication failure rather than pretending the
	 * tool was never written — a caller who forgot their key has to be told that, not
	 * sent to debug a registry they cannot reach. A tool that declares nothing is
	 * public, so leaving @security off is how a tool says it may be used without a
	 * key.
	 *
	 * @access public
	 * @return array<int, \Skeleton\Application\Mcp\Tool> $tools The tools this caller may list
	 */
	public function get_tools(): array {
		$tools = $this->get_all_tools();

		if ($this->credential_is_valid() === true) {
			return $tools;
		}

		$public = [];

		foreach ($tools as $tool) {
			if (count($tool->get_security()) === 0) {
				$public[] = $tool;
			}
		}

		return $public;
	}

	/**
	 * Get a single tool by its advertised name
	 *
	 * Every name this server knows is looked up, not only the ones this caller may
	 * use: a protected tool that answered "unknown tool" would be reporting a missing
	 * credential as a spelling mistake, and a client would go looking for the bug in
	 * the wrong place. A name that exists nowhere keeps its -32602.
	 *
	 * @access public
	 * @param string $name The name the client called
	 * @return \Skeleton\Application\Mcp\Tool $tool The tool that answers to that name
	 * @throws \Skeleton\Application\Mcp\Jsonrpc\Exception When no tool of this application carries that name
	 */
	public function get_tool(string $name): \Skeleton\Application\Mcp\Tool {
		foreach ($this->get_all_tools() as $tool) {
			if ($tool->get_name() !== $name) {
				continue;
			}

			if (count($tool->get_security()) > 0 && $this->credential_is_valid() === false) {
				$this->refuse_caller();
			}

			return $tool;
		}

		throw new \Skeleton\Application\Mcp\Jsonrpc\Exception('Unknown tool ' . $name, -32602);
	}

	/**
	 * Get the one instance of a security handler for this request
	 *
	 * A handler that resolves who is calling pays for it, and paying per tool is how a
	 * credential check becomes the cost of a page. Tools ask for their handlers through
	 * this method, so the static key of a request is read once and the principal behind a
	 * bearer token is looked up once, whoever the tool.
	 *
	 * @access public
	 * @param string $classname The handler class a tool declared
	 * @return \Skeleton\Application\Mcp\Security $security The shared instance
	 * @throws \Exception When the class does not exist
	 */
	public function get_security_handler(string $classname): \Skeleton\Application\Mcp\Security {
		if (isset($this->security_instances[$classname]) === false) {
			if (class_exists($classname) === false) {
				throw new \Exception('Unknown security class ' . $classname . ' for application ' . $this->name);
			}

			$this->security_instances[$classname] = new $classname();
		}

		return $this->security_instances[$classname];
	}

	/**
	 * Get details
	 *
	 * The four directories an MCP application may hold, and their namespaces. Tools,
	 * security handlers, results and helpers are all autoloaded from here: only the
	 * first two are ever instantiated by this class, the others are looked up by name
	 * when a tool asks for them.
	 *
	 * @access protected
	 */
	protected function get_details(): void {
		parent::get_details();

		$this->tool_path = $this->path . '/tool/';
		$this->security_path = $this->path . '/security/';
		$this->definition_path = $this->path . '/definition/';
		$this->helper_path = $this->path . '/helper/';

		$this->tool_namespace = '\\App\\' . ucfirst($this->name) . '\\Tool\\';
		$this->security_namespace = '\\App\\' . ucfirst($this->name) . '\\Security\\';
		$this->definition_namespace = '\\App\\' . ucfirst($this->name) . '\\Definition\\';
		$this->helper_namespace = '\\App\\' . ucfirst($this->name) . '\\Helper\\';

		$autoloader = new \Skeleton\Core\Autoloader();
		$autoloader->add_namespace($this->tool_namespace, $this->tool_path);
		$autoloader->add_namespace($this->security_namespace, $this->security_path);
		$autoloader->add_namespace($this->definition_namespace, $this->definition_path);
		$autoloader->add_namespace($this->helper_namespace, $this->helper_path);
		$autoloader->register();
	}

	/**
	 * Load the config
	 *
	 * @access protected
	 */
	protected function load_config(): void {
		/**
		 * Set some defaults
		 */
		$this->config->hostnames = [];

		// MCP server metadata (should be overwritten by the app)
		$this->config->server_name = $this->name;
		$this->config->server_version = '1.0.0';
		$this->config->protocol_version = '2025-06-18';
		$this->config->instructions = '';

		// what a caller who showed no credential is told instead, see run()
		$this->config->instructions_public = '';

		parent::load_config();
	}

	/**
	 * Get events
	 *
	 * @access protected
	 * @return array<string, string> $events The events this application offers
	 */
	protected function get_events(): array {
		$parent_events = parent::get_events();
		$mcp_events = [
			'Tool' => '\\Skeleton\\Application\\Mcp\\Event\\Tool',
		];

		return array_merge($parent_events, $mcp_events);
	}

	/**
	 * Get all tools defined in the application, whoever the caller is
	 *
	 * @access protected
	 * @return array<int, \Skeleton\Application\Mcp\Tool> $tools Every tool of the application
	 */
	protected function get_all_tools(): array {
		if ($this->tools !== null) {
			return $this->tools;
		}

		return $this->tools = $this->instantiate_tools();
	}

	/**
	 * Instantiate every tool of the application
	 *
	 * A tool is a file under `tool/` whose path below that directory spells its class
	 * name. Nothing is filtered out: every `.php` in there becomes a tool, which is why
	 * a helper or an abstract class left in that directory is advertised — and why the
	 * autoloader trap in the README (a class name may not hold an underscore) is worth
	 * knowing before the first `tools/list` dies with an opaque `-32603`.
	 *
	 * @access protected
	 * @return array<int, \Skeleton\Application\Mcp\Tool> $tools The instances, in path order
	 */
	protected function instantiate_tools(): array {
		$tool_path = (string)$this->tool_path;

		if ($tool_path === '' || file_exists($tool_path) === false) {
			return [];
		}

		$tools = [];

		foreach (\Skeleton\Application\Mcp\Util::rglob($tool_path . '*.php') as $file) {
			$file = str_replace($tool_path, '', $file);
			$pathinfo = pathinfo($file);

			$classname = '';

			if ($pathinfo['dirname'] !== '.') {
				$classname .= $pathinfo['dirname'] . '/';
			}

			$classname .= $pathinfo['filename'];
			$classname = str_replace(DIRECTORY_SEPARATOR, '\\', $classname);

			$tools[] = new ($this->tool_namespace . $classname)();
		}

		return $tools;
	}

	/**
	 * Get the handler classes at least one tool declares
	 *
	 * Read from the docblocks, so it asks the tools what they name without instantiating
	 * a single handler — which is what keeps this and get_security_handler() from
	 * calling each other.
	 *
	 * @access protected
	 * @return array<string, bool> $classes Class name => true, in the order first seen
	 */
	protected function get_declared_security_classes(): array {
		if ($this->declared_security_classes !== null) {
			return $this->declared_security_classes;
		}

		$classes = [];

		foreach ($this->get_all_tools() as $tool) {
			foreach ($tool->get_declared_security_classes() as $classname) {
				$classes[$classname] = true;
			}
		}

		return $this->declared_security_classes = $classes;
	}

	/**
	 * Get the handlers that answer for the caller's identity
	 *
	 * Two filters, and both are load-bearing. Only handlers **at least one tool
	 * declared** are asked: a class sitting in `security/` that nothing named must not
	 * become an alternative way to authenticate, which is what an OR would let it do —
	 * under the AND this method replaces it could only ever lock people out, so the
	 * difference is between a bug a suite notices and a door nobody notices. And only
	 * handlers that **authenticate** are asked: an authorization handler answers a
	 * question about a tool, not about the caller, and letting it into a verdict that
	 * applies to `initialize` and `tools/list` would answer a perfectly authenticated
	 * caller with a 401 because one tool asked for more than they hold.
	 *
	 * @access protected
	 * @return array<int, \Skeleton\Application\Mcp\Security> $handlers
	 */
	protected function get_credential_handlers(): array {
		$handlers = [];

		foreach ($this->get_declared_security_classes() as $classname => $declared) {
			$security = $this->get_security_handler($classname);

			if ($security->is_authenticating() === true) {
				$handlers[] = $security;
			}
		}

		return $handlers;
	}

	/**
	 * Tell whether the caller presented a credential of any kind
	 *
	 * Asked of the credential handlers only: what an authorization handler considers
	 * "presented" is a fact about a tool, not about the request, and a caller who sent a
	 * good key and holds no role is a caller who authenticated.
	 *
	 * @access protected
	 * @return bool $presented Whether somebody tried to authenticate
	 */
	protected function credential_was_presented(): bool {
		if ($this->credential_presented !== null) {
			return $this->credential_presented;
		}

		$this->credential_presented = false;

		foreach ($this->get_credential_handlers() as $security) {
			if ($security->presents_credential() === true) {
				$this->credential_presented = true;

				break;
			}
		}

		return $this->credential_presented;
	}

	/**
	 * Tell whether the credential this request carries is one we accept
	 *
	 * **At least one** authenticating handler has to accept the caller. A server that
	 * takes a static key and a bearer token is not asking for both, and a caller who
	 * proved their identity one of the two ways is authenticated whatever the other
	 * handler says about a credential they never sent. With a single kind of credential
	 * — the case every application had until a second one existed — one handler is both
	 * the OR and the AND, so nothing changes for it.
	 *
	 * An application that declares no authenticating handler at all accepts everybody:
	 * there is nobody to ask, and the empty OR of "prove who you are" is the same answer
	 * as "anybody may look", which is how a tool without @security is public here too.
	 *
	 * @access protected
	 * @return bool $valid Whether this caller may see the protected tools
	 */
	protected function credential_is_valid(): bool {
		if ($this->credential_valid !== null) {
			return $this->credential_valid;
		}

		$handlers = $this->get_credential_handlers();

		if (count($handlers) === 0) {
			$this->credential_valid = true;

			return $this->credential_valid;
		}

		foreach ($handlers as $security) {
			try {
				$security->handle();

				$this->credential_valid = true;

				return $this->credential_valid;
			} catch (\Skeleton\Application\Mcp\Jsonrpc\Exception) {
				// the next handler may still accept this caller
			}
		}

		$this->credential_valid = false;

		return $this->credential_valid;
	}

	/**
	 * Let a security handler say why it is refusing this caller
	 *
	 * The handler's own exception, code and message, so that "you have no key", "that
	 * key is not ours" and "this server has no keys configured" all arrive in the same
	 * words whoever asked — and the words of the handler whose credential the caller
	 * actually offered are the useful ones: somebody who sent a bearer token wants to
	 * hear about tokens, not about a staff key list they were never in.
	 *
	 * @access protected
	 * @throws \Skeleton\Application\Mcp\Jsonrpc\Exception With the handler's own code and message
	 */
	protected function refuse_caller(): void {
		$refusal = null;

		foreach ($this->get_credential_handlers() as $security) {
			if ($refusal === null && $security->presents_credential() === false) {
				continue;
			}

			try {
				$security->handle();
			} catch (\Skeleton\Application\Mcp\Jsonrpc\Exception $e) {
				$refusal ??= $e;
			}
		}

		if ($refusal !== null) {
			throw $refusal;
		}

		// Nothing refused us, and yet we are refusing: say the least we can say, in the
		// shape the transport knows how to promote to a 401.
		$refusing_handler = $this->get_refusing_handler();

		throw new \Skeleton\Application\Mcp\Jsonrpc\Exception(
			'Unauthorized',
			$refusing_handler?->get_refusal_code() ?? -32001
		);
	}

	/**
	 * Refuse a request whose credential does not check out
	 *
	 * Only a caller who actually presented something is judged: the one who presented
	 * nothing is not failing an authentication, they are using the public part of the
	 * server. The answer carries the id of the member that expects a reply — the whole
	 * body is refused, as JSON-RPC refuses a body it cannot use, and a notification has
	 * no answer to refuse.
	 *
	 * @access protected
	 * @param mixed $request The decoded request body
	 * @return ?array<string, mixed> $refusal The JSON-RPC error to answer with, null to continue
	 */
	protected function refuse_bad_credential(mixed $request): ?array {
		if ($this->credential_was_presented() === false) {
			return null;
		}

		if ($this->credential_is_valid() === true) {
			return null;
		}

		if (is_array($request) === true && array_is_list($request) === true) {
			$members = $request;
		} elseif (is_array($request) === true) {
			$members = [ $request ];
		} else {
			// A body that is not even a structure is treated as one request without an
			// id: the caller is refused on credentials, and the server neither confirms
			// nor denies that the JSON was unreadable.
			$members = [ [ 'id' => null ] ];
		}

		foreach ($members as $member) {
			if (is_array($member) === false || array_key_exists('id', $member) === false) {
				continue;
			}

			try {
				$this->refuse_caller();
			} catch (\Skeleton\Application\Mcp\Jsonrpc\Exception $e) {
				return [
					'jsonrpc' => '2.0',
					'id' => $member['id'],
					'error' => [
						'code' => $e->getCode(),
						'message' => $e->getMessage(),
					],
				];
			}
		}

		return null;
	}

	/**
	 * Describe this server in the words this caller has earned
	 *
	 * initialize hands its instructions to the client, and they name the tools behind
	 * a credential and how to use them. A caller who showed none is being described a
	 * server they cannot use, so they get instructions_public: the text that mentions
	 * only what is public and explains how to get the rest. An application with nothing
	 * to hide leaves it empty and nothing changes.
	 *
	 * @access protected
	 */
	protected function describe_only_what_is_public(): void {
		if ($this->credential_is_valid() === true) {
			return;
		}

		$public = $this->config->instructions_public ?? '';

		if (is_string($public) === true && $public !== '') {
			$this->config->instructions = $public;
		}
	}

	/**
	 * Answer a client, with the HTTP status its security handler asks for
	 *
	 * The handler writes a JSON-RPC body and lets the status line stay at 200. Over
	 * HTTP that is a lost signal: a refused credential is a 401 with a challenge, so a
	 * caller knows the server is up and its own key is the problem, which is what a
	 * 200-with-error cannot say. Which status, and whether to send a challenge at all,
	 * is up to the handler that refused — the application does not know what a
	 * credential looks like.
	 *
	 * @access protected
	 * @param array<string, mixed> $response The JSON-RPC response
	 * @param bool $unauthorized Whether the whole answer is a credential refusal
	 */
	protected function send(array $response, bool $unauthorized = false): void {
		$this->response = $response;

		if ($unauthorized === true) {
			$security = $this->get_refusing_handler();

			if ($security !== null) {
				http_response_code($security->get_http_status());

				$challenge = $security->get_http_challenge($this->credential_was_presented());

				if ($challenge !== null) {
					header('WWW-Authenticate: ' . $challenge);
				}
			}
		}

		$json = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

		if ($json === false) {
			// Nothing of this response can be written as JSON, so nothing of it can be
			// sent either. The answer has to be a valid JSON-RPC error, which rules out
			// echoing anything that came from the response itself — the id included,
			// since the id is part of what would not encode.
			error_log('MCP: the response could not be encoded: ' . json_last_error_msg());

			$json = '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}';
		}

		echo $json;
	}

	/**
	 * Get the handler that says what its refusals cost
	 *
	 * The one whose credential the caller actually offered, and failing that the first
	 * that says anything: a caller who sent a bearer token and was refused should be
	 * challenged for a token, not pointed at a staff key list they never used.
	 *
	 * @access protected
	 * @return ?\Skeleton\Application\Mcp\Security $security The handler that maps its own refusals
	 */
	protected function get_refusing_handler(): ?\Skeleton\Application\Mcp\Security {
		$fallback = null;

		foreach ($this->get_credential_handlers() as $security) {
			if ($security->get_refusal_code() === null) {
				continue;
			}

			if ($security->presents_credential() === true) {
				return $security;
			}

			$fallback ??= $security;
		}

		return $fallback;
	}

	/**
	 * Tell whether the answer is nothing but a credential refusal
	 *
	 * A batch that answered anything else keeps its 200 — the HTTP status belongs to the
	 * message as a whole, and one member's refusal is not a refusal of the request — and
	 * so does a response that carries a result.
	 *
	 * @access protected
	 * @param array<string, mixed> $response The JSON-RPC response, or a list of them
	 * @return bool $unauthorized Whether every member refuses the credential
	 */
	protected function is_auth_failure(array $response): bool {
		$members = array_is_list($response) === true ? $response : [ $response ];

		if (count($members) === 0) {
			return false;
		}

		$codes = [];

		foreach ($this->get_credential_handlers() as $security) {
			$refusal_code = $security->get_refusal_code();

			if ($refusal_code !== null) {
				$codes[] = $refusal_code;
			}
		}

		foreach ($members as $member) {
			if (is_array($member) === false) {
				return false;
			}

			$error = $member['error'] ?? null;

			if (is_array($error) === false || in_array($error['code'] ?? null, $codes, true) === false) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the JSON-RPC method a decoded body asks for
	 *
	 * One string for the one request the transport answers, null for anything that is
	 * not a request at all — an unreadable body, or a batch (refused before the handler
	 * is reached). The application reports it so a log line says what was asked even
	 * when nothing dispatched.
	 *
	 * @access protected
	 * @param mixed $request The decoded body
	 * @return ?string $method The method name, null when there is none
	 */
	protected function get_jsonrpc_method(mixed $request): ?string {
		if (is_array($request) === false || array_is_list($request) === true) {
			return null;
		}

		if (isset($request['method']) === false || is_string($request['method']) === false) {
			return null;
		}

		return $request['method'];
	}

	/**
	 * Tell whether the request asks for a well-known document
	 *
	 * Which of these holds the path the client typed depends on the webserver in front
	 * of it: REQUEST_URI on a plain Apache, ORIG_REQUEST_URI and REDIRECT_URL once
	 * mod_rewrite has had its say, SCRIPT_NAME on the odd setup that maps the handler as
	 * a script. All of them are checked, because answering a discovery request with 405
	 * is a lie we only tell when we failed to notice the path.
	 *
	 * @access protected
	 * @return bool $well_known Whether any reading of the request puts it under /.well-known/
	 */
	protected function is_well_known_request(): bool {
		$candidates = [
			$_SERVER['ORIG_REQUEST_URI'] ?? null,
			$_SERVER['REDIRECT_URL'] ?? null,
			$_SERVER['REQUEST_URI'] ?? null,
			$_SERVER['SCRIPT_NAME'] ?? null,
			$this->request_relative_uri,
		];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === false) {
				continue;
			}

			$path = parse_url($candidate, PHP_URL_PATH);

			if (is_string($path) === true && strpos($path, '/.well-known/') === 0) {
				return true;
			}
		}

		return false;
	}
}
