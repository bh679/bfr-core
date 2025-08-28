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
 */
return [
    'max_depth' => [
        'name'            => 'Max Depth',
        'slug'            => 'max_depth',
        'class'           => MaxDepth::class,
        'target_cpt_id'   => 'destination',          // TODO: confirm
        'target_meta_key' => 'bfr_max_depth',        // TODO: confirm
        'input_cpt_id'    => ['course', 'retreat'],  // TODO: confirm
        'input_meta_keys' => ['depth_a', 'depth_b'], // TODO: confirm
        'relation_meta_key' => '_bfr_destination_id' // input post meta that holds target destination ID // TODO: confirm
    ],
    'languages' => [
        'name'            => 'Languages',
        'slug'            => 'languages',
        'class'           => Languages::class,
        'target_cpt_id'   => 'destination',            // TODO: confirm
        'target_meta_key' => 'bfr_languages_array',    // store JSON array
        'input_cpt_id'    => ['freedive-school'],      // TODO: confirm
        'input_meta_keys' => ['languages', 'langs'],   // TODO: confirm
        'relation_meta_key' => '_bfr_destination_id',  // TODO: confirm
    ],
    'facilities' => [
        'name'            => 'Facilities',
        'slug'            => 'facilities',
        'class'           => Facilities::class,
        'target_cpt_id'   => 'destination',             // TODO: confirm
        'target_meta_key' => 'bfr_facilities_array',    // store JSON array
        'input_cpt_id'    => ['freedive-school'],       // TODO: confirm
        'input_meta_keys' => ['facilities', 'facility_list'], // TODO: confirm
        'relation_meta_key' => '_bfr_destination_id',   // TODO: confirm
    ],
    'school_count' => [
        'name'            => 'School Count',
        'slug'            => 'school_count',
        'class'           => SchoolCount::class,
        'target_cpt_id'   => 'destination',             // TODO: confirm
        'target_meta_key' => 'bfr_school_count',
        'input_cpt_id'    => ['freedive-school'],       // TODO: confirm
        'input_meta_keys' => [],
        'relation_meta_key' => '_bfr_destination_id',   // TODO: confirm
    ],
];
