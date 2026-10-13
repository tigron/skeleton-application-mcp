<?php
/**
 * Util class
 */

namespace Skeleton\Application\Mcp;

class Util {

	/**
	 * Convert a PHP type into the JSON Schema one
	 *
	 * The single map for the whole package: a parameter of a tool and a property of
	 * a result class are described by the same vocabulary, and two copies of it drift
	 * — one of them already answered `object` where the other answered `string`.
	 *
	 * Anything the map does not know becomes a string: a class name, `mixed`, a type
	 * spelled oddly. That is the same choice the package made before this method
	 * existed, and it is the safe one — a client that is told `string` still gets
	 * what the tool returns, where a client handed a type it cannot render gets
	 * nothing. A caller that knows better says so itself: `Tool` overrides the type
	 * from the `@param` of the docblock, and `Component\Definition` overrides it
	 * from the property's own class.
	 *
	 * @access public
	 * @param ?string $php_type The name of the PHP type, as reflection spells it
	 * @return string $json_type The name of the JSON type
	 */
	public static function to_json_type(?string $php_type): string {
		switch (strtolower((string)$php_type)) {
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

			default:
				return 'string';
		}
	}

	/**
	 * Recursive glob
	 *
	 * @access public
	 * @param string $pattern
	 * @param int $flags
	 * @return array $files
	 */
	public static function rglob($pattern, $flags = 0) {
		$files = glob($pattern, $flags);
		foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
			$files = array_merge($files, self::rglob($dir . '/' . basename($pattern), $flags));
		}
		return $files;
	}
}
