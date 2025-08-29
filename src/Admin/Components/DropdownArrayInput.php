<?php
declare(strict_types=1);

namespace BFR\Admin\Components;

/**
 * Class DropdownArrayInput
 *
 * A helper for rendering and resolving multi-row dropdown inputs that
 * populate an array of values. Each row consists of a select box backed
 * by a provided set of options plus a “Custom…” option to allow free
 * text entry. A hidden input tracks whether the row represents a
 * predefined value or a custom value.
 *
 * This class delegates the actual select + custom field rendering to
 * {@see DropdownProvider::render_select_with_custom()}, and handles
 * repeating rows, the add/remove UI, and parsing posted values back
 * into a clean array. The generated markup mirrors the existing
 * meta‑keys UI, ensuring the existing JavaScript that binds add/remove
 * behaviour continues to work without changes.
 */
final class DropdownArrayInput
{
    private DropdownProvider $dropdowns;

    /**
     * Track whether the JavaScript for handling select/custom toggles and
     * add/remove rows has already been injected into the page. This
     * prevents emitting duplicate script tags when multiple dropdowns
     * are rendered on the same admin screen.
     *
     * @var bool
     */
    private static bool $scriptInjected = false;

    public function __construct(DropdownProvider $dropdowns)
    {
        $this->dropdowns = $dropdowns;
    }

