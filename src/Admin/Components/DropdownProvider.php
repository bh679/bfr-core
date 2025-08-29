<?php
declare(strict_types=1);

namespace BFR\Admin\Components;

/**
 * DropdownProvider
 *
 * Small helper responsible for producing option lists and rendering
 * a "select + Custom" input group. It DOES NOT render labels/descriptions.
 *
 * Usage:
 *  - render_post_type_select_with_custom('target_cpt_id', 'target_cpt_id_custom', 'target_cpt_id_mode', $selected, $customPrefill)
 *  - render_select_with_custom($name, $customName, $modeName, $selected, $customPrefill, $options)
 *
 * Security: outputs are escaped; JS is injected once per page load.
 */
final class DropdownProvider
{
    /** Ensures our inline script is output only once per request. */
    private static bool $scriptPrinted = false;

    /**
     * Return a slug => label array of public post types.
     *
     * @return array<string,string>
     */
    public function get_post_type_options(): array
    {
        $out = [];

        $objects = get_post_types(['public' => true], 'objects');
        foreach ($objects as $slug => $obj) {
            // Build a nice label: "Destinations (destinations)"
            $label = sprintf('%s (%s)', $obj->labels->singular_name ?? $slug, $slug);
            $out[$slug] = $label;
        }

        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * Best-effort list of meta keys for a given post type.
     * Tries core-registered meta first, then allows external providers via a filter.
     *
     * @param string $postType
     * @return array<string,string> meta_key => label
     */
    public function get_meta_key_options(string $postType): array
    {
        $options = [];

        // 1) Core-registered meta (if available)
        if (function_exists('get_registered_meta_keys')) {
            $registered = get_registered_meta_keys('post', $postType);
            if (is_array($registered)) {
                foreach ($registered as $key => $args) {
                    if (is_string($key) && $key !== '') {
                        $options[$key] = $key;
                    }
                }
            }
        }

        /**
         * 2) Allow external providers (e.g., JetEngine) to contribute keys.
         * Hook with:
         *   add_filter('bfr_dropdown_meta_keys', function($keys, $postType) {
         *       // return array_merge($keys, [...]);
         *       return $keys;
         *   }, 10, 2);
         */
        $options = apply_filters('bfr_dropdown_meta_keys', $options, $postType);

        // 3) Final tidy
        ksort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    /**
     * Render a "select + Custom" input group. No label/description.
     *
     * Structure:
     *  <select name="$name">[opts..., "__custom__"]</select>
     *  <input name="$customName" ... />  (hidden unless Custom is selected)
     *  <input type="hidden" name="$modeName" value="value|custom" />
     *
     * @param string               $name         Name for the <select>
     * @param string               $customName   Name for the custom <input type="text">
     * @param string               $modeName     Name for the hidden mode input
     * @param string               $selected     Currently selected value (if present in options)
     * @param string               $customValue  Prefill for custom input
     * @param array<string,string> $options      value => label
     * @return string                          HTML (escaped)
     */
    public function render_select_with_custom(
        string $name,
        string $customName,
        string $modeName,
        string $selected,
        string $customValue,
        array $options
    ): string {
        // Normalize selection: if not in options but non-empty, treat as custom.
        $hasSelectedInOptions = ($selected !== '' && array_key_exists($selected, $options));
        $isCustom             = ! $hasSelectedInOptions && $selected !== '';
        $selectValue          = $isCustom ? '__custom__' : $selected;

        $idBase = sanitize_title($name);
        $selectId = $idBase . '-select';
        $customId = $idBase . '-custom';
        $modeId   = $idBase . '-mode';

        $html  = '';

        // Inject the controller script only once.
        if (! self::$scriptPrinted) {
            $html .= $this->inline_controller_script();
            self::$scriptPrinted = true;
        }

        // Build select
        $html .= '<select name="' . esc_attr($name) . '" id="' . esc_attr($selectId) . '" ';
        $html .= ' data-bfr-has-custom="1" data-bfr-custom-id="' . esc_attr($customId) . '" data-bfr-mode-id="' . esc_attr($modeId) . '">';

        // Placeholder option
        $html .= '<option value="">' . esc_html__('— Select —', 'bfr') . '</option>';

        foreach ($options as $val => $label) {
            $html .= '<option value="' . esc_attr($val) . '"' . selected($selectValue, $val, false) . '>';
            $html .= esc_html($label) . '</option>';
        }

        // Custom sentinel option
        $html .= '<option value="__custom__"' . selected($selectValue, '__custom__', false) . '>';
        $html .= esc_html__('Custom…', 'bfr') . '</option>';

        $html .= '</select> ';

        // Custom input
        $html .= '<input type="text" class="regular-text" name="' . esc_attr($customName) . '" id="' . esc_attr($customId) . '"';
        $html .= ' value="' . esc_attr($isCustom ? $selected : $customValue) . '"';
        $html .= $isCustom ? '' : ' style="display:none"';
        $html .= ' />';

        // Hidden mode input
        $html .= '<input type="hidden" name="' . esc_attr($modeName) . '" id="' . esc_attr($modeId) . '" value="' . esc_attr($isCustom ? 'custom' : 'value') . '" />';

        return $html;
    }

    /**
     * Convenience wrapper for post type dropdown with custom entry.
     *
     * @param string $name
     * @param string $customName
     * @param string $modeName
     * @param string $selected
     * @param string $customValue
     * @return string
     */
    public function render_post_type_select_with_custom(
        string $name,
        string $customName,
        string $modeName,
        string $selected,
        string $customValue
    ): string {
        $options = $this->get_post_type_options();
        return $this->render_select_with_custom($name, $customName, $modeName, $selected, $customValue, $options);
    }

    /**
     * Small inline controller to toggle custom inputs.
     * Printed once per page.
     *
     * @return string
     */
    private function inline_controller_script(): string
    {
        $js = <<<JS
<script>
(function() {
  function hook(el) {
    if (!el || el.dataset.bfrHooked) return;
    el.dataset.bfrHooked = '1';
    var customId = el.getAttribute('data-bfr-custom-id');
    var modeId   = el.getAttribute('data-bfr-mode-id');
    var custom   = customId ? document.getElementById(customId) : null;
    var mode     = modeId ? document.getElementById(modeId) : null;

    function sync() {
      if (!custom || !mode) return;
      if (el.value === '__custom__') {
        custom.style.display = '';
        mode.value = 'custom';
      } else {
        custom.style.display = 'none';
        mode.value = 'value';
      }
    }
    el.addEventListener('change', sync);
    // Initial state
    sync();
  }

  document.addEventListener('DOMContentLoaded', function() {
    var sels = document.querySelectorAll('select[data-bfr-has-custom="1"]');
    for (var i = 0; i < sels.length; i++) hook(sels[i]);
  });
})();
</script>
JS;
        return $js;
    }
}