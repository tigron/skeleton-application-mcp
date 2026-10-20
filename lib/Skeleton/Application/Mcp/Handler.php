<?php

declare(strict_types=1);

/**
 * Handler
 *
 * The JSON-RPC layer of the MCP server: how a body becomes a structure, which requests
 * expect an answer, and how a result is wrapped. What a caller may see and what a
 * refusal costs is the application's decision, in `Skeleton\Application\Mcp`.
 *
 * Methods implemented: `initialize`, `tools/list`, `tools/call`, `ping`, and
 * `notifications/*` (acknowledged with no body).
 *
 * Batch requests are refused rather than answered: MCP 2025-06-18 removed them, and a
 * server that answers one looks to a client like a server that speaks a superseded
 * revision.
 *
 * Everything the client sent that this layer cannot use is refused here, as a
 * JSON-RPC error. That is not politeness, it is containment: `params` reaching a typed
 * parameter of this class, or `method` reaching `strpos()`, raises a `TypeError` whose
 * message names the class, the method, the parameter and the file it all lives in. A
 * caller who mistyped a field is owed a readable refusal, not a tour of the
 * implementation.
 *
 * @author Gerry Demaret <gerry@tigron.be>
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp;

class Handler {
	/**
	 * The application this handler answers for
	 *
	 * @access private
	 */
	private \Skeleton\Application\Mcp $application;

	/**
	 * Constructor
	 *
	 * @access public
	 * @param \Skeleton\Application\Mcp $application The application that read the body
	 */
	public function __construct(\Skeleton\Application\Mcp $application) {
		$this->application = $application;
	}

	/**
	 * Handle a body that has already been read
	 *
	 * The entry point for an application that decoded the body itself, which it has to do
	 * to decide what a caller who cannot authenticate is owed. Reading the body and
	 * interpreting it are kept together in `decode()` and here, so the two never disagree
	 * about what a request means.
	 *
	 * @access public
	 * @param mixed $request The decoded body
	 * @return ?array<string, mixed> $response The JSON-RPC answer, null when none is owed
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

		if (is_array($request) === false) {
			return $this->error(null, -32600, 'Invalid request');
		}

		return $this->handle_single($request);
	}

	/**
	 * Handle one request object
	 *
	 * @access private
	 * @param array<string, mixed> $request The decoded request
	 * @return ?array<string, mixed> $response The JSON-RPC answer, null when none is owed
	 */
	private function handle_single(array $request): ?array {
		// A notification is a request *without an id*, which is not the same thing as a
		// request whose id is 0, "" or false: those are real ids and their answers have
		// to come back. Testing them for emptiness — which an earlier version of this
		// method did — silently turns the first request of any client that numbers its
		// ids from zero into a notification, and that client then waits forever for a
		// reply the server already threw away.
		$is_notification = array_key_exists('id', $request) === false;
		$id = $request['id'] ?? null;

		// An id is a scalar the caller can find its request by again. A list or an
		// object cannot be echoed back in a way that means anything, so the request is
		// refused rather than answered with something the client will drop. Note what
		// this does *not* test: 0, "0", "" and false are all real ids and keep their
		// answers — see the comment on the notification rule above.
		if (is_array($id) === true) {
			// The id is the thing that is wrong, so there is nothing to answer with: an
			// id member exists (a notification would not have reached here) and it is not
			// a scalar. null is what JSON-RPC prescribes for an error whose request id
			// cannot be determined.
			return $this->error(null, -32600, 'Invalid request: id must be a string, a number, true, false or null');
		}

		$method = $request['method'] ?? null;

		if (is_string($method) === false || $method === '') {
			return $this->refuse($id, $is_notification, -32600, 'Invalid request: method must be a non-empty string');
		}

		// reply nothing to a notification
		if ($is_notification === true || str_starts_with($method, 'notifications/')) {
			return null;
		}

		$params = $request['params'] ?? [];

		// JSON-RPC allows params to be absent, and MCP always sends it as an object. A
		// body of any other shape is refused here rather than at the typed parameter it
		// was heading for.
		if (is_array($params) === false) {
			return $this->refuse($id, $is_notification, -32602, 'Invalid params: params must be an object');
		}

		try {
			switch ($method) {
				case 'initialize':
					$result = $this->handle_initialize();
					break;
				case 'tools/list':
					$result = $this->handle_tools_list();
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
			// "expected" exception, answered with the code it carries: a refusal of the
			// credential and a name that does not exist are both said in the handler's
			// own words, and the application turns the first of them into a 401.
			$code = $e->getCode();

			return $this->error($id, is_int($code) === true && $code !== 0 ? $code : -32603, $e->getMessage());
		} catch (\Throwable $e) {
			// unexpected exception: the detail stays on this machine
			return $this->internal_error($e, $method, $id);
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
	 * @return array<string, mixed> $result What the server says about itself
	 */
	private function handle_initialize(): array {
		$config = $this->application->config;

		return [
			'protocolVersion' => $config->protocol_version,
			'capabilities' => [
				'tools' => [
					// The capability promises a notifications/tools/list_changed the
					// client can subscribe to. There is no channel to send one on — no
					// sessions, no streaming, nothing leaves this server except the
					// answer to a request — so the honest answer is false, and the
					// client is told in the instructions to list again.
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
	 * The application decides which tools this caller may see; this method does not
	 * filter, and must not start to.
	 *
	 * @access private
	 * @return array<string, mixed> $result The definitions of the tools
	 */
	private function handle_tools_list(): array {
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
	 * @param array<string, mixed> $params The params of the request
	 * @return array<string, mixed> $result The wrapped answer
	 * @throws \Skeleton\Application\Mcp\Jsonrpc\Exception When the tool is not named, not found, or the call is refused
	 */
	private function handle_tools_call(array $params): array {
		if (isset($params['name']) === false) {
			throw new \Skeleton\Application\Mcp\Jsonrpc\Exception('tool name not provided', -32602);
		}

		if (is_string($params['name']) === false || $params['name'] === '') {
			throw new \Skeleton\Application\Mcp\Jsonrpc\Exception(
				'Invalid params: name must be a non-empty string',
				-32602
			);
		}

		$name = $params['name'];
		$arguments = $params['arguments'] ?? [];

		if (is_array($arguments) === false) {
			throw new \Skeleton\Application\Mcp\Jsonrpc\Exception(
				'Invalid params: arguments must be an object',
				-32602
			);
		}

		$tool = $this->application->get_tool($name);

		try {
			$result = $tool->call($arguments);
		} catch (\Skeleton\Application\Mcp\Exception $e) {
			// A caller mistake is not a transport error: it is answered as a result the
			// model can read and act on. Jsonrpc\Exception is deliberately not caught
			// here — it belongs to the layer above, which turns it into an error.
			return [
				'content' => [
					[
						'type' => 'text',
						'text' => $e->getMessage(),
					],
				],
				'isError' => true,
			];
		}

		// the tool already reduced the answer once, for its own log line; the client is
		// answered from that same array rather than asking the object to build it again
		return $this->wrap_result($result, $name, $tool->get_reduced_info());
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
	 * A result that declares its own shape (a `DefinitionInterface`) arrives already
	 * reduced by `Tool::call()`, which checked it against its declaration on the way -
	 * payload keys, types and advertised `outputSchema` are the same list of fields, and
	 * nobody pays to build it twice. The `$info` argument is optional only so that a
	 * definition reduced outside a call (a probe, say) still gets the same treatment.
	 *
	 * @access private
	 * @param mixed $result The return value of execute()
	 * @param string $tool_name The tool that answered, for the log
	 * @param ?array<string, mixed> $info The answer already reduced by the tool, if it was
	 * @return array<string, mixed> $envelope The result to hand to the client
	 */
	private function wrap_result($result, string $tool_name, ?array $info = null): array {
		if ($result instanceof \Skeleton\Application\Mcp\DefinitionInterface) {
			$result = $info ?? \Skeleton\Application\Mcp\Reducer::reduce($result, $tool_name);
		}

		$encodable = true;

		if (is_string($result) === true) {
			$text = $result;
		} else {
			$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

			if ($encoded === false) {
				// Nothing about the answer can be written as JSON, and the caller is not
				// owed the reason — that is a bug in the tool. It is logged, the call
				// degrades to a sentence of text, and structuredContent is left out:
				// putting the same value in the envelope would only move the failure to
				// the point where the whole response is encoded.
				error_log('MCP: the result of tool ' . $tool_name . ' could not be encoded: ' . json_last_error_msg());

				$encodable = false;
				$text = 'The result of tool ' . $tool_name . ' could not be encoded';
			} else {
				$text = $encoded;
			}
		}

		$envelope = [
			'content' => [
				[
					'type' => 'text',
					'text' => $text,
				],
			],
			'isError' => false,
		];

		if (is_array($result) === true && array_is_list($result) === true) {
			error_log('MCP: list-shaped structuredContent dropped from the result of tool ' . $tool_name);
		} elseif ($encodable === true && (is_array($result) === true || is_object($result) === true)) {
			$envelope['structuredContent'] = $result;
		}

		return $envelope;
	}

	/**
	 * Wrap an error in a JSON-RPC error
	 *
	 * @access private
	 * @param mixed $id The id of the request that is answered
	 * @param int $code The JSON-RPC error code
	 * @param string $message What to tell the client
	 * @return array<string, mixed> $response The error to answer with
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

	/**
	 * Refuse a body, unless it is a notification
	 *
	 * A notification has no answer to give — that is what makes one — and JSON-RPC is
	 * emphatic about it: the server must not reply, not even to say the body was
	 * unreadable. A caller that sent no id is not waiting for anything, and an unexpected
	 * response to it is a second bug for the client to trip over rather than the first
	 * one explained.
	 *
	 * @access private
	 * @param mixed $id The id of the request, null when there is none
	 * @param bool $is_notification Whether the body carries no id member at all
	 * @param int $code The JSON-RPC error code
	 * @param string $message What to tell the client
	 * @return ?array<string, mixed> $response The error to answer with, null for a notification
	 */
	private function refuse(mixed $id, bool $is_notification, int $code, string $message): ?array {
		if ($is_notification === true) {
			return null;
		}

		return $this->error($id, $code, $message);
	}

	/**
	 * Answer a failure nobody planned for
	 *
	 * The message the exception carries is written for whoever maintains this server:
	 * a `TypeError` names a class, a method and a parameter, a database failure names a
	 * table, and an internal error is exactly the response that must not describe its
	 * own implementation to a caller. So the detail is logged, with the file and line it
	 * came from, and the client gets the standard phrase for `-32603`. What a caller can
	 * act on is a different exception: `Mcp\Exception` is answered as a readable
	 * `isError` result and `Jsonrpc\Exception` carries its own message, because both are
	 * said deliberately.
	 *
	 * @access private
	 * @param \Throwable $e The failure to report
	 * @param string $method The JSON-RPC method that failed
	 * @param mixed $id The id of the request that is answered
	 * @return array<string, mixed> $response The error to answer with
	 */
	private function internal_error(\Throwable $e, string $method, mixed $id): array {
		error_log(
			'MCP: ' . $method . ' failed: ' . get_class($e) . ': ' . $e->getMessage()
				. ' in ' . $e->getFile() . ':' . $e->getLine()
		);

		return $this->error($id, -32603, 'Internal error');
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
		} catch (\JsonException) {
			return null;
		}
	}
}
