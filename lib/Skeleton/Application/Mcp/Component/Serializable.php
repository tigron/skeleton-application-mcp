<?php

declare(strict_types=1);

/**
 * Component interface, require minimal serialisation
 */
namespace Skeleton\Application\Mcp\Component;

interface Serializable {
	/**
	 * to_array
	 *
	 * @access public
	 * @return array
	 */
	public function to_array(): array;
}
