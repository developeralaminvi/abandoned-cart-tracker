<?php
/**
 * Plugin Name:       Abandoned Cart Tracker
 * Plugin URI:        https://github.com/developeralaminvi/abandoned-cart-tracker/
 * Description:       Captures customer data and product details from abandoned checkouts and displays them in the admin dashboard.
 * Version:           1.0.0
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

        // SQL query for the database table
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) DEFAULT '' NOT NULL,
            user_id bigint(20) DEFAULT 0 NOT NULL,
            customer_data longtext NOT NULL,
            cart_contents longtext NOT NULL,
            checkout_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            status varchar(50) DEFAULT 'abandoned' NOT NULL,
            is_viewed tinyint(1) DEFAULT 0 NOT NULL, -- New column: 0 = not viewed, 1 = viewed
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Include database upgrade file
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        // Create or update the table (dbDelta can add new columns)
        dbDelta($sql);
    }

    /**
     * Plugin deactivation function
     * (No database table deletion is performed here, but can be added if needed)
     */
    public function deactivate()
    {
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

        // Count the number of unviewed abandoned carts
        $unread_count = $wpdb->get_var("SELECT COUNT(id) FROM $table_name WHERE status = 'abandoned' AND is_viewed = 0");

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
                '1.0.0', // Version number
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
        check_ajax_referer('abandoned-cart-tracker-nonce', 'security');

        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        // Get and sanitize customer data from POST data
        $customer_data_raw = isset($_POST['customer_data']) ? wp_unslash($_POST['customer_data']) : array();
        $customer_data = array();
        foreach ($customer_data_raw as $key => $value) {
            if (strpos($key, 'email') !== false) {
                $customer_data[$key] = sanitize_email($value);
            } else {
                $customer_data[$key] = sanitize_text_field($value);
            }
        }

        // Get current session ID and user ID
        $session_id = WC()->session->get_customer_id();
        $user_id = get_current_user_id(); // User ID if logged in

        // Get current cart contents from server-side (more reliable than client-side data)
        $cart_contents = WC()->cart->get_cart_contents();
        $products_in_cart = array();
        foreach ($cart_contents as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $product = wc_get_product($product_id);
            if ($product) {
                $products_in_cart[] = array(
                    'product_id' => $product_id,
                    'product_name' => $product->get_name(),
                    'quantity' => $cart_item['quantity'],
                    'price' => $product->get_price(),
                );
            }
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
            $wpdb->update(
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
            wp_send_json_success(array('message' => 'Abandoned cart updated.', 'id' => $existing_entry_id));
        } else {
            // If no existing entry is found, insert a new one
            $wpdb->insert(
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
            wp_send_json_success(array('message' => 'Abandoned cart inserted.', 'id' => $wpdb->insert_id));
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
        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        $session_id = WC()->session->get_customer_id();
        $user_id = get_current_user_id();

        // Get current cart contents
        $cart_contents = WC()->cart->get_cart_contents();
        $products_in_cart = array();
        foreach ($cart_contents as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $product = wc_get_product($product_id);
            if ($product) {
                $products_in_cart[] = array(
                    'product_id' => $product_id,
                    'product_name' => $product->get_name(),
                    'quantity' => $cart_item['quantity'],
                    'price' => $product->get_price(),
                );
            }
        }

        // Get customer data from form submission (might be more complete than AJAX data)
        $customer_data = array(
            'billing_first_name' => sanitize_text_field($data['billing_first_name']),
            'billing_last_name' => sanitize_text_field($data['billing_last_name']),
            'billing_company' => sanitize_text_field($data['billing_company']),
            'billing_address_1' => sanitize_text_field($data['billing_address_1']),
            'billing_address_2' => sanitize_text_field($data['billing_address_2']),
            'billing_city' => sanitize_text_field($data['billing_city']),
            'billing_state' => sanitize_text_field($data['billing_state']),
            'billing_postcode' => sanitize_text_field($data['billing_postcode']),
            'billing_country' => sanitize_text_field($data['billing_country']),
            'billing_email' => sanitize_email($data['billing_email']),
            'billing_phone' => sanitize_text_field($data['billing_phone']),
            'shipping_first_name' => sanitize_text_field($data['shipping_first_name']),
            'shipping_last_name' => sanitize_text_field($data['shipping_last_name']),
            'shipping_company' => sanitize_text_field($data['shipping_company']),
            'shipping_address_1' => sanitize_text_field($data['shipping_address_1']),
            'shipping_address_2' => sanitize_text_field($data['shipping_address_2']),
            'shipping_city' => sanitize_text_field($data['shipping_city']),
            'shipping_state' => sanitize_text_field($data['shipping_state']),
            'shipping_postcode' => sanitize_text_field($data['shipping_postcode']),
            'shipping_country' => sanitize_text_field($data['shipping_country']),
        );

        // Check if an existing entry with 'abandoned' status exists for this session/user
        $existing_entry_id = 0;
        if ($user_id) {
            $existing_entry_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND status = 'abandoned'", $user_id));
        } elseif ($session_id) {
            $existing_entry_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE session_id = %s AND status = 'abandoned'", $session_id));
        }

        if ($existing_entry_id) {
            // Update the existing entry with more complete data from form submission
            $wpdb->update(
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
        } else {
            // Insert a new entry if it doesn't exist (less likely with AJAX, but a good fallback)
            $wpdb->insert(
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
        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $session_id = WC()->session->get_customer_id();
        $user_id = get_current_user_id();

        // Find the corresponding abandoned cart entry using user ID or session ID and update its status to 'completed'.
        // The 'is_viewed' status is not changed here, as it only indicates if the cart has been completed.
        if ($user_id) {
            $wpdb->update(
                $table_name,
                array('status' => 'completed'), // Set status to 'completed'
                array('user_id' => $user_id, 'status' => 'abandoned'), // Find entry with 'abandoned' status for this user
                array('%s'), // Data format
                array('%d', '%s') // Condition format
            );
        } elseif ($session_id) {
            $wpdb->update(
                $table_name,
                array('status' => 'completed'), // Set status to 'completed'
                array('session_id' => $session_id, 'status' => 'abandoned'), // Find entry with 'abandoned' status for this session ID
                array('%s'), // Data format
                array('%s', '%s') // Condition format
            );
        }
    }
}

// Instantiate the plugin class
new Abandoned_Cart_Tracker();