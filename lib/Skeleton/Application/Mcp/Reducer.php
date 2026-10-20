<?php

declare(strict_types=1);

/**
 * Reducer
 *
 * The one place a declared result becomes the array a client is answered, and the one
 * place the question is asked that a map can only promise and code can only keep: does
 * what the class returned match what its map declares?
 *
 * Two kinds of definition exist — one that writes its own `get_mcp_definition_info()`,
 * one that lets the trait read its properties — and both hand this class **raw** values.
 * That is what makes one set of rules possible: keys, types, nullability, `enum`
 * membership and "an object is a Definition or a source to project" are asked here, of
 * every answer, whichever shape its author chose. A rule that bound only the newer style
 * would be a rule waiting for the first class written the older way to break it quietly,
 * and a validating client is the worst party to find out.
 *
 * A mismatch is a bug in the tool, not a surprise for the caller: the class, the field and
 * the value that arrived go to the error log, and the client gets one neutral sentence
 * naming the tool. Same policy `Handler` applies to a failure nobody planned for, in the
 * one place both call sites reach. *
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp;

class Reducer {
	/**
	 * Reduce a declared result into the array the client is answered
	 *
	 * @access public
	 * @param \Skeleton\Application\Mcp\DefinitionInterface $result The answer of execute()
	 * @param ?string $tool_name The tool that answered, for the log and the refusal
	 * @return array<string, mixed> $info The structuredContent
	 * @throws \Skeleton\Application\Mcp\Exception When the answer is not shaped as the map promises
	 */
	public static function reduce(DefinitionInterface $result, ?string $tool_name = null): array {
		try {
			// both kinds of definition hand over raw values, so there is one job here and
			// one set of rules, whoever wrote the answer
			return self::verify($result, $result->get_mcp_definition_info(), $tool_name);
		} catch (\Throwable $e) {
			error_log(
				'MCP: the result of tool ' . ($tool_name ?? 'a tool') . ' does not reduce: ' . $e->getMessage()
			);

			throw new Exception(
				'The result of tool ' . ($tool_name ?? 'this tool') . ' is not shaped as its declaration promises'
			);
		}
	}

	/**
	 * Hold an authored answer to what its own map promised
	 *
	 * The keys first, because that is the promise the client checks: the payload and the
	 * advertised `outputSchema` are meant to be the same list of fields, and a key that
	 * is missing, added or renamed is a schema violation the client rejects — silently,
	 * and only for the answers nobody thought to test. Then the values, on the raw form
	 * the class returned: `coerce()` shapes what is accepted, and shaping what is not
	 * would hide the mistake rather than report it.
	 *
	 * Public because projection reaches it from the trait: a source handed to a field is
	 * held to the same map as a value the class computed itself.
	 *
	 * @access public
	 * @param \Skeleton\Application\Mcp\DefinitionInterface $result The answer to check
	 * @param array<string, mixed> $info What the class, or the source, returned
	 * @param ?string $tool_name The tool that answered, for a refusal
	 * @return array<string, mixed> $reduced The answer, reduced to what the schema declares
	 * @throws \Exception When the answer does not match the map
	 */
	public static function verify(DefinitionInterface $result, array $info, ?string $tool_name = null): array {
		if (method_exists($result, 'get_mcp_definition_declaration') === false) {
			// a DefinitionInterface of somebody's own making, with no map to hold it to
			return $info;
		}

		$declaration = $result->get_mcp_definition_declaration();

		$declared = array_keys($declaration);
		$given = array_keys($info);

		$extra = array_values(array_diff($given, $declared));
		$absent = array_values(array_diff($declared, $given));

		if ($extra !== [] || $absent !== []) {
			throw new \Exception(
				self::name($result) . ' answers with ' . count($given) . ' field(s) where its map declares '
					. count($declared) . ': ' . self::list_the_difference($extra, $absent)
			);
		}

		$reduced = [];

		// the keywords are the other half of the map — `enum` in particular — and they
		// live in their own method because the schema reads them as-is
		$keywords = method_exists($result, 'get_mcp_declaration_keywords') === true
			? $result->get_mcp_declaration_keywords()
			: [];

		foreach ($declaration as $field => $definition) {
			$value = $info[$field];

			self::check($result, $field, $definition, $value, $keywords[$field] ?? []);

			$reduced[$field] = $value === null ? null : $result->coerce($value, $definition, $field, $tool_name);
		}

		return $reduced;
	}

	/**
	 * Say which keys drifted, in the direction they drifted
	 *
	 * @access private
	 * @param array<int, string> $extra Keys the answer carries that the map does not declare
	 * @param array<int, string> $absent Keys the map declares that the answer left out
	 * @return string $difference One readable clause
	 */
	private static function list_the_difference(array $extra, array $absent): string {
		$parts = [];

		if ($extra !== []) {
			$parts[] = 'not declared: ' . implode(', ', $extra);
		}

		if ($absent !== []) {
			$parts[] = 'declared but absent: ' . implode(', ', $absent);
		}

		return implode('; ', $parts);
	}

	/**
	 * Check one raw value against the entry its field declares
	 *
	 * The types of JSON Schema, spelled the way `Util::to_json_type()` writes them, and
	 * nothing the map does not say: a field declared to hold a Definition may be handed
	 * one or a source to project, and it is `coerce()` that judges that — refusing the
	 * model that arrives where a scalar was promised. What is checked here is the
	 * mistake `coerce()` cannot see: a value that is of a perfectly good type and still
	 * not the one the client was told to expect.
	 *
	 * @access private
	 * @param \Skeleton\Application\Mcp\DefinitionInterface $result The answer being checked
	 * @param string $field The declared field
	 * @param array $definition What the map says that field is
	 * @param mixed $value The raw value the class returned
	 * @param array $keywords The other keywords the map carries for that field
	 * @throws \Exception When the value is not what the map declares
	 */
	private static function check(
		DefinitionInterface $result,
		string $field,
		array $definition,
		$value,
		array $keywords = []
	): void {
		if ($value === null) {
			if (($keywords['nullable'] ?? $definition['nullable']) !== true) {
				throw new \Exception(
					self::name($result) . '::$' . $field . ' answers null, which its map does not allow'
				);
			}

			return;
		}

		$type = $definition['type'];

		if ($type === 'array') {
			if (is_array($value) === false || array_is_list($value) === false) {
				throw new \Exception(
					self::name($result) . '::$' . $field . ' must be a list, ' . self::describe($value) . ' given'
				);
			}

			return;
		}

		if ($type === 'object') {
			if (is_array($value) === true || is_object($value) === true) {
				return;
			}

			throw new \Exception(
				self::name($result) . '::$' . $field . ' must be an object or a source to project, '
					. self::describe($value) . ' given'
			);
		}

		$fits = true;
		$says = $type;

		switch ($type) {
			case 'string':
				$fits = is_string($value);
				break;
			case 'integer':
				$fits = is_int($value);
				break;
			case 'number':
				$fits = is_float($value) === true || is_int($value) === true;
				break;
			case 'boolean':
				$fits = is_bool($value);
				break;
			default:
				// a type the map should never hold (Util::to_json_type() answers string
				// for anything it does not know), but say so rather than wave it through
				$says = 'a value of the declared type ' . $type;
				$fits = false;
		}

		if ($fits === false) {
			throw new \Exception(
				self::name($result) . '::$' . $field . ' must be ' . $says . ', ' . self::describe($value) . ' given'
			);
		}

		if (isset($keywords['enum']) === true && is_array($keywords['enum']) === true) {
			// the map told the client which values are possible — `enum` is a keyword of
			// the map, not of the normalised declaration, which is why it arrives in
			// $keywords and not in $definition
			$allowed = array_map('strval', $keywords['enum']);

			if (in_array((string)$value, $allowed, true) === false && $type !== 'boolean') {
				throw new \Exception(
					self::name($result) . '::$' . $field . ' answers ' . self::describe($value)
						. ', which its enum does not name: ' . implode(', ', $allowed)
				);
			}
		}
	}

	/**
	 * Name a class the way a log line can carry it
	 *
	 * `get_class()` of an anonymous class contains NUL bytes, and a string with a NUL in
	 * it is cut short the moment `error_log()` writes it — which takes the useful half of
	 * the message with it, leaving exactly the line that was written to say what went
	 * wrong saying nothing but a prefix. Anonymous classes are how a definition is built
	 * in a probe and in a test, so this is not a corner.
	 *
	 * @access private
	 * @param object $object The class to name
	 * @return string $name A printable name, the parent and `anonymous` where needed
	 */
	private static function name(object $object): string {
		$class = get_class($object);
		$anonymous = strpos($class, "\0");

		if ($anonymous === false) {
			return $class;
		}

		return substr($class, 0, $anonymous) . ' (anonymous)';
	}

	/**
	 * Say what a value is, in a refusal the author will read
	 *
	 * @access private
	 * @param mixed $value The value to describe
	 * @return string $description A type name, or the class of an object
	 */
	private static function describe($value): string {
		if (is_object($value) === true) {
			return 'a ' . self::name($value);
		}

		if (is_array($value) === true) {
			return array_is_list($value) === true ? 'a list' : 'an object';
		}

		if ($value === null) {
			return 'null';
		}

		if (is_string($value) === true) {
			return 'the string ' . var_export(mb_substr($value, 0, 30), true);
		}

		return gettype($value) . ' ' . var_export($value, true);
	}
}
