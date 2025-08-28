<?php
declare(strict_types=1);

namespace BFR\Core;

use BFR\Infrastructure\WordPress\WPHooks;
use BFR\Infrastructure\WordPress\OptionRepository;
use BFR\Admin\AdminPanel;
use BFR\Admin\CalculatedMetaEditor;
use BFR\Infrastructure\WPCLI\Commands;

final class Loader
{
    /** @var array<string, array<string,mixed>> */
    private array $defaultRegistry;

    /** @var array<string, array<string,mixed>> */
    private array $activeRegistry;

    private OptionRepository $options;
    private WPHooks $hooks;
    private AdminPanel $admin;
    private CalculatedMetaEditor $editor;
    private Commands $cli;

    public function __construct(array $defaultRegistry)
    {
        $this->defaultRegistry = $defaultRegistry;
        $this->options = new OptionRepository();
        $this->activeRegistry = $this->mergeRegistryWithOptions($defaultRegistry);
        $this->hooks = new WPHooks($this->activeRegistry);
        $this->admin = new AdminPanel($this->activeRegistry, $this->options);
        $this->editor = new CalculatedMetaEditor($this->activeRegistry, $this->options);
        $this->cli = new Commands($this->activeRegistry);
    }

    public function register(): void
    {
        // WP Hooks
        $this->hooks->register();

        // Admin UI
        if (is_admin()) {
            $this->admin->register();
            $this->editor->register();
        }

        // WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            $this->cli->register();
        }
    }

    /**
     * Overlay defaults with saved options (editor results).
     *
     * @param array<string, array<string,mixed>> $defaults
     * @return array<string, array<string,mixed>>
     */
    private function mergeRegistryWithOptions(array $defaults): array
    {
        $saved = $this->options->get_registry_overrides();
        if (! is_array($saved) || empty($saved)) {
            return $defaults;
        }
        // Shallow merge by calculator slug
        foreach ($saved as $slug => $override) {
            if (isset($defaults[$slug]) && is_array($override)) {
                $defaults[$slug] = array_merge($defaults[$slug], $override);
            } elseif (is_array($override)) {
                $defaults[$slug] = $override;
            }
        }
        return $defaults;
    }
}
