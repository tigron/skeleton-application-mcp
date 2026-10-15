<?php

declare(strict_types=1);

/**
 * Security
 *
 * What a tool declares with `@security` and the application runs before execute().
 *
 * One class, two kinds of handler, and the difference is `is_authenticating()`: a
 * handler that proves who is calling, and a handler that decides whether that caller may
 * do this thing. The two are combined differently — the first as an OR, the second as an
 * AND — which is why the question is asked of the handler rather than assumed by the
 * application. A handler never inspects a credential on the application's behalf: only
 * the handler knows what its own credential looks like, so only the handler can say what
 * a refusal of it means to the transport.
 *
 * @author Gerry Demaret <gerry@tigron.be>
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp;

abstract class Security {
	/**
	 * Handle the security check, throw exception on failure or nothing if it
	 * is all good
	 *
	 * @access public
	 * @throws \Skeleton\Application\Mcp\Jsonrpc\Exception When the caller is refused
	 */
	abstract public function handle(): void;

	/**
	 * Tell whether this handler proves who the caller is
	 *
	 * Two kinds of handler share this class, and they are not combined the same way.
	 * An **authenticating** handler answers "who is calling": a server that offers a
	 * static key *and* a bearer token expects a caller to satisfy **at least one** of
	 * them, because two ways to prove who you are are alternatives, not a conjunction.
	 * An **authorizing** handler answers "may this caller do it", and every one of
	 * those a tool declares has to agree.
	 *
	 * The default is true, so a handler written before the distinction existed keeps
	 * the behaviour it had — and so does a server with exactly one credential kind,
	 * where the OR and the AND are the same question.
	 *
	 * @access public
	 * @return bool $authenticating Whether this handler is a way to authenticate
	 */
	public function is_authenticating(): bool {
		return true;
	}

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
