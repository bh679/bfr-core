<?php
/**
 * MetaKeysTable
 *
 * Renders the Calculators table in the admin with columns:
 * Name (with ID + description), Target Meta Key, Calculation (class), Input Meta Keys.
 *
 * @package BookFreedivingRetreats\Core
 */

namespace BFR\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// We rely on WP_List_Table for admin tables.
if ( ! class_exists( '\WP_List_Table' ) ) {
    // Load core class only in admin context.
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class MetaKeysTable
 *
 * Extends WP_List_Table to display the Calculated Meta registry.
 */
class MetaKeysTable extends \WP_List_Table {

    /**
     * @var array $items_data The raw registry rows passed in by the caller.
     */
    private array $items_data = [];

    /**
     * Constructor.
     *
     * @param array $args Arguments passed to WP_List_Table (singular/plural, etc.).
     */
    public function __construct( array $args = [] ) {
        // Call parent to set up labels and screen.
        parent::__construct( [
            'singular' => 'calculator',     // Single item name.
            'plural'   => 'calculators',    // Plural item name.
            'ajax'     => false,            // We do standard page loads.
        ] + $args );
    }

    /**
     * Accept the registry data to render.
     *
     * @param array $registry Array of calculators (each an associative array).
     * @return void
     */
    public function set_items_data( array $registry ): void {
        // Store for prepare_items(); caller provides the already-built registry array.
        $this->items_data = $registry;
    }

    /**
     * Define the table columns.
     *
     * @return array
     */
    public function get_columns(): array {
        // Keys are the column slugs; values are the column headers.
        return [
            'name'             => __( 'Name', 'bfr-core' ),
            'target_meta_key'  => __( 'Target Meta Key', 'bfr-core' ),
            'calculation'      => __( 'Calculation (Class)', 'bfr-core' ),
            'input_meta_keys'  => __( 'Input Meta Keys', 'bfr-core' ),
        ];
    }

    /**
     * Make columns sortable if needed (currently not required, but wired for future).
     *
     * @return array
     */
    protected function get_sortable_columns(): array {
        // You can enable sorting later; leaving empty keeps UI simple for now.
        return [];
    }

    /**
     * Prepare, paginate, and assign $this->items for the table.
     *
     * @return void
     */
    public function prepare_items(): void {
        // 1) Get columns and set headers.
        $columns  = $this->get_columns();       // Column definitions.
        $hidden   = [];                         // No hidden columns.
        $sortable = $this->get_sortable_columns();  // (Currently none.)
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        // 2) Convert raw registry rows to flat rows suitable for rendering.
        $data = array_map( function( array $row ): array {
            // Safely read expected fields with sane fallbacks.
            $name         = $row['name']            ?? '';
            $slug         = $row['slug']            ?? '';
            $description  = $row['description']     ?? '';
            $target_meta  = $row['target_meta_key'] ?? '';
            $calc_class   = $row['calculation']     ?? '';
            $input_keys   = $row['input_meta_keys'] ?? [];

            // Normalize calculation to a displayable string (handles ::class and strings).
            if ( is_string( $calc_class ) ) {
                $calc_display = $calc_class;
            } elseif ( is_object( $calc_class ) ) {
                $calc_display = get_class( $calc_class );
            } else {
                $calc_display = '';
            }

            // Normalize input keys to a comma-separated string.
            if ( is_array( $input_keys ) ) {
                $input_keys_display = implode( ', ', array_map( 'strval', $input_keys ) );
            } else {
                $input_keys_display = (string) $input_keys;
            }

            // Return the flattened row for the table.
            return [
                'name'            => [
                    'title'       => $name,
                    'slug'        => $slug,
                    'description' => $description,
                ],
                'target_meta_key' => $target_meta,
                'calculation'     => $calc_display,
                'input_meta_keys' => $input_keys_display,
            ];
        }, $this->items_data );

        // 3) Basic pagination (small lists usually; adjust per page if needed).
        $per_page     = 50;                                 // Show up to 50 calculators per page.
        $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );    // Current admin page query var.
        $total_items  = count( $data );                     // Total rows.

        // Slice the data for current page.
        $this->items = array_slice(
            $data,
            ( $current_page - 1 ) * $per_page,
            $per_page
        );

        // Register pagination args with WP_List_Table.
        $this->set_pagination_args( [
            'total_items' => $total_items,  // Total items count.
            'per_page'    => $per_page,     // Items per page.
            'total_pages' => (int) ceil( $total_items / $per_page ), // Page count.
        ] );
    }

    /**
     * Default column renderer (fallback).
     *
     * @param array  $item        The row item prepared in prepare_items().
     * @param string $column_name The current column being rendered.
     * @return string
     */
    public function column_default( $item, $column_name ): string {
        // Safely print scalar values for non-custom columns.
        $value = $item[ $column_name ] ?? '';
        return is_scalar( $value ) ? esc_html( (string) $value ) : '';
    }

    /**
     * Custom renderer for the "name" column.
     * Displays Name, then the ID/slug and description on separate muted lines.
     *
     * @param array $item The current row item.
     * @return string
     */
    public function column_name( $item ): string {
        // Extract structured subfields.
        $title       = $item['name']['title']       ?? '';
        $slug        = $item['name']['slug']        ?? '';
        $description = $item['name']['description'] ?? '';

        // Build safe HTML with subtle formatting.
        ob_start();
        ?>
        <strong><?php echo esc_html( $title ); ?></strong>
        <?php if ( $slug ): ?>
            <br><code><?php echo esc_html( $slug ); ?></code>
        <?php endif; ?>
        <?php if ( $description ): ?>
            <br><span style="color:#666;"><?php echo esc_html( $description ); ?></span>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }
}