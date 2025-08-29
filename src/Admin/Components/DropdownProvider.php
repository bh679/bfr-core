<?php 
declare(strict_types=1);

namespace BFR\Admin\Components;

/**
 * Class DropdownProvider
 *
 * Responsibilities:
 * - Provide option lists for CPTs and meta keys (WordPress first; optionally JetEngine if present).
 * - Render a generic <select> with a "Custom…" option that reveals a text input,
 *   plus a hidden "mode" input to make saves reliable.
 *
 * This class knows nothing about the BFR meta calculators/classes;
 * it's a generic dropdown provider.
 */
final class DropdownProvider
{
    /**
     * Return a map of post type slug => human label.
     * - Primary: WordPress public post types.
     * - If JetEngine is active, merge in its CPTs (without overriding existing labels).
     *
     * @return array<string,string> slug => label
     */
    public function get_cpt_options(): array
    {
        $options = [];

        // WordPress public CPTs
        $types = get_post_types(['public' => true], 'objects');
        foreach ($types as $slug => $obj) {
            $label = $obj->labels->singular_name ?: $obj->label ?: $slug;
            $options[(string)$slug] = (string)$label;
        }

        // JetEngine CPTs (if available)
        if (function_exists('jet_engine')) {
            try {
                $jet = \jet_engine();
                if (isset($jet->post_types)) {
                    $items = $jet->post_types->get_items();
                    if (is_array($items)) {
                        foreach ($items as $item) {
                            $slug  = (string)($item['slug'] ?? '');
                            $label = (string)($item['labels']['singular_name'] ?? $slug);
                            if ($slug !== '' && ! isset($options[$slug])) {
                                $options[$slug] = $label;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Silently ignore JetEngine errors to avoid breaking admin.
            }
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    /**
     * Discover distinct meta keys used by posts of a given post type.
     * - Uses a DB join of posts and postmeta.
     * - Filters out leading underscore keys (internal), can be adjusted.
     *
     * @param string $post_type CPT slug
     * @param int    $limit     Max number of keys to return
     * @return array<string,string> meta_key => meta_key
     */
    public function discover_meta_keys_for_post_type(string $post_type, int $limit = 200): array
    {
        global $wpdb;

        $post_type = sanitize_key($post_type);
        if ($post_type === '') {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "
            SELECT DISTINCT pm.meta_key
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = %s
              AND pm.meta_key NOT LIKE '\_%'
            ORDER BY pm.meta_key ASC
            LIMIT %d
            ",
            $post_type,
            $limit
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $keys = $wpdb->get_col($sql);
        if (! is_array($keys)) {
            return [];
        }

        $out = [];
        foreach ($keys as $k) {
            $k = (string)$k;
            $out[$k] = $k;
        }
        return $out;
    }

    /**
     * Render a <select> with provided options and an extra "Custom…" option.
     * Also emits a sibling text input for custom, and a hidden input that records the mode:
     * - "value": user picked a predefined option
     * - "custom": user entered a custom value
     *
     * @param string               $select_name POST name for the <select>
     * @param string               $custom_name POST name for the custom <input type="text">
     * @param string               $mode_name   POST name for the hidden <input> storing 'value'|'custom'
     * @param array<string,string> $options     value => label
     * @param string               $selected    selected value (use '__custom__' to show custom)
     * @param string               $custom_val  text to prefill in the custom box
     * @param string|null          $id          optional id for the <select>
     * @return string HTML
     */
    public function render_select_with_custom(
        string $select_name,
        string $custom_name,
        string $mode_name,
        array $options,
        string $selected = '',
        string $custom_val = '',
        ?string $id = null
    ): string {
        $id = $id ?: 'fld_' . md5($select_name . wp_rand());

        // Append synthetic custom choice
        $options_with_custom = $options + ['__custom__' => 'Custom…'];

        $opts_html = '';
        foreach ($options_with_custom as $val => $label) {
            $opts_html .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr((string)$val),
                selected($selected, (string)$val, false),
                esc_html((string)$label)
            );
        }

        $is_custom   = ($selected === '__custom__');
        $custom_style = $is_custom ? '' : 'style="display:none"';
        $mode_value   = $is_custom ? 'custom' : 'value';

        $html  = '<span class="bfr-select-with-custom" ';
        $html .= 'data-select-id="'.esc_attr($id).'" ';
        $html .= 'data-mode-name="'.esc_attr($mode_name).'">';
        $html .= sprintf(
            '<select id="%s" name="%s" class="regular-text">',
            esc_attr($id),
            esc_attr($select_name)
        );
        $html .= $opts_html . '</select> ';
        $html .= sprintf(
            '<input type="text" name="%s" value="%s" class="regular-text" %s/>',
            esc_attr($custom_name),
            esc_attr($custom_val),
            $custom_style
        );
        $html .= sprintf(
            '<input type="hidden" name="%s" value="%s" />',
            esc_attr($mode_name),
            esc_attr($mode_value)
        );
        $html .= '</span>';

        return $html;
    }
}