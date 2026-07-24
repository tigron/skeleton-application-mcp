<?php
/**
 * Handler
 *
 * Implemented methods:
 *   - initialize
 *   - tools/list
 *   - tools/call
 *   - ping
 *   - notifications/* (just empty reply)
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
		try {
			$request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return $this->error(null, -32700, 'Parse error');
		}

		// split up a batch request in single requests, reassemble the responses
		if (is_array($request) && array_is_list($request)) {
			$responses = [];
			foreach ($request as $single) {
				$response = $this->handle_single($single);
				if ($response !== null) {
					$responses[] = $response;
				}
			}
			return count($responses) > 0 ? $responses : null;
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
		$id = null;
		$is_notification = true;

		if (!empty($request['id'])) {
			$id = $request['id'];
			// if there is a request ID, it is not a notification
			$is_notification = false;
		}

		if (!is_array($request) || !isset($request['method'])) {
			return $this->error($id, -32600, 'Invalid request');
		}

		$method = $request['method'];

		// reply nothing to a notification
		if ($is_notification || strpos($method, 'notifications/') === 0) {
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

		return $this->wrap_result($result);
	}

	/**
	 * Werap the response from a tool in a JSON-RPC response
	 *
	 * @access private
	 * @param mixed $result
	 * @return array
	 */
	private function wrap_result($result): array {
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

		if (is_array($result) || is_object($result)) {
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
