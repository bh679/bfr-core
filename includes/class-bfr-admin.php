<?php
if ( ! defined('ABSPATH') ) exit;
if ( ! class_exists('BFR_Helpers') ) {
    require_once dirname(__FILE__) . '/class-bfr-helpers.php';
}

final class BFR_Admin {

    private static $instance = null;
    public static function instance() { return self::$instance ?: self::$instance = new self(); }

    private function __construct() {
        add_action('admin_menu',  [$this, 'admin_menu']);
        add_action('admin_init',  [$this, 'register_settings']);
        add_action('admin_post_bfr_recalc', [$this, 'handle_recalc_now']);
    }

    public function admin_menu() {
        add_options_page('BFR Core', 'BFR Core', 'manage_options', 'bfr-core', [$this, 'render_settings']);
    }

    public function register_settings() {
        register_setting('bfr_core_group', 'bfr_core_options', [
            'sanitize_callback' => function($input){
                $opts = BFR_Helpers::get_opts();
                $input = is_array($input) ? $input : [];

                // Validate CPTs
                $valid_cpts = $this->get_cpt_choices();
                $input['dest_cpt']   = (isset($input['dest_cpt'],   $valid_cpts[$input['dest_cpt']]))   ? $input['dest_cpt']   : $opts['dest_cpt'];
                $input['school_cpt'] = (isset($input['school_cpt'], $valid_cpts[$input['school_cpt']])) ? $input['school_cpt'] : $opts['school_cpt'];

                // Relation slug (optional string)
                $input['je_relation'] = isset($input['je_relation']) ? sanitize_text_field((string)$input['je_relation']) : $opts['je_relation'];

                // Text inputs on School meta (your existing inputs)
                foreach (['meta_dest_id','meta_max_depth','meta_price','meta_languages','meta_facilities'] as $k) {
                    if (isset($input[$k])) $input[$k] = sanitize_text_field( (string) $input[$k] );
                }

                // ---- Output meta keys on Destination (dropdown + custom)
                foreach ([
                    'out_school_count',
                    'out_max_depth',
                    'out_min_course_price',
                    'out_languages',
                    'out_facilities',
                ] as $okey) {
                    $sel_key = $okey . '_select';
                    $selected = isset($input[$sel_key]) ? (string)$input[$sel_key] : '';
                    $custom   = isset($input[$okey])     ? sanitize_text_field((string)$input[$okey]) : '';

                    if ($selected === '__custom__') {
                        $input[$okey] = $custom;
                    } else {
                        // if dropdown picked a concrete key, store that; if empty, keep previous/default
                        $input[$okey] = ($selected !== '') ? $selected : ($opts[$okey] ?? '');
                    }
                    unset($input[$sel_key]);
                }

                return wp_parse_args($input, $opts);
            }
        ]);

        add_settings_section('bfr_core_section', 'General Settings', function(){
            echo '<p>Pick your CPTs, relation slug (optional), and mapping of aggregate outputs to Destination meta keys.</p>';
        }, 'bfr-core');

        // Destination CPT (select)
        add_settings_field('dest_cpt', 'Destination CPT Slug', function(){
            $opts = BFR_Helpers::get_opts();
            $choices = $this->get_cpt_choices();
            echo '<select name="bfr_core_options[dest_cpt]">';
            foreach ($choices as $slug => $label) {
                printf('<option value="%s"%s>%s</option>',
                    esc_attr($slug),
                    selected($opts['dest_cpt'], $slug, false),
                    esc_html($label . " ($slug)")
                );
            }
            echo '</select>';
            echo '<p class="description">This CPT will receive the aggregated fields (output).</p>';
        }, 'bfr-core', 'bfr_core_section');

        // School CPT (select)
        add_settings_field('school_cpt', 'School CPT Slug', function(){
            $opts = BFR_Helpers::get_opts();
            $choices = $this->get_cpt_choices();
            echo '<select name="bfr_core_options[school_cpt]">';
            foreach ($choices as $slug => $label) {
                printf('<option value="%s"%s>%s</option>',
                    esc_attr($slug),
                    selected($opts['school_cpt'], $slug, false),
                    esc_html($label . " ($slug)")
                );
            }
            echo '</select>';
        }, 'bfr-core', 'bfr_core_section');

        // JetEngine Relation (optional text)
        add_settings_field('je_relation', 'JetEngine Relation Slug', function(){
            $opts = BFR_Helpers::get_opts();
            printf('<input type="text" class="regular-text" name="bfr_core_options[je_relation]" value="%s" placeholder="%s" />',
                esc_attr($opts['je_relation']),
                esc_attr('e.g. destination-to-school (leave blank to use meta only)')
            );
        }, 'bfr-core', 'bfr_core_section');

        // ---- Output meta pickers (Destination)
        $this->add_dest_output_meta_picker('out_school_count',     'Destination Output → School Count key');
        $this->add_dest_output_meta_picker('out_max_depth',        'Destination Output → Max Depth key');
        $this->add_dest_output_meta_picker('out_min_course_price', 'Destination Output → Min Course Price key');
        $this->add_dest_output_meta_picker('out_languages',        'Destination Output → Languages key');
        $this->add_dest_output_meta_picker('out_facilities',       'Destination Output → Facilities key');
    }

