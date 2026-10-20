<?php

declare(strict_types=1);

/**
 * JSON-RPC exception
 *
 * A failure of the request rather than of the call: the body cannot be read, the method
 * does not exist, the credential does not check out. `Handler` answers it as a JSON-RPC
 * error carrying this code and this message, and the application turns the codes an
 * authentication carries into the HTTP status they deserve.
 *
 * The codes of JSON-RPC 2.0 (https://www.jsonrpc.org/specification#error_object):
 *
 *   -32700  Parse error
 *   -32600  Invalid Request
 *   -32601  Method not found
 *   -32602  Invalid params
 *   -32603  Internal error
 *
 * -32001 is this package's, from the range reserved for implementations: the credential
 * the caller offered was refused (see Security\ApiKey::ERROR_CODE_UNAUTHORIZED).
 *
 * @author Gerry Demaret <gerry@tigron.be>
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp\Jsonrpc;

class Exception extends \Exception {
}
