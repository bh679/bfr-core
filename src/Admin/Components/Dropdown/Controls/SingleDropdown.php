<?php
declare(strict_types=1);	// Enforce strict typing

namespace BFR\Admin\Components\Dropdown\Controls;	// Controls namespace

use BFR\Admin\Components\Dropdown\OptionProviderInterface;			// Import interface
use BFR\Admin\Components\Dropdown\Rendering\SelectRenderer;			// Import renderer

/**
 * SingleDropdown
 *
 * Composition: OptionProviderInterface + SelectRenderer.
 * Supports an optional "custom" input and a hidden "mode" field.
 * You can pass an "onchange" attribute to hook behaviors (e.g., refresh preview posts).
 */
class SingleDropdown
{
	/** @var OptionProviderInterface */
	protected OptionProviderInterface $provider;		// Supplies options

	/** @var SelectRenderer */
	protected SelectRenderer $renderer;					// Renders the select UI

	/**
	 * @param OptionProviderInterface $provider	Options backend
	 * @param SelectRenderer          $renderer	HTML renderer
	 */
	public function __construct(OptionProviderInterface $provider, SelectRenderer $renderer)
	{
		$this->provider = $provider;					// Store provider
		$this->renderer = $renderer;					// Store renderer
	}

	/**
	 * Render a single dropdown (optionally with custom & mode).
	 *
	 * @param string                 $nameSelect		Name for <select>
	 * @param string|null            $currentValue	Selected value or null
	 * @param array<string,mixed>    $context			Context for provider (e.g., ['cpt'=>'destinations'])
	 * @param array<string,string>   $attrsSelect		Extra attributes for <select> (e.g., ['id'=>'x','onchange'=>'...'])
	 * @param string|null            $nameCustom		Name for custom text input (null = disable custom)
	 * @param string|null            $currentCustom	Current custom text (if any)
	 * @param string|null            $nameMode			Name for hidden mode input (null = disable)
	 * @return string									HTML
	 */
	public function render(
		string $nameSelect,
		?string $currentValue,
		array $context = [],
		array $attrsSelect = [],
		?string $nameCustom = null,
		?string $currentCustom = null,
		?string $nameMode = null
	): string {
		$options = $this->provider->get_options($context);			// Get option map
		return $this->renderer->render(								// Delegate render
			$nameSelect,											// Select name
			$currentValue,											// Current selection
			$options,												// Options
			$attrsSelect,											// Select attributes
			$nameCustom,											// Custom input name
			$currentCustom,											// Custom text
			$nameMode												// Mode name
		);
	}
}