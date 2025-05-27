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
 * - Sync history logging (including detailed errors and failed syncs)
 * - Email reporting (including for failed syncs)
 * - Admin history page
 * - Robust activation and file loading for live server compatibility
 * - Improved image handling with explicit dependency loading and error logging
 * - Handling for {resize} placeholder in image URLs
 * - Output buffering for AJAX responses
 * - Verified gallery meta saving logic with enhanced diagnostics
 * - Detailed exception handling and logging
 */

if (!defined("ABSPATH")) {
    exit; // Exit if accessed directly
}

class AutoTrader_Integration {
    private $api;
    private $debug_mode = true; // Set to true for debugging
    private $db_version = "1.0";
    private $table_name;
    private static $instance = null; // Singleton instance

    /**
     * Private constructor for Singleton pattern
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . "autotrader_sync_logs";

        // Load API file safely
        $api_file_path = plugin_dir_path(__FILE__) . "autotraderauthapi.php";
        if (file_exists($api_file_path)) {
            require_once($api_file_path);
            // Check if the class exists before instantiating
            if (class_exists("AutoTrader_API")) {
                $this->api = new AutoTrader_API();
            } else {
                $error_message = "Error: AutoTrader_API class not found in " . $api_file_path;
                $this->handle_api_load_error($error_message);
            }
        } else {
            $error_message = "Error: Required API file autotraderauthapi.php not found in " . plugin_dir_path(__FILE__);
            $this->handle_api_load_error($error_message);
        }

        // Add hooks only if API loaded successfully
        if ($this->api !== null) {
            $this->add_runtime_hooks();
        } else {
            $this->log("AutoTrader_API could not be initialized. Integration features disabled.");
        }
    }

    /**
     * Handle API loading errors
     */
    private function handle_api_load_error($error_message) {
        error_log("[AutoTrader Integration] " . $error_message);
        if (is_admin()) {
            add_action("admin_notices", function () use ($error_message) {
                echo 
                    '<div class="notice notice-error"><p>
                        <strong>AutoTrader Integration Error:</strong> Required API component failed to load. Please ensure the <code>autotraderauthapi.php</code> file exists in the plugin directory and is readable. <br/>
                        <em>Error details: ' . esc_html($error_message) . '</em>
                    </p></div>';
            });
        }
        $this->api = null;
    }

    /**
     * Add WordPress hooks that run during normal operation
     */
    private function add_runtime_hooks() {
        add_action("admin_menu", array($this, "add_admin_menu"));
        add_action("admin_init", array($this, "register_settings"));
        add_action("autotrader_sync_event", array($this, "run_cron_sync")); // Renamed cron callback
        add_action("wp_ajax_sync_autotrader_listings", array($this, "ajax_sync_autotrader_listings"));
        add_action("wp_ajax_create_sync_table", array($this, "ajax_create_sync_table"));
        add_action("wp_ajax_repair_sync_table", array($this, "ajax_repair_sync_table"));
        add_action("template_redirect", array($this, "debug_template"));
        add_action("admin_init", array($this, "check_and_create_table")); // Fallback table check
        
        // Hook for updating cron schedule when settings change
        add_action("update_option_autotrader_sync_enabled", array($this, "update_cron_schedule"), 10, 2);
        add_action("update_option_autotrader_sync_frequency", array($this, "update_cron_schedule"), 10, 2);
    }

    /**
     * Singleton instance method
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Static activation method
     */
    public static function activate_plugin() {
        // Create database table
        self::create_logs_table();
        // Schedule cron job based on default/existing settings
        self::setup_cron_job_static();
    }

    /**
     * Static deactivation method
     */
    public static function deactivate_plugin() {
        // Clear scheduled hook
        $timestamp = wp_next_scheduled("autotrader_sync_event");
        if ($timestamp) {
            wp_unschedule_event($timestamp, "autotrader_sync_event");
        }
        error_log("[AutoTrader Integration] Static: Cron event unscheduled during deactivation.");
    }

    /**
     * Create logs table (can be called statically or from instance)
     */
    public static function create_logs_table() {
        global $wpdb;
        // Use static property access if needed, or pass table name
        $table_name = $wpdb->prefix . "autotrader_sync_logs";
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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

        // Use direct query for reliability
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php'); // Needed for dbDelta, but we use query
        $wpdb->query($sql);
        error_log("[AutoTrader Integration] Static: Sync logs table created or verified.");
        
        // Check if table was actually created
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        if (!$table_exists) {
             error_log("[AutoTrader Integration] ERROR: Failed to create sync logs table: {$table_name}. Check DB permissions and user privileges.");
        } else {
            // Optionally add a test record only if table is newly created or empty
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            if ($count == 0) {
                 $test_record = array(
                    'sync_time' => current_time('mysql'),
                    'sync_type' => 'activation_test',
                    'advertiser_id' => 'test',
                    'created_count' => 0,
                    'updated_count' => 0,
                    'deleted_count' => 0,
                    'skipped_count' => 0,
                    'status' => 'success',
                    'duration' => 0,
                    'error_message' => '',
                    'details' => json_encode(array('message' => 'Test record inserted during activation/check'))
                );
                $wpdb->insert($table_name, $test_record);
                error_log('[AutoTrader Integration] Static: Test record inserted into sync logs table.');
            }
        }
    }

    /**
     * Setup cron job (static version for activation)
     */
    private static function setup_cron_job_static() {
        // Use get_option with default values
        if (get_option("autotrader_sync_enabled", 0)) {
            $frequency = get_option("autotrader_sync_frequency", "daily");
            if (!wp_next_scheduled("autotrader_sync_event")) {
                wp_schedule_event(time(), $frequency, "autotrader_sync_event");
                error_log("[AutoTrader Integration] Static: Cron event scheduled ({$frequency}).");
            } else {
                 error_log("[AutoTrader Integration] Static: Cron event already scheduled.");
            }
        } else {
             error_log("[AutoTrader Integration] Static: Automatic sync is disabled, cron not scheduled.");
        }
    }
    
    /**
     * Update cron schedule when settings change
     */
    public function update_cron_schedule($old_value, $new_value) {
        // Clear existing schedule first
        $timestamp = wp_next_scheduled("autotrader_sync_event");
        if ($timestamp) {
            wp_unschedule_event($timestamp, "autotrader_sync_event");
            $this->log("Cleared existing cron schedule.");
        }
        
        // Reschedule if enabled
        if (get_option("autotrader_sync_enabled", 0)) {
            $frequency = get_option("autotrader_sync_frequency", "daily");
            wp_schedule_event(time(), $frequency, "autotrader_sync_event");
            $this->log("Cron event rescheduled ({$frequency}).");
        } else {
             $this->log("Automatic sync disabled, cron schedule cleared.");
        }
    }

