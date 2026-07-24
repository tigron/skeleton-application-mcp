<?php
/**
 * Tool base class
 */

namespace Skeleton\Application\Mcp;

abstract class Tool extends \Skeleton\Core\Application\Module {

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
	 * Fetched from the docblock above the class Foo {} definition
	 *
	 * @access public
	 * @return string $description
	 */
	public function get_description(): string {
		$reflection = new \ReflectionClass($this);
		$docblock = $reflection->getDocComment();

		if ($docblock === false) {
			return '';
		}

		try {
			$factory = \phpDocumentor\Reflection\DocBlockFactory::createInstance();
			$parsed = $factory->create($docblock);
			$summary = $parsed->getSummary();
			$description = $parsed->getDescription()->render();
			return trim($summary . "\n\n" . $description);
		} catch (\Exception $e) {
			return '';
		}
	}

	/**
	 * Build the JSON inputSchema for the current tool (tells the MCP client how
	 * and what it should send to the tool)
	 *
	 * Reads the docblock for execute()
	 *
	 * @access public
	 * @return array $schema
	 */
	public function get_input_schema(): array {
		$reflection = new \ReflectionMethod($this, 'execute');

		$docblock_params = [];
		$docblock = $reflection->getDocComment();
		if ($docblock !== false) {
			try {
				$factory = \phpDocumentor\Reflection\DocBlockFactory::createInstance();
				$parsed = $factory->create($docblock);

				foreach ($parsed->getTagsByName('param') as $tag) {
					$type = null;
					$description = null;

					if ($tag->getType() !== null) {
						$type = (string)$tag->getType();
					}

					if ($tag->getDescription() !== null) {
						$description = $tag->getDescription()->render();
					}

					$docblock_params[$tag->getVariableName()] = [
						'type' => $type,
						'description' => $description,
					];
				}
			} catch (\Exception $e) {
				// just skip if we can't read the dockblock
			}
		}

		$properties = [];
		$required = [];

		foreach ($reflection->getParameters() as $parameter) {
			$name = $parameter->getName();
			$meta = $docblock_params[$name] ?? ['type' => null, 'description' => ''];

			$property = [
				'type' => self::to_json_type($parameter, $meta['type']),
			];

			if (!empty($meta['description'])) {
				$property['description'] = trim($meta['description']);
			}

			$properties[$name] = $property;

			if (!$parameter->isOptional()) {
				$required[] = $name;
			}
		}

		$schema = [
			'type' => 'object',
			'properties' => (object)$properties,
		];

		if (count($required) > 0) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Combined tool definition
	 *
	 * @access public
	 * @return array $definition
	 */
	public function get_definition(): array {

		return [
			'name' => $this->get_name(),
			'description' => $this->get_description(),
			'inputSchema' => $this->get_input_schema(),
		];
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
		$ordered = [];

		foreach ($reflection->getParameters() as $parameter) {
			$name = $parameter->getName();

			if (array_key_exists($name, $arguments)) {
				$ordered[] = $arguments[$name];
			} elseif ($parameter->isOptional()) {
				$ordered[] = $parameter->getDefaultValue();
			} else {
				throw new \Skeleton\Application\Mcp\Jsonrpc\Exception('Missing required argument ' . $name, -32602);
			}
		}

		$result = $reflection->invokeArgs($this, $ordered);

		$application->call_event_if_exists('tool', 'teardown', [$this]);

		return $result;
	}

	/**
	 * Get the security class for this tool (from docblock)
	 *
	 * @access public
	 * @return \Skeleton\Application\Mcp\Security[] $securities
	 */
	public function get_security(): array {
		$reflection = new \ReflectionMethod($this, 'execute');
		$docblock = $reflection->getDocComment();

		if ($docblock === false) {
			return [];
		}

		$securities = [];

		$factory = \phpDocumentor\Reflection\DocBlockFactory::createInstance();
		$parsed = $factory->create($docblock);

		foreach ($parsed->getTagsByName('security') as $tag) {
			$description = (string)$tag->getDescription();
			$classname = trim($description);

			if (empty($classname)) {
				continue;
			}

			if (!class_exists($classname)) {
				throw new \Exception('Unknown security class ' . $classname . ' for tool ' . $this->get_name());
			}

			$securities[] = new $classname();
		}

		return $securities;
	}

	/**
	 * helper: try to convert PHP type definition to JSON schema type, fall back
	 * to string if we can't figure it out (should mostly work anyway)
	 *
	 * @access private
	 * @param \ReflectionParameter $parameter
	 * @param ?string $docblock_type
	 * @return string
	 */
	private static function to_json_type(\ReflectionParameter $parameter, ?string $docblock_type): string {
		$type = null;

		if ($parameter->hasType()) {
			$reflection_type = $parameter->getType();
			if ($reflection_type instanceof \ReflectionNamedType) {
				$type = $reflection_type->getName();
			}
		}

		if ($type === null && $docblock_type !== null) {
			$type = ltrim($docblock_type, '\\');
		}

		switch (strtolower((string)$type)) {
			case 'int':
			case 'integer':
				return 'integer';
			case 'float':
			case 'double':
				return 'number';
			case 'bool':
			case 'boolean':
				return 'boolean';
			case 'array':
				return 'array';
			case 'object':
				return 'object';
			case 'string':
			default:
				return 'string';
		}
	}
}
