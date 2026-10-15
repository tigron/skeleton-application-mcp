<?php

declare(strict_types=1);

/**
 * Regular MCP exception
 *
 * What a tool throws when the caller is the one who got it wrong: an argument that
 * cannot be read, a filter that contradicts another, an identifier that names nothing.
 * `Handler` answers it as a result with `isError` set and this message in the text the
 * model reads, so the caller is told in words the tool chose rather than in a transport
 * error it cannot act on.
 *
 * Throwing `Jsonrpc\Exception` instead means something else: that the request itself
 * could not be dispatched.
 *
 * @author Gerry Demaret <gerry@tigron.be>
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp;

class Exception extends \Exception {
}
