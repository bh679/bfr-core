# BFR Core (OOP Refactor)

Clean, testable OOP architecture for **calculated meta fields** in WordPress.

## What this does

- Provides an abstract base `CalculatedMetaField` and concrete calculators:
  - `MaxDepth`: max numeric depth across related input posts/meta
  - `Languages`: merged unique list from inputs (stored as JSON array)
  - `Facilities`: merged unique list from inputs (stored as JSON array)
  - `SchoolCount`: number of related input posts
- Boots from `bfr-core.php`, attaches hooks to recompute on relevant saves, and exposes a simple Admin UI and WP‑CLI commands.
- Saves list-type outputs as **JSON arrays** (consistent and filterable).

> **Terminology note**: The configuration keys use `target_cpt_id` / `input_cpt_id` as per requirements, but these represent **post type slugs** in WordPress (e.g., `destination`, `freedive-school`). **TODO: confirm** and rename in a future pass if desired.

---

## Folder Tree

```
bfr-core.php
src/
  Admin/
    AdminPanel.php
    CalculatedMetaEditor.php
  Config/
    registry.php
  Core/
    Loader.php
  Infrastructure/
    WPCLI/
      Commands.php
    WordPress/
      OptionRepository.php
      WPHooks.php
  Meta/
    CalculatedMetaField.php
    Fields/
      Facilities.php
      Languages.php
      MaxDepth.php
      SchoolCount.php
  Utils/
    Arr.php
    Sanitize.php
    Str.php
README.md
```

---

## How it works

1. **Bootstrap** (`bfr-core.php`)
   - Defines constants, autoloads the `BFR\` namespace, and initializes `BFR\Core\Loader` with default calculators from `src/Config/registry.php`.
   - Merges defaults with any **overrides** saved from the Admin editor (options).

2. **Loader** (`src/Core/Loader.php`)
   - Wires up WordPress hooks (`WPHooks`), Admin pages (`AdminPanel` & `CalculatedMetaEditor`), and WP‑CLI commands.

3. **Hooks** (`src/Infrastructure/WordPress/WPHooks.php`)
   - On `save_post` of any **input** post type, it finds the **target** post ID via the configured `relation_meta_key` and runs the relevant calculator(s) just for that target.
   - Optional cron hook `bfr_calculations_nightly` recomputes for all targets.

4. **Calculated Meta** (`src/Meta`)
   - `CalculatedMetaField` declares the contract and common helpers:
     - `run($target_post_id, $dryRun=false)`: compute & optionally persist.
     - `average_input_meta_values($target_post_id)`: numeric average helper.
     - `findInputPostsForTarget($target_post_id)`: WP_Query based on `relation_meta_key`.
     - `normalize_list($raw)`: JSON/CSV/string → unique, sorted array (Title Case).
   - Concrete classes implement `compute($target_post_id)`:
     - `MaxDepth`: `max()` of numeric meta values across inputs.
     - `Languages` & `Facilities`: merged lists, saved as **JSON**.
     - `SchoolCount`: count related inputs.

5. **Admin UI**
   - **BFR → Calculated Meta**: run calculators on-demand (per target ID) or **Run All**.
   - **BFR → Calculator Editor**: edit and persist calculator configs (target type, target meta, input types, input meta keys, relation key).

6. **Options**
   - Stored under the option key `bfr_calculator_configs`. These override the defaults in `src/Config/registry.php`.

---

## Add a New Calculator

1. Create a class in `src/Meta/Fields/YourThing.php`:

```php
<?php
declare(strict_types=1);

namespace BFR\Meta\Fields;

use BFR\Meta\CalculatedMetaField;

final class YourThing extends CalculatedMetaField
{
    protected function compute(int $target_post_id): mixed
    {
        // Use helpers: $this->findInputPostsForTarget(), $this->get_input_meta_value(), etc.
        $posts = $this->findInputPostsForTarget($target_post_id);
        $value = null; // compute...
        return $value;
    }
}
```

2. Register it in `src/Config/registry.php`:

```php
use BFR\Meta\Fields\YourThing;

