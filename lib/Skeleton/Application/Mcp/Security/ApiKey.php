<?php

declare(strict_types=1);

/**
 * Security ApiKey
 *
 * Validates the request against the keys of the configuration.
 *
 * A tool opts in with @security \Skeleton\Application\Mcp\Security\ApiKey on the
 * docblock of its execute(). The key is accepted in either header, because not
 * every client can add a custom one, and compared with hash_equals() one key at a
 * time, so the running time does not say which of them matched.
 *
 * An empty key list fails closed: a server that was handed no key accepts nobody,
 * which makes a deploy that forgot its configuration unreachable rather than open.
 *
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp\Security;

class ApiKey extends \Skeleton\Application\Mcp\Security {
	/**
	 * JSON-RPC error code returned when the request is not authorized
	 *
	 * @access public
	 */
	public const ERROR_CODE_UNAUTHORIZED = -32001;

	/**
	 * The configuration property that holds the accepted keys
	 *
	 * @access public
	 */
	public const CONFIG_KEY = 'mcp_api_keys';

	/**
	 * The configuration property that holds the realm to name in the challenge
	 *
	 * Only the realm, deliberately: the shape of the challenge — which scheme, and
	 * whether an `error` parameter belongs in it — is this class's business, and an
	 * application that hand-wrote the whole string would get the parameter wrong
	 * exactly as often as it got it right.
	 *
	 * @access public
	 */
	public const CONFIG_REALM_KEY = 'mcp_auth_challenge_realm';

	/**
	 * The realm used when the configuration does not name one
	 *
	 * @access public
	 */
	public const REALM_DEFAULT = 'MCP';

	/**
	 * The scheme this handler challenges for
	 *
	 * `ApiKey`, not `Bearer`. The credential is a static key, there is no
	 * authorization server behind an MCP application, and `Bearer` is not a
	 * decoration: a spec-compliant client that sees it starts RFC 9728 discovery and
	 * goes looking for a `/.well-known/oauth-protected-resource` document and a token
	 * endpoint that do not exist here. Challenging for a scheme the server cannot
	 * satisfy is worse than challenging for one a client has to be told about,
	 * because it fails later and less legibly. The scheme is what the configuration
	 * cannot change; the realm is.
	 *
	 * @access public
	 */
	public const HTTP_SCHEME = 'ApiKey';

	/**
	 * The HTTP status a missing or wrong key deserves
	 *
	 * @access public
	 */
	public const HTTP_STATUS_UNAUTHORIZED = 401;

	/**
	 * Handle the security check
	 *
	 * @access public
	 * @throws \Skeleton\Application\Mcp\Jsonrpc\Exception When no valid API key was provided
	 */
	public function handle(): void {
		$api_keys = $this->get_api_keys();

		if (count($api_keys) === 0) {
			throw new \Skeleton\Application\Mcp\Jsonrpc\Exception(
				$this->get_unconfigured_message(),
				$this->get_error_code()
			);
		}

		$api_key = $this->get_provided_key();

		if ($api_key === null) {
			throw new \Skeleton\Application\Mcp\Jsonrpc\Exception(
				'No API key provided, send it as "Authorization: Bearer <key>" or "X-Api-Key: <key>"',
				$this->get_error_code()
			);
		}

		$digest = $this->get_digest($api_key);

		foreach ($api_keys as $configured_api_key) {
			if (hash_equals((string)$configured_api_key, $digest) === true) {
				return;
			}
		}

		throw new \Skeleton\Application\Mcp\Jsonrpc\Exception('Invalid API key', $this->get_error_code());
	}

	/**
	 * Tell whether the request carries a key of any kind
	 *
	 * A header that is present but empty is the same as no header:
	 * `get_provided_key()` says so, and it is the one place that knows how this
	 * credential is spelled.
	 *
	 * @access public
	 * @return bool $presented Whether an Authorization or X-Api-Key header was sent
	 */
	public function presents_credential(): bool {
		return $this->get_provided_key() !== null;
	}

	/**
	 * A refusal from this handler is an authentication failure
	 *
	 * @access public
	 * @return ?int $refusal_code
	 */
	public function get_refusal_code(): ?int {
		return $this->get_error_code();
	}

	/**
	 * Which is why the transport answers it with a 401 rather than a 200
	 *
	 * @access public
	 * @return int $http_status
	 */
	public function get_http_status(): int {
		return self::HTTP_STATUS_UNAUTHORIZED;
	}

	/**
	 * The challenge that goes with that 401
	 *
	 * Two refusals, two challenges, per RFC 6750 §3.1: no credential offered gets
	 * `ApiKey realm="…"`, an offered one that does not check out gets
	 * `…, error="invalid_token"`. Nothing here carries `error="invalid_request"`,
	 * which describes a malformed token request in a flow this server does not have.
	 *
	 * @access public
	 * @param bool $presented_credential Whether the caller offered a credential
	 * @return ?string $challenge
	 */
	public function get_http_challenge(bool $presented_credential): ?string {
		$realm = \Skeleton\Core\Config::get()->{self::CONFIG_REALM_KEY} ?? null;

		if (is_string($realm) === false || $realm === '') {
			$realm = self::REALM_DEFAULT;
		}

		$challenge = self::HTTP_SCHEME . ' realm="' . $realm . '"';

		if ($presented_credential === true) {
			$challenge .= ', error="invalid_token"';
		}

		return $challenge;
	}

	/**
	 * Bring a presented credential into the form it is stored in
	 *
	 * The identity by default: `mcp_api_keys` holds the keys themselves, so what the
	 * client sent is compared as it arrived. A subclass that stores its credentials in
	 * another form says so here rather than taking over the header reading too — a token
	 * kept as a sha256 overrides this method and nothing else, which keeps the precedence
	 * between the two headers, the trimming, and the fail-closed empty list in one
	 * reviewed place.
	 *
	 * @access protected
	 * @param string $provided The credential as the client sent it
	 * @return string $digest The value the stored list holds
	 */
	protected function get_digest(string $provided): string {
		return $provided;
	}

	/**
	 * What to say when this handler has nothing to compare against
	 *
	 * An empty list is a misconfigured server, not a caller's mistake, and the sentence
	 * should name the thing that is missing in the words the caller would use for it — a
	 * token handler talks about tokens, a key handler about keys.
	 *
	 * @access protected
	 * @return string $message
	 */
	protected function get_unconfigured_message(): string {
		return 'No MCP API key is configured on this server';
	}

	/**
	 * Get the keys a client may use
	 *
	 * Override this to read them somewhere else — a table, when every client has
	 * its own key and the calls have to be attributed to one.
	 *
	 * @access protected
	 * @return array<int, string> $api_keys The accepted keys
	 */
	protected function get_api_keys(): array {
		$api_keys = \Skeleton\Core\Config::get()->{self::CONFIG_KEY} ?? [];

		return is_array($api_keys) === true ? $api_keys : [];
	}

	/**
	 * Get the key the client provided, if any
	 *
	 * @access protected
	 * @return ?string $api_key
	 */
	protected function get_provided_key(): ?string {
		$authorization = $this->get_header('authorization');

		if ($authorization !== null) {
			// An Authorization header IS the credential. A second header never
			// replaces one that is present: a gateway or test harness injecting
			// X-Api-Key would otherwise silently swap out who is calling — and a
			// stale X-Api-Key would fail a request whose Bearer token is valid.
			return $this->get_bearer_token($authorization);
		}

		// no Authorization at all: fall back to the header some clients can send
		return $this->get_header('x-api-key');
	}

	/**
	 * Get the error code of a refusal
	 *
	 * @access protected
	 * @return int $error_code
	 */
	protected function get_error_code(): int {
		return self::ERROR_CODE_UNAUTHORIZED;
	}

	/**
	 * Get the value of a request header
	 *
	 * @access private
	 * @param string $name Name of the header, as in HTTP (case insensitive)
	 * @return ?string $value The value of the header, null when it is absent or empty
	 */
	private function get_header(string $name): ?string {
		$name = strtolower($name);
		$server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
		$headers = getallheaders();

		if (is_array($headers) === false) {
			$headers = [];
		}

		$headers = array_change_key_case($headers, CASE_LOWER);

		// Apache does not always hand the Authorization header over to PHP, so
		// prefer what the webserver put in $_SERVER
		$value = $_SERVER[$server_key] ?? $headers[$name] ?? null;

		if (is_string($value) === false) {
			return null;
		}

		$value = trim($value);

		return $value === '' ? null : $value;
	}

	/**
	 * Get the token out of an "Authorization: Bearer <token>" header value
	 *
	 * @access private
	 * @param string $authorization Value of the Authorization header
	 * @return ?string $token The token, null when the header does not carry one
	 */
	private function get_bearer_token(string $authorization): ?string {
		if (preg_match('/^Bearer[ \t]+(\S+)$/i', $authorization, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}
}