    /**
     * Check if table exists and create it if it doesn't (Instance method)
     */
    public function check_and_create_table() {
        global $wpdb;
        // Only run this check on our plugin pages
        if (!is_admin()) return; // Only run in admin
        $screen = get_current_screen();
        if (!$screen || (strpos($screen->id, "autotrader-sync") === false && strpos($screen->id, "listing") === false)) {
            return;
        }
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        if (!$table_exists) {
            self::create_logs_table(); // Call static method
            $this->log("Created sync logs table as fallback via admin_init.");
        }
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Register settings group
        register_setting(
            "autotrader_sync_options", // Option group
            "autotrader_sync_enabled", // Option name
            array(
                "type" => "boolean",
                "sanitize_callback" => "intval",
                "default" => 0,
            )
        );

        register_setting(
            "autotrader_sync_options", // Option group
            "autotrader_sync_frequency", // Option name
            array(
                "type" => "string",
                "sanitize_callback" => "sanitize_text_field",
                "default" => "daily",
            )
        );

        register_setting(
            "autotrader_sync_options", // Option group
            "autotrader_email_reports", // Option name
            array(
                "type" => "boolean",
                "sanitize_callback" => "intval",
                "default" => 0,
            )
        );

        register_setting(
            "autotrader_sync_options", // Option group
            "autotrader_email_recipient", // Option name
            array(
                "type" => "string",
                "sanitize_callback" => "sanitize_email",
                "default" => get_option("admin_email"),
            )
        );

        // Add settings section
        add_settings_section(
            "autotrader_sync_section", // ID
            "Sync Settings", // Title
            array($this, "sync_section_callback"), // Callback
            "autotrader_sync_options" // Page
        );

        // Add settings fields
        add_settings_field(
            "autotrader_sync_enabled", // ID
            "Enable Automatic Sync", // Title
            array($this, "sync_enabled_callback"), // Callback
            "autotrader_sync_options", // Page
            "autotrader_sync_section" // Section
        );

        add_settings_field(
            "autotrader_sync_frequency", // ID
            "Sync Frequency", // Title
            array($this, "sync_frequency_callback"), // Callback
            "autotrader_sync_options", // Page
            "autotrader_sync_section" // Section
        );

        add_settings_field(
            "autotrader_email_reports", // ID
            "Email Reports", // Title
            array($this, "email_reports_callback"), // Callback
            "autotrader_sync_options", // Page
            "autotrader_sync_section" // Section
        );

        add_settings_field(
            "autotrader_email_recipient", // ID
            "Email Recipient", // Title
            array($this, "email_recipient_callback"), // Callback
            "autotrader_sync_options", // Page
            "autotrader_sync_section" // Section
        );
    }

    /**
     * Section callback
     */
    public function sync_section_callback() {
        echo "<p>Configure automatic synchronization settings for AutoTrader listings.</p>";
    }

    /**
     * Sync enabled callback
     */
    public function sync_enabled_callback() {
        $enabled = get_option("autotrader_sync_enabled", 0);
        echo '<input type="checkbox" name="autotrader_sync_enabled" value="1" ' . checked(1, $enabled, false) . "/>";
        echo '<p class="description">Enable automatic synchronization of listings from AutoTrader.</p>';
    }

    /**
     * Sync frequency callback
     */
    public function sync_frequency_callback() {
        $frequency = get_option("autotrader_sync_frequency", "daily");
        echo '<select name="autotrader_sync_frequency">';
        echo '<option value="hourly" ' . selected("hourly", $frequency, false) . ">Hourly</option>";
        echo '<option value="twicedaily" ' . selected("twicedaily", $frequency, false) . ">Twice Daily</option>";
        echo '<option value="daily" ' . selected("daily", $frequency, false) . ">Daily</option>";
        echo "</select>";
        echo '<p class="description">How often should the sync process run.</p>';
    }

    /**
     * Email reports callback
     */
    public function email_reports_callback() {
        $enabled = get_option("autotrader_email_reports", 0);
        echo '<input type="checkbox" name="autotrader_email_reports" value="1" ' . checked(1, $enabled, false) . "/>";
        echo '<p class="description">Send email reports after each sync process.</p>';
    }

    /**
     * Email recipient callback
     */
    public function email_recipient_callback() {
        $recipient = get_option("autotrader_email_recipient", get_option("admin_email"));
        echo '<input type="email" name="autotrader_email_recipient" value="' . esc_attr($recipient) . '" class="regular-text" />';
        echo '<p class="description">Email address to receive sync reports. Default is the admin email.</p>';
    }

    /**
     * Debug template issues
     */
    public function debug_template() {
        if ($this->debug_mode && (is_post_type_archive("listing") || is_singular("listing"))) {
            global $wp_query, $template;
            error_log("Current template: " . $template);
            error_log("Query vars: " . print_r($wp_query->query_vars, true));
        }
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            "edit.php?post_type=listing",
            "AutoTrader Sync",
            "AutoTrader Sync",
            "manage_options",
            "autotrader-sync",
            array($this, "render_admin_page")
        );

