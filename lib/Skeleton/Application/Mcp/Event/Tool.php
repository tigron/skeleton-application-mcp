<?php
/**
 * Tool event (like module in application-web)
 */

namespace Skeleton\Application\Mcp\Event;

class Tool extends \Skeleton\Core\Application\Event {

	/**
	 * Bootstrap
	 *
	 * @access public
	 * @param \Skeleton\Application\Mcp\Tool $tool
	 */
	public function bootstrap(\Skeleton\Application\Mcp\Tool $tool): void {
	}

	/**
	 * Teardown
	 *
	 * @access public
	 * @param \Skeleton\Application\Mcp\Tool $tool
	 */
	public function teardown(\Skeleton\Application\Mcp\Tool $tool): void {
	}
}
