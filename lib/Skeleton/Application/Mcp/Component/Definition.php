<?php

declare(strict_types=1);

/**
 * Component Definition
 *
 * Base class for the shape a tool returns.
 *
 * A tool answers with structuredContent, the protocol says a server that
 * advertises an outputSchema MUST conform to it, and the usual way for an
 * application to get that wrong is to write the shape twice: once while building
 * the answer and once in a schema array. This class makes the properties of a
 * small object the single declaration — their PHP types give the JSON types, a
 * ? gives the null union, their docblock summary gives the description the client
 * reads, and both the schema and the payload are drawn from that. What reflection
 * cannot know (an enum, a format, a field that is genuinely optional) goes in
 * get_definition_overrides().
 *
 * It follows the same route as get_input_schema(), which reads the parameters of
 * execute() rather than asking the author to describe them again, and it reads its
 * docblocks through the same Docblock class, so a property and a parameter are
 * described to the client in exactly one way.
 *
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp\Component;

use Skeleton\Application\Mcp\Docblock;
use Skeleton\Application\Mcp\Util;

abstract class Definition {
	/**
	 * The described properties of this result, once
	 *
	 * The schema and the payload are both drawn from this map, and reflecting and
	 * parsing every property twice per request for two reads of the same object is
	 * work no author asked for.
	 *
	 * @access private
	 * @var ?array<string, array> $properties
	 */
	private ?array $properties = null;

	/**
	 * Get the JSON schema this result's structuredContent conforms to
	 *
	 * @access public
	 * @return array $schema
	 */
	public function get_definition_schema(): array {
		$overrides = $this->get_definition_overrides();
		$properties = [];
		$required = [];

		foreach ($this->get_definition_properties() as $name => $definition) {
			$properties[$name] = $this->schema_for_property($definition, $overrides[$name] ?? []);

			if (($overrides[$name]['required'] ?? true) === true) {
				$required[] = $name;
			}
		}

		$schema = [
			'type' => 'object',

			// an empty property map still has to reach the client as {} and not as
			// [], which is the only reason json_encode needs an object here
			'properties' => count($properties) === 0 ? (object)$properties : $properties,
		];

		if (count($required) > 0) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Get the structuredContent of this result
	 *
	 * Every declared property is answered, null when the code that built the
	 * result never set one: a missing key would contradict the required list of
	 * the schema, while a null says what the schema promises anyway.
	 *
	 * @access public
	 * @return array $info
	 */
	public function get_definition_info(): array {
		$info = [];

		foreach ($this->get_definition_properties() as $name => $definition) {
			$info[$name] = $this->value_for_property($definition, $this->$name ?? null);
		}

		return $info;
	}

	/**
	 * Get what reflection can not tell about this result
	 *
	 * JSON Schema keywords per property name, merged into that property's entry of
	 * the schema: format, enum, minimum and maximum, and required (false makes a
	 * property optional). Keep the description in the property docblock: that is
	 * the one place a client reads it and the one place a colleague sees it.
	 *
	 * @access public
	 * @return array $overrides Keywords per property name
	 */
	public function get_definition_overrides(): array {
		return [];
	}

	/**
	 * Describe every public property of this result
	 *
	 * @access protected
	 * @return array $properties name => type, nullable, items, definition, description
	 */
	protected function get_definition_properties(): array {
		if ($this->properties !== null) {
			return $this->properties;
		}

		$properties = [];

		foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic() === true || str_starts_with($property->getName(), '_') === true) {
				continue;
			}

			$properties[$property->getName()] = $this->describe_property($property);
		}

		return $this->properties = $properties;
	}

	/**
	 * Read a property: its PHP type, its @var and its docblock summary
	 *
	 * The docblock is parsed once here and handed to read_var() as text: a property
	 * asked for its summary and then again for its @var, which meant reflecting and
	 * parsing the same block twice for every field of every result the client is
	 * shown.
	 *
	 * @access private
	 * @param \ReflectionProperty $property The property to describe
	 * @return array $definition What that property is
	 */
	private function describe_property(\ReflectionProperty $property): array {
		$docblock = Docblock::read($property->getDocComment());

		$definition = [
			'type' => 'string',
			'nullable' => false,
			'items' => null,
			'definition' => null,
			'description' => $docblock?->get_first_line(),
		];

		$type = $property->getType();

		if ($type instanceof \ReflectionNamedType) {
			$definition['type'] = Util::to_json_type($type->getName());
			$definition['nullable'] = $type->allowsNull();

			if ($type->getName() === 'array') {
				$definition = $this->read_var($property, $definition, $docblock?->get_tag('var') ?? '');
			} elseif ($type->isBuiltin() === false) {
				$definition['type'] = 'object';
				$definition['definition'] = $type->getName();
			}

			return $definition;
		}

		// a union type, or none at all: the @var carries what we could not read
		return $this->read_var($property, $definition, $docblock?->get_tag('var') ?? '');
	}

	/**
	 * Get what the @var of a property says about its elements
	 *
	 * reflection gives "array" and stops there, while the docblock already tells
	 * us what is in the array — either a scalar, which needs an items schema, or
	 * another Definition class, which nests its schema.
	 *
	 * @access private
	 * @param \ReflectionProperty $property The property to read
	 * @param array $definition What we know so far
	 * @param string $var What the @var says, empty when there is none
	 * @return array $definition The same definition, filled in
	 */
	private function read_var(\ReflectionProperty $property, array $definition, string $var): array {
		if ($var === '') {
			return $definition;
		}

		// array<int, string> / array<string, Foo> / Foo[]
		if (preg_match('/array\s*<\s*[^,>]+\s*,\s*([^\s>]+)\s*>/i', $var, $matches) === 1 || preg_match('/^([^\s\[\]]+)\[\]$/', $var, $matches) === 1) {
			$element = ltrim($matches[1], '\\');

			if (in_array(strtolower($element), [ 'string', 'int', 'integer', 'float', 'double', 'bool', 'boolean' ], true) === true) {
				$definition['items'] = [ 'type' => Util::to_json_type($element) ];
			} else {
				$definition['definition'] = $this->resolve_class($element, $property);
			}
		}

		// the declared type was no help at all: take the @var as the type
		if ($definition['type'] === 'string' && $definition['items'] === null && $definition['definition'] === null) {
			$scalar = strtolower(preg_replace('/array.*/i', '', $var));

			if (in_array($scalar, [ 'string', 'int', 'integer', 'float', 'double', 'bool', 'boolean' ], true) === true) {
				$definition['type'] = Util::to_json_type($scalar);
			}
		}

		return $definition;
	}

	/**
	 * Build the schema of one property
	 *
	 * What the author stated in get_definition_overrides() wins over what reflection
	 * read: the override is the deliberate word about a field, reflection is the
	 * inference. The elements of an array are settled here too, in the order that
	 * says so — an override, then a nested Definition, then what the @var named,
	 * then nothing at all, which is honest where a guessed `string` would not be.
	 *
	 * @access private
	 * @param array $definition What the property is
	 * @param array $override What the author added for it
	 * @return array $schema What the property must satisfy
	 */
	private function schema_for_property(array $definition, array $override): array {
		$type = $override['type'] ?? $definition['type'];

		$schema = [
			'type' => ($override['nullable'] ?? $definition['nullable']) === true ? [ $type, 'null' ] : $type,
		];

		$description = $override['description'] ?? $definition['description'];

		if ($description !== null && $description !== '') {
			$schema['description'] = $description;
		}

		foreach ([ 'format', 'enum', 'minimum', 'maximum', 'pattern' ] as $keyword) {
			if (isset($override[$keyword]) === true) {
				$schema[$keyword] = $override[$keyword];
			}
		}

		if ($type === 'array') {
			// the order is the one the author of an override meant to decide: what they
			// stated, then a nested Definition, then what the @var named
			if (isset($override['items']) === true) {
				$items = $override['items'];
			} elseif ($definition['definition'] !== null) {
				$items = (new ($definition['definition'])())->get_definition_schema();
			} else {
				$items = $definition['items'];
			}

			// no items at all says "an array of anything", which is honest; saying
			// string when the elements are objects would fail a validating client
			if ($items !== null) {
				$schema['items'] = $items;
			}
		}

		if ($type === 'object' && $definition['definition'] !== null) {
			$nested = (new ($definition['definition'])())->get_definition_schema();
			unset($nested['type'], $nested['description']);

			return array_merge($schema, $nested);
		}

		return $schema;
	}

	/**
	 * Find the class a @var names
	 *
	 * A docblock writes Row where PHP would need the whole namespace, so a name
	 * without one is looked for in the namespace of the class that carries the
	 * property. null when no class of that name declares a shape: the array is then
	 * simply an array of anything.
	 *
	 * @access private
	 * @param string $name The class name as the @var spells it
	 * @param \ReflectionProperty $property The property that carries the @var
	 * @return ?string $classname The class, null when it declares no shape
	 */
	private function resolve_class(string $name, \ReflectionProperty $property): ?string {
		$candidates = [ $name ];

		if (str_contains($name, '\\') === false) {
			$namespace = $property->getDeclaringClass()->getNamespaceName();

			if ($namespace !== '') {
				$candidates[] = $namespace . '\\' . $name;
			}
		}

		foreach ($candidates as $candidate) {
			if (class_exists($candidate) === true && method_exists($candidate, 'get_definition_schema') === true) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Turn one property value into what the client receives
	 *
	 * @access private
	 * @param array $definition What the property is
	 * @param mixed $value The value the result carries
	 * @return mixed $value The same value, with nested results reduced to arrays
	 */
	private function value_for_property(array $definition, $value) {
		if ($value === null) {
			return null;
		}

		if (is_array($value) === true) {
			$info = [];

			foreach ($value as $item) {
				$info[] = is_object($item) === true && method_exists($item, 'get_definition_info')
					? $item->get_definition_info()
					: $item;
			}

			return $info;
		}

		if (is_object($value) === true && method_exists($value, 'get_definition_info')) {
			return $value->get_definition_info();
		}

		return $value;
	}
}
