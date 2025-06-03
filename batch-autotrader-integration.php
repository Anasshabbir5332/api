<?php
/**
 * AutoTrader API Integration with TF Car Listing Plugin
 * 
 * This file handles the integration between AutoTrader API and TF Car Listing Plugin.
 * It includes functions for fetching data from the API, creating/updating listings,
 * mapping API data to plugin meta fields, handling images, and automating the process.
 * 
 * Enhanced with:
 * - Proper settings registration
 * - Sync history logging
 * - Email reporting
 * - Admin history page
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class AutoTrader_Integration {
    private $api;
    private $debug_mode = true; // Set to true for debugging
    private $db_version = '1.0';
    private $table_name;
    private $batch_size = 5; // Number of listings to process per batch
    private $batch_option_name = 'autotrader_sync_batch_state'; // Option name to store batch state

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'autotrader_sync_logs';
        
        // Initialize the API
        require_once(TF_PLUGIN_PATH . 'autotraderauthapi.php');
        $this->api = new AutoTrader_API();

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Register cron job
        add_action('autotrader_sync_event', array($this, 'sync_autotrader_listings'));

        // Add AJAX handlers
        add_action('wp_ajax_sync_autotrader_listings', array($this, 'ajax_sync_autotrader_listings'));
        
        // Add debug action for template issues
        add_action('template_redirect', array($this, 'debug_template'));
        
        // Register activation hook for database setup
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        
        // Check and create table on admin page load as a fallback
        add_action('admin_init', array($this, 'check_and_create_table'));
    }
    
    /**
     * Check if table exists and create it if it doesn't
     * This is a fallback in case the activation hook doesn't fire
     */
    public function check_and_create_table() {
        global $wpdb;
        
        // Only run this check on our plugin pages
        $screen = get_current_screen();
        if (!$screen || (strpos($screen->id, 'autotrader-sync') === false && strpos($screen->id, 'listing') === false)) {
            return;
        }
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        
        if (!$table_exists) {
            $this->create_logs_table();
            $this->log('Created sync logs table as fallback');
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate_plugin() {
        // Create database table
        $this->create_logs_table();
        
        // Schedule cron job if enabled
        $this->setup_cron_job();
    }
    
    /**
     * Create logs table
     */
    private function create_logs_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            sync_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            sync_type varchar(50) NOT NULL,
            advertiser_id varchar(50) NOT NULL,
            created_count int NOT NULL,
            updated_count int NOT NULL,
            deleted_count int NOT NULL,
            skipped_count int NOT NULL,
            status varchar(20) NOT NULL,
            duration float NOT NULL,
            error_message text NULL,
            details longtext NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        // Use direct query instead of dbDelta for better compatibility
        $wpdb->query($sql);
        
        // Log table creation
        $this->log('Sync logs table created or verified');
        
        // Add a test record to verify table is working
        $test_record = array(
            'sync_time' => current_time('mysql'),
            'sync_type' => 'test',
            'advertiser_id' => 'test',
            'created_count' => 0,
            'updated_count' => 0,
            'deleted_count' => 0,
            'skipped_count' => 0,
            'status' => 'success',
            'duration' => 0,
            'error_message' => '',
            'details' => json_encode(array('message' => 'Test record to verify table creation'))
        );
        
        $wpdb->insert($this->table_name, $test_record);
        $this->log('Test record inserted into sync logs table');
    }
    
    /**
     * Setup cron job
     */
    private function setup_cron_job() {
        if (get_option('autotrader_sync_enabled', 0)) {
            $frequency = get_option('autotrader_sync_frequency', 'daily');
            
            if (!wp_next_scheduled('autotrader_sync_event')) {
                wp_schedule_event(time(), $frequency, 'autotrader_sync_event');
            }
        }
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Register settings group
        register_setting(
            'autotrader_sync_options', // Option group
            'autotrader_sync_enabled', // Option name
            array(
                'type' => 'boolean',
                'sanitize_callback' => 'intval',
                'default' => 0
            )
        );
        
        register_setting(
            'autotrader_sync_options', // Option group
            'autotrader_sync_frequency', // Option name
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'daily'
            )
        );
        
        register_setting(
            'autotrader_sync_options', // Option group
            'autotrader_email_reports', // Option name
            array(
                'type' => 'boolean',
                'sanitize_callback' => 'intval',
                'default' => 0
            )
        );
        
        register_setting(
            'autotrader_sync_options', // Option group
            'autotrader_email_recipient', // Option name
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_email',
                'default' => get_option('admin_email')
            )
        );
        
        // Add settings section
        add_settings_section(
            'autotrader_sync_section', // ID
            'Sync Settings', // Title
            array($this, 'sync_section_callback'), // Callback
            'autotrader_sync_options' // Page
        );
        
        // Add settings fields
        add_settings_field(
            'autotrader_sync_enabled', // ID
            'Enable Automatic Sync', // Title
            array($this, 'sync_enabled_callback'), // Callback
            'autotrader_sync_options', // Page
            'autotrader_sync_section' // Section
        );
        
        add_settings_field(
            'autotrader_sync_frequency', // ID
            'Sync Frequency', // Title
            array($this, 'sync_frequency_callback'), // Callback
            'autotrader_sync_options', // Page
            'autotrader_sync_section' // Section
        );
        
        add_settings_field(
            'autotrader_email_reports', // ID
            'Email Reports', // Title
            array($this, 'email_reports_callback'), // Callback
            'autotrader_sync_options', // Page
            'autotrader_sync_section' // Section
        );
        
        add_settings_field(
            'autotrader_email_recipient', // ID
            'Email Recipient', // Title
            array($this, 'email_recipient_callback'), // Callback
            'autotrader_sync_options', // Page
            'autotrader_sync_section' // Section
        );
    }
    
    /**
     * Section callback
     */
    public function sync_section_callback() {
        echo '<p>Configure automatic synchronization settings for AutoTrader listings.</p>';
    }
    
    /**
     * Sync enabled callback
     */
    public function sync_enabled_callback() {
        $enabled = get_option('autotrader_sync_enabled', 0);
        echo '<input type="checkbox" name="autotrader_sync_enabled" value="1" ' . checked(1, $enabled, false) . '/>';
        echo '<p class="description">Enable automatic synchronization of listings from AutoTrader.</p>';
    }
    
    /**
     * Sync frequency callback
     */
    public function sync_frequency_callback() {
        $frequency = get_option('autotrader_sync_frequency', 'daily');
        echo '<select name="autotrader_sync_frequency">';
        echo '<option value="hourly" ' . selected('hourly', $frequency, false) . '>Hourly</option>';
        echo '<option value="twicedaily" ' . selected('twicedaily', $frequency, false) . '>Twice Daily</option>';
        echo '<option value="daily" ' . selected('daily', $frequency, false) . '>Daily</option>';
        echo '</select>';
        echo '<p class="description">How often should the sync process run.</p>';
    }
    
    /**
     * Email reports callback
     */
    public function email_reports_callback() {
        $enabled = get_option('autotrader_email_reports', 0);
        echo '<input type="checkbox" name="autotrader_email_reports" value="1" ' . checked(1, $enabled, false) . '/>';
        echo '<p class="description">Send email reports after each sync process.</p>';
    }
    
    /**
     * Email recipient callback
     */
    public function email_recipient_callback() {
        $recipient = get_option('autotrader_email_recipient', get_option('admin_email'));
        echo '<input type="email" name="autotrader_email_recipient" value="' . esc_attr($recipient) . '" class="regular-text" />';
        echo '<p class="description">Email address to receive sync reports. Default is the admin email.</p>';
    }
    
    /**
     * Debug template issues
     */
    public function debug_template() {
        if ($this->debug_mode && (is_post_type_archive('listing') || is_singular('listing'))) {
            global $wp_query, $template;
            error_log('Current template: ' . $template);
            error_log('Query vars: ' . print_r($wp_query->query_vars, true));
        }
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=listing',
            'AutoTrader Sync',
            'AutoTrader Sync',
            'manage_options',
            'autotrader-sync',
            array($this, 'render_admin_page')
        );
        
        add_submenu_page(
            'edit.php?post_type=listing',
            'AutoTrader Sync History',
            'Sync History',
            'manage_options',
            'autotrader-sync-history',
            array($this, 'render_history_page')
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Check and create table if needed
        $this->check_and_create_table();
        
        ?>
        <div class="wrap">
            <h1>AutoTrader API Integration</h1>
            <p>Use this page to synchronize car listings from AutoTrader API.</p>
            
            <div class="card">
                <h2>Manual Sync</h2>
                <p>Click the button below to manually sync listings from AutoTrader.</p>
                <button id="sync-autotrader-btn" class="button button-primary">Sync Now</button>
                <div id="sync-status"></div>
            </div>
            
            <div class="card">
                <h2>Automatic Sync</h2>
                <p>Set up automatic synchronization of listings from AutoTrader.</p>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('autotrader_sync_options');
                    do_settings_sections('autotrader_sync_options');
                    submit_button('Save Settings');
                    ?>
                </form>
            </div>
            
            <div class="card">
                <h2>Recent Sync History</h2>
                <?php $this->display_recent_sync_history(5); ?>
                <p>
                    <a href="<?php echo admin_url('edit.php?post_type=listing&page=autotrader-sync-history'); ?>" class="button">
                        View Full History
                    </a>
                </p>
            </div>
            
            <div class="card">
                <h2>Database Table Status</h2>
                <?php $this->display_table_status(); ?>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#sync-autotrader-btn').on('click', function() {
                    var $button = $(this);
                    var $status = $('#sync-status');
                    
                    $button.prop('disabled', true);
                    $status.html('<p>Syncing listings... Please wait.</p>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'sync_autotrader_listings',
                            nonce: '<?php echo wp_create_nonce('sync_autotrader_listings_nonce'); ?>'
                        },
                        success: function(response) {
                            $status.html('<p>' + response.data + '</p>');
                            $button.prop('disabled', false);
                            
                            // Refresh the recent history section
                            location.reload();
                        },
                        error: function() {
                            $status.html('<p>Error occurred during sync. Please try again.</p>');
                            $button.prop('disabled', false);
                        }
                    });
                });
            });
        </script>
        <?php
    }
    
    /**
     * Display table status
     */
    private function display_table_status() {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        
        if ($table_exists) {
            echo '<div class="notice notice-success inline"><p>✅ Sync logs table exists: <code>' . esc_html($this->table_name) . '</code></p></div>';
            
            // Count records
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
            echo '<p>Total sync records: ' . intval($count) . '</p>';
            
            // Show table structure
            echo '<p><strong>Table Structure:</strong></p>';
            $structure = $wpdb->get_results("DESCRIBE {$this->table_name}");
            
            if (!empty($structure)) {
                echo '<table class="widefat striped" style="max-width: 600px;">';
                echo '<thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr></thead>';
                echo '<tbody>';
                
                foreach ($structure as $column) {
                    echo '<tr>';
                    echo '<td>' . esc_html($column->Field) . '</td>';
                    echo '<td>' . esc_html($column->Type) . '</td>';
                    echo '<td>' . esc_html($column->Null) . '</td>';
                    echo '<td>' . esc_html($column->Key) . '</td>';
                    echo '</tr>';
                }
                
                echo '</tbody></table>';
            }
            
            // Add manual repair button
            echo '<p><button id="repair-table-btn" class="button">Repair/Recreate Table</button></p>';
            echo '<script>
                jQuery(document).ready(function($) {
                    $("#repair-table-btn").on("click", function() {
                        if (confirm("Are you sure you want to repair/recreate the sync logs table? This will not delete existing data.")) {
                            $(this).prop("disabled", true).text("Repairing...");
                            
                            $.ajax({
                                url: ajaxurl,
                                type: "POST",
                                data: {
                                    action: "repair_sync_table",
                                    nonce: "' . wp_create_nonce('repair_sync_table_nonce') . '"
                                },
                                success: function(response) {
                                    alert("Table repair completed. Page will now reload.");
                                    location.reload();
                                },
                                error: function() {
                                    alert("Error occurred during table repair. Please try again.");
                                    $("#repair-table-btn").prop("disabled", false).text("Repair/Recreate Table");
                                }
                            });
                        }
                    });
                });
            </script>';
            
            // Add AJAX handler for table repair
            add_action('wp_ajax_repair_sync_table', array($this, 'ajax_repair_sync_table'));
            
        } else {
            echo '<div class="notice notice-error inline"><p>❌ Sync logs table does not exist!</p></div>';
            echo '<p>The table <code>' . esc_html($this->table_name) . '</code> was not found in your database.</p>';
            echo '<p><button id="create-table-btn" class="button button-primary">Create Table Now</button></p>';
            
            echo '<script>
                jQuery(document).ready(function($) {
                    $("#create-table-btn").on("click", function() {
                        $(this).prop("disabled", true).text("Creating...");
                        
                        $.ajax({
                            url: ajaxurl,
                            type: "POST",
                            data: {
                                action: "create_sync_table",
                                nonce: "' . wp_create_nonce('create_sync_table_nonce') . '"
                            },
                            success: function(response) {
                                alert("Table created successfully. Page will now reload.");
                                location.reload();
                            },
                            error: function() {
                                alert("Error occurred during table creation. Please try again.");
                                $("#create-table-btn").prop("disabled", false).text("Create Table Now");
                            }
                        });
                    });
                });
            </script>';
            
            // Add AJAX handler for table creation
            add_action('wp_ajax_create_sync_table', array($this, 'ajax_create_sync_table'));
        }
    }
    
    /**
     * AJAX handler for creating sync table
     */
    public function ajax_create_sync_table() {
        check_ajax_referer('create_sync_table_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to perform this action.');
        }
        
        $this->create_logs_table();
        wp_send_json_success('Table created successfully.');
    }
    
    /**
     * AJAX handler for repairing sync table
     */
    public function ajax_repair_sync_table() {
        check_ajax_referer('repair_sync_table_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to perform this action.');
        }
        
        global $wpdb;
        
        // Backup existing data
        $existing_data = $wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
        
        // Drop and recreate table
        $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
        $this->create_logs_table();
        
        // Restore data if any exists
        if (!empty($existing_data)) {
            foreach ($existing_data as $row) {
                // Remove the id to let it auto-increment
                unset($row['id']);
                $wpdb->insert($this->table_name, $row);
            }
        }
        
        wp_send_json_success('Table repaired successfully.');
    }
    
    /**
     * Render history page
     */
    public function render_history_page() {
        // Check and create table if needed
        $this->check_and_create_table();
        
        ?>
        <div class="wrap">
            <h1>AutoTrader Sync History</h1>
            <p>View the history of AutoTrader synchronization sessions.</p>
            
            <?php $this->display_sync_history(); ?>
        </div>
        <?php
    }
    
    /**
     * Display recent sync history
     */
    private function display_recent_sync_history($limit = 5) {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        
        if (!$table_exists) {
            echo '<div class="notice notice-error inline"><p>Sync history table does not exist. Please visit the settings page to create it.</p></div>';
            return;
        }
        
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY sync_time DESC LIMIT %d",
                $limit
            )
        );
        
        if (empty($logs)) {
            echo '<p>No sync history available.</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Date/Time</th>';
        echo '<th>Type</th>';
        echo '<th>Created</th>';
        echo '<th>Updated</th>';
        echo '<th>Deleted</th>';
        echo '<th>Skipped</th>';
        echo '<th>Status</th>';
        echo '<th>Duration</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($logs as $log) {
            $status_class = $log->status === 'success' ? 'success' : 'error';
            
            echo '<tr>';
            echo '<td>' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->sync_time)) . '</td>';
            echo '<td>' . esc_html($log->sync_type) . '</td>';
            echo '<td>' . intval($log->created_count) . '</td>';
            echo '<td>' . intval($log->updated_count) . '</td>';
            echo '<td>' . intval($log->deleted_count) . '</td>';
            echo '<td>' . intval($log->skipped_count) . '</td>';
            echo '<td><span class="status-' . $status_class . '">' . esc_html($log->status) . '</span></td>';
            echo '<td>' . number_format($log->duration, 2) . 's</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        
        echo '<style>
            .status-success { color: green; font-weight: bold; }
            .status-error { color: red; font-weight: bold; }
        </style>';
    }
    
    /**
     * Display full sync history with pagination
     */
    private function display_sync_history() {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        
        if (!$table_exists) {
            echo '<div class="notice notice-error inline"><p>Sync history table does not exist. Please visit the settings page to create it.</p></div>';
            return;
        }
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $total_pages = ceil($total_items / $per_page);
        
        // Get logs for current page
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY sync_time DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
        
        if (empty($logs)) {
            echo '<p>No sync history available.</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Date/Time</th>';
        echo '<th>Type</th>';
        echo '<th>Advertiser ID</th>';
        echo '<th>Created</th>';
        echo '<th>Updated</th>';
        echo '<th>Deleted</th>';
        echo '<th>Skipped</th>';
        echo '<th>Status</th>';
        echo '<th>Duration</th>';
        echo '<th>Details</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($logs as $log) {
            $status_class = $log->status === 'success' ? 'success' : 'error';
            $details = json_decode($log->details, true);
            
            echo '<tr>';
            echo '<td>' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->sync_time)) . '</td>';
            echo '<td>' . esc_html($log->sync_type) . '</td>';
            echo '<td>' . esc_html($log->advertiser_id) . '</td>';
            echo '<td>' . intval($log->created_count) . '</td>';
            echo '<td>' . intval($log->updated_count) . '</td>';
            echo '<td>' . intval($log->deleted_count) . '</td>';
            echo '<td>' . intval($log->skipped_count) . '</td>';
            echo '<td><span class="status-' . $status_class . '">' . esc_html($log->status) . '</span></td>';
            echo '<td>' . number_format($log->duration, 2) . 's</td>';
            echo '<td>';
            echo '<button type="button" class="button toggle-details" data-id="' . $log->id . '">View Details</button>';
            echo '</td>';
            echo '</tr>';
            
            // Details row (hidden by default)
            echo '<tr class="details-row" id="details-' . $log->id . '" style="display: none;">';
            echo '<td colspan="10">';
            
            if (!empty($log->error_message)) {
                echo '<div class="error-message"><strong>Error:</strong> ' . esc_html($log->error_message) . '</div>';
            }
            
            if (!empty($details)) {
                echo '<div class="details-content">';
                echo '<h4>Sync Details:</h4>';
                
                if (isset($details['skipped_listings']) && !empty($details['skipped_listings'])) {
                    echo '<h5>Skipped Listings:</h5>';
                    echo '<ul>';
                    foreach ($details['skipped_listings'] as $stock_id => $reason) {
                        echo '<li><strong>' . esc_html($stock_id) . ':</strong> ' . esc_html($reason) . '</li>';
                    }
                    echo '</ul>';
                }
                
                echo '</div>';
            }
            
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        
        // Pagination
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages">';
        
        if ($total_pages > 1) {
            echo '<span class="displaying-num">' . $total_items . ' items</span>';
            
            echo '<span class="pagination-links">';
            
            // First page
            if ($current_page > 1) {
                echo '<a class="first-page button" href="' . add_query_arg('paged', 1) . '"><span class="screen-reader-text">First page</span><span aria-hidden="true">&laquo;</span></a>';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
            }
            
            // Previous page
            if ($current_page > 1) {
                echo '<a class="prev-page button" href="' . add_query_arg('paged', max(1, $current_page - 1)) . '"><span class="screen-reader-text">Previous page</span><span aria-hidden="true">&lsaquo;</span></a>';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
            }
            
            // Current page
            echo '<span class="paging-input">' . $current_page . ' of <span class="total-pages">' . $total_pages . '</span></span>';
            
            // Next page
            if ($current_page < $total_pages) {
                echo '<a class="next-page button" href="' . add_query_arg('paged', min($total_pages, $current_page + 1)) . '"><span class="screen-reader-text">Next page</span><span aria-hidden="true">&rsaquo;</span></a>';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
            }
            
            // Last page
            if ($current_page < $total_pages) {
                echo '<a class="last-page button" href="' . add_query_arg('paged', $total_pages) . '"><span class="screen-reader-text">Last page</span><span aria-hidden="true">&raquo;</span></a>';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
            }
            
            echo '</span>';
        }
        
        echo '</div>';
        echo '</div>';
        
        echo '<style>
            .status-success { color: green; font-weight: bold; }
            .status-error { color: red; font-weight: bold; }
            .error-message { background-color: #ffeeee; padding: 10px; border-left: 4px solid #dc3232; margin-bottom: 10px; }
            .details-content { background-color: #f8f8f8; padding: 10px; border-left: 4px solid #00a0d2; }
        </style>';
        
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                $(".toggle-details").on("click", function() {
                    var id = $(this).data("id");
                    $("#details-" + id).toggle();
                });
            });
        </script>';
    }

        /**
     * AJAX handler for manual sync
     */
    public function ajax_sync_autotrader_listings() {
        // Verify nonce
        check_ajax_referer("sync_autotrader_listings_nonce", "nonce");

        // Check user capabilities
        if (!current_user_can("manage_options")) {
            wp_send_json_error("Permission denied.");
        }

        // Start the sync process (now handles batching)
        $result = $this->sync_autotrader_listings("manual");

        // Send JSON response
        if (isset($result["error"])) {
            wp_send_json_error($result["error"]);
        } else {
            wp_send_json_success($result["message"]);
        }
    }

    /**
     * Main sync function (handles batching)
     *
     * @param string $sync_type Type of sync ("manual" or "cron")
     * @return array Result array with message or error
     */
    public function sync_autotrader_listings($sync_type = "manual") {
        set_time_limit(300); // Set time limit for this batch (e.g., 5 minutes)
        $start_time = microtime(true);
        $this->log("Starting AutoTrader sync process (Type: {$sync_type})");

        // Use hardcoded Advertiser ID
        $advertiser_id = "10012495"; 
        $this->log("Using hardcoded Advertiser ID: {$advertiser_id}"); // Add log for clarity
        // No need to check if empty anymore as it's hardcoded
        $this->log("Using Advertiser ID: {$advertiser_id}");

        // --- Batch Processing Logic ---
        $batch_state = get_option($this->batch_option_name, false);
        $current_batch_processed = 0;
        $batch_created = 0;
        $batch_updated = 0;
        $batch_skipped = 0;
        $batch_skipped_listings = array();

        try {
            if ($batch_state === false || !isset($batch_state["stock_data"])) {
                // --- Start of a new sync run (Fetch all data) ---
                $this->log("Starting new sync run. Fetching all stock data...");
                $all_stock_data = $this->get_stock_data($advertiser_id); // Fetches all pages

                if ($all_stock_data === false || !is_array($all_stock_data)) {
                    $error_msg = "Failed to fetch stock data from API or invalid format.";
                    $this->log($error_msg);
                    // Log initial failure to DB if possible
                    $this->log_sync_result($start_time, $sync_type, $advertiser_id, 0, 0, 0, 0, "failed", $error_msg);
                    return array("error" => $error_msg);
                }
                
                $total_items = count($all_stock_data);
                $this->log("Fetched {$total_items} total listings from API.");

                // Initialize batch state
                $batch_state = array(
                    "start_time" => $start_time, // Store the overall start time
                    "sync_type" => $sync_type,
                    "advertiser_id" => $advertiser_id,
                    "total_items" => $total_items,
                    "processed_items" => 0,
                    "current_index" => 0,
                    "stock_data" => $all_stock_data, // Store all fetched data
                    "processed_ids" => array(), // Store WP Post IDs processed across all batches
                    "total_created" => 0,
                    "total_updated" => 0,
                    "total_skipped" => 0,
                    "total_skipped_listings" => array(),
                    "existing_listings" => $this->get_existing_listings() // Get existing listings once at the start
                );
                update_option($this->batch_option_name, $batch_state, false); // Use autoload false
                $this->log("Initialized batch state. Total items: {$total_items}");

            } else {
                // --- Continuing an existing sync run ---
                $total_items = $batch_state["total_items"];
                $this->log("Continuing sync run. Processed {$batch_state["processed_items"]} of {$total_items} items.");
            }

            // Determine items for the current batch
            $start_index = $batch_state["current_index"];
            $end_index = min($start_index + $this->batch_size, $total_items);
            // Use array_slice on the stored full data
            $stock_data_batch = array_slice($batch_state["stock_data"], $start_index, $this->batch_size, true); 

            $this->log("Processing batch: Index {$start_index} to " . ($end_index - 1));

            // --- Process the current batch ---
            foreach ($stock_data_batch as $car_index => $car) { // $car_index is the original index from the full list
                $stock_id = isset($car["metadata"]["stockId"]) ? $car["metadata"]["stockId"] : (isset($car["metadata"]["externalStockId"]) ? $car["metadata"]["externalStockId"] : "unknown");
                $this->log("Processing listing #" . ($car_index + 1) . " (Overall Index: {$car_index}) with Stock ID: {$stock_id}");

                // Skip listings with advertiserAdvert status NOT_PUBLISHED
                if (isset($car["adverts"]["retailAdverts"]["advertiserAdvert"]["status"]) && $car["adverts"]["retailAdverts"]["advertiserAdvert"]["status"] === "NOT_PUBLISHED") {
                    $this->log("Skipping NOT_PUBLISHED listing: {$stock_id}");
                    $batch_skipped++;
                    $batch_skipped_listings[] = $stock_id;
                    $current_batch_processed++; // Count as processed for batch progress
                    continue;
                }

                // Process the listing (create or update)
                // Pass only the car data and the full list of existing WP listings
                $result = $this->process_listing($car, $batch_state["existing_listings"]); 

                if ($result["status"] === "created") {
                    $batch_created++;
                } elseif ($result["status"] === "updated") {
                    $batch_updated++;
                } elseif ($result["status"] === "skipped") { // Should not happen if NOT_PUBLISHED is caught above, but handle defensively
                    $batch_skipped++;
                    $batch_skipped_listings[] = $stock_id;
                }

                if (isset($result["id"]) && $result["id"]) {
                    // Add WP Post ID to the list for deletion check later
                    // Ensure it's added to the main state, not just a local variable
                    $batch_state["processed_ids"][] = $result["id"]; 
                }
                $this->log("Finished processing listing #" . ($car_index + 1) . " (Overall Index: {$car_index}) with Stock ID: {$stock_id}. Status: {$result["status"]}");
                $current_batch_processed++;
            }
            // --- End of Batch Processing ---

            // Update batch state counters
            $batch_state["processed_items"] += $current_batch_processed;
            $batch_state["current_index"] = $end_index; // Set the starting point for the next batch
            $batch_state["total_created"] += $batch_created;
            $batch_state["total_updated"] += $batch_updated;
            $batch_state["total_skipped"] += $batch_skipped;
            // Merge skipped listings for this batch into the main state
            $batch_state["total_skipped_listings"] = array_unique(array_merge($batch_state["total_skipped_listings"], $batch_skipped_listings));
            
            // Remove duplicates from processed IDs just in case
            $batch_state["processed_ids"] = array_unique($batch_state["processed_ids"]);

            $this->log("Batch complete. Processed items in this batch: {$current_batch_processed}. Total processed: {$batch_state["processed_items"]}/{$total_items}");

            // Check if all items are processed
            if ($batch_state["processed_items"] >= $total_items) {
                // --- Finalizing Sync Run ---
                $this->log("All listings processed. Finalizing sync run.");
                
                // Remove listings that no longer exist in the API
                // Use the accumulated processed_ids from the batch state
                $deleted_count = $this->delete_old_listings($batch_state["existing_listings"], $batch_state["processed_ids"]);
                
                $overall_start_time = $batch_state["start_time"]; // Use the stored start time
                $end_time = microtime(true);
                $duration = round($end_time - $overall_start_time, 2);
                $final_message = sprintf(
                    "Sync complete. Total Processed: %d. Created: %d, Updated: %d, Deleted: %d, Skipped: %d. Total Duration: %.2f seconds.",
                    $batch_state["processed_items"],
                    $batch_state["total_created"],
                    $batch_state["total_updated"],
                    $deleted_count,
                    $batch_state["total_skipped"],
                    $duration
                );
                $this->log($final_message);
                
                // Log final result to DB
                $final_log_data = array(
                    'sync_time' => current_time('mysql'),
                    'sync_type' => $batch_state["sync_type"],
                    'advertiser_id' => $batch_state["advertiser_id"],
                    'created_count' => $batch_state["total_created"],
                    'updated_count' => $batch_state["total_updated"],
                    'deleted_count' => $deleted_count,
                    'skipped_count' => $batch_state["total_skipped"],
                    'status' => "success",
                    'duration' => $duration,
                    'error_message' => "",
                    'details' => json_encode(array(
                        "skipped_listings" => $batch_state["total_skipped_listings"]
                        // Duration is now a top-level field
                    ))
                );
                $this->save_sync_log($final_log_data); // Use correct function name
                
                // Send email report if enabled
                if (get_option("autotrader_email_reports", 0)) { // Check if email reports are enabled in settings
                    $this->send_sync_report_email($final_log_data); // Use correct function name
                }
                
                // Clear the batch state
                delete_option($this->batch_option_name);
                $this->log("Cleared batch state.");
                
                return array("message" => $final_message);

            } else {
                // --- More Batches Needed ---
                // Save the updated state for the next run
                update_option($this->batch_option_name, $batch_state, false); // Use autoload false
                
                $progress_message = sprintf(
                    "Processed batch %d - %d of %d listings. (%d Created, %d Updated, %d Skipped in this batch). Click Sync Now again to continue.",
                    $start_index + 1,
                    $batch_state["processed_items"],
                    $total_items,
                    $batch_created,
                    $batch_updated,
                    $batch_skipped
                );
                $this->log($progress_message);
                // Return progress message, indicating more work to do
                return array("message" => $progress_message, "continue" => true); 
            }

        } catch (Exception $e) {
            $error_msg = "An exception occurred during batch processing: " . $e->getMessage();
            $this->log($error_msg);
            // Log error to DB using data from batch state if available
            $overall_start_time = isset($batch_state["start_time"]) ? $batch_state["start_time"] : $start_time;
            $this->log_sync_result(
                $overall_start_time,
                isset($batch_state["sync_type"]) ? $batch_state["sync_type"] : $sync_type, 
                isset($batch_state["advertiser_id"]) ? $batch_state["advertiser_id"] : $advertiser_id, 
                isset($batch_state["total_created"]) ? $batch_state["total_created"] : 0, 
                isset($batch_state["total_updated"]) ? $batch_state["total_updated"] : 0, 
                0, // Deletion happens only at the end
                isset($batch_state["total_skipped"]) ? $batch_state["total_skipped"] : 0, 
                "failed", 
                $error_msg
            );
            // Send email report if enabled
            $this->send_email_report("Failed", $error_msg);
            // Clear potentially corrupted batch state on error
            delete_option($this->batch_option_name);
            $this->log("Cleared batch state due to error.");
            return array("error" => $error_msg);
        }
    }

    /**
     * Save sync log to database
     */
    private function save_sync_log($log_data) {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        
        if (!$table_exists) {
            $this->create_logs_table();
        }
        
        $wpdb->insert(
            $this->table_name,
            $log_data
        );
        
        if ($wpdb->last_error) {
            $this->log('Error saving sync log: ' . $wpdb->last_error);
        } else {
            $this->log('Sync log saved with ID: ' . $wpdb->insert_id);
        }
    }
    
    /**
     * Send sync report email
     */
    private function send_sync_report_email($log_data) {
        $recipient = get_option('autotrader_email_recipient', get_option('admin_email'));
        $site_name = get_bloginfo('name');
        $sync_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log_data['sync_time']));
        
        $subject = "[{$site_name}] AutoTrader Sync Report - {$sync_time}";
        
        $body = "<h2>AutoTrader Sync Report</h2>";
        $body .= "<p><strong>Date/Time:</strong> {$sync_time}</p>";
        $body .= "<p><strong>Sync Type:</strong> " . ucfirst($log_data['sync_type']) . "</p>";
        $body .= "<p><strong>Advertiser ID:</strong> {$log_data['advertiser_id']}</p>";
        $body .= "<p><strong>Status:</strong> " . ucfirst($log_data['status']) . "</p>";
        $body .= "<p><strong>Duration:</strong> " . number_format($log_data['duration'], 2) . " seconds</p>";
        
        $body .= "<h3>Results:</h3>";
        $body .= "<ul>";
        $body .= "<li><strong>Created:</strong> {$log_data['created_count']} listings</li>";
        $body .= "<li><strong>Updated:</strong> {$log_data['updated_count']} listings</li>";
        $body .= "<li><strong>Deleted:</strong> {$log_data['deleted_count']} listings</li>";
        $body .= "<li><strong>Skipped:</strong> {$log_data['skipped_count']} listings</li>";
        $body .= "</ul>";
        
        if (!empty($log_data['error_message'])) {
            $body .= "<h3>Error:</h3>";
            $body .= "<p style='color: red;'>{$log_data['error_message']}</p>";
        }
        
        $details = json_decode($log_data['details'], true);
        if (isset($details['skipped_listings']) && !empty($details['skipped_listings'])) {
            $body .= "<h3>Skipped Listings:</h3>";
            $body .= "<ul>";
            foreach ($details['skipped_listings'] as $stock_id => $reason) {
                $body .= "<li><strong>{$stock_id}:</strong> {$reason}</li>";
            }
            $body .= "</ul>";
        }
        
        $body .= "<p>You can view the full sync history in the WordPress admin under Listings > Sync History.</p>";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        wp_mail($recipient, $subject, $body, $headers);
    }

    /**
     * Get stock data from API with pagination support
     * 
     * @param string $advertiser_id The Advertiser ID to fetch stock for
     * @param int $max_pages Maximum number of pages to retrieve (0 for all)
     * @return array Parsed stock data
     */
    private function get_stock_data($advertiser_id = '10012495', $max_pages = 0) {
        $all_results = array();
        $page = 1;
        $page_size = 100; // Fetch 100 items per page
        $total_pages = 1; // Will be updated after first request
        $total_fetched = 0;
        
        $this->log('Starting paginated data fetch with page size: ' . $page_size);
        
        // Loop through pages until we have all data or reach max_pages
        while (($max_pages === 0 || $page <= $max_pages) && $page <= $total_pages) {
            $this->log('Fetching page ' . $page . ' of ' . ($max_pages > 0 ? min($max_pages, $total_pages) : $total_pages));
            
            // Get data for current page
            $response = $this->api->get_stock_data($advertiser_id, $page, $page_size);
            $data = json_decode($response, true);
            
            // Check if we have valid data
            if (is_array($data) && isset($data['results']) && is_array($data['results'])) {
                $page_results = count($data['results']);
                $total_fetched += $page_results;
                $this->log('Received ' . $page_results . ' results on page ' . $page);
                
                // Add results to our collection
                $all_results = array_merge($all_results, $data['results']);
                
                // Update total pages if available in response
                if (isset($data['pagination']) && isset($data['pagination']['totalPages'])) {
                    $total_pages = $data['pagination']['totalPages'];
                    $this->log('Total pages: ' . $total_pages);
                } else if ($page_results < $page_size) {
                    // If we got fewer results than page size, we're on the last page
                    $total_pages = $page;
                    $this->log('Reached last page based on result count');
                }
                
                // Move to next page
                $page++;
                
                // Add a small delay to avoid rate limiting
                usleep(500000); // 0.5 second delay
            } else {
                $this->log('No valid data from API on page ' . $page);
                break;
            }
        }
        
        $this->log('Completed data fetch. Total items: ' . $total_fetched);
        
        return $all_results;
    }

    /**
     * Process a single listing
     */
    private function process_listing($car) {
        // Check if listing exists by stock ID
        $existing_id = $this->find_existing_listing($car);
        
        if ($existing_id) {
            // Update existing listing
            $post_id = $this->update_listing($existing_id, $car);
            $status = 'updated';
        } else {
            // Create new listing
            $post_id = $this->create_listing($car);
            $status = 'created';
        }
        
        // Handle images
        if ($post_id && isset($car['media']['images']) && is_array($car['media']['images'])) {
            $this->handle_images($post_id, $car['media']['images']);
        }
        
        return array(
            'id' => $post_id,
            'status' => $status
        );
    }

    /**
     * Find existing listing by stock ID
     */
    private function find_existing_listing($car) {
        $stock_id = '';
        
        // Get stock ID from metadata
        if (isset($car['metadata']['stockId'])) {
            $stock_id = sanitize_text_field($car['metadata']['stockId']);
        } elseif (isset($car['metadata']['externalStockId'])) {
            $stock_id = sanitize_text_field($car['metadata']['externalStockId']);
        }
        
        // Get VIN if available
        $vin = '';
        if (isset($car['vehicle']['vin'])) {
            $vin = sanitize_text_field($car['vehicle']['vin']);
        }
        
        if (empty($stock_id) && empty($vin)) {
            return false;
        }
        
        $args = array(
            'post_type' => 'listing',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_query' => array(
                'relation' => 'OR'
            )
        );
        
        if (!empty($stock_id)) {
            $args['meta_query'][] = array(
                'key' => 'stock_number',
                'value' => $stock_id,
                'compare' => '='
            );
        }
        
        if (!empty($vin)) {
            $args['meta_query'][] = array(
                'key' => 'vin_number',
                'value' => $vin,
                'compare' => '='
            );
        }
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return $query->posts[0]->ID;
        }
        
        return false;
    }

    /**
     * Create a new listing
     */
    private function create_listing($car) {
        // Prepare post data
        $post_data = array(
            'post_title' => $this->generate_car_title($car),
            'post_name' => $this->generate_car_slug($car),
            'post_status' => 'publish',
            'post_type' => 'listing',
            'post_content' => $this->get_car_description($car),
            'post_date' => isset($car['metadata']['lastUpdated']) ? date('Y-m-d H:i:s', strtotime($car['metadata']['lastUpdated'])) : current_time('mysql')
        );
        
        // Insert post
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            $this->log('Error creating listing: ' . $post_id->get_error_message());
            return false;
        }
        
        // Map meta fields
        $this->map_meta_fields($post_id, $car);
        
        // Set taxonomies
        $this->set_taxonomies($post_id, $car);
        
        $this->log('Created new listing: ' . $post_id);
        return $post_id;
    }

    /**
     * Update an existing listing
     */
    private function update_listing($post_id, $car) {
        // Prepare post data
        $post_data = array(
            'ID' => $post_id,
            'post_title' => $this->generate_car_title($car),
            'post_name' => $this->generate_car_slug($car),
            'post_content' => $this->get_car_description($car)
        );
        
        // Update post
        $result = wp_update_post($post_data);
        
        if (is_wp_error($result)) {
            $this->log('Error updating listing: ' . $result->get_error_message());
            return false;
        }
        
        // Map meta fields
        $this->map_meta_fields($post_id, $car);
        
        // Set taxonomies
        $this->set_taxonomies($post_id, $car);
        
        $this->log('Updated listing: ' . $post_id);
        return $post_id;
    }

    /**
     * Set taxonomies for the listing
     */
    private function set_taxonomies($post_id, $car) {
        // Get vehicle data
        $vehicle = isset($car['vehicle']) ? $car['vehicle'] : array();
        $standard = isset($vehicle['standard']) ? $vehicle['standard'] : array();
        
        // Define taxonomies to set
        $taxonomies = array(
            'make' => isset($standard['make']) ? $standard['make'] : (isset($vehicle['make']) ? $vehicle['make'] : 'Default'),
            'model' => isset($standard['model']) ? $standard['model'] : (isset($vehicle['model']) ? $vehicle['model'] : ''),
            'body_type' => isset($standard['bodyType']) ? $standard['bodyType'] : (isset($vehicle['bodyType']) ? $vehicle['bodyType'] : ''),
            'fuel_type' => isset($standard['fuelType']) ? $standard['fuelType'] : (isset($vehicle['fuelType']) ? $vehicle['fuelType'] : ''),
            'transmission' => isset($standard['transmissionType']) ? $standard['transmissionType'] : (isset($vehicle['transmissionType']) ? $vehicle['transmissionType'] : '')
        );
        
        // Set each taxonomy
        foreach ($taxonomies as $taxonomy => $term) {
            if (!empty($term)) {
                wp_set_object_terms($post_id, $term, $taxonomy);
            }
        }
    }

    /**
     * Map API data to plugin meta fields
     */
    private function map_meta_fields($post_id, $car) {
        // Get vehicle data
        $vehicle = isset($car['vehicle']) ? $car['vehicle'] : array();
        $metadata = isset($car['metadata']) ? $car['metadata'] : array();
        $advertiser = isset($car['advertiser']) ? $car['advertiser'] : array();
        $adverts = isset($car['adverts']) ? $car['adverts'] : array();
        
        // Define meta fields mapping
        $meta_fields = array(
            // Vehicle details
            'stock_number' => isset($metadata['stockId']) ? $metadata['stockId'] : '',
            'vin_number' => isset($vehicle['vin']) ? $vehicle['vin'] : '',
            'year' => isset($vehicle['yearOfManufacture']) ? $vehicle['yearOfManufacture'] : '',
            'mileage' => isset($vehicle['odometerReadingMiles']) ? $vehicle['odometerReadingMiles'] : '',
            'engine_size' => isset($vehicle['badgeEngineSizeLitres']) ? $vehicle['badgeEngineSizeLitres'] : '',
            'doors' => isset($vehicle['doors']) ? $vehicle['doors'] : '',
            'seats' => isset($vehicle['seats']) ? $vehicle['seats'] : '',
            
            // Pricing
            'price' => isset($adverts['forecourtPrice']['amountGBP']) ? $adverts['forecourtPrice']['amountGBP'] : 
                      (isset($adverts['retailAdverts']['suppliedPrice']['amountGBP']) ? $adverts['retailAdverts']['suppliedPrice']['amountGBP'] : ''),
            'sale_price' => isset($adverts['retailAdverts']['totalPrice']['amountGBP']) ? $adverts['retailAdverts']['totalPrice']['amountGBP'] : '',
            
            // Dealer info
            'dealer_name' => isset($advertiser['name']) ? $advertiser['name'] : '',
            'dealer_location' => isset($advertiser['location']) ? $this->format_dealer_location($advertiser['location']) : '',
            
            // AutoTrader specific
            'autotrader_id' => isset($metadata['id']) ? $metadata['id'] : '',
            'autotrader_last_updated' => isset($metadata['lastUpdated']) ? $metadata['lastUpdated'] : '',
            'autotrader_lifecycle_state' => isset($metadata['lifecycleState']) ? $metadata['lifecycleState'] : ''
        );
        
        // Set each meta field
        foreach ($meta_fields as $key => $value) {
            if (!empty($value)) {
                update_post_meta($post_id, $key, $value);
            }
        }
    }

    /**
     * Format dealer location
     */
    private function format_dealer_location($location) {
        $parts = array();
        
        if (isset($location['addressLineOne']) && !empty($location['addressLineOne'])) {
            $parts[] = $location['addressLineOne'];
        }
        
        if (isset($location['town']) && !empty($location['town'])) {
            $parts[] = $location['town'];
        }
        
        if (isset($location['county']) && !empty($location['county'])) {
            $parts[] = $location['county'];
        }
        
        if (isset($location['postCode']) && !empty($location['postCode'])) {
            $parts[] = $location['postCode'];
        }
        
        return implode(', ', $parts);
    }

    /**
     * Get car description
     */
    private function get_car_description($car) {
        $description = '';
        
        if (isset($car['adverts']['retailAdverts']['description'])) {
            $description = $car['adverts']['retailAdverts']['description'];
        }
        
        return $description;
    }

    /**
     * Handle images for a listing
     */
    private function handle_images($post_id, $images) {
        $this->log("Starting image handling for post ID: {$post_id}"); // Enhanced logging
        if (empty($images)) {
            $this->log("No images found for post ID: {$post_id}"); // Enhanced logging
            return;
        }
        
        // Get existing images
        $existing_images = get_post_meta($post_id, 'gallery_images', true);
        $existing_array = !empty($existing_images) ? explode(',', $existing_images) : array();
        $new_images = array();
        
        // Process each image
        foreach ($images as $index => $image) {
            if (!isset($image['href'])) {
                continue;
            }
            
            $url = $image["href"];
            $this->log("Processing image URL [{$index}]: {$url}"); // Enhanced logging
            
            // Check if image already exists
            $attachment_id = $this->get_attachment_id_by_url($url);
            
            if (!$attachment_id) {
                // Download and attach the image
                $attachment_id = $this->download_and_attach_image($url, $post_id);
            }
            
            if ($attachment_id) {
                $new_images[] = $attachment_id;
                
                // Set featured image if this is the first image
                if ($index === 0 && !has_post_thumbnail($post_id)) {
                    set_post_thumbnail($post_id, $attachment_id);
                }
            }
            
            // Add a small delay to throttle image downloads and prevent timeouts
            usleep(200000); // 0.2 second delay between image downloads
        }
        
        // Update image gallery
        if (!empty($new_images)) {
            $gallery = implode(',', array_unique(array_merge($existing_array, $new_images)));
            update_post_meta($post_id, 'gallery_images', $gallery);
        }
    }

    /**
     * Download and attach image
     */
    private function download_and_attach_image($url, $post_id) {
        $this->log("Attempting to download image: {$url} for post ID: {$post_id}"); // Enhanced logging
        // Get file name from URL
        $filename = basename($url);
        
        // Create temp file
        $tmp = download_url($url);
        
        if (is_wp_error($tmp)) {
            $this->log("Error downloading image {$url}: " . $tmp->get_error_message()); // Enhanced logging
            $this->log("Error downloading image: " . $tmp->get_error_message());
            return false;
        }
        $this->log("Image downloaded successfully to temp path: {$tmp}"); // Enhanced logging
        
        // Prepare file array
        $file_array = array(
            'name' => basename($url),
            'tmp_name' => $tmp
        );
        
        // Upload the image
        $attachment_id = media_handle_sideload($file_array, $post_id);
        
        // Clean up temp file
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
        
        if (is_wp_error($attachment_id)) {
            $this->log("Error uploading/attaching image {$url} for post {$post_id}: " . $attachment_id->get_error_message()); // Enhanced logging
            $this->log("Error uploading image: " . $attachment_id->get_error_message());
            return false;
        }
        $this->log("Image attached successfully with ID: {$attachment_id} for post {$post_id}"); // Enhanced logging
        
        return $attachment_id;
    }

    /**
     * Get attachment ID by URL
     */
    private function get_attachment_id_by_url($url) {
        global $wpdb;
        
        // First, try to get the attachment ID from the URL
        $attachment_id = attachment_url_to_postid($url);
        
        if ($attachment_id) {
            return $attachment_id;
        }
        
        // If that fails, try to get it from the guid
        $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $url));
        
        if (!empty($attachment)) {
            return $attachment[0];
        }
        
        // If that fails, try to get it from the filename
        $filename = basename($url);
        $query = "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_wp_attached_file' AND meta_value LIKE '%s'";
        $result = $wpdb->get_col($wpdb->prepare($query, '%' . $filename));
        
        if (!empty($result)) {
            return $result[0];
        }
        
        return 0;
    }

    /**
     * Generate car title
     */
    private function generate_car_title($car) {
        $vehicle = isset($car['vehicle']) ? $car['vehicle'] : array();
        $standard = isset($vehicle['standard']) ? $vehicle['standard'] : array();
        
        $year = isset($vehicle['yearOfManufacture']) ? $vehicle['yearOfManufacture'] : date('Y');
        $make = isset($standard['make']) ? $standard['make'] : (isset($vehicle['make']) ? $vehicle['make'] : '');
        $model = isset($standard['model']) ? $standard['model'] : (isset($vehicle['model']) ? $vehicle['model'] : '');
        
        $title_parts = array_filter(array($year, $make, $model));
        
        if (empty($title_parts)) {
            return 'Auto Listing ' . date('Y-m-d');
        }
        
        return implode(' ', $title_parts);
    }

    /**
     * Generate car slug
     */
    private function generate_car_slug($car) {
        $vehicle = isset($car['vehicle']) ? $car['vehicle'] : array();
        $standard = isset($vehicle['standard']) ? $vehicle['standard'] : array();
        $metadata = isset($car['metadata']) ? $car['metadata'] : array();
        
        $year = isset($vehicle['yearOfManufacture']) ? $vehicle['yearOfManufacture'] : '';
        $make = isset($standard['make']) ? sanitize_title($standard['make']) : (isset($vehicle['make']) ? sanitize_title($vehicle['make']) : '');
        $model = isset($standard['model']) ? sanitize_title($standard['model']) : (isset($vehicle['model']) ? sanitize_title($vehicle['model']) : '');
        $stock = isset($metadata['stockId']) ? sanitize_title($metadata['stockId']) : '';
        
        $slug_parts = array_filter(array($make, $model, $year, $stock));
        
        if (empty($slug_parts)) {
            // Create a more descriptive slug when data is missing
            return sanitize_title('auto-listing-' . uniqid());
        }
        
        return implode('-', $slug_parts);
    }

    /**
     * Get existing listings
     */
    private function get_existing_listings() {
        $args = array(
            'post_type' => 'listing',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        
        $query = new WP_Query($args);
        
        return $query->posts;
    }

    /**
     * Delete old listings
     */
    private function delete_old_listings($existing_listings, $processed_listings) {
        $to_delete = array_diff($existing_listings, $processed_listings);
        $deleted = 0;
        
        foreach ($to_delete as $post_id) {
            wp_delete_post($post_id, true);
            $deleted++;
        }
        
        return $deleted;
    }

    /**
     * Log message
     */
    private function log($message) {
        if ($this->debug_mode) {
            error_log('[AutoTrader Integration] ' . $message);
        }
    }
}

// Initialize the integration
$autotrader_integration = new AutoTrader_Integration();

// Register activation hook
register_activation_hook(__FILE__, array($autotrader_integration, 'activate_plugin'));

// Register deactivation hook
register_deactivation_hook(__FILE__, 'autotrader_integration_deactivate');

/**
 * Deactivation function
 */
function autotrader_integration_deactivate() {
    // Clear scheduled hook
    $timestamp = wp_next_scheduled('autotrader_sync_event');
    
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'autotrader_sync_event');
    }
}
