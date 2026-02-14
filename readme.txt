=== Rudrastyh Product Sync for WooCommerce ===
Contributors: rudrastyh
Tags: woocommerce, woocommerce products, product sync, product management
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.2
Requires PHP: 7.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allows you to sync products between standalone WooCommerce stores.

== Description ==

This plugin allows you to connect multiple WooCommerce stores via the REST API and to sync products between them.

= Features =

✅ Instantly sync new products to selected stores, and then sync product updates.
✅ Choose what specific product data to sync, for example, you can exclude **Price** or **Status.**
✅ Two-directional product sync is supported.
✅ The plugin also allows you to automatically delete synced products when the original product is deleted.
✅ Debug logging – all the syncing information is available in **WooCommerce > Status > Log.**

= Pro features =

🔥 **An unlimited number** of WooCommerce stores can be connected (only one in the free version of the plugin).
🔥 The **Auto Mode** allows you to sync products to all connected stores automatically (without selecting them).
🔥 Product metadata (custom fields) synchronization (you can also exclude specific meta keys from syncing in the plugin settings).
🔥 Change product prices (or other product data) dynamically when syncing to a specific store.
🔥 Syncing products without SKU.
🔥 Bulk syncing multiple products from the **Product > All Products** page.
🔥 WP-CLI commands

🚀 [Upgrade to Pro](https://rudrastyh.com/plugins/simple-wordpress-crossposting)

== Installation ==

= Automatic Install =

1. Log into your WordPress dashboard and go to Plugins > Add New
2. Search for "Rudrastyh Product Sync for WooCommerce"
3. Click "Install Now" under the Rudrastyh Product Sync for WooCommerce plugin
4. Click "Activate Now"

= Manual Install =

1. Download the plugin from the download button on this page
2. Unzip the file, and upload the resulting `rudrastyh-product-sync-for-woocommerce` folder to your `/wp-content/plugins` directory
3. Log into your WordPress dashboard and go to Plugins
4. Click "Activate" under the Rudrastyh Product Sync for WooCommerce plugin

== Frequently Asked Questions ==

= Does it work on localhost? =
Yes, but if you are going to sync from localhost to a remote server, products with images will not be synced. It is not supported by default by the WooCommerce REST API. But you can consider using the [PRO version](https://rudrastyh.com/plugins/simple-wordpress-crossposting) of the plugin where it is fully supported. 

= Does it support two-way product sync? =
Yes. But in this case you need to install the plugin on both sites and add each one in the plugin settings.


== Screenshots ==
1. Select stores to which you want to sync this product.
2. 
3. Stores can be connected on this page, you need to provide Consumer Key and Secret.
4. Exclude any product data from syncing

== Changelog ==

= 1.2 =
* Improved plugin logging (logs are available in WooCommerce > Status > Logs)
* Added: Translation support for log messages
* Minor UI improvements

= 1.1 =
* Added: Synced products are now connected by SKU
* Fixed: An issue when syncing product data changes happened only on the second product update

= 1.0 =
* Initial release
