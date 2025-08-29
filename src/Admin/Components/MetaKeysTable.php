<?php
declare(strict_types=1);

namespace BFR\Admin\Components;

/**
 * Class MetaKeysTable
 *
 * Responsibilities:
 * - Render the "Calculators" table (rows for each calculator).
 * - Delegate field rendering and saving to CalculatedMetaFieldInputs.
 * - Does NOT render the Save button (Editor does that).
 */
final class MetaKeysTable
{
    /** @var array<string,array<string,mixed>> */
    private array $registry;

    private CalculatedMetaFieldInputs $inputs;

    /**
     * @param array<string,array<string,mixed>> $registry Active calculators registry (merged config)
     * @param CalculatedMetaFieldInputs         $inputs   Field renderer/saver per calculator
     */
    public function __construct(array $registry, CalculatedMetaFieldInputs $inputs)
    {
        $this->registry = $registry;
        $this->inputs   = $inputs;
    }

    /**
     * Render the calculators table.
     * Columns: Calculator (name + slug + description), Target Meta Key, Input Meta Keys
     *
     * @return string HTML
     */
    public function render(): string
    {
        ob_start();

        echo '<h2>Calculators</h2>';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:30%">Calculator</th>';
        echo '<th style="width:30%">Target Meta Key</th>';
        echo '<th style="width:40%">Input Meta Keys</th>';
        echo '</tr></thead><tbody>';

        foreach ($this->registry as $slug => $cfg) {
            $slug     = (string)$slug;
            $name     = (string)($cfg['name'] ?? $slug);
            $desc     = (string)($cfg['description'] ?? '');
            $tmeta    = (string)($cfg['target_meta_key'] ?? '');
            $imeta    = array_map(static fn($v) => (string)$v, (array)($cfg['input_meta_keys'] ?? []));

            echo '<tr>';

            // Column 1: Name + slug + description
            echo '<td>';
            echo '<strong>'.esc_html($name).'</strong><br/><code>'.esc_html($slug).'</code>';
            if ($desc !== '') {
                echo '<p style="margin:6px 0 0;color:#555;">'.esc_html($desc).'</p>';
            }
            echo '</td>';

            // Column 2: Target Meta Key selector
            echo '<td>';
            echo $this->inputs->render_target_meta_key($slug, $tmeta, '');
            echo '<p class="description">Pick a known key or choose <em>Custom…</em> and type your own.</p>';
            echo '</td>';

            // Column 3: Input Meta Keys (multi)
            echo '<td>';
            echo $this->inputs->render_input_meta_keys($slug, $imeta, []);
            echo '<p class="description">Add as many input keys as needed. Each can be a known key or <em>Custom…</em>.</p>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        return (string)ob_get_clean();
    }

    /**
     * Save all calculators' posted values.
     * - Delegates per-calculator resolution to CalculatedMetaFieldInputs::save_for_slug().
     * - Adds global CPTs + relation into each override record.
     *
     * @param array<string,array<string,mixed>> $overrides     Existing overrides (will be updated inline)
     * @param string                            $target_cpt    Selected global target CPT
     * @param string                            $input_cpt     Selected global input CPT
     * @param string                            $relation_key  Global relation meta key
     * @return array<string,array<string,mixed>> Updated overrides
     */
    public function save_all(array $overrides, string $target_cpt, string $input_cpt, string $relation_key): array
    {
        foreach ($this->registry as $slug => $cfg) {
            $slug_key = sanitize_key((string)$slug);

            $resolved = $this->inputs->save_for_slug($slug_key, $cfg);

            $overrides[$slug_key] = array_merge($overrides[$slug_key] ?? [], [
                'target_cpt_id'     => $target_cpt,
                'target_meta_key'   => $resolved['target_meta_key'],
                'input_cpt_id'      => [$input_cpt],
                'input_meta_keys'   => $resolved['input_meta_keys'],
                'relation_meta_key' => $relation_key,
                // Preserve original name/description if present:
                'name'              => (string)($cfg['name'] ?? $slug_key),
                'description'       => (string)($cfg['description'] ?? ''),
            ]);
        }
        return $overrides;
    }
}