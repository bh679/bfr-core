<?php
/**
 * Registry of available calculated meta field classes.
 * 
 * Location: /src/Meta/registry.php (next to CalculatedMetaField.php)
 * 
 * This file has NO class. It simply outputs (returns) a PHP array describing
 * each calculator that currently exists in /Meta/Fields.
 *
 * Shape of each entry:
 *  - name        : Human-readable name for the calculator
 *  - class       : Fully Qualified Class Name (FQCN)
 *  - arguments   : Array of argument TYPES (order matches the compute signature)
 *  - return type : Return type(s) from compute (loosely documented if mixed)
 *  - description : Short explanation of what the calculator does
 */

return [
	// MaxDepth: computes the maximum numeric value found on input posts' meta keys
	'max_depth' => [														// Array key used internally (slug-like)
		'name'         => 'Max Depth',										// Human name displayed in admin/UI
		'class'        => '\\BFR\\Meta\\Fields\\MaxDepth',					// FQCN of the calculator class
		'arguments'    => ['int'],											// compute(int $target_post_id)
		'return type'  => 'float|int|null',									// Numeric maximum, or null if no data
		'description'  => 'Finds all INPUT posts related to the TARGET post, reads numeric values from the configured input meta keys, and returns the maximum value.',
	],

	// SchoolCount: counts related input posts
	'school_count' => [														// Array key used internally (slug-like)
		'name'         => 'School Count',									// Human name displayed in admin/UI
		'class'        => '\\BFR\\Meta\\Fields\\SchoolCount',				// FQCN of the calculator class
		'arguments'    => ['int'],											// compute(int $target_post_id)
		'return type'  => 'int',											// Count of input posts
		'description'  => 'Counts how many INPUT posts are related to the TARGET post via the relation meta key.',
	],

	// Languages: merges + de-duplicates language lists from input posts and returns JSON string
	'languages' => [														// Array key used internally (slug-like)
		'name'         => 'Languages',										// Human name displayed in admin/UI
		'class'        => '\\BFR\\Meta\\Fields\\Languages',					// FQCN of the calculator class
		'arguments'    => ['int'],											// compute(int $target_post_id)
		'return type'  => 'string',											// JSON-encoded array (e.g., ["English","Spanish"])
		'description'  => 'Aggregates, normalizes, de-duplicates, and sorts language values from input meta, returning them as a JSON-encoded array string.',
	],

	// Facilities: merges + de-duplicates facility lists from input posts and returns JSON string
	'facilities' => [														// Array key used internally (slug-like)
		'name'         => 'Facilities',										// Human name displayed in admin/UI
		'class'        => '\\BFR\\Meta\\Fields\\Facilities',					// FQCN of the calculator class
		'arguments'    => ['int'],											// compute(int $target_post_id)
		'return type'  => 'string',											// JSON-encoded array (e.g., ["Pool","Boat","Shop"])
		'description'  => 'Aggregates, normalizes, de-duplicates, and sorts facilities from input meta, returning them as a JSON-encoded array string.',
	],
];