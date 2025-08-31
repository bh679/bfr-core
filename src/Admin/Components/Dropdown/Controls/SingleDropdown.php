<?php
declare(strict_types=1);

namespace BFR\Admin\Components\Dropdown\Controls;

use BFR\Admin\Components\Dropdown\OptionProviderInterface;
use BFR\Admin\Components\Dropdown\Rendering\SelectRenderer;

/**
 * SingleDropdown
 * - A single-value dropdown control that uses a provider + shared renderer.
 */
final class SingleDropdown {
	/** @var OptionProviderInterface */
	private OptionProviderInterface $provider;
	/** @var SelectRenderer */
	private SelectRenderer $renderer;

	/**
	 * @param OptionProviderInterface $provider	// Supplies options (CPTs, Meta Keys, etc.)
	 * @param SelectRenderer $renderer		// Shared renderer for consistent HTML
	 */
	public function __construct(OptionProviderInterface $provider, SelectRenderer $renderer) {
		// Save provider instance.
		$this->provider = $provider;
		// Save renderer instance.
		$this->renderer = $renderer;
	}

	/**
	 * Render a single dropdown control.
	 * @param string $name		// Name attribute for <select>
	 * @param string|null $value	// Current saved value
	 * @param array $context		// Context for provider (e.g., ['post_type'=>'destinations'])
	 * @param array $args		// Renderer args (id,class,placeholder,show_preview,…)
	 * @return string			// HTML output
	 */
	public function render(string $name, ?string $value, array $context, array $args = []): string {
		// Ask provider for options based on context.
		$options = $this->provider->get_options($context);
		// Delegate to shared renderer for HTML.
		return $this->renderer->render($name, $options, $value, $args);
	}
}