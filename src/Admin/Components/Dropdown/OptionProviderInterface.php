<?php
declare(strict_types=1);	// Enforce strict typing

namespace BFR\Admin\Components\Dropdown;	// Namespace for dropdown components

/**
 * OptionProviderInterface
 *
 * A tiny interface that supplies "value => label" pairs for a dropdown.
 */
interface OptionProviderInterface
{
	/**
	 * Produce the options for a dropdown.
	 *
	 * @param array<string,mixed> $context	Optional context (e.g., ['cpt' => 'destinations'])
	 * @return array<string,string>			Map of option value => label
	 */
	public function get_options(array $context = []): array;	// Contract for providers
}