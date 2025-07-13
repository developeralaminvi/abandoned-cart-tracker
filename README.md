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
- On activation, a custom database table `wp_abandoned_carts` is created.
- If `is_viewed` column is missing or not populated in old data, run:

```sql
UPDATE wp_abandoned_carts SET is_viewed = 0 WHERE is_viewed IS NULL;
