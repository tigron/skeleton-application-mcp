<?php

declare(strict_types=1);

/**
 * Definition trait
 *
 * The shape a tool returns, declared once as a map of fields.
 *
 * A tool answers with structuredContent, the protocol says a server that advertises an
 * outputSchema MUST conform to it, and the usual way for an application to get that
 * wrong is to write the shape twice: once while building the answer and once in a
 * schema array. Here one method, `get_mcp_definition_properties()`, is the single
 * declaration — the type it states gives the JSON type, `nullable` gives the null
 * union, `description` gives the text the client reads, and `format`, `enum`,
 * `minimum`, `maximum`, `pattern` and `required` say what a PHP type cannot.
 *
 * That map is the schema, and it is also the law. The answer itself is produced one of
 * two ways, and the class chooses:
 *
 * * **authored** — the class writes `get_mcp_definition_info()` and returns one array,
 *   fields in the order a human reads them, values raw. This is the recommended way: a
 *   payload that computes is easier to follow in one array than in a `case` per field.
 * * **property-driven** — the class declares public properties and lets the trait build
 *   the answer from them, field by field, through `get_mcp_definition_value()`. Fine for
 *   a row that is nothing but assigned columns.
 *
 * Both go through `Reducer::reduce()` exactly once per call, and an authored answer is
 * held to its own map there: a declared field missing from it, a field it invented, a
 * null the map forbids, a value of the wrong type, or a model where a scalar was
 * promised is a refused call and a line in the log naming the class, the field and what
 * arrived. The schema and the payload cannot drift, whichever way the payload was built
 * — and a client that validates is not the party that finds out.
 *
 * The input side works the same way: `Tool::get_input_schema()` reads the parameters of
 * execute() rather than asking the author to describe them again. One declaration per
 * direction, and no second place a field can be stated in.
 *
 * **The declaration also decides what is read, not only what is named.** A field whose
 * type is another Definition accepts an instance of it, an array, or any object — and
 * an object is *projected*: only the field names the definition declares are read from
 * it, so handing a tool's model row to a result cannot send a column nobody agreed to
 * publish. Four rules keep that honest:
 *
 * 1. a field declared to hold a Definition may be handed one, or a source to project;
 * 2. a field not declared to hold an object never emits one: the call is refused with
 *    the name of the class and the field, rather than serialised and shipped;
 * 3. a projection reads columns, never relations — an object where a scalar was
 *    declared is a refusal ("project it yourself"), because a magic relation is both a
 *    query and more data than the field promised;
 * 4. a declared field the source does not carry is `null` when nullable and a refusal
 *    when not, which is the net under rule 1: it is the only one that can quietly map
 *    nothing at all.
 *
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp;

trait Definition {
	/**
	 * The keywords a map entry is allowed to carry
	 *
	 * @access private
	 */
	private const DECLARED_KEYWORDS = [
		'type',
		'description',
		'nullable',
		'format',
		'enum',
		'minimum',
		'maximum',
		'pattern',
		'required',
		'value_type',
		'items',
	];

	/**
	 * The reduced answer of this result, once
	 *
	 * The handler reduces it into structuredContent and the logging around a call asks
	 * the same question of the same object; reducing twice would walk every nested
	 * result twice.
	 *
	 * @access private
	 * @var ?array<string, mixed> $info
	 */
	private ?array $info = null;

	/**
	 * The fields this result declares, worked out once
	 *
	 * Either half of the contract — the advertised schema and the payload — is drawn from
	 * this map, so it is normalised and memoised once per request rather than twice per
	 * read.
	 *
	 * @access private
	 * @var ?array<string, array<string, mixed>> $declaration
	 */
	private ?array $declaration = null;

	/**
	 * Get the JSON schema this result's structuredContent conforms to
	 *
	 * @access public
	 * @return array<string, mixed> $schema The outputSchema to advertise
	 */
	public function get_mcp_definition_schema(): array {
		$keywords = $this->get_mcp_declaration_keywords();
		$properties = [];
		$required = [];

		foreach ($this->get_mcp_definition_declaration() as $name => $definition) {
			// the map is the only word about a field: there is no second place a keyword can
			// be stated, and so no precedence between two places to get wrong
			$override = $keywords[$name] ?? [];

			$properties[$name] = $this->schema_for_property($definition, $override, $name);

			if (($override['required'] ?? true) === true) {
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
	 * The entry point `Handler` and `Reducer` call. A class that builds its answer from
	 * declared properties never sees this method — the trait answers for it below. A
	 * class that would rather write its payload in one readable array overrides *this*
	 * method and leaves `build_mcp_definition_info()` alone: that is how the trait can
	 * still project a source onto it, and how `Reducer` tells the two kinds apart.
	 *
	 * @access public
	 * @return array<string, mixed> $info The answer to hand to the client
	 */
	public function get_mcp_definition_info(): array {
		return $this->build_mcp_definition_info();
	}

	/**
	 * Build the structuredContent from the declaration, field by field
	 *
	 * Every declared field is answered, null when neither a value method nor a property
	 * supplies one: a missing key would contradict the required list of the schema, while
	 * a null says what the schema promises anyway. The answer is memoised because the
	 * handler and the logging around a call ask the same question of the same object, and
	 * reducing twice would walk every nested result twice.
	 *
	 * @access public
	 * @return array<string, mixed> $info The answer to hand to the client
	 */
	public function build_mcp_definition_info(): array {
		if ($this->info !== null) {
			return $this->info;
		}

		$info = [];

		foreach ($this->get_mcp_definition_declaration() as $name => $definition) {
			$info[$name] = $this->value_for_property($definition, $this->get_mcp_definition_value($name), $name);
		}

		return $this->info = $info;
	}

	/**
	 * Declare the fields of this result
	 *
	 * **The contract, in the author's words rather than the compiler's.** This method is
	 * abstract: a class that uses the trait has to state its fields, or PHP refuses the
	 * class at the moment it is loaded. The map is the advertised `outputSchema`, and it
	 * is the law the answer is held to — whether that answer is authored in
	 * `get_mcp_definition_info()` or built from properties by the trait.
	 *
	 * An entry is the field's name mapped to the JSON Schema keywords the client sees:
	 * `type` (required), `description`, `nullable`, `format`, `enum`, `minimum`,
	 * `maximum`, `pattern`, `required` (`false` makes the field optional), `value_type`
	 * (the Definition class an object or an array of objects holds), and `items` for a
	 * list of scalars (`['type' => 'string']`) or of objects
	 * (`['type' => 'object', 'value_type' => Row::class]`).
	 *
	 * An entry carrying a keyword the trait does not read is a refusal naming the class,
	 * the field and the keyword: a word nothing reads is not a hint, it is a sentence said
	 * to nobody — and this is where `definition` written instead of `value_type` would
	 * otherwise vanish and publish an array with no element schema.
	 *
	 * @access public
	 * @return array<string, array<string, mixed>> $properties Field name => its keywords
	 */
	abstract public function get_mcp_definition_properties(): array;

	/**
	 * Get the fields this result declares
	 *
	 * Every reader of the contract — the schema, the payload, a projection into another
	 * definition — goes through here, so no two of them can disagree about what a result
	 * is.
	 *
	 * @access public
	 * @return array<string, array<string, mixed>> $declaration Field name => normalised definition
	 * @throws \Exception When the map states nothing, or a field without a type
	 */
	public function get_mcp_definition_declaration(): array {
		if ($this->declaration !== null) {
			return $this->declaration;
		}

		$properties = $this->get_mcp_definition_properties();

		if ($properties === []) {
			// reachable only by a map that states nothing, which is a bug rather than a
			// shape: an answer that publishes no field has nothing to advertise either
			throw new \Exception(
				get_class($this) . ' declares no fields: the map is the contract'
			);
		}

		$declaration = [];

		foreach ($properties as $name => $keywords) {
			if (isset($keywords['type']) === false) {
				throw new \Exception(
					get_class($this) . ' declares ' . $name . ' without a type: a field of the contract '
						. 'has to say what it is'
				);
			}

			$declaration[$name] = $this->normalise($keywords, $name);
		}

		return $this->declaration = $declaration;
	}

	/**
	 * The schema keywords a declared map carries per field
	 *
	 * `format`, `enum`, `minimum`, `maximum` and `pattern` are what a type cannot say,
	 * so they live in the declaration next to the type: one place per field, and no second
	 * array keyed by name that could disagree with the first.
	 *
	 * @access public
	 * @return array<string, array<string, mixed>> $keywords Field name => keywords
	 */
	public function get_mcp_declaration_keywords(): array {
		$keywords = [];

		foreach ($this->get_mcp_definition_properties() as $name => $entry) {
			// everything the map may carry except the two keywords that are not schema
			// words: `required` is asked about on its own below, and `value_type` names a
			// class rather than describing a value
			$schema_keywords = array_diff(self::DECLARED_KEYWORDS, [ 'required', 'value_type' ]);

			$kept = array_intersect_key($entry, array_flip($schema_keywords));

			if (isset($entry['required']) === true) {
				$kept['required'] = $entry['required'];
			}

			$keywords[$name] = $kept;
		}

		return $keywords;
	}

	/**
	 * Compute one field of a property-driven answer
	 *
	 * **Only the second kind of definition uses this** — see the header of this trait. A
	 * class that authors `get_mcp_definition_info()` returns its fields in one array and
	 * never meets this method; the map still decides whether what it returned is legal.
	 *
	 * For the property-driven kind this is where a derived value belongs — a date trimmed
	 * to a day, an empty string turned into the null the schema promises, a row built
	 * from an object the pager already holds — rather than an assignment in
	 * `__construct`: the constructor holds sources, this computes what the client is told.
	 *
	 * The default reads a same-named property, which is what keeps a result whose fields
	 * are plain assignments working, and answers null when there is none — a declared field
	 * with neither a property nor a case here is refused at reduce time, never silently
	 * null.
	 *
	 * @access protected
	 * @param string $field The declared field to answer
	 * @return mixed $value The value as the code holds it, before the schema reduces it
	 */
	protected function get_mcp_definition_value(string $field): mixed {
		return $this->$field ?? null;
	}

	/**
	 * Turn one property value into what the client receives
	 *
	 * @access protected
	 * @param array $definition What the property is
	 * @param mixed $value The value the result carries
	 * @param string $field The name of the property, for a refusal
	 * @return mixed $value The same value, reduced to what the schema declares
	 * @throws \Exception When a value cannot be expressed as the declared type
	 */
	protected function value_for_property(array $definition, $value, string $field): mixed {
		if ($value === null) {
			return null;
		}

		return $this->coerce($value, $definition, $field);
	}

	/**
	 * Bring one value into the type its field declares
	 *
	 * Public as well as protected because `Reducer` reaches it from outside the class:
	 * one value, one rule, whoever asks. An override that reshapes `info()` still gets
	 * the same projection, refusal and recursion as the trait's own loop.
	 *
	 * @access public
	 * @param mixed $value The value as the code built it
	 * @param array $definition What the field is
	 * @param string $field The name of the property, for a refusal
	 * @param ?string $tool_name The tool that answered, so a nested refusal names it too
	 * @return mixed $value A scalar, a list of them, or a reduced definition
	 * @throws \Exception When the value is an object the field did not declare
	 */
	public function coerce($value, array $definition, string $field, ?string $tool_name = null): mixed {
		if (is_object($value) === true && $value instanceof DefinitionInterface) {
			// a child is reduced the way the top level is, so its own map gets to hold it
			// to account too — a class is not exempt from its declaration by being nested
			return Reducer::reduce($value, $tool_name);
		}

		if (is_array($value) === true) {
			// An associative array where the field names a Definition is a source to
			// project; a list is a list, and every element answers to the element
			// definition — which is how `$result->rows = $pager->items` stays legal
			// while each row still leaves as the fields Row declares.
			if ($definition['definition'] !== null && array_is_list($value) === false) {
				return $this->project($definition['definition'], $value, $field, $tool_name);
			}

			$element = $this->element_definition($definition);

			return array_map(
				fn ($item) => $this->coerce($item, $element, $field, $tool_name),
				array_values($value)
			);
		}

		if (is_object($value) === true) {
			// Rule 2 and 3 together: an object leaves this result only when the field
			// was declared to hold one, and then only as the projection of a Definition.
			// Anything else is a model, a row or a relation about to be json_encoded
			// column by column, which is the bug and not the feature.
			if ($definition['definition'] === null) {
				throw new \Exception(
					get_class($this) . '::$' . $field . ' holds a ' . get_class($value)
						. ', which its declaration does not allow: name the Definition it projects to, '
						. 'or assign the scalar it promises'
				);
			}

			return $this->project($definition['definition'], $value, $field);
		}

		if ($definition['type'] === 'object' && $definition['definition'] !== null) {
			throw new \Exception(
				get_class($this) . '::$' . $field . ' declares ' . $definition['definition']
					. ' but holds ' . gettype($value)
			);
		}

		return $value;
	}

	/**
	 * What one element of a list field is
	 *
	 * The array's own definition describes the container; the elements are judged by
	 * what the map named inside it — a Definition class, a scalar, or nothing at all,
	 * which is the honest "an array of anything" and still refuses an object.
	 *
	 * @access protected
	 * @param array $definition The definition of the list
	 * @return array<string, mixed> $definition What one element must be
	 */
	protected function element_definition(array $definition): array {
		if ($definition['definition'] !== null) {
			return [
				'type' => 'object',
				'nullable' => false,
				'items' => null,
				'definition' => $definition['definition'],
				'description' => null,
			];
		}

		if (is_array($definition['items']) === true) {
			return [
				'type' => $definition['items']['type'] ?? 'string',
				'nullable' => false,
				'items' => null,
				'definition' => null,
				'description' => null,
			];
		}

		return [
			'type' => $definition['type'],
			'nullable' => false,
			'items' => null,
			'definition' => null,
			'description' => null,
		];
	}

	/**
	 * Bring one declared entry to the shape the reducers read
	 *
	 * @access private
	 * @param array $keywords The entry as the author wrote it
	 * @param string $field The name of the property, for a refusal
	 * @return array<string, mixed> $definition The normalised definition
	 * @throws \Exception When the entry carries a keyword this trait does not read
	 */
	private function normalise(array $keywords, string $field): array {
		$unknown = array_values(array_diff(array_keys($keywords), self::DECLARED_KEYWORDS));

		if ($unknown !== []) {
			// an unrecognised keyword is not a hint, it is a word said to nobody: this is
			// where `definition` written instead of `value_type` would have been caught,
			// instead of surfacing as an array with no element schema and no error
			throw new \Exception(
				get_class($this) . ' declares ' . $field . ' with the unknown keyword(s) '
					. implode(', ', $unknown) . ' — a map entry carries '
					. implode(', ', self::DECLARED_KEYWORDS)
			);
		}

		$definition = [
			'type' => $keywords['type'],
			'nullable' => $keywords['nullable'] ?? false,
			'items' => null,
			'definition' => null,
			'description' => $keywords['description'] ?? null,
		];

		if ($definition['type'] === 'array') {
			$items = $keywords['items'] ?? null;

			if (is_array($items) === true) {
				if (($items['type'] ?? null) === 'object' && isset($items['value_type']) === true) {
					$definition['definition'] = $this->resolve_named_definition($items['value_type']);
				} else {
					$definition['items'] = [ 'type' => $items['type'] ?? 'string' ];
				}
			} elseif (isset($keywords['value_type']) === true) {
				$definition['definition'] = $this->resolve_named_definition($keywords['value_type']);
			}

			return $definition;
		}

		if ($definition['type'] === 'object' && isset($keywords['value_type']) === true) {
			$definition['definition'] = $this->resolve_named_definition($keywords['value_type']);
		}

		return $definition;
	}

	/**
	 * Accept a nested definition named as a class or as an instance
	 *
	 * @access private
	 * @param mixed $value The `value_type` as written
	 * @return ?string $classname The Definition class, null when it names none
	 */
	private function resolve_named_definition($value): ?string {
		if (is_object($value) === true) {
			$value = get_class($value);
		}

		if (is_string($value) === false || is_a($value, DefinitionInterface::class, true) === false) {
			return null;
		}

		return $value;
	}

	/**
	 * Build the schema of one property
	 *
	 * The map is the author's deliberate word about a field, and the only one: what the
	 * elements of an array are is settled here too, in the order that says so — a nested
	 * Definition named by `value_type`, then the `items` the map stated, then nothing at
	 * all, which is honest where a guessed `string` would not be.
	 *
	 * @access private
	 * @param array $definition What the property is
	 * @param array $override What the author added for it
	 * @param string $field The name of the property, for a refusal
	 * @return array<string, mixed> $schema What the property must satisfy
	 * @throws \Exception When the property names a class that declares no shape
	 */
	private function schema_for_property(array $definition, array $override, string $field): array {
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
			// stated, then a nested Definition, then what the map named
			if (isset($override['items']) === true) {
				$items = $override['items'];
			} elseif ($definition['definition'] !== null) {
				$items = $this->schema_of($definition['definition']);
			} else {
				$items = $definition['items'];
			}

			// An `items` entry that names a Definition is a pointer, not a schema: the
			// shape of the elements belongs to that class, and `value_type` is a word of
			// this trait rather than a JSON Schema keyword a client has any business
			// reading. Without this the declared form `items => [type => object,
			// value_type => Row::class]` — which this file documents — would publish the
			// pointer itself and leave the elements untyped, exactly the silent loss the
			// property-driven fallback was removed for.
			if (is_array($items) === true && isset($items['value_type']) === true) {
				$classname = $this->resolve_named_definition($items['value_type']);

				if ($classname !== null) {
					$items = $this->schema_of($classname);
				}
			}

			// no items at all says "an array of anything", which is honest; saying
			// string when the elements are objects would fail a validating client
			if ($items !== null) {
				$schema['items'] = $items;
			}

			return $schema;
		}

		if ($type === 'object') {
			if ($definition['definition'] === null) {
				throw new \Exception(
					get_class($this) . '::$' . $field . ' is an object that names no Definition: '
						. 'declare the class it holds, or give it a scalar type'
				);
			}

			$nested = $this->schema_of($definition['definition']);
			unset($nested['type'], $nested['description']);

			return array_merge($schema, $nested);
		}

		return $schema;
	}

	/**
	 * Get the schema another definition class declares
	 *
	 * @access private
	 * @param string $classname The class the map named for this field
	 * @return array<string, mixed> $schema The schema that class draws for itself
	 */
	private function schema_of(string $classname): array {
		return (new $classname())->get_mcp_definition_schema();
	}

	/**
	 * Project a source onto the fields another definition declares
	 *
	 * Only the names the definition itself states are read, in the order it states
	 * them, so a source with a column nobody agreed to publish cannot publish it by
	 * accident. Names the source does not carry become null when the field allows it
	 * and a refusal when it does not — the second half being the net that catches a
	 * field renamed on one side only.
	 *
	 * The map of the target is the whole authority: the values go straight from the
	 * source through `Reducer::verify()`, which checks each against the declared type
	 * and coerces it once. The target's own `get_mcp_definition_info()` is deliberately
	 * not consulted — a source was handed in precisely because its fields are to come
	 * from that source.
	 *
	 * @access private
	 * @param string $classname The Definition to project onto
	 * @param object|array $source Where to read the declared names from
	 * @param string $field The name of the property being filled, for a refusal
	 * @param ?string $tool_name The tool that answered, so a nested refusal names it too
	 * @return array<string, mixed> $info The reduced answer
	 * @throws \Exception When a non-nullable field is not on the source
	 */
	private function project(string $classname, $source, string $field, ?string $tool_name = null): array {
		$instance = new $classname();

		$projected = [];

		foreach ($instance->get_mcp_definition_declaration() as $name => $definition) {
			$value = $this->read($source, $name);

			if ($value === $this->missing()) {
				if ($definition['nullable'] !== true) {
					throw new \Exception(
						$classname . '::$' . $name . ' is required, but ' . $this->describe_source($source)
							. ' carries no ' . $name . ' to project (from ' . get_class($this) . '::$' . $field . ')'
					);
				}

				$projected[$name] = null;

				continue;
			}

			$projected[$name] = $value;
		}

		// verified and coerced once, by the same code that holds an authored answer to
		// its own map — a projected source is no more allowed to publish a model than a
		// field the class computed itself
		return Reducer::verify($instance, $projected, $tool_name);
	}

	/**
	 * Read one name from a projection source
	 *
	 * An array is read by key; an object through its own accessor, which is how a
	 * model's loaded columns come back without a query. Unknown keys throw there, and
	 * a throw is this method's only sane answer: the marker below means "the source
	 * does not carry this name", which project() then judges against the field's
	 * nullability.
	 *
	 * @access private
	 * @param object|array $source Where to read from
	 * @param string $name The field to read
	 * @return mixed $value The value, or the missing marker
	 */
	private function read($source, string $name): mixed {
		if (is_array($source) === true) {
			return array_key_exists($name, $source) === true ? $source[$name] : $this->missing();
		}

		try {
			return $source->$name;
		} catch (\Throwable) {
			return $this->missing();
		}
	}

	/**
	 * Say what a projection source is, in a refusal
	 *
	 * @access private
	 * @param object|array $source The source being projected
	 * @return string $description A class name, or "the array"
	 */
	private function describe_source($source): string {
		return is_object($source) === true ? get_class($source) : 'the array';
	}

	/**
	 * The marker that means a name is not on the source
	 *
	 * An object rather than a magic string: a value no author can have typed, so a
	 * real null, an empty string or a zero can never be mistaken for an absent field.
	 *
	 * @access private
	 * @return object $missing The marker
	 */
	private function missing(): object {
		static $missing = null;

		if ($missing === null) {
			$missing = new \stdClass();
		}

		return $missing;
	}
}
