<?php

declare(strict_types=1);

/**
 * Util
 *
 * The two things the rest of the package shares and neither owns: the map from a PHP
 * type to a JSON Schema type, and the walk that finds the classes of an application.
 *
 * @author Gerry Demaret <gerry@tigron.be>
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp;

class Util {
	/**
	 * Convert a PHP type into the JSON Schema one
	 *
	 * The single map for the whole package: a parameter of a tool and a field of a
	 * result are described by the same vocabulary, and two copies of it drift — one of
	 * them already answered `object` where the other answered `string`.
	 *
	 * Anything the map does not know becomes a string: a class name, `mixed`, a type
	 * spelled oddly. That is the safe choice — a client that is told `string` still
	 * gets what the tool returns, where a client handed a type it cannot render gets
	 * nothing. A caller that knows better says so itself: `Tool` takes the type of a
	 * parameter from its PHP declaration and falls back to the `@param` of the
	 * docblock, and a result states the type of a field in its declaration map.
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
	 * `glob()` on its own does not cross a directory, and a tool in a sub-directory is
	 * as much a tool as one at the top. A directory that cannot be read yields no files
	 * rather than a warning: `glob()` answers false, and an empty list is the honest
	 * answer to "what is in there".
	 *
	 * @access public
	 * @param string $pattern The pattern to match, as glob() takes it
	 * @param int $flags The glob() flags
	 * @return array<int, string> $files The paths that matched
	 */
	public static function rglob(string $pattern, int $flags = 0): array {
		$files = (array)glob($pattern, $flags);

		foreach ((array)glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
			$files = array_merge($files, self::rglob($dir . '/' . basename($pattern), $flags));
		}

		return $files;
	}
}
