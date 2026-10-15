<?php

declare(strict_types=1);

/**
 * Tool event
 *
 * The two moments around a single `tools/call`, for an application that wants to record
 * them: an audit trail, a counter, a slow-call log. The class is the contract
 * `\Skeleton\Application\Mcp\Mcp::get_events()` announces, and `app/<name>/event/Tool.php`
 * extends it to take part.
 *
 * @author Gerry Demaret <gerry@tigron.be>
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp\Event;

class Tool extends \Skeleton\Core\Application\Event {
	/**
	 * Bootstrap
	 *
	 * Called before anything about the call is decided: before the security handlers,
	 * before the arguments are checked and before execute(). The tool is the only
	 * argument, so this is where a call begins rather than where it ends.
	 *
	 * @access public
	 * @param \Skeleton\Application\Mcp\Tool $tool The tool that is about to be called
	 */
	public function bootstrap(\Skeleton\Application\Mcp\Tool $tool): void {
	}

	/**
	 * Teardown
	 *
	 * Called on every path out of `Tool::call()`, refused and failed calls included —
	 * which is the point: an audit that only records the calls that worked measures
	 * success and nothing else. The outcome says how it ended:
	 *
	 *   ok            bool     whether execute() answered
	 *   error_code    ?int     the code of the refusal, null when it answered
	 *   error_message ?string  what was refused, null when it answered
	 *   duration_ms   int      what the call cost
	 *   row_count     ?int     the size of the first list the answer holds, null when it
	 *                          holds none (a detail, or a plain array)
	 *   arguments     array    what the client sent, so a refused call can be read back
	 *
	 * @access public
	 * @param \Skeleton\Application\Mcp\Tool $tool The tool that was called
	 * @param array<string, mixed> $outcome How the call ended
	 */
	public function teardown(\Skeleton\Application\Mcp\Tool $tool, array $outcome = []): void {
	}
}