        add_submenu_page(
            "edit.php?post_type=listing",
            "AutoTrader Sync History",
            "Sync History",
            "manage_options",
            "autotrader-sync-history",
            array($this, "render_history_page")
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Check and create table if needed (fallback)
        $this->check_and_create_table();

        ?>
        <div class="wrap">
            <h1>AutoTrader API Integration</h1>
            <?php 
            // Display API load error if present
            if ($this->api === null) {
                echo '<div class="notice notice-error"><p><strong>API Error:</strong> AutoTrader API failed to load. Sync functionality is disabled. Check error logs and ensure <code>autotraderauthapi.php</code> is present.</p></div>';
            }
            ?>
            <p>Use this page to synchronize car listings from AutoTrader API.</p>
            
            <div class="card">
                <h2>Manual Sync</h2>
                <p>Click the button below to manually sync listings from AutoTrader.</p>
                <button id="sync-autotrader-btn" class="button button-primary" <?php disabled($this->api === null); ?>>Sync Now</button>
                <div id="sync-status"></div>
            </div>
            
            <div class="card">
                <h2>Automatic Sync</h2>
                <p>Set up automatic synchronization of listings from AutoTrader.</p>
                <form method="post" action="options.php">
                    <?php
                    settings_fields("autotrader_sync_options");
                    do_settings_sections("autotrader_sync_options");
                    submit_button("Save Settings");
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
                            nonce: '<?php echo wp_create_nonce("sync_autotrader_listings_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                            } else {
                                // Display detailed error message from server
                                var errorMsg = 'Error: ' + (response.data ? response.data : 'Unknown error occurred during sync.');
                                $status.html('<div class="notice notice-error inline"><p>' + errorMsg + '</p></div>');
                            }
                            $button.prop('disabled', false);
                            // Refresh the page to show updated history and status
                            location.reload(); 
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            // Display more informative AJAX error
                            var errorDetail = jqXHR.responseText ? jqXHR.responseText.substring(0, 500) : 'No response text.'; // Limit response text length
                            $status.html('<div class="notice notice-error inline"><p>AJAX Error occurred during sync: ' + textStatus + ' - ' + errorThrown + '<br><small>Server Response Snippet: ' + errorDetail + '</small></p></div>');
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
            echo "<p>Total sync records: " . intval($count) . "</p>";
            // Show table structure
            echo "<p><strong>Table Structure:</strong></p>";
            $structure = $wpdb->get_results("DESCRIBE {$this->table_name}");
            if (!empty($structure)) {
                echo '<table class="widefat striped" style="max-width: 600px;">';
                echo "<thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr></thead>";
                echo "<tbody>";
                foreach ($structure as $column) {
                    echo "<tr>";
                    echo "<td>" . esc_html($column->Field) . "</td>";
                    echo "<td>" . esc_html($column->Type) . "</td>";
                    echo "<td>" . esc_html($column->Null) . "</td>";
                    echo "<td>" . esc_html($column->Key) . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            }
            // Add manual repair button
            echo '<p><button id="repair-table-btn" class="button">Repair/Recreate Table</button></p>';
            echo '<script>
                jQuery(document).ready(function($) {
                    $("#repair-table-btn").on("click", function() {
                        if (confirm("Are you sure you want to repair/recreate the sync logs table? This will NOT delete existing data.")) {
                            $(this).prop("disabled", true).text("Repairing...");
                            $.ajax({
                                url: ajaxurl,
                                type: "POST",
                                data: {
                                    action: "repair_sync_table",
                                    nonce: "' . wp_create_nonce("repair_sync_table_nonce") . '"
                                },
                                success: function(response) {
                                    alert("Table repair completed. Page will now reload.");
                                    location.reload();
                                },
                                error: function() {
                                    alert("Error occurred during table repair. Please check PHP error logs.");
                                    $("#repair-table-btn").prop("disabled", false).text("Repair/Recreate Table");
                                }
                            });
                        }
                    });
                });
            </script>';
        } else {
            echo '<div class="notice notice-error inline"><p>❌ Sync logs table does not exist!</p></div>';
            echo "<p>The table <code>" . esc_html($this->table_name) . "</code> was not found in your database.</p>";
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
                                nonce: "' . wp_create_nonce("create_sync_table_nonce") . '"
                            },
                            success: function(response) {
                                alert("Table created successfully. Page will now reload.");
                                location.reload();
                            },
                            error: function() {
                                alert("Error occurred during table creation. Please check PHP error logs.");
                                $("#create-table-btn").prop("disabled", false).text("Create Table Now");
                            }
                        });
                    });
                });
            </script>';
        }
    }

    /**
     * AJAX handler for creating sync table
     */
    public function ajax_create_sync_table() {
        check_ajax_referer("create_sync_table_nonce", "nonce");
        if (!current_user_can("manage_options")) {
            wp_send_json_error("Permission denied.");
        }
        self::create_logs_table(); // Call static method
        wp_send_json_success("Table created successfully.");
    }

    /**
     * AJAX handler for repairing sync table
     */
    public function ajax_repair_sync_table() {
        check_ajax_referer("repair_sync_table_nonce", "nonce");
        if (!current_user_can("manage_options")) {
            wp_send_json_error("Permission denied.");
        }
        global $wpdb;
        // Backup existing data
        $existing_data = $wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
        // Drop and recreate table
        $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
        self::create_logs_table(); // Call static method
        // Restore data if any exists
        if (!empty($existing_data)) {
            foreach ($existing_data as $row) {
                unset($row["id"]); // Remove the id to let it auto-increment
                $wpdb->insert($this->table_name, $row);
            }
        }
        wp_send_json_success("Table repaired successfully.");
    }

    /**
     * Render history page
     */
    public function render_history_page() {
        // Check and create table if needed (fallback)
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
            echo '<div class="notice notice-warning inline"><p>Sync history table not found. Please visit the <a href="' . admin_url('edit.php?post_type=listing&page=autotrader-sync') . '">main sync page</a> to create it.</p></div>';
            return;
        }

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY sync_time DESC LIMIT %d",
                $limit
            )
        );

        if (empty($logs)) {
            echo "<p>No sync history available.</p>";
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo "<thead>";
        echo "<tr>";
        echo "<th>Date/Time</th>";
        echo "<th>Type</th>";
        echo "<th>Created</th>";
        echo "<th>Updated</th>";
        echo "<th>Deleted</th>";
        echo "<th>Skipped</th>";
        echo "<th>Status</th>";
        echo "<th>Duration</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";

        foreach ($logs as $log) {
            $status_class = $log->status === "success" ? "success" : "error";
            echo "<tr>";
            echo "<td>" . date_i18n(get_option("date_format") . " " . get_option("time_format"), strtotime($log->sync_time)) . "</td>";
            echo "<td>" . esc_html(ucfirst($log->sync_type)) . "</td>";
            echo "<td>" . intval($log->created_count) . "</td>";
            echo "<td>" . intval($log->updated_count) . "</td>";
            echo "<td>" . intval($log->deleted_count) . "</td>";
            echo "<td>" . intval($log->skipped_count) . "</td>";
            echo '<td><span class="status-' . $status_class . '">' . esc_html(ucfirst($log->status)) . "</span></td>";
            echo "<td>" . number_format($log->duration, 2) . "s</td>";
            echo "</tr>";
        }

        echo "</tbody>";
        echo "</table>";
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
             echo '<div class="notice notice-warning inline"><p>Sync history table not found. Please visit the <a href="' . admin_url('edit.php?post_type=listing&page=autotrader-sync') . '">main sync page</a> to create it.</p></div>';
            return;
        }

        // Pagination
        $per_page = 20;
        $current_page = isset($_GET["paged"]) ? max(1, intval($_GET["paged"])) : 1;
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
            echo "<p>No sync history available.</p>";
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo "<thead>";
        echo "<tr>";
        echo "<th>Date/Time</th>";
        echo "<th>Type</th>";
        echo "<th>Advertiser ID</th>";
        echo "<th>Created</th>";
        echo "<th>Updated</th>";
        echo "<th>Deleted</th>";
        echo "<th>Skipped</th>";
        echo "<th>Status</th>";
        echo "<th>Duration</th>";
        echo "<th>Details</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";

        foreach ($logs as $log) {
            $status_class = $log->status === "success" ? "success" : "error";
            $details = json_decode($log->details, true);
            echo "<tr>";
            echo "<td>" . date_i18n(get_option("date_format") . " " . get_option("time_format"), strtotime($log->sync_time)) . "</td>";
            echo "<td>" . esc_html(ucfirst($log->sync_type)) . "</td>";
            echo "<td>" . esc_html($log->advertiser_id) . "</td>";
            echo "<td>" . intval($log->created_count) . "</td>";
            echo "<td>" . intval($log->updated_count) . "</td>";
            echo "<td>" . intval($log->deleted_count) . "</td>";
            echo "<td>" . intval($log->skipped_count) . "</td>";
            echo '<td><span class="status-' . $status_class . '">' . esc_html(ucfirst($log->status)) . "</span></td>";
            echo "<td>" . number_format($log->duration, 2) . "s</td>";
            echo "<td>";
            echo '<button type="button" class="button toggle-details" data-id="' . $log->id . '">View Details</button>';
            echo "</td>";
            echo "</tr>";
            // Details row (hidden by default)
            echo '<tr class="details-row" id="details-' . $log->id . '" style="display: none;">
                    <td colspan="10">
                        <div style="max-height: 300px; overflow-y: auto; padding: 10px; border: 1px solid #ccc; background: #f9f9f9;">
                            <h4>Sync Details (ID: ' . $log->id . ')</h4>';
            if (!empty($log->error_message)) {
                echo '<div class="error-message"><strong>Error:</strong><br><pre style="white-space: pre-wrap;">' . esc_html($log->error_message) . "</pre></div>";
            }
            if (!empty($details)) {
                echo '<div class="details-content">';
                
                if (isset($details["skipped_listings"]) && !empty($details["skipped_listings"])) {
                    echo "<h5>Skipped Listings (" . count($details["skipped_listings"]) . "):</h5>";
                    echo "<ul>";
                    foreach ($details["skipped_listings"] as $stock_id => $reason) {
                        echo "<li><strong>" . esc_html($stock_id) . ":</strong> " . esc_html($reason) . "</li>";
                    }
                    echo "</ul>";
                }
                // Display image errors
                if (isset($details["image_errors"]) && !empty($details["image_errors"])) {
                    echo "<h5>Image Processing Errors (" . count($details["image_errors"]) . " listings):</h5>";
                    echo "<ul>";
                    foreach ($details["image_errors"] as $stock_id => $errors) {
                        echo "<li><strong>Listing " . esc_html($stock_id) . ":</strong><ul>";
                        foreach ($errors as $error_detail) {
                            echo "<li>" . esc_html($error_detail) . "</li>";
                        }
                        echo "</ul></li>";
                    }
                    echo "</ul>";
                }
                // Display gallery diagnostics
                if (isset($details["gallery_diagnostics"]) && !empty($details["gallery_diagnostics"])) {
                    echo "<h5>Gallery Meta Diagnostics (" . count($details["gallery_diagnostics"]) . " listings):</h5>";
                    echo "<ul>";
                    foreach ($details["gallery_diagnostics"] as $stock_id => $diag) {
                        echo "<li><strong>Listing " . esc_html($stock_id) . ":</strong><ul>";
                        echo "<li>Attempted to save: <code>" . esc_html($diag['attempted_value']) . "</code></li>";
                        echo "<li>Meta update result: " . ($diag['update_result'] ? 'Success' : 'Failed') . "</li>";
                        echo "<li>Value read back: <code>" . esc_html($diag['read_back_value']) . "</code></li>";
                        echo "</ul></li>";
                    }
                    echo "</ul>";
                }
                 if (empty($details["skipped_listings"]) && empty($details["image_errors"]) && empty($details["gallery_diagnostics"]) && empty($log->error_message)) {
                    echo "<p>No specific details recorded for this sync.</p>";
                }
                echo "</div>";
            }
            echo '          </div>
                    </td>
                  </tr>';
        }

        echo "</tbody>";
        echo "</table>";

        // Pagination
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages">';
        if ($total_pages > 1) {
            echo '<span class="displaying-num">' . $total_items . " items</span>";
            echo '<span class="pagination-links">';
            // First page
            if ($current_page > 1) {
                echo '<a class="first-page button" href="' . add_query_arg("paged", 1) . '"><span class="screen-reader-text">First page</span><span aria-hidden="true">&laquo;</span></a>';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
            }
            // Previous page
            if ($current_page > 1) {
                echo '<a class="prev-page button" href="' . add_query_arg("paged", max(1, $current_page - 1)) . '"><span class="screen-reader-text">Previous page</span><span aria-hidden="true">&lsaquo;</span></a>';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
            }
            // Current page
            echo '<span class="paging-input">' . $current_page . ' of <span class="total-pages">' . $total_pages . "</span></span>";
            // Next page
            if ($current_page < $total_pages) {
                echo '<a class="next-page button" href="' . add_query_arg("paged", min($total_pages, $current_page + 1)) . '"><span class="screen-reader-text">Next page</span><span aria-hidden="true">&rsaquo;</span></a>';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
            }
            // Last page
            if ($current_page < $total_pages) {
                echo '<a class="last-page button" href="' . add_query_arg("paged", $total_pages) . '"><span class="screen-reader-text">Last page</span><span aria-hidden="true">&raquo;</span></a>';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
            }
            echo "</span>";
        }
        echo "</div>";
        echo "</div>";

        echo '<style>
            .status-success { color: green; font-weight: bold; }
            .status-error { color: red; font-weight: bold; }
            .error-message { background-color: #ffeeee; padding: 10px; border-left: 4px solid #dc3232; margin-bottom: 10px; }
            .details-content { background-color: #f8f8f8; padding: 10px; border-left: 4px solid #00a0d2; }
            .details-row td { padding: 15px; }
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
     * AJAX handler for syncing listings
     */
    public function ajax_sync_autotrader_listings() {
        check_ajax_referer("sync_autotrader_listings_nonce", "nonce");
        if (!current_user_can("manage_options")) {
            wp_send_json_error("Permission denied.");
            return; // Ensure exit after sending error
        }
        if ($this->api === null) {
             wp_send_json_error("API not initialized. Cannot sync.");
             return; // Ensure exit after sending error
        }
        
        // Start output buffering to catch stray output
        ob_start();
        $ajax_error_message = ''; // Variable to hold error message for JSON response
        $ajax_success_message = ''; // Variable for success message
        
        try {
            // Get parameters from request if available
            $advertiser_id = isset($_POST["advertiser_id"]) ? sanitize_text_field($_POST["advertiser_id"]) : "10012495"; // Consider making this configurable
            $max_pages = isset($_POST["max_pages"]) ? intval($_POST["max_pages"]) : 0;
            
            $result = $this->sync_autotrader_listings($advertiser_id, $max_pages, "manual");
            
            // Check result for errors before sending success
            if (strpos($result, 'Error') === 0) {
                $ajax_error_message = $result;
            } else {
                $ajax_success_message = $result;
            }
        } catch (Exception $e) {
            // Catch any unexpected exceptions during the sync process itself
            $error_details = "Exception in AJAX handler: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
            $this->log($error_details);
            $ajax_error_message = "A critical error occurred during sync. Please check the sync history details and PHP error logs. Message: " . $e->getMessage();
            // Log this critical failure to the sync table as well
            $this->log_critical_failure($advertiser_id, $error_details, "manual");
        }
        
        // Clean the buffer (discard any stray output)
        ob_end_clean();
        
        // Send JSON response
        if (!empty($ajax_error_message)) {
            wp_send_json_error($ajax_error_message);
        } else {
            wp_send_json_success($ajax_success_message);
        }
        // Note: wp_send_json_* functions call die() internally, so no explicit exit needed here.
    }
    
    /**
     * Callback for the cron event
     */
     public function run_cron_sync() {
         $this->log("Cron sync event triggered.");
         if ($this->api === null) {
             $this->log("API not initialized. Cron sync aborted.");
             return;
         }
         // Use default advertiser ID or make it configurable via options
         $advertiser_id = "10012495"; 
         try {
             $this->sync_autotrader_listings($advertiser_id, 0, "automatic");
         } catch (Exception $e) {
             // Catch exceptions during cron sync
             $error_details = "Exception during CRON sync: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
             $this->log($error_details);
             // Log this critical failure to the sync table
             $this->log_critical_failure($advertiser_id, $error_details, "automatic");
         }
     }

    /**
     * Sync AutoTrader listings (Main logic)
     * 
     * @param string $advertiser_id The Advertiser ID to fetch stock for
     * @param int $max_pages Maximum number of pages to retrieve (0 for all)
     * @param string $sync_type Type of sync (manual or automatic)
     * @return string Status message
     * @throws Exception If a critical error occurs that prevents logging
     */
    public function sync_autotrader_listings($advertiser_id = "10012495", $max_pages = 0, $sync_type = "automatic") {
        // Ensure API is loaded
        if ($this->api === null) {
            // Log this failure and return error message
            $error_msg = "Error: AutoTrader API is not initialized. Cannot perform sync.";
            $this->log($error_msg);
            $this->log_critical_failure($advertiser_id, $error_msg, $sync_type); // Log failure
            return $error_msg;
        }
        
        $start_time = microtime(true);
        $this->log("Starting AutoTrader sync process ($sync_type) for advertiser ID: " . $advertiser_id);

        // Check and create table if needed (extra precaution)
        $this->check_and_create_table();

        // Initialize log data structure
        $log_data = array(
            "sync_time" => current_time("mysql"),
            "sync_type" => $sync_type,
            "advertiser_id" => $advertiser_id,
            "created_count" => 0,
            "updated_count" => 0,
            "deleted_count" => 0,
            "skipped_count" => 0,
            "status" => "success", // Assume success initially
            "duration" => 0,
            "error_message" => "",
            "details" => json_encode(array(
                "skipped_listings" => array(),
                "image_errors" => array(),
                "gallery_diagnostics" => array() // Add field for gallery diagnostics
            )),
        );
        $image_errors = array(); // Local array to collect image errors
        $skipped_listings = array(); // Local array for skipped listings
        $gallery_diagnostics = array(); // Local array for gallery diagnostics

        try {
            // Get stock data from API with pagination
            $stock_data = $this->get_stock_data($advertiser_id, $max_pages);

            if ($stock_data === false) { // Check for false explicitly, as empty array is valid
                 throw new Exception("Failed to retrieve stock data from API. Check API connection and credentials.");
            }
            
            if (empty($stock_data) || !is_array($stock_data)) {
                // This is not necessarily an error, could be no listings
                $this->log("No stock data received from API or empty results for advertiser ID: " . $advertiser_id);
                // Continue to process deletions if needed
                $stock_data = array(); // Ensure it's an array for the loop below
            }
            
            $this->log("Received " . count($stock_data) . " listings from API");

            // Track existing listings to identify deleted ones
            $existing_listings = $this->get_existing_listings();
            $processed_listings = array();

            // Process each listing
            $created = 0;
            $updated = 0;
            $skipped = 0;
            
            foreach ($stock_data as $car) {
                $stock_id = isset($car["metadata"]["stockId"]) ? $car["metadata"]["stockId"] : (isset($car["metadata"]["externalStockId"]) ? $car["metadata"]["externalStockId"] : "unknown");
                
                try {
                    // Skip listings with advertiserAdvert status NOT_PUBLISHED
                    if (
                        isset($car["adverts"]["retailAdverts"]["advertiserAdvert"]["status"]) &&
                        $car["adverts"]["retailAdverts"]["advertiserAdvert"]["status"] === "NOT_PUBLISHED"
                    ) {
                        $this->log("Skipping NOT_PUBLISHED listing: " . $stock_id);
                        $skipped++;
                        $skipped_listings[$stock_id] = "NOT_PUBLISHED status";
                        continue;
                    }

                    $result = $this->process_listing($car);

                    if ($result["status"] === "created") {
                        $created++;
                    } elseif ($result["status"] === "updated") {
                        $updated++;
                    }

                    if (isset($result["id"]) && $result["id"]) {
                        $processed_listings[] = $result["id"];
                        // Collect image errors from processing
                        if (isset($result["image_errors"]) && !empty($result["image_errors"])) {
                            $image_errors[$stock_id] = $result["image_errors"];
                        }
                        // Collect gallery diagnostics
                        if (isset($result["gallery_diagnostics"]) && !empty($result["gallery_diagnostics"])) {
                            $gallery_diagnostics[$stock_id] = $result["gallery_diagnostics"];
                        }
                    } else {
                        // If process_listing failed to return an ID, count as skipped/error
                        $skipped++;
                        $skipped_listings[$stock_id] = "Processing failed (no post ID returned)";
                        $log_data["status"] = "error"; // Mark sync as error if any listing fails processing
                    }
                } catch (Exception $listing_ex) {
                    // Catch errors processing a single listing
                    $error_msg = "Error processing listing {$stock_id}: " . $listing_ex->getMessage();
                    $this->log($error_msg);
                    $skipped++;
                    $skipped_listings[$stock_id] = "Processing error: " . $listing_ex->getMessage();
                    $log_data["status"] = "error"; // Mark sync as error
                    // Optionally add more details to the main error message or details field
                    if (empty($log_data["error_message"])) {
                        $log_data["error_message"] = "Error occurred processing one or more listings. See details.";
                    }
                    // Continue to next listing
                }
            }

            // Remove listings that no longer exist in the API
            $deleted = $this->delete_old_listings($existing_listings, $processed_listings);

            $end_time = microtime(true);
            $duration = $end_time - $start_time;

            $this->log("Sync completed: $created created, $updated updated, $deleted deleted, $skipped skipped");

            // Update log data
            $log_data["created_count"] = $created;
            $log_data["updated_count"] = $updated;
            $log_data["deleted_count"] = $deleted;
            $log_data["skipped_count"] = $skipped;
            $log_data["duration"] = $duration;
            // Ensure details field includes all collected info
            $log_data["details"] = json_encode(array(
                "skipped_listings" => $skipped_listings,
                "image_errors" => $image_errors,
                "gallery_diagnostics" => $gallery_diagnostics
            ));

            // Save log to database (will happen outside the catch block too)
            $this->save_sync_log($log_data);

            // Send email report if enabled
            if (get_option("autotrader_email_reports", 0)) {
                $this->send_sync_report_email($log_data);
            }

            // Prepare final status message
            if ($log_data["status"] === "error") {
                $status_message = "Error during sync. " . $log_data["error_message"] . " Results: $created created, $updated updated, $deleted deleted, $skipped skipped/failed. Check history for details.";
            } else {
                $status_message = "Sync completed: $created listings created, $updated listings updated, $deleted listings deleted, $skipped listings skipped.";
                if (!empty($image_errors)) {
                    $status_message .= " Some images failed to process. Check sync history details.";
                }
                 if (!empty($gallery_diagnostics)) {
                    $status_message .= " Some gallery meta updates may have issues. Check sync history details.";
                }
            }
            return $status_message;

        } catch (Exception $e) {
            // Catch critical errors during the overall sync process
            $end_time = microtime(true);
            $duration = $end_time - $start_time;

            $error_message = "Critical Sync Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
            $this->log($error_message);
            $this->log("Stack Trace: \n" . $e->getTraceAsString()); // Log stack trace

            // Update log data for critical error
            $log_data["status"] = "error";
            $log_data["duration"] = $duration;
            $log_data["error_message"] = $error_message; // Store detailed error
            // Ensure details field is updated even on error
            $log_data["details"] = json_encode(array(
                "skipped_listings" => $skipped_listings,
                "image_errors" => $image_errors,
                "gallery_diagnostics" => $gallery_diagnostics,
                "critical_error_trace" => $e->getTraceAsString() // Add trace to details
            ));

            // Save error log
            $this->save_sync_log($log_data);

            // Send email report if enabled
            if (get_option("autotrader_email_reports", 0)) {
                $this->send_sync_report_email($log_data);
            }

            // Return error message for AJAX handler or cron log
            return "Error during sync: " . $e->getMessage() . " Check sync history and PHP error logs for details.";
        }
    }
    
    /**
     * Log a critical failure when the main sync process cannot complete or log normally.
     */
    private function log_critical_failure($advertiser_id, $error_message, $sync_type) {
        $log_data = array(
            "sync_time" => current_time("mysql"),
            "sync_type" => $sync_type,
            "advertiser_id" => $advertiser_id,
            "created_count" => 0,
            "updated_count" => 0,
            "deleted_count" => 0,
            "skipped_count" => 0,
            "status" => "error",
            "duration" => 0,
            "error_message" => "Critical Failure: " . $error_message,
            "details" => json_encode(array("message" => "Sync process failed critically before completion."))
        );
        $this->save_sync_log($log_data);
        // Send email report if enabled
        if (get_option("autotrader_email_reports", 0)) {
            $this->send_sync_report_email($log_data);
        }
    }

    /**
     * Save sync log to database
     */
    private function save_sync_log($log_data) {
        global $wpdb;
        // Ensure table exists before trying to insert
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        if (!$table_exists) {
            $this->log("Error saving sync log: Table {$this->table_name} does not exist.");
            // Optionally try to create it again
            // self::create_logs_table(); 
            return; // Prevent insert error
        }

        // Truncate error message if too long for the TEXT field
        if (isset($log_data['error_message']) && strlen($log_data['error_message']) > 65535) {
            $log_data['error_message'] = substr($log_data['error_message'], 0, 65532) . '...';
        }
        // Truncate details if too long for LONGTEXT (though unlikely)
        if (isset($log_data['details']) && strlen($log_data['details']) > 4294967295) {
             $log_data['details'] = substr($log_data['details'], 0, 4294967290) . '...}'; // Approx limit
        }

        $wpdb->insert($this->table_name, $log_data);

        if ($wpdb->last_error) {
            $this->log("Error saving sync log: " . $wpdb->last_error);
        } else {
            $this->log("Sync log saved with ID: " . $wpdb->insert_id);
        }
    }

    /**
     * Send sync report email
     */
    private function send_sync_report_email($log_data) {
        $recipient = get_option("autotrader_email_recipient", get_option("admin_email"));
        if (!is_email($recipient)) {
             $this->log("Invalid email recipient for sync report: " . $recipient);
             return;
        }
        
        $site_name = get_bloginfo("name");
        $sync_time = date_i18n(get_option("date_format") . " " . get_option("time_format"), strtotime($log_data["sync_time"]));
        $status_text = ucfirst($log_data["status"]);
        $status_color = $log_data["status"] === "success" ? "green" : "red";

        $subject = "[{$site_name}] AutoTrader Sync Report ({$status_text}) - {$sync_time}";

        $body = "<h2>AutoTrader Sync Report</h2>";
        $body .= "<p><strong>Date/Time:</strong> {$sync_time}</p>";
        $body .= "<p><strong>Sync Type:</strong> " . ucfirst($log_data["sync_type"]) . "</p>";
        $body .= "<p><strong>Advertiser ID:</strong> {$log_data["advertiser_id"]}</p>";
        $body .= "<p><strong>Status:</strong> <strong style='color:{$status_color};'>{$status_text}</strong></p>";
        $body .= "<p><strong>Duration:</strong> " . number_format($log_data["duration"], 2) . " seconds</p>";
        $body .= "<hr>";
        $body .= "<h3>Sync Results:</h3>";
        $body .= "<ul>";
        $body .= "<li><strong>Created:</strong> " . intval($log_data["created_count"]) . "</li>";
        $body .= "<li><strong>Updated:</strong> " . intval($log_data["updated_count"]) . "</li>";
        $body .= "<li><strong>Deleted:</strong> " . intval($log_data["deleted_count"]) . "</li>";
        $body .= "<li><strong>Skipped/Failed:</strong> " . intval($log_data["skipped_count"]) . "</li>";
        $body .= "</ul>";

        if ($log_data["status"] === "error" && !empty($log_data["error_message"])) {
            $body .= "<hr>";
            $body .= "<h3>Error Details:</h3>";
            $body .= "<pre style='background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; white-space: pre-wrap;'>" . esc_html($log_data["error_message"]) . "</pre>";
        }

        $details = json_decode($log_data["details"], true);
        if (!empty($details)) {
            $body .= "<hr>";
            $body .= "<h3>Additional Details:</h3>";
            if (isset($details["skipped_listings"]) && !empty($details["skipped_listings"])) {
                $body .= "<h4>Skipped Listings:</h4><ul>";
                foreach ($details["skipped_listings"] as $stock_id => $reason) {
                    $body .= "<li><strong>" . esc_html($stock_id) . ":</strong> " . esc_html($reason) . "</li>";
                }
                $body .= "</ul>";
            }
            if (isset($details["image_errors"]) && !empty($details["image_errors"])) {
                $body .= "<h4>Image Processing Errors:</h4><ul>";
                foreach ($details["image_errors"] as $stock_id => $errors) {
                    $body .= "<li><strong>Listing " . esc_html($stock_id) . ":</strong><ul>";
                    foreach ($errors as $error_detail) {
                        $body .= "<li>" . esc_html($error_detail) . "</li>";
                    }
                    $body .= "</ul></li>";
                }
                $body .= "</ul>";
            }
            if (isset($details["gallery_diagnostics"]) && !empty($details["gallery_diagnostics"])) {
                $body .= "<h4>Gallery Meta Diagnostics:</h4><ul>";
                foreach ($details["gallery_diagnostics"] as $stock_id => $diag) {
                    $body .= "<li><strong>Listing " . esc_html($stock_id) . ":</strong><ul>";
                    $body .= "<li>Attempted to save: <code>" . esc_html($diag['attempted_value']) . "</code></li>";
                    $body .= "<li>Meta update result: " . ($diag['update_result'] ? 'Success' : 'Failed') . "</li>";
                    $body .= "<li>Value read back: <code>" . esc_html($diag['read_back_value']) . "</code></li>";
                    $body .= "</ul></li>";
                }
                $body .= "</ul>";
            }
        }

        $headers = array("Content-Type: text/html; charset=UTF-8");

        wp_mail($recipient, $subject, $body, $headers);
        $this->log("Sync report email sent to: " . $recipient);
    }

    /**
     * Get stock data from API with pagination
     */
    private function get_stock_data($advertiser_id, $max_pages = 0) {
        $all_stock = array();
        $page = 1;
        $has_more_pages = true;

        while ($has_more_pages) {
            $this->log("Fetching page $page for advertiser ID: $advertiser_id");
            $response = $this->api->getStock($advertiser_id, $page);

            if (is_wp_error($response)) {
                $error_message = "API Error fetching page $page: " . $response->get_error_message();
                $this->log($error_message);
                // Decide if we should stop or continue (e.g., maybe just one page failed)
                // For now, let's stop if any page fails
                return false; // Indicate failure
            }

            if (empty($response) || !isset($response["results"])) {
                $this->log("No results found on page $page or invalid response format.");
                $has_more_pages = false;
            } else {
                $all_stock = array_merge($all_stock, $response["results"]);
                $this->log("Fetched " . count($response["results"]) . " listings from page $page. Total so far: " . count($all_stock));
                
                // Check pagination
                if (isset($response["pagination"]["next"]) && !empty($response["pagination"]["next"])) {
                    $page++;
                    if ($max_pages > 0 && $page > $max_pages) {
                        $this->log("Reached max pages limit ($max_pages). Stopping fetch.");
                        $has_more_pages = false;
                    }
                } else {
                    $has_more_pages = false;
                }
            }
            // Optional: Add a small delay between pages to avoid rate limiting
            // sleep(1);
        }

        return $all_stock;
    }

    /**
     * Get existing listings managed by this integration
     */
    private function get_existing_listings() {
        $args = array(
            "post_type" => "listing",
            "posts_per_page" => -1,
            "meta_query" => array(
                array(
                    "key" => "_autotrader_stock_id",
                    "compare" => "EXISTS",
                ),
            ),
            "fields" => "ids", // Only get post IDs
        );
        $query = new WP_Query($args);
        return $query->posts;
    }

    /**
     * Process a single listing from API data
     */
    private function process_listing($car) {
        $stock_id = isset($car["metadata"]["stockId"]) ? $car["metadata"]["stockId"] : (isset($car["metadata"]["externalStockId"]) ? $car["metadata"]["externalStockId"] : null);
        if (!$stock_id) {
            throw new Exception("Missing stockId in listing data: " . print_r($car["metadata"], true));
        }

        $existing_post_id = $this->find_listing_by_stock_id($stock_id);
        $image_errors = array(); // Initialize image errors for this listing
        $gallery_diagnostics = array(); // Initialize gallery diagnostics for this listing

        // Prepare post data
        $post_data = array(
            "post_type" => "listing",
            "post_status" => "publish",
            "post_title" => $this->get_value($car, ["vehicle", "title"]), // Use helper
            "post_content" => $this->get_value($car, ["adverts", "retailAdverts", "description"]),
        );

        if ($existing_post_id) {
            // Update existing listing
            $post_data["ID"] = $existing_post_id;
            wp_update_post($post_data);
            $post_id = $existing_post_id;
            $status = "updated";
            $this->log("Updating listing: $stock_id (Post ID: $post_id)");
        } else {
            // Create new listing
            $post_id = wp_insert_post($post_data);
            if (is_wp_error($post_id)) {
                throw new Exception("Failed to insert post for stock ID {$stock_id}: " . $post_id->get_error_message());
            }
            $status = "created";
            $this->log("Creating new listing: $stock_id (Post ID: $post_id)");
        }

        // Update meta fields
        $this->update_listing_meta($post_id, $car);

        // Handle images
        $image_result = $this->handle_images($post_id, $car);
        if (!empty($image_result["errors"])) {
            $image_errors = $image_result["errors"];
        }
        if (!empty($image_result["gallery_diagnostics"])) {
            $gallery_diagnostics = $image_result["gallery_diagnostics"];
        }

        return array(
            "id" => $post_id,
            "status" => $status,
            "image_errors" => $image_errors,
            "gallery_diagnostics" => $gallery_diagnostics
        );
    }

    /**
     * Find existing listing by AutoTrader stock ID
     */
    private function find_listing_by_stock_id($stock_id) {
        $args = array(
            "post_type" => "listing",
            "posts_per_page" => 1,
            "meta_query" => array(
                array(
                    "key" => "_autotrader_stock_id",
                    "value" => $stock_id,
                ),
            ),
            "fields" => "ids",
        );
        $query = new WP_Query($args);
        return $query->have_posts() ? $query->posts[0] : null;
    }

    /**
     * Update listing meta fields
     */
    private function update_listing_meta($post_id, $car) {
        // Store the AutoTrader stock ID
        update_post_meta($post_id, "_autotrader_stock_id", $this->get_value($car, ["metadata", "stockId"]));
        update_post_meta($post_id, "_autotrader_external_stock_id", $this->get_value($car, ["metadata", "externalStockId"]));

        // --- Map API data to TF Car Listing meta fields --- 
        // Use the helper function for safe access
        update_post_meta($post_id, "listing_price", $this->get_value($car, ["adverts", "retailAdverts", "price", "amountGBP"]));
        update_post_meta($post_id, "listing_mileage", $this->get_value($car, ["vehicle", "mileage"]));
        update_post_meta($post_id, "listing_year", $this->get_value($car, ["vehicle", "registrationYear"]));
        update_post_meta($post_id, "listing_transmission", $this->get_value($car, ["vehicle", "transmissionType"]));
        update_post_meta($post_id, "listing_fuel_type", $this->get_value($car, ["vehicle", "fuelType"]));
        update_post_meta($post_id, "listing_engine_size", $this->get_value($car, ["vehicle", "engine", "sizeLitres"]));
        update_post_meta($post_id, "listing_engine_power", $this->get_value($car, ["vehicle", "engine", "powerPS"]));
        update_post_meta($post_id, "listing_body_type", $this->get_value($car, ["vehicle", "bodyType"]));
        update_post_meta($post_id, "listing_color", $this->get_value($car, ["vehicle", "colour"]));
        update_post_meta($post_id, "listing_doors", $this->get_value($car, ["vehicle", "numberOfDoors"]));
        update_post_meta($post_id, "listing_seats", $this->get_value($car, ["vehicle", "numberOfSeats"]));
        update_post_meta($post_id, "listing_owners", $this->get_value($car, ["vehicle", "numberOfPreviousOwners"]));
        update_post_meta($post_id, "listing_vrm", $this->get_value($car, ["vehicle", "vrm"]));
        update_post_meta($post_id, "listing_vin", $this->get_value($car, ["vehicle", "vin"]));
        update_post_meta($post_id, "listing_make", $this->get_value($car, ["vehicle", "make"]));
        update_post_meta($post_id, "listing_model", $this->get_value($car, ["vehicle", "model"]));
        update_post_meta($post_id, "listing_derivative", $this->get_value($car, ["vehicle", "derivative"]));
        update_post_meta($post_id, "listing_condition", $this->get_value($car, ["vehicle", "condition"])); // e.g., Used, New
        update_post_meta($post_id, "listing_co2_emissions", $this->get_value($car, ["vehicle", "co2Emissions"]));
        update_post_meta($post_id, "listing_insurance_group", $this->get_value($car, ["vehicle", "insuranceGroup"]));
        update_post_meta($post_id, "listing_tax_band", $this->get_value($car, ["vehicle", "taxBand"]));
        update_post_meta($post_id, "listing_urban_mpg", $this->get_value($car, ["vehicle", "fuelConsumptionUrbanMPG"]));
        update_post_meta($post_id, "listing_extra_urban_mpg", $this->get_value($car, ["vehicle", "fuelConsumptionExtraUrbanMPG"]));
        update_post_meta($post_id, "listing_combined_mpg", $this->get_value($car, ["vehicle", "fuelConsumptionCombinedMPG"]));
        
        // --- Set Taxonomies --- 
        // Example: Set 'make' taxonomy term
        $make = $this->get_value($car, ["vehicle", "make"]);
        if ($make) {
            wp_set_object_terms($post_id, $make, "listing_make", false);
        }
        // Example: Set 'model' taxonomy term (assuming it exists)
        $model = $this->get_value($car, ["vehicle", "model"]);
        if ($model) {
            wp_set_object_terms($post_id, $model, "listing_model", false);
        }
        // Example: Set 'body_type' taxonomy term
        $body_type = $this->get_value($car, ["vehicle", "bodyType"]);
        if ($body_type) {
            wp_set_object_terms($post_id, $body_type, "listing_body_type", false);
        }
        // Example: Set 'fuel_type' taxonomy term
        $fuel_type = $this->get_value($car, ["vehicle", "fuelType"]);
        if ($fuel_type) {
            wp_set_object_terms($post_id, $fuel_type, "listing_fuel_type", false);
        }
        // Example: Set 'transmission' taxonomy term
        $transmission = $this->get_value($car, ["vehicle", "transmissionType"]);
        if ($transmission) {
            wp_set_object_terms($post_id, $transmission, "listing_transmission", false);
        }
        
        // --- Handle Features --- 
        $features = $this->get_value($car, ["vehicle", "standardFeatures"], array());
        if (!empty($features) && is_array($features)) {
            // Assuming 'listing_feature' is the taxonomy slug for features
            wp_set_object_terms($post_id, $features, "listing_feature", false);
            // Also save as meta if needed by the theme
            update_post_meta($post_id, "_listing_features", implode("\n", $features));
        }
    }

    /**
     * Handle images for a listing
     */
    private function handle_images($post_id, $car) {
        $errors = array();
        $gallery_diagnostics = array();
        $stock_id = isset($car["metadata"]["stockId"]) ? $car["metadata"]["stockId"] : "unknown";

        // Ensure WordPress media functions are loaded
        if (!function_exists("media_handle_sideload")) {
            require_once(ABSPATH . "wp-admin/includes/media.php");
            require_once(ABSPATH . "wp-admin/includes/file.php");
            require_once(ABSPATH . "wp-admin/includes/image.php");
            $this->log("Loaded media functions for post ID: $post_id");
        }

        $images = $this->get_value($car, ["media", "images"], array());
        $attachment_ids = array();
        $featured_image_set = false;

        if (empty($images)) {
            $this->log("No images found in API data for listing: $stock_id (Post ID: $post_id)");
            // Clear existing gallery and featured image if no images are provided
            delete_post_meta($post_id, "_thumbnail_id");
            delete_post_meta($post_id, "_listing_image_gallery");
            return array("errors" => $errors, "gallery_diagnostics" => $gallery_diagnostics);
        }

        foreach ($images as $index => $image_data) {
            if (!isset($image_data["href"]) || !is_string($image_data["href"])) {
                $error_msg = "Skipping invalid image data (missing or non-string href): " . print_r($image_data, true);
                $this->log($error_msg);
                $errors[] = $error_msg;
                continue;
            }

            $original_url = $image_data["href"];
            // Clean the URL: remove {resize}/
            $cleaned_url = str_replace('/{resize}/', '/', $original_url);
            
            // Validate the cleaned URL format
            if (!filter_var($cleaned_url, FILTER_VALIDATE_URL)) {
                $error_msg = "Skipping invalid image URL format after cleaning: " . $cleaned_url . " (Original: " . $original_url . ")";
                $this->log($error_msg);
                $errors[] = $error_msg;
                continue;
            }

            // Check if image already exists in media library by source URL
            $existing_attachment_id = $this->get_attachment_id_by_url($cleaned_url);

            if ($existing_attachment_id) {
                $this->log("Found existing attachment $existing_attachment_id by source URL: $cleaned_url");
                $attachment_ids[] = $existing_attachment_id;
                // Set as featured image if it's the first one and not already set
                if (!$featured_image_set) {
                    update_post_meta($post_id, "_thumbnail_id", $existing_attachment_id);
                    $featured_image_set = true;
                    $this->log("Set existing attachment $existing_attachment_id as featured image for post $post_id");
                }
            } else {
                // Download and attach image
                $attachment_id = $this->download_and_attach_image($post_id, $cleaned_url);
                if (is_wp_error($attachment_id)) {
                    $error_msg = "Failed to download/attach image from URL: " . $cleaned_url . " - Error: " . $attachment_id->get_error_message();
                    $this->log($error_msg);
                    $errors[] = $error_msg;
                } elseif ($attachment_id) {
                    $attachment_ids[] = $attachment_id;
                    // Store the original URL as meta for future checks
                    update_post_meta($attachment_id, "_source_url", $cleaned_url);
                    $this->log("Successfully attached image $attachment_id from URL: $cleaned_url");
                    // Set as featured image if it's the first one and not already set
                    if (!$featured_image_set) {
                        update_post_meta($post_id, "_thumbnail_id", $attachment_id);
                        $featured_image_set = true;
                        $this->log("Set new attachment $attachment_id as featured image for post $post_id");
                    }
                }
            }
        }

        // Update the listing gallery meta field
        if (!empty($attachment_ids)) {
            // Ensure unique IDs and format as comma-separated string
            $gallery_string = implode(",", array_unique($attachment_ids));
            $this->log("Attempting to save gallery meta for post $post_id: " . $gallery_string);
            
            // Save the meta value
            $update_result = update_post_meta($post_id, "_listing_image_gallery", $gallery_string);
            
            // Read back the value to verify
            $read_back_value = get_post_meta($post_id, "_listing_image_gallery", true);
            
            // Log diagnostics
            $gallery_diagnostics = array(
                'attempted_value' => $gallery_string,
                'update_result' => $update_result, // Will be true if value changed, false if same, or meta_id if new
                'read_back_value' => $read_back_value
            );
            $this->log("Gallery meta diagnostics for post $post_id: " . print_r($gallery_diagnostics, true));
            
            // Self-healing attempt if read-back doesn't match
            if ($read_back_value !== $gallery_string) {
                 $this->log("Warning: Gallery meta read-back value does not match attempted value for post $post_id. Read: '$read_back_value', Attempted: '$gallery_string'. Retrying update.");
                 // Retry update
                 delete_post_meta($post_id, "_listing_image_gallery");
                 $retry_result = update_post_meta($post_id, "_listing_image_gallery", $gallery_string);
                 $retry_read_back = get_post_meta($post_id, "_listing_image_gallery", true);
                 $this->log("Gallery meta retry result for post $post_id: Update success? " . ($retry_result ? 'Yes' : 'No') . ". Read back after retry: '$retry_read_back'");
                 $gallery_diagnostics['retry_result'] = $retry_result;
                 $gallery_diagnostics['retry_read_back'] = $retry_read_back;
            }
            
        } else {
            // If no valid images were processed, clear the gallery meta
            $this->log("No valid images processed for post $post_id. Clearing gallery meta.");
            delete_post_meta($post_id, "_listing_image_gallery");
            // Also clear featured image if none was set
            if (!$featured_image_set) {
                delete_post_meta($post_id, "_thumbnail_id");
            }
        }

        return array("errors" => $errors, "gallery_diagnostics" => $gallery_diagnostics);
    }

    /**
     * Download image from URL and attach to post
     */
    private function download_and_attach_image($post_id, $url) {
        // Increase timeout for download_url
        add_filter('http_request_timeout', function($timeout) { return 60; });
        
        $tmp = download_url($url);
        
        // Reset timeout filter
        remove_filter('http_request_timeout', function($timeout) { return 60; });

        if (is_wp_error($tmp)) {
            $this->log("Error downloading image: " . $tmp->get_error_message() . " URL: " . $url);
            return $tmp; // Return WP_Error object
        }

        // Set variables for storage, fix file filename for query strings.
        preg_match('/[^"]\.(jpg|jpeg|png|gif)/i', $url, $matches);
        $file_array = array();
        $file_array["name"] = basename($url);
        if (!empty($matches[1])) {
            $file_array["name"] = basename(parse_url($url, PHP_URL_PATH)); // Use path basename
        }
        $file_array["tmp_name"] = $tmp;

        // If error storing temporarily, unlink.
        if (is_wp_error($tmp)) {
            @unlink($file_array["tmp_name"]);
            $file_array["tmp_name"] = "";
            $this->log("Error after download (is_wp_error check): " . $tmp->get_error_message());
            return $tmp;
        }

        // Do the validation and storage stuff.
        $id = media_handle_sideload($file_array, $post_id);

        // If error storing permanently, unlink.
        if (is_wp_error($id)) {
            @unlink($file_array["tmp_name"]);
            $this->log("Error sideloading image: " . $id->get_error_message() . " URL: " . $url);
            return $id; // Return WP_Error object
        }

        return $id; // Return attachment ID
    }

    /**
     * Get attachment ID by source URL stored in meta
     */
    private function get_attachment_id_by_url($url) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_source_url' AND meta_value = %s LIMIT 1",
            $url
        );
        $attachment_id = $wpdb->get_var($sql);
        return $attachment_id ? (int) $attachment_id : null;
    }

    /**
     * Delete listings that are no longer in the API feed
     */
    private function delete_old_listings($existing_listings, $processed_listings) {
        $deleted_count = 0;
        $listings_to_delete = array_diff($existing_listings, $processed_listings);

        if (!empty($listings_to_delete)) {
            $this->log("Found " . count($listings_to_delete) . " listings to delete.");
            foreach ($listings_to_delete as $post_id) {
                $stock_id = get_post_meta($post_id, "_autotrader_stock_id", true);
                $deleted = wp_delete_post($post_id, true); // true = force delete
                if ($deleted) {
                    $deleted_count++;
                    $this->log("Deleted listing: $stock_id (Post ID: $post_id)");
                } else {
                    $this->log("Error deleting listing: $stock_id (Post ID: $post_id)");
                }
            }
        }
        return $deleted_count;
    }

    /**
     * Helper function to safely get nested array values
     */
    private function get_value($array, $keys, $default = null) {
        $current = $array;
        foreach ($keys as $key) {
            if (isset($current[$key])) {
                $current = $current[$key];
            } else {
                return $default;
            }
        }
        return $current;
    }

    /**
     * Log messages
     */
    private function log($message) {
        if ($this->debug_mode) {
            error_log("[AutoTrader Integration] " . $message);
        }
    }
}

// Initialize the plugin using the Singleton pattern after plugins are loaded
function initialize_autotrader_integration() {
    AutoTrader_Integration::get_instance();
}
add_action('plugins_loaded', 'initialize_autotrader_integration');

// Register activation and deactivation hooks using static methods
register_activation_hook(__FILE__, array('AutoTrader_Integration', 'activate_plugin'));
register_deactivation_hook(__FILE__, array('AutoTrader_Integration', 'deactivate_plugin'));

?>

