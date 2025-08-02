# Abandoned Cart Tracker for WooCommerce

**Plugin Name:** Abandoned Cart Tracker  
**Version:** 1.0.0  
**Author:** Md Alamin  
**Author URI:** [https://www.upwork.com/freelancers/developeralamin](https://www.upwork.com/freelancers/developeralamin)  
**License:** GPL-2.0+  
**License URI:** http://www.gnu.org/licenses/gpl-2.0.txt

---

## 🧩 Description

The **Abandoned Cart Tracker** plugin for WooCommerce helps store owners recover lost sales by tracking customer data and cart details from abandoned checkouts.

When a customer fills out the checkout form but doesn’t complete the purchase, their information and cart contents are automatically saved and displayed in the WordPress admin dashboard.

If the customer completes the order later, the entry is automatically removed from the abandoned list.

---

## 🚀 Features

- **Real-time Data Capture**: Captures data as users fill out the checkout form.
- **Guest & Logged-in Support**: Works for both logged-in and non-logged-in users.
- **Automatic Status Update**: Removes entry from the abandoned list upon purchase.
- **Admin Dashboard Integration**: View all abandoned carts in a custom admin table.
- **Unviewed Cart Count**: Live count shown in admin menu with a red badge.
- **Visual Cues**: Bold & highlighted entries for unviewed carts.
- **Detailed View**: View complete customer and cart data for each abandoned cart.
- **Clickable Product Links**: Cart items link to their product pages.
- **Relative Time Format**: Displays checkout time like “5 minutes ago”.
- **Search & Pagination**: Easy navigation through all data.

---

## 📦 Installation

### 🔽 Manual Installation

You can manually install the plugin by downloading it from GitHub:

**Download from GitHub:**  
[https://github.com/developeralaminvi/abandoned-cart-tracker/](https://github.com/developeralaminvi/abandoned-cart-tracker/)

#### Steps:

1. Download the plugin ZIP from the GitHub repository.
2. Extract the ZIP file. You should get a folder named `abandoned-cart-tracker`.
3. Upload the folder to your WordPress site's `/wp-content/plugins/` directory via FTP or File Manager.
4. Log in to your WordPress admin dashboard.
5. Go to **Plugins > Installed Plugins**.
6. Locate **Abandoned Cart Tracker** and click **Activate**.

> 📌 Alternatively, you can upload the ZIP file directly via **Plugins > Add New > Upload Plugin** in your WordPress dashboard.

### Activation

1. Log in to your WordPress admin dashboard.
2. Go to **Plugins > Installed Plugins**.
3. Find **Abandoned Cart Tracker** and click **Activate**.

---

## 🛠️ Usage

- Once activated, the plugin automatically starts tracking abandoned carts.
- Go to the **Abandoned Carts** menu in your WordPress admin to view data.
- A red badge shows the number of new/unviewed entries.
- Click **View** to see detailed data. Viewing marks it as “viewed”.
- Visit **Abandoned Carts > Developer Info** to get support contact details.

---

## ⚠️ Important Notes

- Requires **WooCommerce** to be installed and active.
- On activation, a custom database table `wp_abandoned_carts` is created with proper indexing for performance.
- The plugin automatically handles database updates and migrations.
- All data is stored securely with proper sanitization and validation.

---

## 🔧 Troubleshooting

### Common Issues

**1. Plugin not capturing data:**

- Ensure WooCommerce is active and updated
- Check if JavaScript is enabled in the browser
- Verify the checkout page is using the standard WooCommerce checkout form

**2. Database errors:**

- The plugin automatically creates required database tables
- If you see database errors, try deactivating and reactivating the plugin
- Check WordPress debug logs for specific error messages

**3. "View" button not working:**

- This has been fixed in the latest version
- The details page now includes proper security checks and error handling

**4. Performance issues:**

- The plugin includes database indexing for optimal performance
- AJAX requests are debounced to prevent excessive server calls
- Old abandoned carts can be cleaned up manually if needed

### Manual Database Fixes

If you're upgrading from an older version and encounter issues:

```sql
-- Add missing is_viewed column (if needed)
ALTER TABLE wp_abandoned_carts ADD COLUMN is_viewed tinyint(1) DEFAULT 0 NOT NULL;

-- Update existing records
UPDATE wp_abandoned_carts SET is_viewed = 0 WHERE is_viewed IS NULL;

-- Add performance indexes (if missing)
ALTER TABLE wp_abandoned_carts ADD INDEX idx_session_status (session_id, status);
ALTER TABLE wp_abandoned_carts ADD INDEX idx_user_status (user_id, status);
ALTER TABLE wp_abandoned_carts ADD INDEX idx_checkout_time (checkout_time);
ALTER TABLE wp_abandoned_carts ADD INDEX idx_status_viewed (status, is_viewed);
```

---

## 🔒 Security Features

- **Nonce Verification**: All AJAX requests and admin actions are protected with WordPress nonces
- **Input Sanitization**: All user inputs are properly sanitized before database storage
- **SQL Injection Prevention**: All database queries use prepared statements
- **Access Control**: Admin pages require proper WordPress capabilities
- **Error Handling**: Comprehensive error logging and user-friendly error messages

---

## 🚀 Performance Optimizations

- **Database Indexing**: Optimized database queries with proper indexes
- **AJAX Debouncing**: Prevents excessive server requests during form filling
- **Efficient Data Storage**: Serialized data with proper validation
- **Memory Management**: Proper cleanup and resource management
- **Caching Friendly**: Compatible with WordPress caching plugins

---

## 📝 Changelog

### Version 1.0.1 (Latest)

- **Fixed**: Missing `render_abandoned_cart_details_page()` function
- **Enhanced**: Security with proper nonce verification
- **Improved**: Database performance with indexing
- **Added**: Comprehensive error handling and logging
- **Optimized**: JavaScript with better debouncing and error handling
- **Fixed**: SQL injection vulnerabilities
- **Added**: Input validation and sanitization
- **Improved**: User experience with better error messages

### Version 1.0.0

- Initial release
- Basic abandoned cart tracking functionality
- Admin dashboard integration
