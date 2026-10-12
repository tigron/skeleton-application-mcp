<?php
/**
 * Util class
 */

namespace Skeleton\Application\Mcp;

class Util {

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
