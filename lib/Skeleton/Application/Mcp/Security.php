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

	/**
	 * Tell whether the request carries a credential this handler can read
	 *
	 * This is what separates two callers an application must not put in one group.
	 * One that never meant to authenticate: it is a visitor, and it gets whatever
	 * this class leaves open. One that tried with a credential that does not check
	 * out: it has a broken configuration and is entitled to be told so, on every
	 * method, and to nothing else.
	 *
	 * The default is true — a handler that cannot tell is assumed to have been
	 * offered something, so an unknown credential type is refused rather than
	 * quietly treated as a visitor.
	 *
	 * @access public
	 * @return bool $presented Whether the caller attempted to authenticate
	 */
	public function presents_credential(): bool {
		return true;
	}

	/**
	 * The JSON-RPC code this handler throws when it refuses a request
	 *
	 * The application needs to recognise its own refusals in a response in order
	 * to give them the HTTP status they deserve. A handler that answers null
	 * declines to take part in that mapping.
	 *
	 * @access public
	 * @return ?int $refusal_code
	 */
	public function get_refusal_code(): ?int {
		return null;
	}

	/**
	 * The HTTP status the transport owes one of this handler's refusals
	 *
	 * @access public
	 * @return int $http_status
	 */
	public function get_http_status(): int {
		return 200;
	}

	/**
	 * The WWW-Authenticate value to pair with that status, if any
	 *
	 * The argument is the only thing that distinguishes the two refusals, and
	 * RFC 6750 asks a server to say which one it means: a request that carried no
	 * credential at all gets a bare challenge, because there is no error to report
	 * — the caller simply has not authenticated yet. A request that carried one and
	 * was refused gets `error="invalid_token"`. `error="invalid_request"` belongs to
	 * neither: it describes a malformed *token* request, which is a step in an OAuth
	 * flow this server does not have.
	 *
	 * @access public
	 * @param bool $presented_credential Whether the caller offered a credential
	 * @return ?string $challenge
	 */
	public function get_http_challenge(bool $presented_credential): ?string {
		return null;
	}
}
