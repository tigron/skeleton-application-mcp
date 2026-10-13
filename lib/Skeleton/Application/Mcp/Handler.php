<?php

declare(strict_types=1);

/**
 * Handler
 *
 * Implemented methods:
 *   - initialize
 *   - tools/list
 *   - tools/call
 *   - ping
 *   - notifications/* (just empty reply)
 *
 * What lives here is the JSON-RPC layer: how a body becomes a structure, which
 * requests expect an answer, and how a result is wrapped. Batch requests are refused
 * rather than answered, because MCP 2025-06-18 removed them and a server that answers
 * one looks to a client like a server that speaks a superseded revision.
 *
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp;

class Handler {
	/**
	 * Local application instance
	 *
	 * @var \Skeleton\Application\Mcp $application
	 * @access private
	 */
	private $application = null;

	/**
	 * Constructor
	 *
	 * @access public
	 * @param \Skeleton\Application\Mcp $application
	 */
	public function __construct(\Skeleton\Application\Mcp $application) {
		$this->application = $application;
	}

	/**
	 * Read a request body
	 *
	 * One definition of what a body means: an array when it parsed as one, whatever
	 * else it parsed as, and null when it is not JSON at all. The application asks the
	 * same question as this class does — whether the caller expects an answer, and with
	 * which id — so the reading is done in one place and the answer is the same in both.
	 *
	 * @access public
	 * @param string $raw The request body as the client sent it
	 * @return mixed $request The decoded body, null when it does not parse
	 */
	public static function decode(string $raw): mixed {
		try {
			return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return null;
		}
	}

	/**
	 * Handle a request body
	 *
	 * Batches (logic inspired by https://github.com/rambler-digital-solutions/php-json-rpc/blob/master/src/JsonRPC/Server.php#L402)
	 * get split up in single requetsts and handled in the same way later on
	 *
	 * @access public
	 * @param string $raw
	 * @return ?array
	 */
	public function handle(string $raw): ?array {
		return $this->handle_request(self::decode($raw));
	}

	/**
	 * Handle a body that has already been read
	 *
	 * The entry point for an application that decoded the body itself, which it has to
	 * do to decide what a caller who cannot authenticate is owed.
	 *
	 * @access public
	 * @param mixed $request The decoded body
	 * @return ?array
	 */
	public function handle_request(mixed $request): ?array {
		if ($request === null) {
			return $this->error(null, -32700, 'Parse error');
		}

		// MCP 2025-06-18 dropped batch requests. The members are not answered one by
		// one: a client that sends an array gets one refusal, over the transport it
		// came in on, so it learns the shape is wrong instead of learning to rely on it.
		if (is_array($request) === true && array_is_list($request) === true) {
			return $this->error(null, -32600, 'Batch requests are not supported by this server (MCP 2025-06-18)');
		}

		return $this->handle_single($request);
	}

	/**
	 * Handle a single request
	 *
	 * @access private
	 * @param mixed $request
	 * @return ?array
	 */
	private function handle_single($request): ?array {
		if (is_array($request) === false) {
			return $this->error(null, -32600, 'Invalid request');
		}

		// A notification is a request *without an id*, which is not the same thing as a
		// request whose id is 0, "" or false: those are real ids and their answers have
		// to come back. Testing them for emptiness — which an earlier version of this
		// method did — silently turns the first request of any client that numbers its
		// ids from zero into a notification, and that client then waits forever for a
		// reply the server already threw away.
		$is_notification = array_key_exists('id', $request) === false;
		$id = $request['id'] ?? null;

		if (!isset($request['method'])) {
			return $this->error($id, -32600, 'Invalid request');
		}

		$method = $request['method'];

		// reply nothing to a notification
		if ($is_notification === true || strpos($method, 'notifications/') === 0) {
			return null;
		}

		$params = [];
		if (!empty($request['params'])) {
			$params = $request['params'];
		}

		try {
			switch ($method) {
				case 'initialize':
					$result = $this->handle_initialize($params);
					break;
				case 'tools/list':
					$result = $this->handle_tools_list($params);
					break;
				case 'tools/call':
					$result = $this->handle_tools_call($params);
					break;
				case 'ping':
					$result = new \stdClass();
					break;
				default:
					return $this->error($id, -32601, 'Method not found: ' . $method);
			}
		} catch (\Skeleton\Application\Mcp\Jsonrpc\Exception $e) {
			// "expected" exception, handle with provided code
			return $this->error($id, $e->getCode(), $e->getMessage());
		} catch (\Throwable $e) {
			// unexpected exception
			return $this->error($id, -32603, $e->getMessage());
		}

		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => $result,
		];
	}

	/**
	 * Handle initialize
	 *
	 * @access private
	 * @param array $params
	 * @return array
	 */
	private function handle_initialize(array $params): array {
		$config = $this->application->config;

		return [
			'protocolVersion' => $config->protocol_version,
			'capabilities' => [
				'tools' => [
					// I think this defaults to false if undefined
					'listChanged' => false,
				],
			],
			'serverInfo' => [
				'name' => $config->server_name,
				'version' => $config->server_version,
			],
			'instructions' => $config->instructions,
		];
	}

	/**
	 * Handle tools/list
	 *
	 * @access private
	 * @param array $params
	 * @return array
	 */
	private function handle_tools_list(array $params): array {
		$tools = [];
		foreach ($this->application->get_tools() as $tool) {
			$tools[] = $tool->get_definition();
		}

		return [
			'tools' => $tools,
		];
	}

	/**
	 * Handle tools/call
	 *
	 * @access private
	 * @param array $params
	 * @return array
	 */
	private function handle_tools_call(array $params): array {
		if (!isset($params['name'])) {
			throw new \Skeleton\Application\Mcp\Jsonrpc\Exception('tool name not provided', -32602);
		}

		$arguments = $params['arguments'] ?? [];
		$tool = $this->application->get_tool($params['name']);

		try {
			$result = $tool->call($arguments);
		} catch (\Skeleton\Application\Mcp\Jsonrpc\Exception $e) {
			// these are caught higher up
			throw $e;
		} catch (\Skeleton\Application\Mcp\Exception $e) {
			// wrap in a proper return
			return [
				'content' => [
					[
						'type' => 'text',
						'text' => $e->getMessage()],
				],
				'isError' => true,
			];
		} catch (\Throwable $e) {
			// wrap anything else in a JSON-RPC exception
			// gets caught higher up
			throw new \Skeleton\Application\Mcp\Jsonrpc\Exception($e->getMessage(), -32603);
		}

		return $this->wrap_result($result, $params['name']);
	}

	/**
	 * Wrap the response from a tool in a JSON-RPC result
	 *
	 * Two shapes of answer, and the client is promised both: content[0].text, which
	 * always carries the JSON, and structuredContent, which the specification defines as
	 * a JSON *object* and a validating client rejects as anything else. So a tool that
	 * returned a bare list gets that field left out rather than sent — the text is still
	 * the identical JSON, and the client falls back to it instead of throwing the whole
	 * call away — with a line in the error log naming the tool, because the tool is
	 * wrong and somebody should be able to find out which one.
	 *
	 * A result that declares its own shape (a Component\Definition) is reduced through
	 * that declaration, so the payload and the advertised outputSchema come from the same
	 * list of properties and cannot drift apart.
	 *
	 * @access private
	 * @param mixed $result
	 * @param string $tool_name The tool that answered, for the log
	 * @return array
	 */
	private function wrap_result($result, string $tool_name): array {
		if (is_object($result) === true && method_exists($result, 'get_definition_info') === true) {
			$result = $result->get_definition_info();
		}

		if (is_string($result)) {
			$text = $result;
		} else {
			$text = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		}

		$envelope = [
			'content' => [
				[
					'type' => 'text',
					'text' => $text],
			],
			'isError' => false,
		];

		if (is_array($result) === true && array_is_list($result) === true) {
			error_log('MCP: list-shaped structuredContent dropped from the result of tool ' . $tool_name);
		} elseif (is_array($result) === true || is_object($result) === true) {
			$envelope['structuredContent'] = $result;
		}

		return $envelope;
	}

	/**
	 * Wrap an error in a JSON-RPC error
	 *
	 * @access private
	 * @param mixed $id
	 * @param int $code
	 * @param string $message
	 * @return array
	 */
	private function error(mixed $id, int $code, string $message): array {
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => [
				'code' => $code,
				'message' => $message,
			],
		];
	}
}
