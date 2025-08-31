<?php
declare(strict_types=1);

namespace BFR\Admin\Components\Dropdown\Providers;

use BFR\Admin\Components\Dropdown\OptionProviderInterface;

/**
 * ClassOptionsProvider
 * - Returns available calculator class names from your registry or map.
 */
final class ClassOptionsProvider implements OptionProviderInterface {
	/** @var array<string,string> */
	private array $map;

	/**
	 * @param array<string,string> $class_map	// value => label (e.g., FQCN => 'Max Depth')
	 */
	public function __construct(array $class_map) {
		// Save the map injected by caller.
		$this->map = $class_map;
	}

	/**
	 * @inheritDoc
	 */
	public function get_options(array $context): array {
		// Return injected map unchanged.
		return $this->map;
	}
}