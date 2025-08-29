<?php
declare(strict_types=1);

namespace BFR\Admin\Components;

/**
 * Class DropdownArrayManager
 *
 * Generic manager for rendering and resolving arrays of dropdown inputs where
 * each row is a select-with-custom trio:
 *   - <select name="{$baseSelect}[i]"> … </select>
 *   - <input  name="{$baseCustom}[i]"  type="text">
 *   - <input  name="{$baseMode}[i]"    type="hidden" value="value|custom">
 *
 * This class:
 * - Knows NOTHING about BFR calculators or meta fields.
 * - Depends only on DropdownProvider to render select-with-custom widgets.
 * - Can render zero or more rows (including an empty list) and includes a JS
 *   block (printed once) to handle Add/Remove rows and "Custom…" toggling.
 */
final class DropdownArrayManager
{
    private DropdownProvider $dropdowns;

    /** Print-once guard for inline JS (shared by all instances). */
    private static bool $jsPrinted = false;

    public function __construct(DropdownProvider $dropdowns)
    {
        $this->dropdowns = $dropdowns;
    }

    /**
     * Ensure the inline JS is printed once.
     * This JS handles:
     * - Toggling the text input visibility and mode for any .bfr-select-with-custom
     * - Adding/removing rows inside any .bfr-metakeys-multi block
     *
     * Keep this public so other components (e.g., single-field renderers) can call it.
     */
    public function ensureScripts(): void
    {
        if (self::$jsPrinted) {
            return;
        }
        self::$jsPrinted = true;

        echo '<script>
(function(){
  // Toggle select-with-custom blocks (event delegation)
  function syncSelectWithCustom(wrapper){
    var sel = wrapper.querySelector("select");
    var txt = wrapper.querySelector(\'input[type="text"]\');
    var modeName = wrapper.getAttribute("data-mode-name");
    if (!sel || !modeName) return;
    var hidden = wrapper.querySelector(\'input[type="hidden"][name="\'+modeName+\'"]\');
    if (!hidden) return;
    var isCustom = sel.value === "__custom__";
    if (txt) txt.style.display = isCustom ? "" : "none";
    hidden.value = isCustom ? "custom" : "value";
  }

  // On change of any select within .bfr-select-with-custom
  document.addEventListener("change", function(ev){
    var select = ev.target.closest && ev.target.closest(".bfr-select-with-custom select");
    if (!select) return;
    var wrap = ev.target.closest(".bfr-select-with-custom");
    if (wrap) syncSelectWithCustom(wrap);
  });

  // Initial sync on DOM ready
  document.addEventListener("DOMContentLoaded", function(){
    document.querySelectorAll(".bfr-select-with-custom").forEach(syncSelectWithCustom);
  });

  // Add/Remove rows inside any .bfr-metakeys-multi container
  document.addEventListener("click", function(ev){
    var addBtn = ev.target.closest && ev.target.closest(".bfr-metakeys-multi .bfr-add-row");
    if (addBtn) {
      var block = addBtn.closest(".bfr-metakeys-multi");
      if (!block) return;

      var baseSelect = block.getAttribute("data-base-select");
      var baseCustom = block.getAttribute("data-base-custom");
      var baseMode   = block.getAttribute("data-base-mode");
      var nextIndex  = block.querySelectorAll(".bfr-metakeys-row").length;

      // Construct a new row
      var row = document.createElement("div");
      row.className = "bfr-metakeys-row";
      row.style.marginBottom = "6px";
      row.innerHTML =
        \'<span class="bfr-select-with-custom" data-mode-name="\'+baseMode+\'[\'+nextIndex+\']">\'
        + \'<select name="\'+baseSelect+\'[\'+nextIndex+\']" class="regular-text"></select> \'
        + \'<input type="text" name="\'+baseCustom+\'[\'+nextIndex+\']" value="" class="regular-text" style="display:none" />\'
        + \'<input type="hidden" name="\'+baseMode+\'[\'+nextIndex+\']" value="value" />\'
        + \'</span> \'
        + \'<button type="button" class="button bfr-remove-row" aria-label="Remove">–</button>\';

      // Populate options from first existing row if present
      var templateSelect = block.querySelector(".bfr-metakeys-row select");
      var newSelect = row.querySelector("select");
      if (templateSelect) {
        newSelect.innerHTML = templateSelect.innerHTML;
      } else {
        // Minimal fallback options if no template exists
        var opt = document.createElement("option");
        opt.value = "";
        opt.textContent = "";
        newSelect.appendChild(opt);
        var optC = document.createElement("option");
        optC.value = "__custom__";
        optC.textContent = "Custom…";
        newSelect.appendChild(optC);
      }

      // Insert before the control <p>
      addBtn.parentNode.parentNode.insertBefore(row, addBtn.parentNode);

      // Initial sync for the new row
      var wrap = row.querySelector(".bfr-select-with-custom");
      if (wrap) syncSelectWithCustom(wrap);
      return;
    }

    var remBtn = ev.target.closest && ev.target.closest(".bfr-metakeys-multi .bfr-remove-row");
    if (remBtn) {
      var row = remBtn.closest(".bfr-metakeys-row");
      if (row) row.remove(); // Allow deleting to zero rows
      return;
    }
  });
})();
</script>';
    }

    /**
     * Render an array control composed of multiple select-with-custom rows.
     * No initial row is forced — it’s valid to render an empty list (user intent).
     *
     * @param string               $baseSelectName Base for select names (e.g. "input_meta_keys[slug]").
     * @param string               $baseCustomName Base for custom names (e.g. "input_meta_keys_custom[slug]").
     * @param string               $baseModeName   Base for mode names (e.g. "input_meta_keys_mode[slug]").
     * @param array<string,string> $options        Options map value=>label used for all rows.
     * @param string[]             $selected       Preselected values for each row.
     * @param string[]             $customValues   Custom values aligned to $selected indices.
     * @param string               $containerClass Optional extra class on container.
     * @return string HTML
     */
    public function renderArrayControl(
        string $baseSelectName,
        string $baseCustomName,
        string $baseModeName,
        array $options,
        array $selected = [],
        array $customValues = [],
        string $containerClass = 'bfr-metakeys-multi'
    ): string {
        $this->ensureScripts();

        $html  = '<div class="'.esc_attr($containerClass).'"';
        $html .= ' data-base-select="'.esc_attr($baseSelectName).'"';
        $html .= ' data-base-custom="'.esc_attr($baseCustomName).'"';
        $html .= ' data-base-mode="'.esc_attr($baseModeName).'">';

        // Render existing rows (may be zero)
        foreach (array_values($selected) as $i => $sel) {
            $sel    = (string)$sel;
            $custom = (string)($customValues[$i] ?? '');

            $select_name = $baseSelectName . '['.$i.']';
            $custom_name = $baseCustomName . '['.$i.']';
            $mode_name   = $baseModeName   . '['.$i.']';

            $html .= '<div class="bfr-metakeys-row" style="margin-bottom:6px">';
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

        // Control to add new row(s)
        $html .= '<p><button type="button" class="button button-secondary bfr-add-row">Add key +</button></p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Resolve an array control from $_POST by base names.
     * Returns the final list of values where each element is either the chosen select value
     * or the custom text (depending on per-row mode).
     *
     * @param string $baseSelectName Base for select names (e.g. "input_meta_keys[slug]").
     * @param string $baseCustomName Base for custom names (e.g. "input_meta_keys_custom[slug]").
     * @param string $baseModeName   Base for mode names (e.g. "input_meta_keys_mode[slug]").
     * @return array<int,string>     Resolved values; may be an empty array.
     */
    public function resolveArrayFromPost(
        string $baseSelectName,
        string $baseCustomName,
        string $baseModeName
    ): array {
        // Extract arrays from $_POST (supporting both missing/empty cases)
        $sels  = $this->getNestedArray($_POST, $baseSelectName);
        $modes = $this->getNestedArray($_POST, $baseModeName);
        $custs = $this->getNestedArray($_POST, $baseCustomName);

        $resolved = [];
        if ($sels) {
            $max = max(array_keys($sels));
            for ($i = 0; $i <= $max; $i++) {
                $sel  = isset($sels[$i])  ? sanitize_text_field((string)$sels[$i])  : '';
                $mode = isset($modes[$i]) ? sanitize_text_field((string)$modes[$i]) : 'value';
                $cus  = isset($custs[$i]) ? sanitize_key((string)$custs[$i])        : '';
                $value = ($mode === 'custom') ? $cus : sanitize_key($sel);
                if ($value !== '') {
                    $resolved[] = $value;
                }
            }
        }
        return array_values(array_unique($resolved));
    }

    /**
     * Helper to fetch nested POST arrays by a "base name" like "input_meta_keys[slug]".
     * Converts the bracket path into nested keys and returns the leaf array (or []).
     *
     * @param array<string,mixed> $source Superglobal (e.g., $_POST)
     * @param string              $base   Name like "input_meta_keys[slug]"
     * @return array<int|string,mixed>
     */
    private function getNestedArray(array $source, string $base): array
    {
        // Parse "name[slug]" into ["name","slug"]
        if (! preg_match('/^([^\[]+)((\[[^\]]*\])*)$/', $base, $m)) {
            return [];
        }
        $root = $m[1];
        $path = [];
        if (! empty($m[2])) {
            preg_match_all('/\[([^\]]*)\]/', $m[2], $mm);
            $path = $mm[1] ?? [];
        }

        if (! isset($source[$root])) {
            return [];
        }
        $node = $source[$root];
        foreach ($path as $segment) {
            if ($segment === '') {
                // empty brackets "[]" not expected in our base names; bail safely
                return [];
            }
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return [];
            }
            $node = $node[$segment];
        }
        return is_array($node) ? $node : [];
    }
}