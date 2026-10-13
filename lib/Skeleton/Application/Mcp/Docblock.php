<?php

declare(strict_types=1);

/**
 * Docblock
 *
 * One place that reads a docblock.
 *
 * The schemas this package advertises are generated from docblocks: the description of
 * a tool from its class docblock, the inputSchema from the `@param` tags of execute(),
 * the outputSchema from its `@return`, the security handlers from its `@security`, and
 * the description of every field of a result from the docblock of that property. Written
 * out at each of those places, the same five steps appear again and again — reflect,
 * getDocComment(), build the factory, create(), decide what to do when the parser
 * refuses. Six copies of a parse means six ways to disagree about what a malformed
 * docblock means, and one of them (the security tags) historically let the exception
 * escape while the others swallowed it.
 *
 * So it is done once here. The rule for the two readers is the difference that matters:
 * the tags that only *describe* (summary, param, return) come from the parser and yield
 * nothing when it cannot read the text, because a description is allowed to be missing;
 * the tags the server *acts* on (`@security`, `@var`) are read from the raw text with a
 * regular expression, which cannot fail and therefore cannot silently turn a guarded
 * tool into an open one.
 *
 * @author Roan Buysse <roan@tigron.be>
 */

namespace Skeleton\Application\Mcp;

class Docblock {
	/**
	 * The docblock as it was written, tags and all
	 *
	 * @access private
	 * @var string $raw
	 */
	private string $raw = '';

	/**
	 * The parsed docblock, null when there is nothing to parse or the parser refused it
	 *
	 * @access private
	 * @var ?\phpDocumentor\Reflection\DocBlock $parsed
	 */
	private ?\phpDocumentor\Reflection\DocBlock $parsed = null;

	/**
	 * Constructor
	 *
	 * Private on purpose: the only way to one of these is read(), which is where the
	 * parser is called and where its failure is decided.
	 *
	 * @access private
	 * @param string $raw The docblock as it was written
	 * @param ?\phpDocumentor\Reflection\DocBlock $parsed The parsed docblock, if it parsed
	 */
	private function __construct(string $raw, ?\phpDocumentor\Reflection\DocBlock $parsed) {
		$this->raw = $raw;
		$this->parsed = $parsed;
	}

	/**
	 * Read a docblock, as reflection hands it over
	 *
	 * Returns null for a class, method or property with no docblock at all, so the
	 * caller can tell "nothing was written" from "something was written and it says
	 * nothing useful", which the two differ in more than cosmetically: a tool whose
	 * execute() has no docblock has no parameters described, while a tool whose
	 * docblock is unreadable still has its own @param text to fall back on.
	 *
	 * @access public
	 * @param string|false $docblock What getDocComment() returned, false when there is none
	 * @return ?Docblock $docblock The reader, null when there is nothing to read
	 */
	public static function read(string|false $docblock): ?Docblock {
		if ($docblock === false || trim($docblock) === '') {
			return null;
		}

		try {
			$parsed = \phpDocumentor\Reflection\DocBlockFactory::createInstance()->create($docblock);
		} catch (\Throwable $e) {
			// A docblock PHP cannot read is a docblock that says nothing: the raw text
			// below is still there for the tags the server acts on.
			$parsed = null;
		}

		return new self($docblock, $parsed);
	}

	/**
	 * Get the summary and the description, as a client should read them
	 *
	 * This is the text a model sees when it decides whether to call a tool, so it is
	 * the class docblock of that tool, in full: summary, blank line, body.
	 *
	 * @access public
	 * @return string $description The whole description, empty when there is nothing to say
	 */
	public function get_description(): string {
		if ($this->parsed === null) {
			return '';
		}

		return trim($this->parsed->getSummary() . "\n\n" . $this->parsed->getDescription()->render());
	}

	/**
	 * Get the first prose line of the docblock
	 *
	 * A property docblock usually says one thing about the property and then lists its
	 * tags; that first line is what the client is shown for the field. It is read from
	 * the raw text rather than the parser because a @var of an unusual shape must not
	 * cost the field its description.
	 *
	 * @access public
	 * @return ?string $summary The line, null when the docblock is only tags
	 */
	public function get_first_line(): ?string {
		foreach (preg_split('/\R/', $this->raw) as $line) {
			$line = trim($line, " \t*/");

			// the summary is the first real line: what comes after a tag is not
			// prose anymore
			if ($line === '' || str_starts_with($line, '@') === true) {
				continue;
			}

			return $line;
		}

		return null;
	}

	/**
	 * Get the parameters execute() takes, as their docblock describes them
	 *
	 * @access public
	 * @return array<string, array<string, ?string>> $params Name => type and description
	 */
	public function get_params(): array {
		if ($this->parsed === null) {
			return [];
		}

		$params = [];

		foreach ($this->parsed->getTagsByName('param') as $tag) {
			$type = $tag->getType() === null ? null : (string)$tag->getType();
			$description = $tag->getDescription() === null ? null : $tag->getDescription()->render();

			$params[$tag->getVariableName()] = [
				'type' => $type,
				'description' => $description,
			];
		}

		return $params;
	}

	/**
	 * Get the class execute() says it returns
	 *
	 * The `@return` of execute() is what the outputSchema is drawn from: when it names a
	 * class that declares its own shape, that shape is advertised, and the author of the
	 * tool has described the answer exactly once (§ Component\Definition).
	 *
	 * @access public
	 * @return ?string $type The class as the docblock spells it, null when none is named
	 */
	public function get_return_type(): ?string {
		if ($this->parsed === null) {
			return null;
		}

		foreach ($this->parsed->getTagsByName('return') as $tag) {
			$type = $tag->getType();

			if ($type !== null && trim((string)$type) !== '') {
				return trim((string)$type);
			}
		}

		return null;
	}

	/**
	 * Get every line one tag is written on
	 *
	 * Read from the raw text, on purpose. A tag the server acts on must never be lost
	 * because a parser had trouble elsewhere in the block: an `@security` that failed to
	 * parse would otherwise run a guarded tool unguarded, which is the one mistake this
	 * package must not be able to make.
	 *
	 * @access public
	 * @param string $tag The tag to look for, without its @
	 * @return array<int, string> $lines What each occurrence says, one per line
	 */
	public function get_tag_lines(string $tag): array {
		$lines = [];

		if (preg_match_all('/@' . $tag . '\s+([^\r\n]+)/', $this->raw, $matches) > 0) {
			foreach ($matches[1] as $line) {
				$line = trim($line);

				if ($line !== '') {
					$lines[] = $line;
				}
			}
		}

		return $lines;
	}

	/**
	 * Get what one tag says
	 *
	 * @access public
	 * @param string $tag The tag to look for, without its @
	 * @return string $value What the first occurrence says, empty when the tag is absent
	 */
	public function get_tag(string $tag): string {
		$lines = $this->get_tag_lines($tag);

		return $lines[0] ?? '';
	}
}
