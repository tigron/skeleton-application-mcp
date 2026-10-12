<?php
/**
 * Internal Jsonrpc\Exception
 *
 * From https://www.jsonrpc.org/historical/json-rpc-2-0.html
 *
 *   -32700  Parse error
 *   -32600  Invalid Request
 *   -32601  Method not found
 *   -32602  Invalid params
 *   -32603  Internal error
 */

namespace Skeleton\Application\Mcp\Jsonrpc;

class Exception extends \Exception {
}