    /**
     * Render a multi‑row dropdown input.
     *
     * Given base field names for the select, custom text input and mode
     * hidden input, this will emit a container with one row per selected
     * value. Each row contains a select/custom pair and a remove button.
     * At the end of the container an “Add key +” button is rendered to
     * duplicate the last row when clicked (handled by existing JS).
     *
     * @param string               $base_select_name Base name of the select field (include slug in square brackets)
     *                                               Example: "input_meta_keys[my_slug]"
     * @param string               $base_custom_name Base name of the custom text field (include slug)
     *                                               Example: "input_meta_keys_custom[my_slug]"
     * @param string               $base_mode_name   Base name of the mode hidden field (include slug)
     *                                               Example: "input_meta_keys_mode[my_slug]"
     * @param array<string,string> $options          Map of value => label used to populate the select
     * @param array<int,string>    $selected_values  Preselected values (empty array yields one blank row)
     * @param array<int,string>    $custom_values    Preselected custom values aligned by index
     * @param string|null          $data_post_type   Optional post type slug to emit as data‑post‑type attribute
     * @return string HTML
     */
    public function render(
        string $base_select_name,
        string $base_custom_name,
        string $base_mode_name,
        array $options,
        array $selected_values = [],
        array $custom_values = [],
        ?string $data_post_type = null
    ): string {
        /*
         * Always keep at least one row in the DOM. When there are no
         * pre-selected values the first row will be blank and hidden. This
         * hidden row acts as a template for cloning when the user clicks
         * “Add key +”. Clearing and hiding the last visible row also
         * results in an empty array when saving while preserving the
         * template for future additions.
         */
        $initially_empty = empty($selected_values);
        if ($initially_empty) {
            // Create a single blank entry when no values are supplied
            $selected_values = [''];
            $custom_values   = [''];
        }

        $html  = '<div class="bfr-metakeys-multi"';
        if ($data_post_type !== null && $data_post_type !== '') {
            $html .= ' data-post-type="' . esc_attr($data_post_type) . '"';
        }
        $html .= '>';

        foreach ($selected_values as $i => $sel) {
            $sel    = (string)$sel;
            $custom = (string)($custom_values[$i] ?? '');
            $select_name = $base_select_name . '[' . $i . ']';
            $custom_name = $base_custom_name . '[' . $i . ']';
            $mode_name   = $base_mode_name   . '[' . $i . ']';

            // Hide the first row when there were no initial selections
            $style = 'margin-bottom:6px';
            if ($initially_empty && $i === 0 && $sel === '') {
                $style .= ';display:none';
            }

            $html .= '<div class="bfr-metakeys-row" style="' . $style . '">';
            $html .= $this->dropdowns->render_select_with_custom(
                $select_name,
                $custom_name,
                $mode_name,
                $options,
                $sel,
                $custom
            );
            $html .= ' <button type="button" class="button bfr-remove-row" aria-label="Remove">–</button>';
            $html .= '</div>';
        }
        $html .= '<p><button type="button" class="button button-secondary bfr-add-row">Add key +</button></p>';
        $html .= '</div>';

        // Append the supporting JavaScript once per page. This script
        // binds change handlers to select boxes that toggle the custom
        // input, and manages adding/removing rows in multi-key blocks.
        if (! self::$scriptInjected) {
            $html .= "\n<script>\n";
            $html .= 'document.addEventListener("DOMContentLoaded", function(){' . "\n";
            $html .= '  function bindSelectWithCustom(wrapper){' . "\n";
            $html .= '    var sel = wrapper.querySelector("select");' . "\n";
            $html .= '    var txt = wrapper.querySelector("input[type=\"text\"]");' . "\n";
            $html .= '    var modeName = wrapper.getAttribute("data-mode-name");' . "\n";
            $html .= '    function update(){' . "\n";
            $html .= '      if (!sel || !modeName) return;' . "\n";
            $html .= '      var isCustom = sel.value === "__custom__";' . "\n";
            $html .= '      if (txt) txt.style.display = isCustom ? "" : "none";' . "\n";
            $html .= '      var hidden = wrapper.querySelector("input[type=\"hidden\"][name=\"" + modeName + "\"]");' . "\n";
            $html .= '      if (hidden) hidden.value = isCustom ? "custom" : "value";' . "\n";
            $html .= '    }' . "\n";
            $html .= '    if (sel) sel.addEventListener("change", update);' . "\n";
            $html .= '    update();' . "\n";
            $html .= '  }' . "\n";
            $html .= '  document.querySelectorAll(".bfr-select-with-custom").forEach(bindSelectWithCustom);' . "\n";
            $html .= '  document.querySelectorAll(".bfr-metakeys-multi").forEach(function(block){' . "\n";
            $html .= '    function rebindRow(row){' . "\n";
            $html .= '      row.querySelectorAll(".bfr-select-with-custom").forEach(bindSelectWithCustom);' . "\n";
            $html .= '      var rem = row.querySelector(".bfr-remove-row");' . "\n";
            $html .= '      if (rem) rem.addEventListener("click", function(){' . "\n";
            $html .= '        var rows = block.querySelectorAll(".bfr-metakeys-row");' . "\n";
            $html .= '        if (rows.length > 1) {' . "\n";
            $html .= '          row.remove();' . "\n";
            $html .= '        } else {' . "\n";
            $html .= '          // If there is only one row, clear and hide it to leave an empty array' . "\n";
            $html .= '          var sel = row.querySelector("select");' . "\n";
            $html .= '          if (sel) sel.value = "";' . "\n";
            $html .= '          var txt = row.querySelector("input[type=\"text\"]");' . "\n";
            $html .= '          if (txt) { txt.value = ""; txt.style.display = "none"; }' . "\n";
            $html .= '          var hid = row.querySelector("input[type=\"hidden\"]");' . "\n";
            $html .= '          if (hid) hid.value = "value";' . "\n";
            $html .= '          row.style.display = "none";' . "\n";
            $html .= '        }' . "\n";
            $html .= '      });' . "\n";
            $html .= '    }' . "\n";
            $html .= '    block.querySelectorAll(".bfr-metakeys-row").forEach(rebindRow);' . "\n";
            $html .= '    var addBtn = block.querySelector(".bfr-add-row");' . "\n";
            $html .= '    if (addBtn) addBtn.addEventListener("click", function(){' . "\n";
            $html .= '      var rows = block.querySelectorAll(".bfr-metakeys-row");' . "\n";
            $html .= '      var last = rows[rows.length - 1];' . "\n";
            $html .= '      if (!last) return;' . "\n";
            $html .= '      var newIndex = rows.length;' . "\n";
            $html .= '      var clone = last.cloneNode(true);' . "\n";
            $html .= '      // Update names and reset values in the clone' . "\n";
            $html .= '      clone.querySelectorAll("select").forEach(function(s){' . "\n";
            $html .= '        var name = s.getAttribute("name");' . "\n";
            $html .= '        if (name) { s.setAttribute("name", name.replace(/\\[\\d+\\]$/, "[" + newIndex + "]")); }' . "\n";
            $html .= '        s.value = "";' . "\n";
            $html .= '      });' . "\n";
            $html .= '      clone.querySelectorAll("input[type=\"text\"]").forEach(function(t){' . "\n";
            $html .= '        var name = t.getAttribute("name");' . "\n";
            $html .= '        if (name) { t.setAttribute("name", name.replace(/\\[\\d+\\]$/, "[" + newIndex + "]")); }' . "\n";
            $html .= '        t.value = "";' . "\n";
            $html .= '        t.style.display = "none";' . "\n";
            $html .= '      });' . "\n";
            $html .= '      clone.querySelectorAll("input[type=\"hidden\"]").forEach(function(h){' . "\n";
            $html .= '        var name = h.getAttribute("name");' . "\n";
            $html .= '        if (name) { h.setAttribute("name", name.replace(/\\[\\d+\\]$/, "[" + newIndex + "]")); }' . "\n";
            $html .= '        h.value = "value";' . "\n";
            $html .= '      });' . "\n";
            $html .= '      clone.querySelectorAll(".bfr-select-with-custom").forEach(function(w){' . "\n";
            $html .= '        var modeName = w.getAttribute("data-mode-name");' . "\n";
            $html .= '        if (modeName) { w.setAttribute("data-mode-name", modeName.replace(/\\[\\d+\\]$/, "[" + newIndex + "]")); }' . "\n";
            $html .= '      });' . "\n";
            $html .= '      // Ensure the cloned row is visible' . "\n";
            $html .= '      clone.style.display = "";' . "\n";
            $html .= '      addBtn.parentNode.parentNode.insertBefore(clone, addBtn.parentNode);' . "\n";
            $html .= '      rebindRow(clone);' . "\n";
            $html .= '    });' . "\n";
            $html .= '  });' . "\n";
            $html .= '});' . "\n";
            $html .= '</script>' . "\n";
            self::$scriptInjected = true;
        }

        return $html;
    }

