<?php

declare(strict_types=1);

/**
 * Tool base class
 *
 * What a tool is made of: an execute() whose signature is its inputSchema, a class
 * docblock that is the description a model reads before deciding to call it, an optional
 * security annotation that says who may call it, and a return docblock that names the
 * class declaring the shape of the answer.
 *
 * Everything the client is told is *derived* from that, never written twice: see
 * Docblock for how the docblocks are read, Util::to_json_type() for how a PHP type
 * becomes a JSON Schema type, and the Definition trait for the result side of the same
 * idea. A tool that states a bound it does not enforce, or enforces one it does not
 * state, is the failure this design exists to prevent — get_input_constraints() is read
 * by both the schema and the check.
 *
 * @author Gerry Demaret <gerry@tigron.be>
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp;

abstract class Tool extends \Skeleton\Core\Application\Module {
	/**
	 * The security handlers of this tool, resolved once
	 *
	 * @access private
	 * @var ?array<int, \Skeleton\Application\Mcp\Security> $security
	 */
	private ?array $security = null;

	/**
	 * The answer of this call, already reduced against its declaration
	 *
	 * Set once by `call()` and read by `Handler`: the payload is built for the log line
	 * and for the client from the same reduction, never twice.
	 *
	 * @access private
	 * @var ?array<string, mixed> $reduced
	 */
	private ?array $reduced = null;

	/**
	 * Constructor
	 *
	 * @access public
	 * @throws \Exception When the class does not declare a public execute()
	 */
	public function __construct() {
		// execute() can not be an abstract function as it has a varying signature; PHP
		// doesn't support that. So the one thing every tool must have is checked here,
		// where a missing one is a fatal error at the moment it is instantiated rather
		// than an opaque refusal at the moment a client calls it.
		if (method_exists($this, 'execute') === false) {
			throw new \Exception(get_class($this) . ' must declare a public execute() method');
		}
	}

	/**
	 * Get the tool name (advertised to the MCP client)
	 *
	 * The path below `tool/`, flattened: `Search/Organization.php` is
	 * `search_organization`, which is the snake_case the protocol asks for and the same
	 * name the file spells. A name is lowercased because the lookup is a comparison, and
	 * two tools that differ only in case would be one tool to a client and two to the
	 * server.
	 *
	 * @access public
	 * @return string $name The advertised name
	 */
	public function get_name(): string {
		$application = \Skeleton\Core\Application::get();
		$class = new \ReflectionClass($this);
		$filename = $class->getFileName();
		$filename = str_replace((string)$application->tool_path, '', (string)$filename);
		$filename = pathinfo($filename);

		if ($filename['dirname'] !== '.') {
			$name = $filename['dirname'] . '/' . $filename['filename'];
		} else {
			$name = $filename['filename'];
		}

		// flatten whatever the result is with underscores
		$name = str_replace(['/', '\\'], '_', $name);

		return strtolower($name);
	}

	/**
	 * Get the tool description (advertised to the MCP client)
	 *
	 * Fetched from the docblock above the class Foo {} definition, and the only text a
	 * model reads before it decides whether to call this tool at all.
	 *
	 * @access public
	 * @return string $description What the client is told the tool does
	 */
	public function get_description(): string {
		$docblock = Docblock::read((new \ReflectionClass($this))->getDocComment());

		if ($docblock === null) {
			return '';
		}

		return $docblock->get_description();
	}

	/**
	 * Build the JSON inputSchema for the current tool (tells the MCP client how
	 * and what it should send to the tool)
	 *
	 * Read from execute(): its parameters give the properties, their types give the
	 * JSON types (a nullable one as a union, so a client that sends null does not die
	 * in PHP), their defaults are published, and what the tool enforces beyond the type
	 * comes from get_input_constraints(). Nothing here is asked of the author twice.
	 *
	 * @access public
	 * @return array<string, mixed> $schema The inputSchema to advertise
	 */
	public function get_input_schema(): array {
		$reflection = new \ReflectionMethod($this, 'execute');
		$docblock = Docblock::read($reflection->getDocComment());
		$docblock_params = $docblock?->get_params() ?? [];
		$constraints = $this->get_input_constraints();

		$properties = [];
		$required = [];

		foreach ($reflection->getParameters() as $parameter) {
			$name = $parameter->getName();
			$meta = $docblock_params[$name] ?? ['type' => null, 'description' => null];
			$reflection_type = $parameter->getType();
			$php_type = $reflection_type instanceof \ReflectionNamedType ? $reflection_type->getName() : null;

			// no declared type to go on (a union, or none at all): the @param says what
			// the author meant
			if ($php_type === null && isset($meta['type']) === true) {
				$php_type = ltrim((string)$meta['type'], '\\');
			}

			$property = [
				'type' => Util::to_json_type($php_type),
			];

			// a nullable parameter accepts null too: say so, otherwise a client
			// that sends null dies on a PHP TypeError
			$accepts_null = $reflection_type instanceof \ReflectionNamedType
				&& $reflection_type->allowsNull() === true
				&& $reflection_type->getName() !== 'null';

			if ($accepts_null === true) {
				$property['type'] = [ $property['type'], 'null' ];
			}

			if (empty($meta['description']) === false) {
				$property['description'] = trim($meta['description']);
			}

			// what the parameter falls back to when it is left out
			if ($parameter->isOptional() === true) {
				$property['default'] = $parameter->getDefaultValue();
			}

			// what the tool enforces beyond the type: minimum, maximum, enum, …
			foreach ($constraints[$name] ?? [] as $keyword => $value) {
				$property[$keyword] = $value;
			}

			$properties[$name] = $property;

			if ($parameter->isOptional() === false) {
				$required[] = $name;
			}
		}

		$schema = [
			'type' => 'object',
			'properties' => (object)$properties,
			// call() refuses anything else, so say so: a client that mistypes an
			// argument should be told, not watch the filter vanish
			'additionalProperties' => false,
		];

		if (count($required) > 0) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Get what the client should know about calling this tool
	 *
	 * The annotations of the MCP specification: title, readOnlyHint,
	 * destructiveHint, idempotentHint and openWorldHint. A host uses them to
	 * decide whether a call needs confirming, so a read-only tool that says
	 * nothing is a read-only tool that gets asked about. Return null (the default)
	 * when there is nothing to say.
	 *
	 * @access public
	 * @return ?array<string, mixed> $annotations The annotations to advertise, null for none
	 */
	public function get_annotations(): ?array {
		return null;
	}

	/**
	 * Get what this tool enforces about its parameters beyond their type
	 *
	 * JSON Schema keywords per parameter name, merged into that parameter's entry
	 * of the inputSchema: minimum and maximum for an int, enum for a string with a
	 * fixed set of values, pattern or maxLength for free text. A tool that clamps
	 * or validates an argument should state it here, in terms of the same
	 * constants its execute() uses, so the client is told the truth the tool
	 * enforces instead of learning it from a rejected call.
	 *
	 * Read once per call, by the schema and by the check alike: a bound that is
	 * advertised without being enforced, or enforced without being advertised, is
	 * the mistake this hook exists to make impossible.
	 *
	 * @access public
	 * @return array<string, array<string, mixed>> $constraints Keywords per parameter name
	 */
	public function get_input_constraints(): array {
		return [];
	}

	/**
	 * Get the JSON schema the structuredContent of this tool conforms to
	 *
	 * The specification says a server which declares an outputSchema MUST conform
	 * to it, and a client is allowed to reject anything else.
	 *
	 * Nothing to write here for the usual case: when the @return docblock of
	 * execute() names a class that declares its shape (a Definition, see
	 * `Skeleton\Application\Mcp\Definition`), that schema is advertised. A tool that
	 * returns a plain array it does not model can still override this method, and a
	 * tool with no structured output gets null and no outputSchema at all.
	 *
	 * @access public
	 * @return ?array<string, mixed> $output_schema The schema to advertise, null for none
	 */
	public function get_output_schema(): ?array {
		$docblock = Docblock::read((new \ReflectionMethod($this, 'execute'))->getDocComment());
		$return_type = $docblock?->get_return_type();

		if ($return_type === null) {
			return null;
		}

		$classname = ltrim($return_type, '\\');

		$names_a_definition = $classname !== ''
			&& is_a($classname, \Skeleton\Application\Mcp\DefinitionInterface::class, true) === true;

		if ($names_a_definition === false) {
			return null;
		}

		$definition = new $classname();

		return $definition->get_mcp_definition_schema();
	}

	/**
	 * Combined tool definition
	 *
	 * What `tools/list` hands out for this tool: only the keys the tool actually
	 * says something about are included, so a client never reads an empty
	 * `annotations` block as a statement.
	 *
	 * @access public
	 * @return array<string, mixed> $definition The definition to advertise
	 */
	public function get_definition(): array {
		$definition = [
			'name' => $this->get_name(),
			'description' => $this->get_description(),
			'inputSchema' => $this->get_input_schema(),
		];

		$output_schema = $this->get_output_schema();

		if ($output_schema !== null) {
			$definition['outputSchema'] = $output_schema;
		}

		$annotations = $this->get_annotations();

		if ($annotations !== null) {
			$definition['annotations'] = $annotations;
		}

		return $definition;
	}

	/**
	 * Accept the request
	 *
	 * The module contract this package does not use: a tool is not routed to, it is
	 * called by name out of a JSON-RPC body, which is why the request never passes
	 * through here. `Skeleton\Core\Application\Module` declares it abstract, so it is
	 * answered with nothing.
	 *
	 * @access public
	 */
	public function accept_request(): void {
	}

	/**
	 * Call security and make the tool handle the request
	 *
	 * The order is the policy: bootstrap, the credential (at least one of the
	 * authenticating handlers must accept the caller), the authorization handlers
	 * (every one of them must agree), the arguments, only then `execute()`, and last of
	 * all the reduction of the answer against its own declaration. The teardown event is
	 * fired on **every** path out of here, refused calls and failures included, because
	 * the calls worth auditing are precisely the ones that did not produce an answer — an
	 * event that only runs on success measures the traffic and not the truth.
	 *
	 * The reduction happens here, once, and is kept: the handler answers from it rather
	 * than asking the object again. A payload that computes (rows off a pager, labels off
	 * a table) would otherwise be built twice per call — the second time by a log line
	 * that only counts it.
	 *
	 * @access public
	 * @param array<string, mixed> $arguments The arguments as the client sent them
	 * @return mixed $result What execute() answered
	 * @throws \Skeleton\Application\Mcp\Jsonrpc\Exception When the caller is refused
	 * @throws \Skeleton\Application\Mcp\Exception When an argument does not fit or the answer breaks its map
	 */
	public function call(array $arguments): mixed {
		$application = \Skeleton\Core\Application::get();
		$application->call_event_if_exists('tool', 'bootstrap', [$this]);

		$started = microtime(true);

		try {
			$this->check_security();
			$ordered = $this->order_arguments($arguments);
			$result = $this->run_execute($ordered);

			$this->reduced = $result instanceof \Skeleton\Application\Mcp\DefinitionInterface
				? \Skeleton\Application\Mcp\Reducer::reduce($result, $this->get_name())
				: null;
		} catch (\Throwable $e) {
			// A code is what an MCP exception carries; anything else — a database
			// failure whose code is a SQLSTATE, say — has no number to log, and the
			// message beside it says what happened.
			$code = $e->getCode();

			$this->conclude($started, is_int($code) === true ? $code : null, $e->getMessage(), null, $arguments);

			throw $e;
		}

		$this->conclude($started, null, null, $this->reduced, $arguments);

		return $result;
	}

	/**
	 * Get the answer already reduced against its declaration, if this call had one
	 *
	 * `Handler` takes the structuredContent from here. It is null before `call()` has
	 * run, and for a tool that returns a plain array or a string, which has no
	 * declaration to reduce against.
	 *
	 * @access public
	 * @return ?array<string, mixed> $info The reduced answer, null when there is none
	 */
	public function get_reduced_info(): ?array {
		return $this->reduced;
	}

	/**
	 * Get the security classes this tool declares, without instantiating them
	 *
	 * The application builds the request's one instance per handler class, and it
	 * needs to know which classes to build before any handler runs: reading the tags
	 * is that question, and instantiating them is another. An unknown class is fatal
	 * here, where the author put it, rather than a silent skip.
	 *
	 * @access public
	 * @return array<int, string> $classnames The handlers, in the order they are declared
	 */
	public function get_declared_security_classes(): array {
		$docblock = Docblock::read((new \ReflectionMethod($this, 'execute'))->getDocComment());
		$classnames = [];

		foreach ($docblock?->get_tag_lines('security') ?? [] as $classname) {
			if (class_exists($classname) === false) {
				throw new \Exception('Unknown security class ' . $classname . ' for tool ' . $this->get_name());
			}

			$classnames[] = $classname;
		}

		return $classnames;
	}

	/**
	 * Get the security handlers of this tool, from its @security tags
	 *
	 * The tags are read from the raw text rather than through a parser, and an unknown
	 * class is a fatal error rather than a skipped line: a tool the author meant to
	 * guard must never end up open because of a typo in its own docblock. The
	 * instances come from the application, which holds one per class per request, so
	 * a handler that resolves the caller once answers every tool of that request
	 * without reading the same credential twice.
	 *
	 * @access public
	 * @return array<int, \Skeleton\Application\Mcp\Security> $securities The handlers this tool declared
	 */
	public function get_security(): array {
		if ($this->security !== null) {
			return $this->security;
		}

		$application = \Skeleton\Core\Application::get();
		$securities = [];

		foreach ($this->get_declared_security_classes() as $classname) {
			$securities[] = $application->get_security_handler($classname);
		}

		return $this->security = $securities;
	}

	/**
	 * Run the handlers this tool declares
	 *
	 * Two groups, two rules. Authenticating handlers are an **OR**: a server that
	 * accepts a static key *and* a bearer token is not asking a caller to hold both,
	 * and the first handler that accepts the caller has answered the only question the
	 * group asks. Authorizing handlers are an **AND**: each one states something the
	 * caller must also satisfy, and one that refuses is a refusal whatever the others
	 * said.
	 *
	 * A tool that declares no authenticating handler is not asked one: leaving
	 * `@security` off is how a tool says it may be used by anybody (see the
	 * application's listing), and an empty group cannot fail an OR.
	 *
	 * @access private
	 * @throws \Skeleton\Application\Mcp\Jsonrpc\Exception When no credential was accepted
	 */
	private function check_security(): void {
		$refusal = null;
		$credentials = 0;
		$authenticated = false;

		foreach ($this->get_security() as $security) {
			if ($security->is_authenticating() === false) {
				continue;
			}

			$credentials++;

			try {
				$security->handle();
				$authenticated = true;

				break;
			} catch (\Skeleton\Application\Mcp\Jsonrpc\Exception $e) {
				$refusal ??= $e;
			}
		}

		// $refusal is set whenever the loop above refused a credential and admitted
		// none: a handler either throws or lets the caller through, so an unaccepted
		// caller was refused by somebody who has words for it.
		if ($credentials > 0 && $authenticated === false) {
			throw $refusal;
		}

		foreach ($this->get_security() as $security) {
			if ($security->is_authenticating() === true) {
				continue;
			}

			$security->handle();
		}
	}

	/**
	 * Bring the client's arguments into the order execute() wants them in
	 *
	 * Every parameter is checked against its own declaration and against the bounds
	 * the tool stated for it, an argument that is not a parameter is refused (answering
	 * as if it had been left out is how a wrong result looks like a right one), and a
	 * required one that never arrived is a refusal rather than a null.
	 *
	 * @access private
	 * @param array $arguments The arguments as the client sent them
	 * @return array<int, mixed> $ordered The values, in parameter order
	 * @throws \Skeleton\Application\Mcp\Jsonrpc\Exception When an argument is missing or unknown
	 */
	private function order_arguments(array $arguments): array {
		$reflection = new \ReflectionMethod($this, 'execute');
		$constraints = $this->get_input_constraints();
		$ordered = [];
		$accepted = [];

		foreach ($reflection->getParameters() as $parameter) {
			$name = $parameter->getName();
			$accepted[] = $name;

			if (array_key_exists($name, $arguments)) {
				$this->check_argument($parameter, $arguments[$name], $constraints[$name] ?? []);
				$ordered[] = $arguments[$name];
			} elseif ($parameter->isOptional() === true) {
				$ordered[] = $parameter->getDefaultValue();
			} else {
				throw new \Skeleton\Application\Mcp\Jsonrpc\Exception('Missing required argument ' . $name, -32602);
			}
		}

		// Anything else in arguments was meant as a filter but never reached
		// execute(): answering as if it had been left out is how a wrong result
		// looks like a right one
		$unknown = array_diff(array_map('strval', array_keys($arguments)), $accepted);

		if (count($unknown) > 0) {
			throw new \Skeleton\Application\Mcp\Jsonrpc\Exception(
				'Unknown argument ' . implode(', ', $unknown) . ' for tool ' . $this->get_name()
					. ', accepted arguments: ' . implode(', ', $accepted),
				-32602
			);
		}

		return $ordered;
	}

	/**
	 * Call execute() with the ordered arguments
	 *
	 * @access private
	 * @param array<int, mixed> $ordered The values, in parameter order
	 * @return mixed $result What the tool answered
	 * @throws \Skeleton\Application\Mcp\Exception When PHP itself refuses the arguments
	 */
	private function run_execute(array $ordered): mixed {
		try {
			return (new \ReflectionMethod($this, 'execute'))->invokeArgs($this, $ordered);
		} catch (\TypeError $e) {
			// Whatever PHP would have said names this class, its method and the
			// position of every parameter: a client mistake must never become a
			// tour of the implementation. The sentence the client gets says which tool
			// refused, and the reason goes to the log, where the author of that tool
			// is the one who can do something with it.
			error_log('MCP: tool ' . $this->get_name() . ' refused the arguments it was given: ' . $e->getMessage());

			throw new \Skeleton\Application\Mcp\Exception('Invalid arguments for tool ' . $this->get_name());
		}
	}

	/**
	 * Check one argument against the parameter it is bound to
	 *
	 * Two questions, in this order: does the value fit the declared PHP type at all,
	 * and does it obey the bounds the tool stated for it. No coercion either way: a
	 * client that sends "5" for an integer, or 2.5, or null for a parameter that is not
	 * nullable, gets the same readable refusal as a word the tool refuses to spell —
	 * the same shape as a whitelist rejection, so a model learns one behaviour instead
	 * of two.
	 *
	 * @access private
	 * @param \ReflectionParameter $parameter The parameter the argument is bound to
	 * @param mixed $value The argument the client sent
	 * @param array $constraints The keywords this tool stated for that parameter
	 * @throws \Skeleton\Application\Mcp\Exception When the argument does not fit
	 */
	private function check_argument(\ReflectionParameter $parameter, $value, array $constraints = []): void {
		$type = $parameter->getType();

		if ($type instanceof \ReflectionNamedType === false) {
			// a union or an untyped parameter: nothing reliable to check against
			return;
		}

		$name = $parameter->getName();

		if ($value === null) {
			if ($type->allowsNull() === false) {
				throw new \Skeleton\Application\Mcp\Exception($name . ' can not be null');
			}

			return;
		}

		switch ($type->getName()) {
			case 'int':
				$fits = is_int($value);
				$says = 'an integer';
				break;
			case 'float':
				// an int is a number too, and PHP accepts it here anyway
				$fits = is_float($value) || is_int($value);
				$says = 'a number';
				break;
			case 'string':
				$fits = is_string($value);
				$says = 'text';
				break;
			case 'bool':
				$fits = is_bool($value);
				$says = 'true or false';
				break;
			case 'array':
				$fits = is_array($value);
				$says = 'a list or an object';
				break;
			default:
				// A parameter of a class type: nothing a JSON body can carry is one, and
				// the refusal says what the client can act on rather than naming the
				// class it would have needed.
				$classname = $type->getName();
				$fits = class_exists($classname) === true && $value instanceof $classname;
				$says = 'a value this tool can not read';
		}

		if ($fits === false) {
			throw new \Skeleton\Application\Mcp\Exception($name . ' must be ' . $says);
		}

		// The schema and this check read the same get_input_constraints() map, so a
		// bound cannot be advertised without being enforced — which is the whole point
		// of publishing it.
		foreach ($constraints as $keyword => $bound) {
			if ($keyword === 'minimum' && is_int($value) === true && $value < $bound) {
				throw new \Skeleton\Application\Mcp\Exception($name . ' must be at least ' . $bound);
			}

			if ($keyword === 'maximum' && is_int($value) === true && $value > $bound) {
				throw new \Skeleton\Application\Mcp\Exception($name . ' can not be more than ' . $bound);
			}

			if ($keyword === 'minLength' && is_string($value) === true && mb_strlen($value) < $bound) {
				throw new \Skeleton\Application\Mcp\Exception($name . ' must be at least ' . $bound . ' characters');
			}

			if ($keyword === 'maxLength' && is_string($value) === true && mb_strlen($value) > $bound) {
				throw new \Skeleton\Application\Mcp\Exception(
					$name . ' can not be more than ' . $bound . ' characters'
				);
			}

			if ($keyword === 'enum' && is_array($bound) === true) {
				// case does not matter for a value a client copies out of prose
				$allowed = array_map('strtolower', array_map('strval', $bound));

				if (in_array(strtolower((string)$value), $allowed, true) === false) {
					throw new \Skeleton\Application\Mcp\Exception($name . ' must be one of: ' . implode(', ', $bound));
				}
			}

			if ($keyword === 'pattern' && is_string($value) === true) {
				// the bound is the ECMA-262 source the client is also shown, so the
				// delimiters are added here and nowhere else
				$form = '/' . str_replace('/', '\/', $bound) . '/i';

				if (preg_match($form, $value) !== 1) {
					throw new \Skeleton\Application\Mcp\Exception($name . ' does not have the expected form');
				}
			}
		}
	}

	/**
	 * Tell the application this call is over, however it ended
	 *
	 * @access private
	 * @param float $started When call() began, as a monotonic clock reading
	 * @param ?int $error_code The code of the refusal, null when the call answered
	 * @param ?string $error_message What was refused, null when the call answered
	 * @param ?array<string, mixed> $info The reduced answer, null when there is none
	 * @param array<string, mixed> $arguments What the client sent, so a refused call can be read back
	 */
	private function conclude(
		float $started,
		?int $error_code,
		?string $error_message,
		?array $info = null,
		array $arguments = []
	): void {
		$application = \Skeleton\Core\Application::get();

		if ($application->event_exists('tool', 'teardown') === false) {
			return;
		}

		$application->call_event_if_exists('tool', 'teardown', [
			$this,
			[
				'ok' => $error_code === null,
				'error_code' => $error_code,
				'error_message' => $error_message,
				'duration_ms' => (int)round((microtime(true) - $started) * 1000),
				'row_count' => self::count_rows($info),
				'arguments' => $arguments,
			],
		]);
	}

	/**
	 * Count the rows an answer hands out, for the log
	 *
	 * The size of the first list a reduced answer carries, which is what "how many rows
	 * did this call return" means for a search and answers null for a detail. It is read
	 * from the array `call()` already reduced, so counting rows never costs a second
	 * reduction of a payload that had to compute them.
	 *
	 * @access private
	 * @param ?array<string, mixed> $info The reduced answer, null when there is none
	 * @return ?int $count The number of rows, null when the answer holds no list
	 */
	private static function count_rows(?array $info): ?int {
		if ($info === null) {
			return null;
		}

		foreach ($info as $value) {
			if (is_array($value) === true && array_is_list($value) === true) {
				return count($value);
			}
		}

		return null;
	}
}
