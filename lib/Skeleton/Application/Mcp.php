<?php
/**
 * Skeleton Core Application class for MCP
 *
 * The application owns everything around the handler: which tools a caller may see,
 * what a credential that does not check out is answered with, how the HTTP status
 * relates to the JSON-RPC body, and what the server says about itself to somebody who
 * has not authenticated. None of that is a policy an application should have to write,
 * which is why it lives here rather than in a project's subclass of this class.
 *
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application;

class Mcp extends \Skeleton\Core\Application {

	/**
	 * Tool path
	 *
	 * @var string $tool_path
	 * @access public
	 */
	public $tool_path = null;

	/**
	 * Tool namespace
	 *
	 * @var string $tool_namespace
	 * @access public
	 */
	public $tool_namespace = null;

	/**
	 * Security path
	 *
	 * @var string $security_path
	 * @access public
	 */
	public $security_path = null;

	/**
	 * Security namespace
	 *
	 * @var string $security_namespace
	 * @access public
	 */
	public $security_namespace = null;

	/**
	 * Component path
	 *
	 * @var string $component_path
	 * @access public
	 */
	public $component_path = null;

	/**
	 * Component namespace
	 *
	 * @var string $component_namespace
	 * @access public
	 */
	public $component_namespace = null;

	/**
	 * The security handlers of this application, resolved once per request
	 *
	 * @var ?array<int, \Skeleton\Application\Mcp\Security> $security_handlers
	 * @access private
	 */
	private ?array $security_handlers = null;

	/**
	 * The tools of this application, instantiated once per request
	 *
	 * The listing, the lookup by name and the resolution of the declared security
	 * handlers all want the same set, and each walk means reading and parsing the
	 * docblock of every tool again — which is precisely the work the memos in Tool
	 * and Component\Definition exist to avoid, if the instances survive long enough
	 * to benefit from them.
	 *
	 * @access private
	 * @var ?array<int, \Skeleton\Application\Mcp\Tool> $tools
	 */
	private ?array $tools = null;

	/**
	 * Whether the request offered a credential of any kind
	 *
	 * null until asked: the answer belongs to one request, and it is the difference
	 * between a visitor and a failure.
	 *
	 * @var ?bool $credential_presented
	 * @access private
	 */
	private ?bool $credential_presented = null;

	/**
	 * Whether this request carries a credential every handler accepts
	 *
	 * null until asked, for the same reason, and asked by the listing, the tool
	 * lookup, the refusal and the description of the server alike.
	 *
	 * @var ?bool $credential_valid
	 * @access private
	 */
	private ?bool $credential_valid = null;

	/**
	 * Get details
	 *
	 * @access protected
	 */
	protected function get_details(): void {
		parent::get_details();

		$this->tool_path = $this->path . '/tool/';
		$this->security_path = $this->path . '/security/';
		$this->component_path = $this->path . '/component/';

		$this->tool_namespace = "\\App\\" . ucfirst($this->name) . "\\Tool\\";
		$this->security_namespace = "\\App\\" . ucfirst($this->name) . "\\Security\\";
		$this->component_namespace = "\\App\\" . ucfirst($this->name) . "\\Component\\";

		$autoloader = new \Skeleton\Core\Autoloader();
		$autoloader->add_namespace($this->tool_namespace, $this->tool_path);
		$autoloader->add_namespace($this->security_namespace, $this->security_path);
		$autoloader->add_namespace($this->component_namespace, $this->component_path);
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
	 * Run the application
	 *
	 * The order of what happens here is the policy, so it is written once, in one
	 * method, in the order a request meets it: transport, credential, description,
	 * protocol shape, dispatch, status.
	 *
	 * @access public
	 */
	public function run(): void {
		// response is always JSON
		header('Content-Type: application/json');

		// A request for a well-known document is not this endpoint answered with the
		// wrong verb: there is no such document here, and 405 would advertise one that
		// only wants a different method — which is exactly what a client doing OAuth
		// discovery needs somebody to believe.
		if ($this->is_well_known_request() === true) {
			\Skeleton\Core\Http\Status::code_404('Not Found');

			return;
		}

		$http_method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

		if ($http_method !== 'POST') {
			\Skeleton\Core\Http\Status::code_405('Method Not Allowed');
			return;
		}

		// Read the body once and let everything below look at the same structure: the
		// refusal has to answer the request the caller actually sent, with its id, and
		// the handler has to see the same interpretation of it we did.
		$raw = (string)file_get_contents('php://input');
		$request = \Skeleton\Application\Mcp\Handler::decode($raw);

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
	 * Get events
	 *
	 * @access protected
	 * @return array $events
	 */
	protected function get_events(): array {
		$parent_events = parent::get_events();
		$mcp_events = [
			'Tool' => '\\Skeleton\\Application\\Mcp\\Event\\Tool',
		];

		return array_merge($parent_events, $mcp_events);
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
	 * @return \Skeleton\Application\Mcp\Tool[] $tools
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
	 * @param string $name
	 * @return \Skeleton\Application\Mcp\Tool $tool
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
	 * Get all security handlers defined by the application
	 *
	 * @access public
	 * @return \Skeleton\Application\Mcp\Security[] $securities
	 */
	public function get_security(): array {
		return $this->instantiate_all($this->security_path, $this->security_namespace);
	}

	/**
	 * Get all tools defined in the application, whoever the caller is
	 *
	 * @access protected
	 * @return \Skeleton\Application\Mcp\Tool[] $tools
	 */
	protected function get_all_tools(): array {
		if ($this->tools !== null) {
			return $this->tools;
		}

		return $this->tools = $this->instantiate_all($this->tool_path, $this->tool_namespace);
	}

	/**
	 * Instantiate every class in a directory of the application
	 *
	 * Tools, security handlers and (through the autoloader registered in
	 * get_details()) result components are all found the same way: a file under the
	 * application directory whose path below it spells its class name. Two copies of
	 * that walk is two ways for the two kinds to drift apart in what they accept as a
	 * class name, which the autoloader trap in the README makes an easy mistake to
	 * make.
	 *
	 * @access protected
	 * @param string $path The directory to walk
	 * @param string $namespace The namespace the path is relative to
	 * @return array<int, object> $objects The instances, in path order
	 */
	protected function instantiate_all(string $path, string $namespace): array {
		if (file_exists($path) === false) {
			return [];
		}

		$objects = [];

		foreach (\Skeleton\Application\Mcp\Util::rglob($path . '*.php') as $file) {
			$file = str_replace($path, '', $file);
			$pathinfo = pathinfo($file);

			$classname = '';

			if ($pathinfo['dirname'] !== '.') {
				$classname .= $pathinfo['dirname'] . '/';
			}

			$classname .= $pathinfo['filename'];
			$classname = str_replace(DIRECTORY_SEPARATOR, '\\', $classname);

			$objects[] = new ($namespace . $classname)();
		}

		return $objects;
	}

	/**
	 * Get the security handlers this application has to answer to
	 *
	 * The ones of the application itself (app/<name>/security/) plus the ones every
	 * tool declares with @security, deduplicated by class: asking the same handler
	 * twice about the same request would only ask it twice.
	 *
	 * @access protected
	 * @return array<int, \Skeleton\Application\Mcp\Security> $handlers
	 */
	protected function get_security_handlers(): array {
		if ($this->security_handlers !== null) {
			return $this->security_handlers;
		}

		$handlers = [];

		foreach ($this->get_security() as $security) {
			$handlers[get_class($security)] = $security;
		}

		foreach ($this->get_all_tools() as $tool) {
			foreach ($tool->get_security() as $security) {
				$handlers[get_class($security)] = $security;
			}
		}

		$this->security_handlers = array_values($handlers);

		return $this->security_handlers;
	}

	/**
	 * Tell whether the caller presented a credential of any kind
	 *
	 * @access protected
	 * @return bool $presented Whether somebody tried to authenticate
	 */
	protected function credential_was_presented(): bool {
		if ($this->credential_presented !== null) {
			return $this->credential_presented;
		}

		$this->credential_presented = false;

		foreach ($this->get_security_handlers() as $security) {
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
	 * Every handler has to accept the caller: a tool guarded by two of them is not
	 * open to a request that satisfies one.
	 *
	 * @access protected
	 * @return bool $valid Whether this caller may see the protected tools
	 */
	protected function credential_is_valid(): bool {
		if ($this->credential_valid !== null) {
			return $this->credential_valid;
		}

		$this->credential_valid = true;

		foreach ($this->get_security_handlers() as $security) {
			try {
				$security->handle();
			} catch (\Skeleton\Application\Mcp\Jsonrpc\Exception $e) {
				$this->credential_valid = false;

				break;
			}
		}

		return $this->credential_valid;
	}

	/**
	 * Let a security handler say why it is refusing this caller
	 *
	 * The handler's own exception, code and message, so that "you have no key", "that
	 * key is not ours" and "this server has no keys configured" all arrive in the same
	 * words whoever asked.
	 *
	 * @access protected
	 * @throws \Skeleton\Application\Mcp\Jsonrpc\Exception With the handler's own code and message
	 */
	protected function refuse_caller(): void {
		foreach ($this->get_security_handlers() as $security) {
			try {
				$security->handle();
			} catch (\Skeleton\Application\Mcp\Jsonrpc\Exception $e) {
				throw $e;
			}
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
	 * @return ?array<string, string|array> $refusal The JSON-RPC error to answer with, null to continue
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
	 * @param array<string, int|string|bool|float|array|null> $response The JSON-RPC response
	 * @param bool $unauthorized Whether the whole answer is a credential refusal
	 */
	protected function send(array $response, bool $unauthorized = false): void {
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

		echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Get the first handler that says what its refusals cost
	 *
	 * @access protected
	 * @return ?\Skeleton\Application\Mcp\Security $security The handler that maps its own refusals
	 */
	protected function get_refusing_handler(): ?\Skeleton\Application\Mcp\Security {
		foreach ($this->get_security_handlers() as $security) {
			if ($security->get_refusal_code() !== null) {
				return $security;
			}
		}

		return null;
	}

	/**
	 * Tell whether the answer is nothing but a credential refusal
	 *
	 * A batch that answered anything else keeps its 200 — the HTTP status belongs to the
	 * message as a whole, and one member's refusal is not a refusal of the request — and
	 * so does a response that carries a result.
	 *
	 * @access protected
	 * @param array<string, int|string|array> $response The JSON-RPC response, or a list of them
	 * @return bool $unauthorized Whether every member refuses the credential
	 */
	protected function is_auth_failure(array $response): bool {
		$members = array_is_list($response) === true ? $response : [ $response ];

		if (count($members) === 0) {
			return false;
		}

		$codes = [];

		foreach ($this->get_security_handlers() as $security) {
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

			if (strpos((string)parse_url($candidate, PHP_URL_PATH), '/.well-known/') === 0) {
				return true;
			}
		}

		return false;
	}
}