return [
  // ...
  'your_thing' => [
    'name'            => 'Your Thing',
    'slug'            => 'your_thing',
    'class'           => YourThing::class,
    'target_cpt_id'   => 'destination',
    'target_meta_key' => 'bfr_your_thing',
    'input_cpt_id'    => ['freedive-school'],
    'input_meta_keys' => ['some_meta_key'],
    'relation_meta_key' => '_bfr_destination_id',
  ],
];
```

3. (Optional) Tweak via **BFR → Calculator Editor** in wp‑admin (saved to options).

---

## Triggering Calculations

### Admin
- **BFR → Calculated Meta**
  - Run a single calculator by providing the **Target Post ID**.
  - **Run All** runs every calculator for every target post.

### Hooks
- Saving any **input** post (e.g., `freedive-school`) with a valid `relation_meta_key` pointing at a **target** post (e.g., a `destination`) will recompute the affected target automatically.

### WP‑CLI
```
wp bfr recalc --all
wp bfr recalc --slug=languages --post_id=123
wp bfr recalc --slug=max_depth --post_id=123 --dry-run
```

---

## Configuration Keys (per calculator)

- `name`: Display name.
- `slug`: Unique key.
- `class`: FQCN of the calculator.
- `target_cpt_id`: Target **post type** slug. (*Named per requirement; TODO: confirm terminology*)
- `target_meta_key`: Where to save the result on target posts.
- `input_cpt_id`: Array of input post type slugs.
- `input_meta_keys`: Array of meta keys to read from **input** posts.
- `relation_meta_key`: Meta key on input posts that stores the **target post ID** (to link inputs → target).

---

## Migration Map (Before → After)

| Old file / function | New class / method |
|---|---|
| **Aggregator.php**: numeric max depth calc | `BFR\Meta\Fields\MaxDepth::compute()` |
| **Aggregator.php**: merge languages/facilities | `BFR\Meta\Fields\Languages::compute()`, `BFR\Meta\Fields\Facilities::compute()` |
| **Aggregator.php**: school count | `BFR\Meta\Fields\SchoolCount::compute()` |
| **Aggregator.php**: helpers for meta IO | `BFR\Meta\CalculatedMetaField::get_input_meta_value()` and `::set_target_meta_value()` |
| **Admin.php**: menu & run buttons | `BFR\Admin\AdminPanel` |
| **Admin.php**: settings storage | `BFR\Admin\CalculatedMetaEditor` + `BFR\Infrastructure\WordPress\OptionRepository` |
| **Helpers.php**: array/string normalization | `BFR\Utils\Arr`, `BFR\Utils\Str`, `BFR\Utils\Sanitize` |
| **bfr-core.php**: bootstrap & glue | `bfr-core.php` + `BFR\Core\Loader` |
| Save hooks scattered | `BFR\Infrastructure\WordPress\WPHooks` |
| (new) CLI scripts | `BFR\Infrastructure\WPCLI\Commands` |

---

## Implementation Notes

- List-type values are stored as **JSON arrays** (`wp_json_encode`) for consistency (`bfr_languages_array`, `bfr_facilities_array`). This matches the requirement and your earlier schema. If you previously stored CSV strings, you can keep both for a transition period in a custom subclass, or query decode on read.
- The base class assumes a simple **input → target** link via `relation_meta_key`. For advanced relations (e.g., JetEngine Relations), either:
  - Save a mirror `_bfr_destination_id` meta on inputs, or
  - Override `findInputPostsForTarget()` in a subclass to query via the relation API and return an array of `WP_Post` objects.
- The naming `*_cpt_id` follows your specification but refers to **post type slugs**; adjust labels in the editor if you later rename these keys.

---

## Testing Quickly

1. Activate the plugin.
2. Go to **BFR → Calculated Meta**.
3. Enter a known **Destination** post ID and click **Run** for each calculator.
4. Inspect the target post’s meta (e.g., via “Custom Fields” or a meta viewer).

---

## License

MIT (or your project’s preferred license)