    /**
     * Parse posted values from a multi‑row dropdown input back into an array.
     *
     * This examines the $_POST superglobal for keys of the form
     *    $_POST[$base_select_field][$slug][$i]
     *    $_POST[$base_custom_field][$slug][$i]
     *    $_POST[$base_mode_field][$slug][$i]
     * and resolves each row to either the selected value (sanitized) or
     * the custom value depending on the mode. Empty rows are skipped.
     * If the result would be empty, the provided $existing_values are
     * preserved instead.
     *
     * @param string          $base_select_field Root key for the select values (e.g. 'input_meta_keys')
     * @param string          $base_custom_field Root key for the custom values (e.g. 'input_meta_keys_custom')
     * @param string          $base_mode_field   Root key for the mode values (e.g. 'input_meta_keys_mode')
     * @param string          $slug_key          Sanitized slug used as subkey in $_POST arrays
     * @param array<int,mixed> $existing_values  Existing array of values (used if no new values provided)
     * @return array<int,string> Resolved array of strings (unique, indexed)
     */
    public function parse_post(
        string $base_select_field,
        string $base_custom_field,
        string $base_mode_field,
        string $slug_key,
        array $existing_values = []
    ): array {
        $row_sels  = isset($_POST[$base_select_field][$slug_key]) && is_array($_POST[$base_select_field][$slug_key])
            ? (array)$_POST[$base_select_field][$slug_key]
            : [];
        $row_modes = isset($_POST[$base_mode_field][$slug_key]) && is_array($_POST[$base_mode_field][$slug_key])
            ? (array)$_POST[$base_mode_field][$slug_key]
            : [];
        $row_custs = isset($_POST[$base_custom_field][$slug_key]) && is_array($_POST[$base_custom_field][$slug_key])
            ? (array)$_POST[$base_custom_field][$slug_key]
            : [];

        $final = [];
        if ($row_sels) {
            $max = max(array_keys($row_sels));
            for ($i = 0; $i <= $max; $i++) {
                $sel  = isset($row_sels[$i])  ? sanitize_text_field((string)$row_sels[$i])  : '';
                $mode = isset($row_modes[$i]) ? sanitize_text_field((string)$row_modes[$i]) : 'value';
                $cus  = isset($row_custs[$i]) ? sanitize_key((string)$row_custs[$i])        : '';
                $resolved = ($mode === 'custom') ? $cus : sanitize_key($sel);
                if ($resolved !== '') {
                    $final[] = $resolved;
                }
            }
        }
        // Always return a unique, re-indexed array of resolved values. This may be empty when all
        // rows have been removed, allowing callers to persist an empty array.
        return array_values(array_unique($final));
    }
}