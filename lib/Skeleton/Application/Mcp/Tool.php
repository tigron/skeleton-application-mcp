<?php

declare(strict_types=1);

/**
 * Tool base class
 *
 * What a tool is made of: an execute() whose signature is its inputSchema, a class
 * docblock that is the description a model reads before deciding to call it, an optional
 * @security that says who may call it, and an optional @return that names the class
 * declaring the shape of the answer.
 *
 * Everything the client is told is *derived* from that, never written twice: see
 * Docblock for how the docblocks are read, Util::to_json_type() for how a PHP type
 * becomes a JSON Schema type, and Component\Definition for the result side of the same
 * idea. A tool that states a bound it does not enforce, or enforces one it does not
 * state, is the failure this design exists to prevent — get_input_constraints() is read
 * by both the schema and the check.
 *
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
	 * Constructor
	 *
	 * @access public
	 */
	public function __construct() {
		// execute() can not be an abstract function as it has a varying
		// sigature; PHP doesn't support that
		if (!method_exists($this, 'execute')) {
			throw new \Exception(get_class($this) . ' must declare a public execute() method');
		}
	}

	/**
	 * Get the tool name (advertised to the MCP client)
	 *
	 * @access public
	 * @return string $name
	 */
	public function get_name(): string {
		$application = \Skeleton\Core\Application::get();
		$class = new \ReflectionClass($this);
		$filename = $class->getFileName();
		$filename = str_replace($application->tool_path, '', $filename);
		$filename = pathinfo($filename);

		if ($filename['dirname'] !== '.') {
			$name = $filename['dirname'] . '/' . $filename['filename'];
		} else {
			$name = $filename['filename'];
		}

		// flatten whatever result with undersores
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
	 * @return string $description
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
	 * @return array $schema
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
			if ($reflection_type instanceof \ReflectionNamedType && $reflection_type->allowsNull() && $reflection_type->getName() !== 'null') {
				$property['type'] = [ $property['type'], 'null' ];
			}

			if (!empty($meta['description'])) {
				$property['description'] = trim($meta['description']);
			}

			// what the parameter falls back to when it is left out
			if ($parameter->isOptional()) {
				$property['default'] = $parameter->getDefaultValue();
			}

			// what the tool enforces beyond the type: minimum, maximum, enum, …
			foreach ($constraints[$name] ?? [] as $keyword => $value) {
				$property[$keyword] = $value;
			}

			$properties[$name] = $property;

			if (!$parameter->isOptional()) {
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
	 * @return array|null $annotations
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
	 * @return array $constraints Keywords per parameter name
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
	 * execute() names a class that declares its shape (a get_definition_schema()
	 * method, see Component\Definition), that schema is advertised. A tool that
	 * returns a plain array it does not model can still override this method, and a
	 * tool with no structured output gets null and no outputSchema at all.
	 *
	 * @access public
	 * @return array|null $output_schema
	 */
	public function get_output_schema(): ?array {
		$docblock = Docblock::read((new \ReflectionMethod($this, 'execute'))->getDocComment());
		$return_type = $docblock?->get_return_type();

		if ($return_type === null) {
			return null;
		}

		$classname = ltrim($return_type, '\\');

		if ($classname === '' || class_exists($classname) === false || method_exists($classname, 'get_definition_schema') === false) {
			return null;
		}

		$definition = new $classname();

		return $definition->get_definition_schema();
	}

	/**
	 * Combined tool definition
	 *
	 * @access public
	 * @return array $definition
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
	 * accept_request (Core\Application\Module needs this, but we need something
	 * more complicated); just ignore this
	 *
	 * @access public
	 */
	public function accept_request(): void {
	}

	/**
	 * Call security and make the tool handle the request
	 *
	 * @access public
	 * @param array $arguments
	 * @return mixed
	 */
	public function call(array $arguments) {
		$application = \Skeleton\Core\Application::get();
		$application->call_event_if_exists('tool', 'bootstrap', [$this]);

		// Call security defined in the docblock
		foreach ($this->get_security() as $security) {
			$security->handle();
		}

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
			} elseif ($parameter->isOptional()) {
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

		try {
			$result = $reflection->invokeArgs($this, $ordered);
		} catch (\TypeError $e) {
			// Whatever PHP would have said names this class, its method and the
			// position of every parameter: a client mistake must never become a
			// tour of the implementation
			throw new \Skeleton\Application\Mcp\Exception('Invalid arguments for tool ' . $this->get_name());
		}

		$application->call_event_if_exists('tool', 'teardown', [$this]);

		return $result;
	}

	/**
	 * Get the security handlers of this tool, from its @security tags
	 *
	 * The tags are read from the raw text rather than through a parser, and an unknown
	 * class is a fatal error rather than a skipped line: a tool the author meant to
	 * guard must never end up open because of a typo in its own docblock. Resolved
	 * once, because the application asks every tool what it declares on each request.
	 *
	 * @access public
	 * @return \Skeleton\Application\Mcp\Security[] $securities
	 */
	public function get_security(): array {
		if ($this->security !== null) {
			return $this->security;
		}

		$docblock = Docblock::read((new \ReflectionMethod($this, 'execute'))->getDocComment());
		$securities = [];

		foreach ($docblock?->get_tag_lines('security') ?? [] as $classname) {
			if (class_exists($classname) === false) {
				throw new \Exception('Unknown security class ' . $classname . ' for tool ' . $this->get_name());
			}

			$securities[] = new $classname();
		}

		return $this->security = $securities;
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

		if (!($type instanceof \ReflectionNamedType)) {
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
				$classname = $type->getName();
				$fits = class_exists($classname) === true && $value instanceof $classname;
				$says = 'something else this tool does not accept';
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
				throw new \Skeleton\Application\Mcp\Exception($name . ' can not be more than ' . $bound . ' characters');
			}

			if ($keyword === 'enum' && is_array($bound) === true) {
				// case does not matter for a value a client copies out of prose
				$allowed = array_map('strtolower', array_map('strval', $bound));

				if (in_array(strtolower((string)$value), $allowed, true) === false) {
					throw new \Skeleton\Application\Mcp\Exception($name . ' must be one of: ' . implode(', ', $bound));
				}
			}

			if ($keyword === 'pattern' && is_string($value) === true && preg_match('/' . str_replace('/', '\/', $bound) . '/i', $value) !== 1) {
				throw new \Skeleton\Application\Mcp\Exception($name . ' does not have the expected form');
			}
		}
	}
}
