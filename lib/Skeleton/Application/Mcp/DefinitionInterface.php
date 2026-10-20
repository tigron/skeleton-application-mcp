<?php

declare(strict_types=1);

/**
 * Definition interface
 *
 * What the package asks of the shape a tool returns. A result declares its fields as a
 * map of keywords; this interface is how `tools/list` recognises that declaration (and
 * `tools/call` that it has to reduce one), in the same way `ComponentInterface` is how
 * the OpenAPI application recognises a schema — see `Skeleton\Application\Api`.
 *
 * Implement it and use the `Definition` trait: the map you declare is the
 * `outputSchema` the client is shown, and the answer you return is held to that same map
 * before it leaves the server — so nothing here is a second place to keep a field in
 * step with the first.
 *
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp;

interface DefinitionInterface {
	/**
	 * Declare the fields of this result
	 *
	 * The contract, in the author's words rather than the compiler's: this map decides
	 * both the advertised `outputSchema` and the keys of the payload, and it is required so
	 * that a result cannot answer with whatever its properties happened to end up holding.
	 * An empty map is a bug rather than a shape, and `get_mcp_definition_declaration()`
	 * refuses it by name: an answer that publishes no field has nothing to advertise
	 * either.
	 *
	 * @access public
	 * @return array<string, array<string, mixed>> $properties Field name => its keywords
	 */
	public function get_mcp_definition_properties(): array;

	/**
	 * Get the JSON schema this result's structuredContent conforms to
	 *
	 * @access public
	 * @return array<string, mixed> $schema The outputSchema to advertise
	 */
	public function get_mcp_definition_schema(): array;

	/**
	 * Get the structuredContent of this result
	 *
	 * @access public
	 * @return array<string, mixed> $info The answer to hand to the client
	 */
	public function get_mcp_definition_info(): array;
}
