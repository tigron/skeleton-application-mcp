<?php
/**
 * Security
 */

namespace Skeleton\Application\Mcp;

abstract class Security {

	/**
	 * Handle the security check, throw exception on failure or nothing if it
	 * is all good
	 *
	 * @access public
	 */
	abstract public function handle(): void;
}
