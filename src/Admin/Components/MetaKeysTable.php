<?php
declare(strict_types=1);

namespace BFR\Admin\Components;

/**
 * Class MetaKeysTable
 *
 * Responsibilities:
 * - Render the "Calculators" table (one row per calculator).
 * - Delegate field rendering/saving to CalculatedMetaFieldInputs.
 * - Does NOT render the Save button (the Editor owns it).
 *
 * Notes:
 * - No inline JS here. Dynamic behaviors (Add/Remove rows, "Custom…" toggle) are
 *   handled by DropdownArrayManager::ensureScripts(), which is called by
 *   CalculatedMetaFieldInputs when needed.
 */
final class MetaKeysTable
{
    /** @var array<string,array<string,mixed>> Merged/active calculators registry keyed by slug. */
    private array $registry;

    /** Field renderer/saver for a single calculator row. */
    private CalculatedMetaFieldInputs $inputs;

    /**
     * @param array<string,array<string,mixed>> $registry Active calculators registry (merged config).
     * @param CalculatedMetaFieldInputs         $inputs   Field renderer/saver per calculator.
     */
    public function __construct(array $registry, CalculatedMetaFieldInputs $inputs)
    {
        $this->registry = $registry;
        $this->inputs   = $inputs;
    }

    /**
     * Render the calculators table.
     * Columns:
     *  - Calculator (label + slug + description)
     *  - Target Meta Key (single select-with-custom)
     *  - Input Meta Keys (array of select-with-custom rows)
     *
     * @return string The HTML markup for the table.
     */
    public function render(): string
    {
        ob_start();

        echo '<h2>Calculators</h2>';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:30%">' . esc_html__('Calculator', 'bfr') . '</th>';
        echo '<th style="width:30%">' . esc_html__('Target Meta Key', 'bfr') . '</th>';
        echo '<th style="width:40%">' . esc_html__('Input Meta Keys', 'bfr') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($this->registry as $slug => $cfg) {
            $slug     = (string) $slug;
            $name     = (string) ($cfg['name'] ?? $slug);
            $desc     = (string) ($cfg['description'] ?? '');
            $tmeta    = (string) ($cfg['target_meta_key'] ?? '');
            $imeta    = array_map(static fn($v) => (string) $v, (array) ($cfg['input_meta_keys'] ?? []));

            echo '<tr>';

            // Column 1: Calculator label, slug, and description.
            echo '<td>';
            echo '<strong>' . esc_html($name) . '</strong><br/><code>' . esc_html($slug) . '</code>';
            if ($desc !== '') {
                echo '<p style="margin:6px 0 0;color:#555;">' . esc_html($desc) . '</p>';
            }
            echo '</td>';

            // Column 2: Target Meta Key (single select-with-custom).
            echo '<td>';
            echo $this->inputs->render_target_meta_key($slug, $tmeta, '');
            echo '<p class="description">' . esc_html__('Pick a known key or choose “Custom…” and type your own.', 'bfr') . '</p>';
            echo '</td>';

            // Column 3: Input Meta Keys (array dropdowns with Add/Remove controls).
            echo '<td>';
            echo $this->inputs->render_input_meta_keys($slug, $imeta, []);
            echo '<p class="description">' . esc_html__('Add as many input keys as needed. Each can be a known key or “Custom…”. You may also remove all rows to save an empty list.', 'bfr') . '</p>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        return (string) ob_get_clean();
    }

    /**
     * Save all calculators using posted form values.
     * - Delegates per-calculator resolution to CalculatedMetaFieldInputs::save_for_slug().
     * - Injects the global CPTs and relation key into each calculator override.
     *
     * @param array<string,array<string,mixed>> $overrides    Existing overrides array (will be updated and returned).
     * @param string                            $target_cpt   Globally selected target CPT slug.
     * @param string                            $input_cpt    Globally selected input CPT slug.
     * @param string                            $relation_key Global relation meta key (on input posts).
     * @return array<string,array<string,mixed>> Updated overrides ready to be persisted.
     */
    public function save_all(
        array $overrides,
        string $target_cpt,
        string $input_cpt,
        string $relation_key
    ): array {
        foreach ($this->registry as $slug => $cfg) {
            $slug_key = sanitize_key((string) $slug);

            // Resolve this calculator's fields from $_POST.
            $resolved = $this->inputs->save_for_slug($slug_key, $cfg);

            // Merge into overrides (preserving name/description if present).
            $overrides[$slug_key] = array_merge($overrides[$slug_key] ?? [], [
                'target_cpt_id'     => $target_cpt,
                'target_meta_key'   => $resolved['target_meta_key'],
                'input_cpt_id'      => [$input_cpt],
                'input_meta_keys'   => $resolved['input_meta_keys'],
                'relation_meta_key' => $relation_key,
                'name'              => (string) ($cfg['name'] ?? $slug_key),
                'description'       => (string) ($cfg['description'] ?? ''),
            ]);
        }

        return $overrides;
    }
}