<?php
/**
 * Displays a list of abandoned carts using WP_List_Table.
 */
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Abandoned_Carts_List_Table extends WP_List_Table
{

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'abandoned_cart', // Singular name for the list table
            'plural' => 'abandoned_carts', // Plural name for the list table
            'ajax' => false, // This table does not use AJAX (currently)
        ));
    }

    /**
     * Define the columns for the list table.
     * This function defines the column headers for the table.
     * 'actions' column has been added.
     *
     * @return array An array of column names and their display titles.
     */
    public function get_columns()
    {
        $columns = array(
            'cb' => '<input type="checkbox" />', // Checkbox column for each row
            'customer_name' => __('Customer Name', 'abandoned-cart-tracker'), // Customer's name
            'customer_email' => __('Customer Email', 'abandoned-cart-tracker'), // Customer's email
            'customer_phone' => __('Customer Phone', 'abandoned-cart-tracker'), // Customer's phone number
            'products' => __('Products', 'abandoned-cart-tracker'), // Products in the cart
            'checkout_time' => __('Checkout Time', 'abandoned-cart-tracker'), // Time when checkout was initiated
            'status' => __('Status', 'abandoned-cart-tracker'), // Cart status (abandoned/completed)
            'actions' => __('Actions', 'abandoned-cart-tracker'), // New column: Action buttons
        );
        return $columns;
    }

    /**
     * Define sortable columns.
     * This function specifies which columns can be sorted.
     *
     * @return array An array of sortable columns.
     */
    public function get_sortable_columns()
    {
        $sortable_columns = array(
            'checkout_time' => array('checkout_time', false), // 'checkout_time' column is sortable, default asc
            'status' => array('status', false), // 'status' column is sortable, default asc
        );
        return $sortable_columns;
    }

    /**
     * Main data query.
     * This function fetches data for the table from the database and sets up pagination.
     * Only 'abandoned' status carts will be shown here.
     */
    public function prepare_items()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'abandoned_carts';

        $per_page = 20; // Number of items to display per page
        $current_page = $this->get_pagenum(); // Get the current page number
        $offset = ($current_page - 1) * $per_page; // Calculate the offset

        // Get sorting parameters
        $orderby = (isset($_GET['orderby']) && in_array($_GET['orderby'], array_keys($this->get_sortable_columns()))) ? sanitize_sql_orderby($_GET['orderby']) : 'checkout_time';
        $order = (isset($_GET['order']) && in_array($_GET['order'], array('asc', 'desc'))) ? sanitize_sql_orderby($_GET['order']) : 'desc';

        // Build search query
        $search_query = '';
        if (isset($_GET['s']) && !empty($_GET['s'])) {
            $search_term = sanitize_text_field($_GET['s']);
            // Search in customer_data or cart_contents fields
            $search_query = $wpdb->prepare(" AND (customer_data LIKE %s OR cart_contents LIKE %s)", '%' . $wpdb->esc_like($search_term) . '%', '%' . $wpdb->esc_like($search_term) . '%');
        }

        // Filter by 'abandoned' status
        $where_clause = "WHERE status = 'abandoned'";

        // Fetch items from the database
        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name $where_clause $search_query ORDER BY $orderby $order LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        // Get total number of items (for pagination)
        $total_items = $wpdb->get_var("SELECT COUNT(id) FROM $table_name $where_clause $search_query");

        // Set pagination arguments
        $this->set_pagination_args(array(
            'total_items' => $total_items, // Total number of items
            'per_page' => $per_page,    // Number of items per page
            'total_pages' => ceil($total_items / $per_page), // Total number of pages
        ));

        // Set column headers
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());
    }

    /**
     * Render default column output.
     * This function determines how data for each column will be displayed.
     * 'View' button has been added for the 'actions' column.
     * Color differentiation for viewed/unviewed data.
     * Product names now link to product pages.
     * Checkout time now shows relative time and only the date.
     *
     * @param array $item The current row data.
     * @param string $column_name The name of the column.
     * @return string The data to be displayed.
     */
    protected function column_default($item, $column_name)
    {
        // Unserialize customer data
        $customer_data = unserialize($item['customer_data']);
        // Unserialize cart contents
        $cart_contents = unserialize($item['cart_contents']);

        // Determine row class based on 'is_viewed' status
        $row_class = (isset($item['is_viewed']) && $item['is_viewed'] == 1) ? 'viewed-cart' : 'unviewed-cart';

        switch ($column_name) {
            case 'customer_name':
                // Display concatenated first and last name of the customer
                return '<span class="' . esc_attr($row_class) . '">' . esc_html($customer_data['billing_first_name'] . ' ' . $customer_data['billing_last_name']) . '</span>';
            case 'customer_email':
                // Display customer's email address
                return '<span class="' . esc_attr($row_class) . '">' . esc_html($customer_data['billing_email']) . '</span>';
            case 'customer_phone':
                // Display customer's phone number
                return '<span class="' . esc_attr($row_class) . '">' . esc_html($customer_data['billing_phone']) . '</span>';
            case 'products':
                // Create a list of products in the cart with links
                $product_list = '<ul class="' . esc_attr($row_class) . '">';
                if (is_array($cart_contents) && !empty($cart_contents)) {
                    foreach ($cart_contents as $product) {
                        $product_name = esc_html($product['product_name']);
                        $product_url = get_permalink($product['product_id']);
                        if ($product_url) {
                            $product_name = '<a href="' . esc_url($product_url) . '" target="_blank">' . $product_name . '</a>';
                        }
                        // Display product name (with link), quantity, and total price for each product
                        $product_list .= '<li>' . $product_name . ' (x' . esc_html($product['quantity']) . ') - ' . wc_price($product['price'] * $product['quantity']) . '</li>';
                    }
                } else {
                    $product_list .= '<li>' . __('No products found', 'abandoned-cart-tracker') . '</li>';
                }
                $product_list .= '</ul>';
                return $product_list;
            case 'checkout_time':
                // Display checkout time with only the date and relative time
                $datetime_timestamp = strtotime($item['checkout_time']);
                $date_only = date(wc_date_format(), $datetime_timestamp); // Format to show only date
                $relative_time = $this->_format_relative_time($item['checkout_time']); // Use original for relative time calculation
                return '<span class="' . esc_attr($row_class) . '">' . esc_html($date_only) . '<br><small>(' . $relative_time . ')</small></span>';
            case 'status':
                // Set text and class based on status
                $status_text = ($item['status'] === 'abandoned') ? __('Abandoned', 'abandoned-cart-tracker') : __('Completed', 'abandoned-cart-tracker');
                $status_class = ($item['status'] === 'abandoned') ? 'abandoned-status' : 'completed-status';
                return '<span class="' . esc_attr($row_class) . ' ' . esc_attr($status_class) . '">' . esc_html($status_text) . '</span>';
            case 'actions':
                // Create URL for the 'View' button
                $view_url = admin_url('admin.php?page=abandoned-cart-details&cart_id=' . $item['id']);
                $actions = array(
                    'view' => sprintf('<a href="%s">%s</a>', esc_url($view_url), __('View', 'abandoned-cart-tracker')),
                );
                return $this->row_actions($actions);
            default:
                // For debugging, print data for other columns
                return print_r($item, true);
        }
    }

    /**
     * Get a list of CSS classes for the current row.
     * This method is used by WP_List_Table to add classes to each row.
     * We're overriding it to add 'viewed-cart-row' or 'unviewed-cart-row' class.
     *
     * @param array $item The current item.
     * @return string Space-separated list of CSS classes.
     */
    protected function get_row_class($item)
    {
        $classes = array();
        $classes[] = (isset($item['is_viewed']) && $item['is_viewed'] == 1) ? 'viewed-cart-row' : 'unviewed-cart-row';
        return implode(' ', $classes);
    }

    /**
     * Render the checkbox column.
     * This function displays a checkbox for each row.
     *
     * @param array $item The current row data.
     * @return string The checkbox HTML.
     */
    protected function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s" />',
            $this->_args['singular'], // 'abandoned_cart'
            $item['id'] // ID of the current item
        );
    }

    /**
     * Add additional CSS for the table.
     * This function adds custom CSS styles to the admin page.
     * CSS for the unread count badge in the admin menu has been added.
     * New CSS for viewed/unviewed rows has been added.
     */
    public function print_styles()
    {
        ?>
        <style>
            .abandoned-status {
                color: #dc3232;
                /* Red color for abandoned status */
                font-weight: bold;
            }

            .completed-status {
                color: #46b450;
                /* Green color for completed status */
                font-weight: bold;
            }

            /* Style for the unread count badge in admin menu */
            #adminmenu .awaiting-mod.update-plugins {
                background-color: #d63638;
                /* Red background */
                color: #fff;
                /* White text */
                vertical-align: top;
                margin-left: 6px;
                border-radius: 50%;
                /* Circular shape */
                padding: 2px 6px;
                font-size: 10px;
                line-height: 1.2;
                font-weight: 600;
                display: inline-block;
                text-align: center;
                min-width: 18px;
                box-sizing: border-box;
            }

            #adminmenu .awaiting-mod.update-plugins .abandoned-cart-count {
                display: block;
                line-height: 1;
            }

            /* Styles for viewed/unviewed rows */
            .unviewed-cart-row {
                background-color: #fffbe6;
                /* Light yellow for unviewed rows */
            }

            .viewed-cart-row {
                background-color: #f9f9f9;
                /* Lighter background for viewed rows */
            }

            .unviewed-cart-row td {
                font-weight: bold;
                /* Make text bold for unviewed rows */
                color: #000;
                /* Ensure text is black for unviewed items within the row */
            }

            .viewed-cart-row td {
                font-weight: normal;
                /* Normal text for viewed rows */
                color: #555;
                /* Slightly muted text for viewed items within the row */
            }

            /* Specific styling for product links within the table */
            .unviewed-cart-row a {
                color: #0073aa;
                /* WordPress blue for links in unviewed rows */
            }

            .viewed-cart-row a {
                color: #005177;
                /* Darker blue for links in viewed rows */
            }
        </style>
        <?php
    }

    /**
     * Add a search box above the table.
     *
     * @param string $text The text for the search box.
     * @param string $input_id The ID for the input field.
     */
    public function search_box($text, $input_id)
    {
        // Don't show the search box if there's no search term and no items
        if (empty($_REQUEST['s']) && !$this->has_items()) {
            return;
        }

        $input_id = $input_id . '-search-input';

        // Add hidden inputs for sorting parameters to maintain sorting when searching
        if (!empty($_REQUEST['orderby'])) {
            echo '<input type="hidden" name="orderby" value="' . esc_attr($_REQUEST['orderby']) . '" />';
        }
        if (!empty($_REQUEST['order'])) {
            echo '<input type="hidden" name="order" value="' . esc_attr($_REQUEST['order']) . '" />';
        }
        ?>
        <p class="search-box">
            <label class="screen-reader-text"
                for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($text); ?>:</label>
            <input type="search" id="<?php echo esc_attr($input_id); ?>" name="s" value="<?php _admin_search_query(); ?>" />
            <?php submit_button($text, 'button', '', false, array('id' => 'search-submit')); ?>
        </p>
        <?php
    }

    /**
     * Helper function to format time into a human-readable, relative string.
     *
     * @param string $datetime_string The datetime string from the database (e.g., 'YYYY-MM-DD HH:MM:SS').
     * @return string Human-readable relative time string.
     */
    private function _format_relative_time($datetime_string)
    {
        $timestamp = strtotime($datetime_string);
        if (!$timestamp) {
            return $datetime_string; // Return original if invalid
        }

        $current_time = current_time('timestamp');
        $diff = $current_time - $timestamp;

        if ($diff < 60) {
            return sprintf(_n('%s second ago', '%s seconds ago', $diff, 'abandoned-cart-tracker'), $diff);
        } elseif ($diff < HOUR_IN_SECONDS) {
            $minutes = round($diff / MINUTE_IN_SECONDS);
            return sprintf(_n('%s minute ago', '%s minutes ago', $minutes, 'abandoned-cart-tracker'), $minutes);
        } elseif ($diff < DAY_IN_SECONDS) {
            $hours = round($diff / HOUR_IN_SECONDS);
            return sprintf(_n('%s hour ago', '%s hours ago', $hours, 'abandoned-cart-tracker'), $hours);
        } elseif ($diff < WEEK_IN_SECONDS) {
            $days = round($diff / DAY_IN_SECONDS);
            return sprintf(_n('%s day ago', '%s days ago', $days, 'abandoned-cart-tracker'), $days);
        } elseif ($diff < MONTH_IN_SECONDS) {
            $weeks = round($diff / WEEK_IN_SECONDS);
            return sprintf(_n('%s week ago', '%s weeks ago', $weeks, 'abandoned-cart-tracker'), $weeks);
        } elseif ($diff < YEAR_IN_SECONDS) {
            $months = round($diff / MONTH_IN_SECONDS);
            return sprintf(_n('%s month ago', '%s months ago', $months, 'abandoned-cart-tracker'), $months);
        } else {
            $years = round($diff / YEAR_IN_SECONDS);
            return sprintf(_n('%s year ago', '%s years ago', $years, 'abandoned-cart-tracker'), $years);
        }
    }
}
