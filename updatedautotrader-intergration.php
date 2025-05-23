<?php
/**
 * AutoTrader API Integration with TF Car Listing Plugin
 * 
 * This file handles the integration between AutoTrader API and TF Car Listing Plugin.
 * It includes functions for fetching data from the API, creating/updating listings,
 * mapping API data to plugin meta fields, handling images, and automating the process.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class AutoTrader_Integration {
    private $api;
    private $debug_mode = true; // Set to true for debugging

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize the API
        require_once(TF_PLUGIN_PATH . 'autotraderauthapi.php');
        $this->api = new AutoTrader_API();

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register cron job
        add_action('autotrader_sync_event', array($this, 'sync_autotrader_listings'));

        // Add AJAX handlers
        add_action('wp_ajax_sync_autotrader_listings', array($this, 'ajax_sync_autotrader_listings'));
        
        // Add debug action for template issues
        add_action('template_redirect', array($this, 'debug_template'));
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
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
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
                    ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Automatic Sync</th>
                            <td>
                                <input type="checkbox" name="autotrader_sync_enabled" value="1" <?php checked(get_option('autotrader_sync_enabled'), 1); ?> />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Sync Frequency</th>
                            <td>
                                <select name="autotrader_sync_frequency">
                                    <option value="hourly" <?php selected(get_option('autotrader_sync_frequency'), 'hourly'); ?>>Hourly</option>
                                    <option value="twicedaily" <?php selected(get_option('autotrader_sync_frequency'), 'twicedaily'); ?>>Twice Daily</option>
                                    <option value="daily" <?php selected(get_option('autotrader_sync_frequency'), 'daily'); ?>>Daily</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Settings'); ?>
                </form>
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
     * AJAX handler for syncing listings
     */
    public function ajax_sync_autotrader_listings() {
        check_ajax_referer('sync_autotrader_listings_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to perform this action.');
        }
        
        // Get parameters from request if available
        $advertiser_id = isset($_POST['advertiser_id']) ? sanitize_text_field($_POST['advertiser_id']) : '10012495';
        $max_pages = isset($_POST['max_pages']) ? intval($_POST['max_pages']) : 0;
        
        $result = $this->sync_autotrader_listings($advertiser_id, $max_pages);
        wp_send_json_success($result);
    }

    /**
     * Sync AutoTrader listings
     * 
     * @param string $advertiser_id The Advertiser ID to fetch stock for
     * @param int $max_pages Maximum number of pages to retrieve (0 for all)
     * @return string Status message
     */
    public function sync_autotrader_listings($advertiser_id = '10012495', $max_pages = 0) {
        $this->log('Starting AutoTrader sync process for advertiser ID: ' . $advertiser_id);
        
        // Get stock data from API with pagination
        $stock_data = $this->get_stock_data($advertiser_id, $max_pages);
        
        if (empty($stock_data) || !is_array($stock_data)) {
            $this->log('No stock data received from API or invalid format');
            return 'No stock data received from API or invalid format.';
        }
        
        $this->log('Received ' . count($stock_data) . ' listings from API');
        
        // Track existing listings to identify deleted ones
        $existing_listings = $this->get_existing_listings();
        $processed_listings = array();
        
        // Process each listing
        $created = 0;
        $updated = 0;
        
        foreach ($stock_data as $car) {
            // Skip listings with advertiserAdvert status NOT_PUBLISHED
            if (isset($car['adverts']) && 
                isset($car['adverts']['retailAdverts']) && 
                isset($car['adverts']['retailAdverts']['advertiserAdvert']) && 
                isset($car['adverts']['retailAdverts']['advertiserAdvert']['status']) && 
                $car['adverts']['retailAdverts']['advertiserAdvert']['status'] === 'NOT_PUBLISHED') {
                $this->log('Skipping NOT_PUBLISHED listing: ' . (isset($car['metadata']['stockId']) ? $car['metadata']['stockId'] : 'unknown'));
                continue;
            }
            
            $result = $this->process_listing($car);
            
            if ($result['status'] === 'created') {
                $created++;
            } elseif ($result['status'] === 'updated') {
                $updated++;
            }
            
            if (isset($result['id']) && $result['id']) {
                $processed_listings[] = $result['id'];
            }
        }
        
        // Remove listings that no longer exist in the API
        $deleted = $this->delete_old_listings($existing_listings, $processed_listings);
        
        $this->log("Sync completed: $created created, $updated updated, $deleted deleted");
        return "Sync completed: $created listings created, $updated listings updated, $deleted listings deleted.";
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
        if (empty($images)) {
            return;
        }
        
        // Get existing images
        $existing_images = get_post_meta($post_id, '_listing_image_gallery', true);
        $existing_array = !empty($existing_images) ? explode(',', $existing_images) : array();
        $new_images = array();
        
        // Process each image
        foreach ($images as $index => $image) {
            if (!isset($image['url'])) {
                continue;
            }
            
            $url = $image['url'];
            
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
        }
        
        // Update image gallery
        if (!empty($new_images)) {
            $gallery = implode(',', array_unique(array_merge($existing_array, $new_images)));
            update_post_meta($post_id, '_listing_image_gallery', $gallery);
        }
    }

    /**
     * Download and attach image
     */
    private function download_and_attach_image($url, $post_id) {
        // Get file name from URL
        $filename = basename($url);
        
        // Create temp file
        $tmp = download_url($url);
        
        if (is_wp_error($tmp)) {
            $this->log('Error downloading image: ' . $tmp->get_error_message());
            return false;
        }
        
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
            $this->log('Error uploading image: ' . $attachment_id->get_error_message());
            return false;
        }
        
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
register_activation_hook(__FILE__, 'autotrader_integration_activate');

/**
 * Activation function
 */
function autotrader_integration_activate() {
    // Schedule cron job if enabled
    if (get_option('autotrader_sync_enabled', 0)) {
        $frequency = get_option('autotrader_sync_frequency', 'daily');
        
        if (!wp_next_scheduled('autotrader_sync_event')) {
            wp_schedule_event(time(), $frequency, 'autotrader_sync_event');
        }
    }
}

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
