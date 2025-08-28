<?php
declare(strict_types=1);

use BFR\Meta\Fields\MaxDepth;
use BFR\Meta\Fields\Languages;
use BFR\Meta\Fields\Facilities;
use BFR\Meta\Fields\SchoolCount;

/**
 * Default calculators registry.
 * Keyed by a unique slug. Values are config arrays used to instantiate calculators.
 *
 * NOTE: Names retain the requirement's "cpt_id" naming though these are post type slugs. // TODO: confirm
 * The "description" key in each calculator is intended for display in the Admin UI.
 */
return [
    'max_depth' => [
        'name'              => 'Max Depth',
        'slug'              => 'max_depth',
        'class'             => MaxDepth::class,
        // Display text explaining how this calculator works:
        'description'       => 'Finds all INPUT posts (e.g., schools) linked to the target Destination via "relation_meta_key", reads the numeric depth values from "input_meta_keys", and saves the maximum value into the Destination’s "target_meta_key".',
        'target_cpt_id'     => 'destinations',          // TODO: confirm
        'target_meta_key'   => 'max_depth',             // TODO: confirm
        'input_cpt_id'      => ['freedive-schools'],    // TODO: confirm
        'input_meta_keys'   => ['max_depth'],           // TODO: confirm
        'relation_meta_key' => 'destination_id',        // input post meta that holds target destination ID // TODO: confirm
    ],

    'languages' => [
        'name'              => 'Languages',
        'slug'              => 'languages',
        'class'             => Languages::class,
        'description'       => 'Collects language lists from all related INPUT posts, normalizes (trim/Title-Case), de-duplicates, sorts alphabetically, and saves as a JSON array in the Destination’s "target_meta_key".',
        'target_cpt_id'     => 'destinations',          // TODO: confirm
        'target_meta_key'   => 'languages_array',       // store JSON array
        'input_cpt_id'      => ['freedive-schools'],    // TODO: confirm
        'input_meta_keys'   => ['languages', 'langs'],  // TODO: confirm
        'relation_meta_key' => 'destination_id',        // TODO: confirm
    ],

    'facilities' => [
        'name'              => 'Facilities',
        'slug'              => 'facilities',
        'class'             => Facilities::class,
        'description'       => 'Merges facility lists from all related INPUT posts, normalizes entries, removes duplicates, sorts, and saves as a JSON array into the Destination’s "target_meta_key".',
        'target_cpt_id'     => 'destinations',                 // TODO: confirm
        'target_meta_key'   => 'facilities_array',             // store JSON array
        'input_cpt_id'      => ['freedive-schools'],           // TODO: confirm
        'input_meta_keys'   => ['facilities', 'facility_list'],// TODO: confirm
        'relation_meta_key' => 'destination_id',               // TODO: confirm
    ],

    'school_count' => [
        'name'              => 'School Count',
        'slug'              => 'school_count',
        'class'             => SchoolCount::class,
        'description'       => 'Counts how many INPUT posts (e.g., schools) reference the current Destination via "relation_meta_key", then saves that integer into the Destination’s "target_meta_key".',
        'target_cpt_id'     => 'destinations',          // TODO: confirm
        'target_meta_key'   => 'bfr_school_count',
        'input_cpt_id'      => ['freedive-schools'],    // TODO: confirm
        'input_meta_keys'   => [],
        'relation_meta_key' => 'destination_id',        // TODO: confirm
    ],
];