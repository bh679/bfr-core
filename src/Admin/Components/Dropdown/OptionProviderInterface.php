<?php
declare(strict_types=1);

namespace BFR\Admin\Components\Dropdown;

/**
 * OptionProviderInterface
 * - Contract for any class that can provide value=>label pairs for a dropdown.
 */
interface OptionProviderInterface {
	/**
	 * Return an array of options for a dropdown.
	 * @param array $context	// Extra context (e.g., post_type) that a provider may need
	 * @return array<string,string>	// value => label pairs
	 */
	public function get_options(array $context): array;
}