<?php
/**
 * Plugin Name:       Abandoned Cart Tracker
 * Plugin URI:        https://github.com/developeralaminvi/abandoned-cart-tracker/
 * Description:       Captures customer data and product details from abandoned checkouts and displays them in the admin dashboard.
 * Version:           1.0.1
 * Author:            Md Alamin
 * Author URI:        https://www.upwork.com/freelancers/developeralamin
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       abandoned-cart-tracker
 * Domain Path:       /languages
 */

// Block direct access to the plugin file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Main plugin class
 */
class Abandoned_Cart_Tracker
{

    /**
     * Constructor
     */
    public function __construct()
    {
        // Plugin activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));
        // Plugin deactivation hook
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Hook into WooCommerce checkout process
        // This hook will now capture data during form submission, serving as a fallback alongside AJAX.
        add_action('woocommerce_checkout_update_order_meta', array($this, 'capture_abandoned_checkout_data'), 10, 2);
        // Hook into WooCommerce thank you page (when order is completed)
        add_action('woocommerce_thankyou', array($this, 'mark_cart_as_completed'), 10, 1);

        // Load WP_List_Table class
        add_action('admin_init', array($this, 'load_wp_list_table'));

        // Enqueue front-end scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Add AJAX actions (for both logged-in and non-logged-in users)
        add_action('wp_ajax_save_abandoned_checkout_data', array($this, 'save_abandoned_checkout_data'));
        add_action('wp_ajax_nopriv_save_abandoned_checkout_data', array($this, 'save_abandoned_checkout_data'));

        // Add cleanup functionality
        add_action('wp_ajax_cleanup_old_carts', array($this, 'cleanup_old_carts'));

        // Schedule cleanup event
        add_action('abandoned_cart_cleanup_event', array($this, 'scheduled_cleanup'));
        
        // Add admin notices for important information
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    /**
     * Plugin activation function
     * Creates or updates the database table.
     * The 'is_viewed' column is added.
     */
    public function activate()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        $charset_collate = $wpdb->get_charset_collate();

        // SQL query for the database table with proper indexing
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) DEFAULT '' NOT NULL,
            user_id bigint(20) DEFAULT 0 NOT NULL,
            customer_data longtext NOT NULL,
            cart_contents longtext NOT NULL,
            checkout_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            status varchar(50) DEFAULT 'abandoned' NOT NULL,
            is_viewed tinyint(1) DEFAULT 0 NOT NULL,
            PRIMARY KEY (id),
            KEY idx_session_status (session_id, status),
            KEY idx_user_status (user_id, status),
            KEY idx_checkout_time (checkout_time),
            KEY idx_status_viewed (status, is_viewed)
        ) $charset_collate;";

        // Include database upgrade file
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Create or update the table (dbDelta can add new columns)
        $result = dbDelta($sql);
        
        // Log any database creation issues
        if (empty($result)) {
            error_log('Abandoned Cart Tracker: Database table creation may have failed');
        }

        // Update existing records that might have NULL is_viewed values
        $wpdb->query("UPDATE $table_name SET is_viewed = 0 WHERE is_viewed IS NULL");

        // Schedule cleanup event if not already scheduled
        if (!wp_next_scheduled('abandoned_cart_cleanup_event')) {
            wp_schedule_event(time(), 'daily', 'abandoned_cart_cleanup_event');
        }
    }

    /**
     * Plugin deactivation function
     * (No database table deletion is performed here, but can be added if needed)
     */
    public function deactivate()
    {
        // Clear scheduled cleanup event
        wp_clear_scheduled_hook('abandoned_cart_cleanup_event');
        
        // No need to delete the database table on deactivation,
        // but if required, add the code here.
        // Example: $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}abandoned_carts" );
    }

    /**
     * Add admin menu.
     * The count of unviewed abandoned carts will be shown next to the 'Abandoned Carts' menu item.
     */
    public function add_admin_menu()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        // Count the number of unviewed abandoned carts with error handling
        $unread_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name WHERE status = %s AND is_viewed = %d", 'abandoned', 0));
        
        // Handle potential database errors
        if ($unread_count === null) {
            $unread_count = 0;
            error_log('Abandoned Cart Tracker: Failed to get unread count from database');
        }

        $menu_title = __('Abandoned Carts', 'abandoned-cart-tracker');
        if ($unread_count > 0) {
            // If there are unviewed carts, add the count to the menu title
            $menu_title .= ' <span class="awaiting-mod update-plugins"><span class="abandoned-cart-count">' . number_format_i18n($unread_count) . '</span></span>';
        }

        // Main menu item
        add_menu_page(
            __('Abandoned Carts', 'abandoned-cart-tracker'), // Page title
            $menu_title, // Menu title (with count)
            'manage_options', // Capability required to access this menu
            'abandoned-carts', // Menu slug (used in URL)
            array($this, 'render_admin_page'), // Function to call when this menu item is clicked
            'dashicons-cart', // Menu icon
            6 // Menu position (where it will sit in the dashboard menu)
        );

        // 'View Details' submenu item (this will be hidden and only shown when clicking the 'View' button)
        add_submenu_page(
            null, // Parent menu slug is null, meaning it will be hidden
            __('Abandoned Cart Details', 'abandoned-cart-tracker'), // Submenu page title
            __('View Details', 'abandoned-cart-tracker'), // Submenu title (even if hidden)
            'manage_options', // Capability required to access this submenu
            'abandoned-cart-details', // Submenu slug
            array($this, 'render_abandoned_cart_details_page') // Function to call when this submenu item is clicked
        );

        // Submenu item for Developer Info
        add_submenu_page(
            'abandoned-carts', // Parent menu slug
            __('Developer Info', 'abandoned-cart-tracker'), // Submenu page title
            __('Developer Info', 'abandoned-cart-tracker'), // Submenu title
            'manage_options', // Capability required to access this submenu
            'abandoned-carts-developer-info', // Submenu slug
            array($this, 'render_developer_info_page') // Function to call when this submenu item is clicked
        );

        // Add cleanup submenu
        add_submenu_page(
            'abandoned-carts', // Parent menu slug
            __('Cleanup Old Carts', 'abandoned-cart-tracker'), // Submenu page title
            __('Cleanup', 'abandoned-cart-tracker'), // Submenu title
            'manage_options', // Capability required to access this submenu
            'abandoned-carts-cleanup', // Submenu slug
            array($this, 'render_cleanup_page') // Function to call when this submenu item is clicked
        );
    }

    /**
     * Render the content of the admin page.
     */
    public function render_admin_page()
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Abandoned Carts', 'abandoned-cart-tracker'); ?></h1>
            <p><?php esc_html_e('Here you can see the list of customers who started the checkout process but did not complete their purchase.', 'abandoned-cart-tracker'); ?>
            </p>
            <?php
            // Ensure the Abandoned_Carts_List_Table class is loaded
            if (class_exists('Abandoned_Carts_List_Table')) {
                $abandoned_carts_table = new Abandoned_Carts_List_Table();
                $abandoned_carts_table->prepare_items(); // Prepare data for the table
                $abandoned_carts_table->display(); // Display the table
            } else {
                // If the class is not found, display an error message
                echo '<p>' . esc_html__('Error: Abandoned Carts List Table class not found. Please ensure the plugin files are correctly uploaded.', 'abandoned-cart-tracker') . '</p>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render the developer info page with a beautiful design.
     */
    public function render_developer_info_page()
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Developer Information', 'abandoned-cart-tracker'); ?></h1>
            <div class="developer-info-card">
                <p class="developer-greeting">
                    <?php esc_html_e('Thank you for using this plugin!', 'abandoned-cart-tracker'); ?></p>
                <table class="developer-details-table">
                    <tbody>
                        <tr>
                            <th><?php esc_html_e('Name', 'abandoned-cart-tracker'); ?></th>
                            <td>Md Alamin</td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Hire Me on Upwork', 'abandoned-cart-tracker'); ?></th>
                            <td><a href="https://www.upwork.com/freelancers/developeralamin" target="_blank"
                                    class="developer-link upwork-link">Click here to hire</a></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('WordPress Experience', 'abandoned-cart-tracker'); ?></th>
                            <td>Custom plugins, WooCommerce extensions, Elementor widgets, Theme Development, API Integration.
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Facebook', 'abandoned-cart-tracker'); ?></th>
                            <td><a href="https://www.facebook.com/developeralaminvai" target="_blank"
                                    class="developer-link facebook-link">Facebook Profile</a></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Email', 'abandoned-cart-tracker'); ?></th>
                            <td>developeralaminvi@gmail.com</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <style>
            .developer-info-card {
                background: linear-gradient(135deg, #f0f2f5 0%, #e0e4eb 100%);
                padding: 30px;
                border-radius: 12px;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
                max-width: 700px;
                margin: 30px auto;
                text-align: center;
                font-family: 'Segoe UI', 'Roboto', sans-serif;
                color: #333;
            }

            .developer-info-card h1 {
                font-size: 2.2em;
                color: #2c3e50;
                margin-bottom: 20px;
                text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.05);
            }

            .developer-greeting {
                font-size: 1.1em;
                margin-bottom: 25px;
                color: #555;
            }

            .developer-details-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
                background-color: #ffffff;
                border-radius: 8px;
                overflow: hidden;
                /* Ensures border-radius applies to table content */
                box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            }

            .developer-details-table th,
            .developer-details-table td {
                padding: 15px;
                text-align: left;
                border-bottom: 1px solid #eee;
            }

            .developer-details-table th {
                background-color: #f8f9fa;
                font-weight: 600;
                color: #444;
                width: 35%;
                /* Adjust width for labels */
            }

            .developer-details-table td {
                color: #666;
            }

            .developer-details-table tr:last-child th,
            .developer-details-table tr:last-child td {
                border-bottom: none;
            }

            .developer-link {
                display: inline-block;
                padding: 10px 20px;
                margin: 5px 0;
                border-radius: 25px;
                text-decoration: none;
                font-weight: bold;
                transition: all 0.3s ease;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            }

            .developer-link:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
            }

            .upwork-link {
                background-color: #14a800;
                /* Upwork green */
                color: #fff;
            }

            .upwork-link:hover {
                background-color: #129400;
            }

            .facebook-link {
                background-color: #1877f2;
                /* Facebook blue */
                color: #fff;
            }

            .facebook-link:hover {
                background-color: #166fe5;
            }

            /* Responsive adjustments */
            @media (max-width: 768px) {
                .developer-info-card {
                    padding: 20px;
                    margin: 20px auto;
                }

                .developer-info-card h1 {
                    font-size: 1.8em;
                }

                .developer-details-table th,
                .developer-details-table td {
                    padding: 10px;
                    display: block;
                    /* Stack on small screens */
                    width: 100%;
                    text-align: center;
                }

                .developer-details-table th {
                    background-color: #f0f2f5;
                    /* Lighter background for stacked headers */
                    border-bottom: none;
                }

                .developer-details-table tr {
                    margin-bottom: 15px;
                    display: block;
                    border: 1px solid #eee;
                    border-radius: 8px;
                    overflow: hidden;
                }

                .developer-details-table tr:last-child {
                    margin-bottom: 0;
                }
            }
        </style>
        <?php
    }

    /**
     * Load WP_List_Table class and custom Abandoned_Carts_List_Table class.
     */
    public function load_wp_list_table()
    {
        // Load the WP_List_Table base class if it's not already loaded
        if (!class_exists('WP_List_Table')) {
            require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
        }
        // Load the custom Abandoned_Carts_List_Table class if it's not already loaded
        if (!class_exists('Abandoned_Carts_List_Table')) {
            require_once(plugin_dir_path(__FILE__) . 'includes/class-abandoned-carts-list-table.php');
        }
    }

    /**
     * Enqueue front-end scripts (specifically for the checkout page).
     */
    public function enqueue_scripts()
    {
        // Ensure it loads only on the WooCommerce checkout page and not on the order received page
        if (is_checkout() && !is_wc_endpoint_url('order-received')) {
            wp_enqueue_script(
                'abandoned-cart-tracker-js', // Script handle
                plugin_dir_url(__FILE__) . 'assets/js/abandoned-cart-tracker.js', // URL of the script file
                array('jquery'), // Dependencies (jQuery is required)
                '1.0.1', // Version number
                true // Load the script in the footer
            );
            // Pass PHP variables to the JavaScript file
            wp_localize_script(
                'abandoned-cart-tracker-js', // Handle enqueued above
                'abandoned_cart_tracker_vars', // Name of the JavaScript object
                array(
                    'ajax_url' => admin_url('admin-ajax.php'), // WordPress AJAX URL
                    'nonce' => wp_create_nonce('abandoned-cart-tracker-nonce'), // Security nonce
                )
            );
        }
    }

    /**
     * Save/update checkout data via AJAX.
     * This function is called from front-end JavaScript when form fields change.
     */
    public function save_abandoned_checkout_data()
    {
        // Verify security nonce
        if (!check_ajax_referer('abandoned-cart-tracker-nonce', 'security', false)) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            wp_die();
        }

        // Check if WooCommerce is active and cart exists
        if (!class_exists('WooCommerce') || !WC()->cart) {
            wp_send_json_error(array('message' => 'WooCommerce not available.'));
            wp_die();
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        // Get and sanitize customer data from POST data
        $customer_data_raw = isset($_POST['customer_data']) ? wp_unslash($_POST['customer_data']) : array();
        $customer_data = array();
        
        if (!is_array($customer_data_raw)) {
            wp_send_json_error(array('message' => 'Invalid customer data format.'));
            wp_die();
        }

        foreach ($customer_data_raw as $key => $value) {
            $sanitized_key = sanitize_key($key);
            if (strpos($sanitized_key, 'email') !== false) {
                $customer_data[$sanitized_key] = sanitize_email($value);
            } else {
                $customer_data[$sanitized_key] = sanitize_text_field($value);
            }
        }

        // Get current session ID and user ID
        $session_id = WC()->session->get_customer_id();
        $user_id = get_current_user_id(); // User ID if logged in

        // Get current cart contents from server-side (more reliable than client-side data)
        $cart_contents = WC()->cart->get_cart_contents();
        $products_in_cart = array();
        
        if (!empty($cart_contents)) {
            foreach ($cart_contents as $cart_item_key => $cart_item) {
                $product_id = isset($cart_item['product_id']) ? intval($cart_item['product_id']) : 0;
                $product = wc_get_product($product_id);
                if ($product) {
                    $products_in_cart[] = array(
                        'product_id' => $product_id,
                        'product_name' => $product->get_name(),
                        'quantity' => isset($cart_item['quantity']) ? intval($cart_item['quantity']) : 1,
                        'price' => floatval($product->get_price()),
                    );
                }
            }
        }

        // Don't save if cart is empty
        if (empty($products_in_cart)) {
            wp_send_json_error(array('message' => 'Cart is empty.'));
            wp_die();
        }

        // Check if an existing entry with 'abandoned' status exists for this session/user
        $existing_entry_id = 0;
        if ($user_id) {
            // For logged-in users
            $existing_entry_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND status = 'abandoned'", $user_id));
        } elseif ($session_id) {
            // For non-logged-in users (using session ID)
            $existing_entry_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE session_id = %s AND status = 'abandoned'", $session_id));
        }

        if ($existing_entry_id) {
            // If an existing entry is found, update it
            $update_result = $wpdb->update(
                $table_name,
                array(
                    'customer_data' => serialize($customer_data), // Serialized customer data
                    'cart_contents' => serialize($products_in_cart), // Serialized cart contents
                    'checkout_time' => current_time('mysql'), // Last update time
                ),
                array('id' => $existing_entry_id), // Condition for update
                array('%s', '%s', '%s'), // Data formats
                array('%d') // Condition format
            );
            
            if ($update_result === false) {
                error_log('Abandoned Cart Tracker: Failed to update cart ID ' . $existing_entry_id);
                wp_send_json_error(array('message' => 'Database update failed.'));
            } else {
                wp_send_json_success(array('message' => 'Abandoned cart updated.', 'id' => $existing_entry_id));
            }
        } else {
            // If no existing entry is found, insert a new one
            $insert_result = $wpdb->insert(
                $table_name,
                array(
                    'session_id' => $session_id,
                    'user_id' => $user_id,
                    'customer_data' => serialize($customer_data),
                    'cart_contents' => serialize($products_in_cart),
                    'checkout_time' => current_time('mysql'),
                    'status' => 'abandoned', // Set as 'abandoned' initially
                    'is_viewed' => 0, // Set as 0 for new entry (not viewed)
                ),
                array('%s', '%d', '%s', '%s', '%s', '%s', '%d') // Data formats (added %d for is_viewed)
            );
            
            if ($insert_result === false) {
                error_log('Abandoned Cart Tracker: Failed to insert new cart entry');
                wp_send_json_error(array('message' => 'Database insert failed.'));
            } else {
                wp_send_json_success(array('message' => 'Abandoned cart inserted.', 'id' => $wpdb->insert_id));
            }
        }

        wp_die(); // Always use wp_die() in AJAX callbacks
    }

    /**
     * Capture checkout data (for woocommerce_checkout_update_order_meta hook).
     * This function is triggered when the WooCommerce checkout form is submitted (regardless of payment completion).
     * It acts as a fallback/updater for AJAX capture, ensuring data is saved.
     *
     * @param int $order_id The ID of the order.
     * @param array $data The checkout form data.
     */
    public function capture_abandoned_checkout_data($order_id, $data)
    {
        // Validate inputs
        if (!$order_id || !is_array($data)) {
            error_log('Abandoned Cart Tracker: Invalid parameters in capture_abandoned_checkout_data');
            return;
        }

        // Check if WooCommerce is available
        if (!class_exists('WooCommerce') || !WC()->cart || !WC()->session) {
            error_log('Abandoned Cart Tracker: WooCommerce not available in capture_abandoned_checkout_data');
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        $session_id = WC()->session->get_customer_id();
        $user_id = get_current_user_id();

        // Get current cart contents with error handling
        $cart_contents = WC()->cart->get_cart_contents();
        $products_in_cart = array();
        
        if (!empty($cart_contents)) {
            foreach ($cart_contents as $cart_item_key => $cart_item) {
                $product_id = isset($cart_item['product_id']) ? intval($cart_item['product_id']) : 0;
                $product = wc_get_product($product_id);
                if ($product) {
                    $products_in_cart[] = array(
                        'product_id' => $product_id,
                        'product_name' => $product->get_name(),
                        'quantity' => isset($cart_item['quantity']) ? intval($cart_item['quantity']) : 1,
                        'price' => floatval($product->get_price()),
                    );
                }
            }
        }

        // Don't process if cart is empty
        if (empty($products_in_cart)) {
            return;
        }

        // Get customer data from form submission with proper sanitization
        $customer_data = array();
        $billing_fields = array(
            'billing_first_name', 'billing_last_name', 'billing_company', 'billing_address_1',
            'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode',
            'billing_country', 'billing_phone'
        );
        $shipping_fields = array(
            'shipping_first_name', 'shipping_last_name', 'shipping_company', 'shipping_address_1',
            'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country'
        );

        foreach ($billing_fields as $field) {
            $customer_data[$field] = isset($data[$field]) ? sanitize_text_field($data[$field]) : '';
        }

        foreach ($shipping_fields as $field) {
            $customer_data[$field] = isset($data[$field]) ? sanitize_text_field($data[$field]) : '';
        }

        // Handle email separately
        $customer_data['billing_email'] = isset($data['billing_email']) ? sanitize_email($data['billing_email']) : '';

        // Check if an existing entry with 'abandoned' status exists for this session/user
        $existing_entry_id = 0;
        if ($user_id) {
            $existing_entry_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND status = %s", $user_id, 'abandoned'));
        } elseif ($session_id) {
            $existing_entry_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE session_id = %s AND status = %s", $session_id, 'abandoned'));
        }

        if ($existing_entry_id) {
            // Update the existing entry with more complete data from form submission
            $update_result = $wpdb->update(
                $table_name,
                array(
                    'customer_data' => serialize($customer_data),
                    'cart_contents' => serialize($products_in_cart),
                    'checkout_time' => current_time('mysql'),
                    'status' => 'abandoned', // Still abandoned until payment is complete
                ),
                array('id' => $existing_entry_id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );

            if ($update_result === false) {
                error_log('Abandoned Cart Tracker: Failed to update cart in capture_abandoned_checkout_data for ID ' . $existing_entry_id);
            }
        } else {
            // Insert a new entry if it doesn't exist (less likely with AJAX, but a good fallback)
            $insert_result = $wpdb->insert(
                $table_name,
                array(
                    'session_id' => $session_id,
                    'user_id' => $user_id,
                    'customer_data' => serialize($customer_data),
                    'cart_contents' => serialize($products_in_cart),
                    'checkout_time' => current_time('mysql'),
                    'status' => 'abandoned',
                    'is_viewed' => 0, // Set as 0 for new entry (not viewed)
                ),
                array('%s', '%d', '%s', '%s', '%s', '%s', '%d') // Added %d for is_viewed
            );

            if ($insert_result === false) {
                error_log('Abandoned Cart Tracker: Failed to insert cart in capture_abandoned_checkout_data');
            }
        }
    }

    /**
     * Mark the cart as 'completed' when the order is successfully finished.
     * This function is triggered when a WooCommerce order is successfully completed.
     *
     * @param int $order_id The ID of the order.
     */
    public function mark_cart_as_completed($order_id)
    {
        // Validate order ID
        if (!$order_id) {
            error_log('Abandoned Cart Tracker: Invalid order ID in mark_cart_as_completed');
            return;
        }

        // Check if WooCommerce is available
        if (!class_exists('WooCommerce') || !WC()->session) {
            error_log('Abandoned Cart Tracker: WooCommerce not available in mark_cart_as_completed');
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('Abandoned Cart Tracker: Order not found for ID ' . $order_id);
            return;
        }

        $session_id = WC()->session->get_customer_id();
        $user_id = get_current_user_id();

        // Find the corresponding abandoned cart entry using user ID or session ID and update its status to 'completed'.
        // The 'is_viewed' status is not changed here, as it only indicates if the cart has been completed.
        $update_result = false;
        
        if ($user_id) {
            $update_result = $wpdb->update(
                $table_name,
                array('status' => 'completed'), // Set status to 'completed'
                array('user_id' => $user_id, 'status' => 'abandoned'), // Find entry with 'abandoned' status for this user
                array('%s'), // Data format
                array('%d', '%s') // Condition format
            );
        } elseif ($session_id) {
            $update_result = $wpdb->update(
                $table_name,
                array('status' => 'completed'), // Set status to 'completed'
                array('session_id' => $session_id, 'status' => 'abandoned'), // Find entry with 'abandoned' status for this session ID
                array('%s'), // Data format
                array('%s', '%s') // Condition format
            );
        }

        if ($update_result === false) {
            error_log('Abandoned Cart Tracker: Failed to mark cart as completed for order ID ' . $order_id);
        }
    }

    /**
     * Render the abandoned cart details page.
     * This function displays detailed information about a specific abandoned cart.
     */
    public function render_abandoned_cart_details_page()
    {
        // Check if cart_id is provided in the URL
        if (!isset($_GET['cart_id']) || empty($_GET['cart_id'])) {
            echo '<div class="wrap"><h1>' . esc_html__('Abandoned Cart Details', 'abandoned-cart-tracker') . '</h1>';
            echo '<p>' . esc_html__('Invalid cart ID provided.', 'abandoned-cart-tracker') . '</p></div>';
            return;
        }

        $cart_id = intval($_GET['cart_id']);
        
        // Verify nonce for security
        if (!isset($_GET['view_nonce']) || !wp_verify_nonce($_GET['view_nonce'], 'view_abandoned_cart_' . $cart_id)) {
            echo '<div class="wrap"><h1>' . esc_html__('Abandoned Cart Details', 'abandoned-cart-tracker') . '</h1>';
            echo '<p>' . esc_html__('Security check failed. Please try again.', 'abandoned-cart-tracker') . '</p></div>';
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        // Fetch the cart data from database
        $cart_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $cart_id), ARRAY_A);

        if (!$cart_data) {
            echo '<div class="wrap"><h1>' . esc_html__('Abandoned Cart Details', 'abandoned-cart-tracker') . '</h1>';
            echo '<p>' . esc_html__('Cart not found.', 'abandoned-cart-tracker') . '</p></div>';
            return;
        }

        // Mark the cart as viewed with error handling
        $update_result = $wpdb->update(
            $table_name,
            array('is_viewed' => 1),
            array('id' => $cart_id),
            array('%d'),
            array('%d')
        );

        if ($update_result === false) {
            error_log('Abandoned Cart Tracker: Failed to update is_viewed status for cart ID ' . $cart_id);
        }

        // Safely unserialize data with error handling
        $customer_data = array();
        if (!empty($cart_data['customer_data'])) {
            $unserialized_customer = @unserialize($cart_data['customer_data']);
            if ($unserialized_customer !== false && is_array($unserialized_customer)) {
                $customer_data = $unserialized_customer;
            }
        }

        $cart_contents = array();
        if (!empty($cart_data['cart_contents'])) {
            $unserialized_cart = @unserialize($cart_data['cart_contents']);
            if ($unserialized_cart !== false && is_array($unserialized_cart)) {
                $cart_contents = $unserialized_cart;
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Abandoned Cart Details', 'abandoned-cart-tracker'); ?></h1>
            
            <div class="abandoned-cart-details">
                <!-- Customer Information -->
                <div class="customer-info-section">
                    <h2><?php esc_html_e('Customer Information', 'abandoned-cart-tracker'); ?></h2>
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Name', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html(($customer_data['billing_first_name'] ?? '') . ' ' . ($customer_data['billing_last_name'] ?? '')); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Email', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['billing_email'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Phone', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['billing_phone'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Company', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['billing_company'] ?? ''); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Billing Address -->
                <div class="billing-address-section">
                    <h2><?php esc_html_e('Billing Address', 'abandoned-cart-tracker'); ?></h2>
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Address Line 1', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['billing_address_1'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Address Line 2', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['billing_address_2'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('City', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['billing_city'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('State/Province', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['billing_state'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Postal Code', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['billing_postcode'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Country', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['billing_country'] ?? ''); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Shipping Address -->
                <?php if (!empty($customer_data['shipping_first_name']) || !empty($customer_data['shipping_address_1'])): ?>
                <div class="shipping-address-section">
                    <h2><?php esc_html_e('Shipping Address', 'abandoned-cart-tracker'); ?></h2>
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Name', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html(($customer_data['shipping_first_name'] ?? '') . ' ' . ($customer_data['shipping_last_name'] ?? '')); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Company', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['shipping_company'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Address Line 1', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['shipping_address_1'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Address Line 2', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['shipping_address_2'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('City', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['shipping_city'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('State/Province', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['shipping_state'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Postal Code', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['shipping_postcode'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Country', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($customer_data['shipping_country'] ?? ''); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Cart Contents -->
                <div class="cart-contents-section">
                    <h2><?php esc_html_e('Cart Contents', 'abandoned-cart-tracker'); ?></h2>
                    <?php if (is_array($cart_contents) && !empty($cart_contents)): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Product', 'abandoned-cart-tracker'); ?></th>
                                    <th><?php esc_html_e('Quantity', 'abandoned-cart-tracker'); ?></th>
                                    <th><?php esc_html_e('Price', 'abandoned-cart-tracker'); ?></th>
                                    <th><?php esc_html_e('Total', 'abandoned-cart-tracker'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $cart_total = 0;
                                foreach ($cart_contents as $product):
                                    $product_total = floatval($product['price']) * intval($product['quantity']);
                                    $cart_total += $product_total;
                                    $product_url = get_permalink($product['product_id']);
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($product_url): ?>
                                            <a href="<?php echo esc_url($product_url); ?>" target="_blank">
                                                <?php echo esc_html($product['product_name']); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo esc_html($product['product_name']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($product['quantity']); ?></td>
                                    <td><?php echo wc_price($product['price']); ?></td>
                                    <td><?php echo wc_price($product_total); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3"><?php esc_html_e('Cart Total', 'abandoned-cart-tracker'); ?></th>
                                    <th><?php echo wc_price($cart_total); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    <?php else: ?>
                        <p><?php esc_html_e('No products found in cart.', 'abandoned-cart-tracker'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Cart Information -->
                <div class="cart-info-section">
                    <h2><?php esc_html_e('Cart Information', 'abandoned-cart-tracker'); ?></h2>
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Checkout Time', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($cart_data['checkout_time']))); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Status', 'abandoned-cart-tracker'); ?></th>
                                <td>
                                    <span class="<?php echo ($cart_data['status'] === 'abandoned') ? 'abandoned-status' : 'completed-status'; ?>">
                                        <?php echo esc_html(ucfirst($cart_data['status'])); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Session ID', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($cart_data['session_id']); ?></td>
                            </tr>
                            <?php if ($cart_data['user_id'] > 0): ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('User ID', 'abandoned-cart-tracker'); ?></th>
                                <td><?php echo esc_html($cart_data['user_id']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Back Button -->
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=abandoned-carts')); ?>" class="button button-secondary">
                        <?php esc_html_e('← Back to Abandoned Carts', 'abandoned-cart-tracker'); ?>
                    </a>
                </p>
            </div>
        </div>

        <style>
            .abandoned-cart-details {
                max-width: 1200px;
            }
            .abandoned-cart-details > div {
                margin-bottom: 30px;
                background: #fff;
                padding: 20px;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .abandoned-cart-details h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .abandoned-status {
                color: #dc3232;
                font-weight: bold;
            }
            .completed-status {
                color: #46b450;
                font-weight: bold;
            }
        </style>
        <?php
    }

    /**
     * Render the cleanup page for managing old abandoned carts.
     */
    public function render_cleanup_page()
    {
        // Safety check - ensure WordPress functions are available
        if (!function_exists('wp_verify_nonce') || !function_exists('esc_html_e')) {
            echo '<div class="wrap"><h1>Cleanup Old Abandoned Carts</h1><p>WordPress functions not available. Please refresh the page.</p></div>';
            return;
        }

        try {
            // Handle cleanup action
            if (isset($_POST['cleanup_action']) && wp_verify_nonce($_POST['cleanup_nonce'], 'cleanup_abandoned_carts')) {
                $days = intval($_POST['cleanup_days']);
                if ($days > 0) {
                    $deleted_count = $this->cleanup_old_carts_by_days($days);
                    echo '<div class="notice notice-success"><p>' .
                         sprintf(__('Successfully deleted %d old abandoned carts.', 'abandoned-cart-tracker'), $deleted_count) .
                         '</p></div>';
                }
            }

            global $wpdb;
            
            // Check if $wpdb is available
            if (!$wpdb) {
                ?>
                <div class="wrap">
                    <h1><?php esc_html_e('Cleanup Old Abandoned Carts', 'abandoned-cart-tracker'); ?></h1>
                    <div class="notice notice-error">
                        <p><?php esc_html_e('Database connection not available. Please try again later.', 'abandoned-cart-tracker'); ?></p>
                    </div>
                </div>
                <?php
                return;
            }
            
            $table_name = $wpdb->prefix . 'abandoned_carts';
            
            // Check if table exists first
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
            
            if (!$table_exists) {
                ?>
                <div class="wrap">
                    <h1><?php esc_html_e('Cleanup Old Abandoned Carts', 'abandoned-cart-tracker'); ?></h1>
                    <div class="notice notice-error">
                        <p><?php esc_html_e('Database table does not exist. Please deactivate and reactivate the plugin.', 'abandoned-cart-tracker'); ?></p>
                    </div>
                </div>
                <?php
                return;
            }
            
            // Get statistics with error handling
            $total_abandoned = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name WHERE status = %s", 'abandoned'));
            $total_completed = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name WHERE status = %s", 'completed'));
            
            // Use safer date calculation
            $date_30_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
            $date_90_days_ago = date('Y-m-d H:i:s', strtotime('-90 days'));
            
            $old_carts_30_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name WHERE status = %s AND checkout_time < %s", 'abandoned', $date_30_days_ago));
            $old_carts_90_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name WHERE status = %s AND checkout_time < %s", 'abandoned', $date_90_days_ago));
            
            // Handle potential null values
            $total_abandoned = $total_abandoned !== null ? intval($total_abandoned) : 0;
            $total_completed = $total_completed !== null ? intval($total_completed) : 0;
            $old_carts_30_days = $old_carts_30_days !== null ? intval($old_carts_30_days) : 0;
            $old_carts_90_days = $old_carts_90_days !== null ? intval($old_carts_90_days) : 0;
            
        } catch (Exception $e) {
            error_log('Abandoned Cart Tracker: Cleanup page exception - ' . $e->getMessage());
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Cleanup Old Abandoned Carts', 'abandoned-cart-tracker'); ?></h1>
                <div class="notice notice-error">
                    <p><?php esc_html_e('An error occurred while loading the cleanup page. Please check the error logs.', 'abandoned-cart-tracker'); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Cleanup Old Abandoned Carts', 'abandoned-cart-tracker'); ?></h1>
            
            <div class="cleanup-stats">
                <h2><?php esc_html_e('Statistics', 'abandoned-cart-tracker'); ?></h2>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Total Abandoned Carts', 'abandoned-cart-tracker'); ?></th>
                            <td><?php echo esc_html(number_format_i18n($total_abandoned)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Total Completed Orders', 'abandoned-cart-tracker'); ?></th>
                            <td><?php echo esc_html(number_format_i18n($total_completed)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Abandoned Carts Older Than 30 Days', 'abandoned-cart-tracker'); ?></th>
                            <td><?php echo esc_html(number_format_i18n($old_carts_30_days)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Abandoned Carts Older Than 90 Days', 'abandoned-cart-tracker'); ?></th>
                            <td><?php echo esc_html(number_format_i18n($old_carts_90_days)); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="cleanup-actions">
                <h2><?php esc_html_e('Cleanup Actions', 'abandoned-cart-tracker'); ?></h2>
                <p><?php esc_html_e('Use this tool to clean up old abandoned carts to improve database performance. This action cannot be undone.', 'abandoned-cart-tracker'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('cleanup_abandoned_carts', 'cleanup_nonce'); ?>
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="cleanup_days"><?php esc_html_e('Delete carts older than', 'abandoned-cart-tracker'); ?></label>
                                </th>
                                <td>
                                    <select name="cleanup_days" id="cleanup_days">
                                        <option value="30"><?php esc_html_e('30 days', 'abandoned-cart-tracker'); ?></option>
                                        <option value="60"><?php esc_html_e('60 days', 'abandoned-cart-tracker'); ?></option>
                                        <option value="90" selected><?php esc_html_e('90 days', 'abandoned-cart-tracker'); ?></option>
                                        <option value="180"><?php esc_html_e('180 days', 'abandoned-cart-tracker'); ?></option>
                                        <option value="365"><?php esc_html_e('1 year', 'abandoned-cart-tracker'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Only abandoned carts (not completed orders) will be deleted.', 'abandoned-cart-tracker'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="cleanup_action" class="button button-primary"
                               value="<?php esc_attr_e('Delete Old Carts', 'abandoned-cart-tracker'); ?>"
                               onclick="return confirm('<?php esc_js_e('Are you sure you want to delete old abandoned carts? This action cannot be undone.', 'abandoned-cart-tracker'); ?>');" />
                    </p>
                </form>
            </div>

            <div class="cleanup-info">
                <h2><?php esc_html_e('Automatic Cleanup', 'abandoned-cart-tracker'); ?></h2>
                <p><?php esc_html_e('The plugin automatically runs a cleanup process daily to remove abandoned carts older than 6 months. You can use the manual cleanup above for more aggressive cleanup.', 'abandoned-cart-tracker'); ?></p>
            </div>
        </div>

        <style>
            .cleanup-stats, .cleanup-actions, .cleanup-info {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .cleanup-stats h2, .cleanup-actions h2, .cleanup-info h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
        </style>
        <?php
    }

    /**
     * Cleanup old abandoned carts by specified number of days.
     *
     * @param int $days Number of days to keep carts
     * @return int Number of deleted records
     */
    public function cleanup_old_carts_by_days($days)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';
        
        // Validate input
        $days = intval($days);
        if ($days <= 0) {
            error_log('Abandoned Cart Tracker: Invalid days parameter for cleanup');
            return 0;
        }
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
        if (!$table_exists) {
            error_log('Abandoned Cart Tracker: Table does not exist for cleanup');
            return 0;
        }
        
        try {
            $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            
            // Validate date
            if (!$cutoff_date || $cutoff_date === '1970-01-01 00:00:00') {
                error_log('Abandoned Cart Tracker: Invalid cutoff date for cleanup');
                return 0;
            }
            
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_name WHERE status = %s AND checkout_time < %s",
                'abandoned',
                $cutoff_date
            ));
            
            if ($deleted === false) {
                error_log('Abandoned Cart Tracker: Database error during cleanup - ' . $wpdb->last_error);
                return 0;
            }
            
            return intval($deleted);
            
        } catch (Exception $e) {
            error_log('Abandoned Cart Tracker: Exception during cleanup - ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * AJAX handler for cleanup action.
     */
    public function cleanup_old_carts()
    {
        try {
            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Insufficient permissions.'));
                wp_die();
            }

            // Verify nonce
            if (!check_ajax_referer('cleanup_abandoned_carts', 'nonce', false)) {
                wp_send_json_error(array('message' => 'Security check failed.'));
                wp_die();
            }

            $days = isset($_POST['days']) ? intval($_POST['days']) : 90;
            
            if ($days < 1) {
                wp_send_json_error(array('message' => 'Invalid number of days.'));
                wp_die();
            }

            $deleted_count = $this->cleanup_old_carts_by_days($days);
            
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully deleted %d old abandoned carts.', 'abandoned-cart-tracker'), $deleted_count),
                'deleted_count' => $deleted_count
            ));
            
        } catch (Exception $e) {
            error_log('Abandoned Cart Tracker: AJAX cleanup exception - ' . $e->getMessage());
            wp_send_json_error(array('message' => 'An error occurred during cleanup.'));
        }
        
        wp_die();
    }

    /**
     * Scheduled cleanup function (runs daily).
     */
    public function scheduled_cleanup()
    {
        try {
            // Automatically cleanup carts older than 6 months
            $deleted = $this->cleanup_old_carts_by_days(180);
            if ($deleted > 0) {
                error_log("Abandoned Cart Tracker: Scheduled cleanup removed {$deleted} old carts");
            }
        } catch (Exception $e) {
            error_log('Abandoned Cart Tracker: Scheduled cleanup exception - ' . $e->getMessage());
        }
    }

    /**
     * Display admin notices for important information.
     */
    public function admin_notices()
    {
        try {
            // Only show on plugin pages
            $screen = get_current_screen();
            if (!$screen || strpos($screen->id, 'abandoned-cart') === false) {
                return;
            }

            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php esc_html_e('Abandoned Cart Tracker', 'abandoned-cart-tracker'); ?>:</strong>
                        <?php esc_html_e('WooCommerce is required for this plugin to work. Please install and activate WooCommerce.', 'abandoned-cart-tracker'); ?>
                    </p>
                </div>
                <?php
            }

            // Check database table exists with proper error handling
            global $wpdb;
            $table_name = $wpdb->prefix . 'abandoned_carts';
            
            // Use prepared statement for safety
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
            
            if (!$table_exists) {
                ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php esc_html_e('Abandoned Cart Tracker', 'abandoned-cart-tracker'); ?>:</strong>
                        <?php esc_html_e('Database table is missing. Please deactivate and reactivate the plugin to fix this issue.', 'abandoned-cart-tracker'); ?>
                    </p>
                </div>
                <?php
            }
        } catch (Exception $e) {
            error_log('Abandoned Cart Tracker: Admin notices exception - ' . $e->getMessage());
        }
    }
}

// Instantiate the plugin class
new Abandoned_Cart_Tracker();