    private function add_dest_output_meta_picker( string $opt_key, string $label ) {
        add_settings_field($opt_key, $label, function() use ($opt_key, $label){
            $opts  = BFR_Helpers::get_opts();
            $cpt   = $opts['dest_cpt'] ?? 'destinations';
            $list  = BFR_Helpers::get_meta_keys_for_cpt( $cpt );
            $current = (string) ($opts[$opt_key] ?? '');

            // If saved value isn’t in list, default the dropdown to "Custom…"
            $use_custom = $current !== '' && ! in_array( $current, $list, true );

            $select_name = 'bfr_core_options[' . esc_attr($opt_key) . '_select]';
            $input_name  = 'bfr_core_options[' . esc_attr($opt_key) . ']';

            echo '<select name="'. esc_attr($select_name) .'" data-bfr-target="'. esc_attr($opt_key) .'">';
            printf('<option value="" %s>%s</option>', selected(!$use_custom && $current === '', true, false), esc_html('— Select meta key —'));
            foreach ( $list as $key ) {
                printf('<option value="%1$s" %2$s>%1$s</option>',
                    esc_attr($key),
                    selected(!$use_custom && $current === $key, true, false)
                );
            }
            printf('<option value="__custom__" %s>%s</option>',
                selected($use_custom, true, false),
                esc_html('Custom…')
            );
            echo '</select> ';

            printf(
                '<input type="text" class="regular-text bfr-meta-custom %4$s" data-bfr-for="%1$s" name="%2$s" value="%3$s" aria-label="%5$s" placeholder="%6$s" />',
                esc_attr($opt_key),
                esc_attr($input_name),
                esc_attr($current),
                $use_custom ? '' : 'hidden',
                esc_attr($label),
                esc_attr('Type meta key (when using “Custom…”)')
            );

            // One-time JS to toggle the custom input
            static $printed_js = false;
            if ( ! $printed_js ) {
                $printed_js = true;
                ?>
                <script>
                document.addEventListener('change', function(ev){
                    var sel = ev.target;
                    if (!sel.matches('select[name^="bfr_core_options"][name$="_select]"]')) return;
                    var key = sel.getAttribute('data-bfr-target');
                    var input = document.querySelector('input.bfr-meta-custom[data-bfr-for="'+key+'"]');
                    if (!input) return;
                    if (sel.value === '__custom__') {
                        input.classList.remove('hidden');
                        input.removeAttribute('hidden');
                        input.focus();
                    } else {
                        input.value = sel.value || '';
                        input.classList.add('hidden');
                        input.setAttribute('hidden','hidden');
                    }
                });
                </script>
                <style>.hidden{display:none}</style>
                <?php
            }
            echo '<p class="description">Dropdown lists existing meta keys on the Destination CPT. Choose “Custom…” to type a new key.</p>';
        }, 'bfr-core', 'bfr_core_section');
    }

    public function render_settings() {
        if ( ! current_user_can('manage_options') ) return;
        $opts = BFR_Helpers::get_opts();
        ?>
        <div class="wrap">
            <h1>BFR Core</h1>
            <form method="post" action="options.php">
                <?php settings_fields('bfr_core_group'); ?>
                <?php do_settings_sections('bfr-core'); ?>
                <?php submit_button('Save Changes'); ?>
            </form>

            <hr/>
            <h2>Maintenance</h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <input type="hidden" name="action" value="bfr_recalc" />
                <?php wp_nonce_field('bfr_recalc_now'); ?>
                <?php submit_button('Recalculate all now', 'secondary'); ?>
            </form>

            <hr/>
            <h2>Diagnostics</h2>
            <ul>
                <li><strong>Destination CPT:</strong> <?php echo esc_html($opts['dest_cpt']); ?></li>
                <li><strong>Output keys:</strong>
                    school_count=<code><?php echo esc_html($opts['out_school_count']); ?></code>,
                    max_depth=<code><?php echo esc_html($opts['out_max_depth']); ?></code>,
                    min_course_price=<code><?php echo esc_html($opts['out_min_course_price']); ?></code>,
                    languages=<code><?php echo esc_html($opts['out_languages']); ?></code>,
                    facilities=<code><?php echo esc_html($opts['out_facilities']); ?></code>
                </li>
            </ul>
        </div>
        <?php
    }

    public function handle_recalc_now() {
        if ( ! current_user_can('manage_options') || ! check_admin_referer('bfr_recalc_now') ) {
            wp_die('Not allowed');
        }
        BFR_Aggregator::instance()->cron_recalculate_all();
        wp_safe_redirect( add_query_arg('bfr_recalc_done', '1', wp_get_referer() ?: admin_url('options-general.php?page=bfr-core') ) );
        exit;
    }

    private function get_cpt_choices(): array {
        $builtin_exclude = ['attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache','user_request','wp_block','wp_template','wp_template_part','wp_navigation','elementor_library'];
        $types = get_post_types(['show_ui' => true], 'objects');
        $out = [];
        foreach ($types as $slug => $obj) {
            if ( in_array($slug, $builtin_exclude, true) ) continue;
            $out[$slug] = $obj->labels->singular_name ?: $obj->label ?: $slug;
        }
        natcasesort($out);
        return $out;
    }
}