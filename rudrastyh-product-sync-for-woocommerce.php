<?php
/*
 Plugin name: Rudrastyh Product Sync for WooCommerce
 Description: Allows you to sync products between standalone WooCommerce stores.
 Author: Misha Rudrastyh
 Author URI: https://rudrastyh.com
 Version: 1.1
 Requires Plugins: woocommerce
 Text domain: rudrastyh-product-sync-for-woocommerce
 License: GPL v2 or later
 License URI: http://www.gnu.org/licenses/gpl-2.0.html

 Copyright 2023-2026 Misha Rudrastyh ( https://rudrastyh.com )

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
 the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/includes/WooCommerce/Client.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/BasicAuth.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/HttpClient.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/HttpClientException.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/OAuth.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/Options.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/Request.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/Response.php';

use Automattic\WooCommerce\Client;

class PSFW_Product_Sync {

	const TAB = 'psfw-product-sync';
	const META_KEY = '_psfw_to_';

	public function __construct() {

		// Settings pages
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'settings_tab' ), 25 );
		add_action( 'woocommerce_sections_' . self::TAB, array( $this, 'settings_sections' ), 25 );
		add_filter( 'woocommerce_settings_' . self::TAB, array( $this, 'settings_fields' ), 25 );
		add_action( 'woocommerce_settings_save_' . self::TAB, array( $this, 'settings_save' ), 25 );
		// Stores tab
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
		add_action( 'wp_ajax_psfw_addstore', array( $this, 'add_store' ) );
		add_action( 'wp_ajax_psfw_removestore', array( $this, 'remove_store' ) );
		// action links
		add_filter( 'plugin_action_links', array( $this, 'settings_link' ), 10, 2 );

		add_action( 'admin_menu', array( $this, 'metabox' ) );
		add_action( 'save_post', array( $this, 'save' ), 9999, 2 );
	}

	private function is_localhost_url( $url ) {

		$host = wp_parse_url( $url, PHP_URL_HOST );

		$localhost_hosts = array(
			'localhost',
			'127.0.0.1',
			'::1',
		);

		return in_array( strtolower( $host ), $localhost_hosts );

	}

	/************************************/
	/*    Store management functions    */
	/************************************/
	private function get_stores() {
		$stores = get_option( '_psfw_stores', array() );
		return $stores;
	}

	private function get_store_id( $store ) {
		$id = str_replace( array( 'https://', 'http://' ), '', $store[ 'url' ] );
		$id = sanitize_key( $id );
		return $id;
	}

	private function get_store_name( $store ) {
		return isset( $store[ 'name' ] ) && $store[ 'name' ] ? $store[ 'name' ] : str_replace( array( 'https://', 'http://' ), '', $store[ 'url' ] );
	}

	private function get_store( $store_id ) {
		$stores = $this->get_stores();
		foreach( $stores as $store ) {
			if( $this->get_store_id( $store ) === $store_id ) {
				return $store;
			}
		}
		return false;
	}


	/************************/
	/*    Settings pages    */
	/************************/
	// scripts and styles
	public function scripts() {

		$screen = get_current_screen();
		if( 'woocommerce_page_wc-settings' !== $screen->id ) {
			return;
		}

		wp_enqueue_style( 'psfw', plugin_dir_url( __FILE__ ) . 'assets/styles.css', '', filemtime( __DIR__ . '/assets/styles.css' ) );

		wp_register_script( 'psfw', plugin_dir_url( __FILE__ ) . 'assets/scripts.js', array( 'jquery' ), filemtime( __DIR__ . '/assets/scripts.js' ) );
		wp_localize_script(
			'psfw',
			'psfw_settings',
			array(
				'deleteStoreConfirmText' => __( 'Are you sure you want to remove this store from the list?', 'rudrastyh-product-sync-for-woocommerce' ),
				'nonce' => wp_create_nonce( 'stores-actions-' . get_current_user_id() ),
			)
		);
		wp_enqueue_script( 'psfw' );

	}

	// add a store with ajax
	public function add_store() {

		check_ajax_referer( 'stores-actions-' . get_current_user_id() );

		// url in both cases
		$url = ! empty( $_POST[ 'url' ] ) ? untrailingslashit( sanitize_url( wp_unslash( $_POST[ 'url' ] ) ) ) : '';
		// replace with HTTPS in case it doesn't look like localhost
		if( 'http' == wp_parse_url( $url, PHP_URL_SCHEME ) && false === strpos( $url, 'local' ) ){
			$url = str_replace( 'http://', 'https://', $url );
		}

		$consumer_key = ! empty( $_POST[ 'consumer_key' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'consumer_key' ] ) ) : '';
		$consumer_secret = ! empty( $_POST[ 'consumer_secret' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'consumer_secret' ] ) ) : '';


		// validate required fields
		if( ! $url || ! $consumer_key || ! $consumer_secret ) {
			wp_send_json_error( new WP_Error( 'empty_fields', __( 'Please fill all the required fields', 'rudrastyh-product-sync-for-woocommerce' ) ) );
		}

		$stores = $this->get_stores();
		// before adding a new one let's check if it is already in the list
		if( count( $stores ) > 0 ) {
			foreach( $stores as $store ) {
				if( $store[ 'url' ] == $url ) {
					wp_send_json_error( new WP_Error( 'not_added', __( 'It seems like the store with this URL has already been added.', 'rudrastyh-product-sync-for-woocommerce' ) ) );
				}
			}
		}

		$not_added_err = new WP_Error(
			'not_added',
			sprintf(
				__( 'Store is not added. Please check that it has REST API turned on and also double check Store URL, Consumer Key and Consumer Secret fields.', 'rudrastyh-product-sync-for-woocommerce' ),
				'https://rudrastyh.com/support/site-is-not-added'
			)
		);

		$woocommerce = new Client( $url, $consumer_key, $consumer_secret, array( 'version' => 'wc/v3', 'timeout' => 30 ) );
		// let's check WooCommerce connection first
		try {
			$woocommerce->get( 'system_status' );
		} catch( Exception $error ) {
			wp_send_json_error( $not_added_err );
		}
		// ok, everything is great with the REST API, now let's try to get site name
		$request = wp_remote_get( "{$url}/wp-json", array( 'timeout' => 30 ) );

		if( is_wp_error( $request ) ) {
			wp_send_json_error(
				new WP_Error(
					$request->get_error_code(),
					sprintf(
						/* translators: error message text */
						__( 'Site is not added. %s. Please contact your server support to solve this issue.', 'rudrastyh-product-sync-for-woocommerce' ),
						$request->get_error_message()
					)
				)
			);
		}

		if( 'OK' !== wp_remote_retrieve_response_message( $request ) ) {
			wp_send_json_error( $not_added_err );
		}

		$body = json_decode( wp_remote_retrieve_body( $request ) );

		if( ! $body ) {
			wp_send_json_error( $not_added_err );
		}

		$store = array(
			'name' => $body->name,
			'url' => $url,
			'consumer_key' => $consumer_key,
			'consumer_secret' => $consumer_secret,
		);
		$stores[] = $store;

		update_option( '_psfw_stores', $stores );

		wp_send_json_success(
			array(
				'message' => __( 'The store has been added.', 'rudrastyh-product-sync-for-woocommerce' ),
				'tr' => '<tr class="psfw-store"><td>' . esc_html( ( empty( $store[ 'name' ] ) ? '&ndash;' : $store[ 'name' ] ) ) . '</td><td>' . str_replace( array( 'https://', 'http://' ), '', esc_url( $store[ 'url' ] ) ) . '</td><td><button class="button psfw-remove-store">' . esc_html__( 'Remove this store', 'rudrastyh-product-sync-for-woocommerce' ) . '</button></td></tr>',
			)
		);

		die;
	}

	// remove store
	public function remove_store() {

		check_ajax_referer( 'stores-actions-' . get_current_user_id() );

		$stores = $this->get_stores();

		$stores = array_filter( $stores, function( $store ) {
			$url = isset( $_POST[ 'url' ] ) ? str_replace( array( 'https://', 'http://' ), '', sanitize_url( wp_unslash( $_POST[ 'url' ] ) ) ) : '';
			return $url !== str_replace( array( 'https://', 'http://' ), '', $store[ 'url' ] );
		} );

		update_option( '_psfw_stores', $stores );

		wp_send_json_success(
			array(
				'message' => __( 'No stores have been added yet.', 'rudrastyh-product-sync-for-woocommerce' ),
			)
		);

	}

	// settings tab
	public function settings_tab( $tabs ) {

		$tabs[ self::TAB ] = __( 'Product Sync', 'rudrastyh-product-sync-for-woocommerce' );
		return $tabs;

	}

	// settings sections
	public function settings_sections() {

		global $current_section;

		$sections = array(
			''        => __( 'General', 'rudrastyh-product-sync-for-woocommerce' ),
			'stores'  => __( 'Stores', 'rudrastyh-product-sync-for-woocommerce' ),
			'fields'  => __( 'Fields', 'rudrastyh-product-sync-for-woocommerce' ),
		);

		echo '<ul class="subsubsub">';

		foreach( $sections as $id => $label ) {

			printf(
				'<li><a href="%s" %s>%s</a> %s </li>',
				esc_html(
					add_query_arg(
						array(
							'page' => 'wc-settings',
							'tab' => self::TAB,
							'section' => $id,
						),
						admin_url( 'admin.php' )
					)
				),
				$current_section === $id ? 'class="current"' : '',
				esc_html( $label ),
				'fields' === $id ? '' : '|'
			);

		}

		echo '</ul><br class="clear" />';

	}

	// fields
	public function settings_fields() {

		global $current_section, $hide_save_button;

		$excluded_fields = get_option( '_psfw_excluded_fields', array() );

		if( 'stores' === $current_section ) {
			// hide the standard save button
			$hide_save_button = true;
			?>
				<h2><?php esc_html_e( 'Add Store', 'rudrastyh-product-sync-for-woocommerce' ) ?></h2>

				<!-- add store form -->
				<div class="psfw-add-store form-wrap">
					<div>
						<div class="form-field">
							<label for="store_url"><?php esc_html_e( 'Store URL', 'rudrastyh-product-sync-for-woocommerce' ) ?></label>
							<input type="text" size="35" id="store_url" name="store_url" class="input" aria-required="true" placeholder="https://" />
						</div>
						<div class="form-field">
							<label for="consumer_key"><?php esc_html_e( 'Consumer Key', 'rudrastyh-product-sync-for-woocommerce' ) ?></label>
							<input type="text" size="35" id="consumer_key" name="consumer_key" class="input" aria-required="true" placeholder="ck_" />
							<p class="description"><?php esc_html_e( 'Consumer Key and Consumer Secret from the target store.', 'rudrastyh-product-sync-for-woocommerce' ) ?></p>
						</div>
						<div class="form-field">
							<label for="consumer_secret"><?php esc_html_e( 'Consumer Secret', 'rudrastyh-product-sync-for-woocommerce' ) ?></label>
							<input type="text" size="35" id="consumer_secret" name="consumer_secret" class="input" aria-required="true" placeholder="cs_" />
						</div>
						<button type="button" id="psfw_add_new_store" disabled class="components-button is-primary"><?php esc_html_e( 'Add store', 'rudrastyh-product-sync-for-woocommerce' ) ?></button>
					</div>
				</div>
				<!-- notices -->
				<div id="psfw-stores-notices"></div>
				<!-- table with stores -->
				<h2><?php esc_html_e( 'Stores', 'rudrastyh-product-sync-for-woocommerce' ) ?></h2>
				<div class="psfw-stores-table">
					<table class="wp-list-table widefat fixed striped table-view-list">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Name', 'rudrastyh-product-sync-for-woocommerce' ) ?></th>
								<th scope="col"><?php esc_html_e( 'URL', 'rudrastyh-product-sync-for-woocommerce' ) ?></th>
								<th scope="col">&nbsp;</th>
							</tr>
						</thead>

						<tbody id="the-list">
							<?php
								$stores = $this->get_stores();
								if( $stores ) {
									foreach( $stores as $store ) {
										?>
											<tr class="psfw-store">
												<td><?php echo isset( $store[ 'name' ] ) && $store[ 'name' ] ? esc_html( $store[ 'name' ] ) : '&ndash;' ?></td>
												<td><?php echo esc_url( str_replace( array( 'https://', 'http://' ), '', $store[ 'url' ] ) ) ?></td>
												<td><button class="button psfw-remove-store"><?php esc_html_e( 'Remove this store', 'rudrastyh-product-sync-for-woocommerce' ) ?></button></td>
											</tr>
										<?php
									}
								} else {
									?><tr><td colspan="3"><?php esc_html_e( 'Add stores where the orders are going to be synced to.', 'rudrastyh-product-sync-for-woocommerce' ) ?></td></tr><?php
								}
							?>
						</tbody>
						<tfoot>
							<tr>
								<th scope="col"><?php esc_html_e( 'Name', 'rudrastyh-product-sync-for-woocommerce' ) ?></th>
								<th scope="col"><?php esc_html_e( 'URL', 'rudrastyh-product-sync-for-woocommerce' ) ?></th>
								<th scope="col">&nbsp;</th>
							</tr>
						</tfoot>
					</table>
				</div>
			<?php
		} elseif( 'fields' === $current_section ) {

			?><h2><?php esc_html_e( 'Fields', 'rudrastyh-product-sync-for-woocommerce' ) ?></h2><?php

          $product_fields = array(
            // General
            array(
              'title' => '',
              'fields' => array(
                'name' => array( 'label' => esc_html__( 'Product title', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'slug' => array( 'label' => __( 'Slug', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'status' => array( 'label' => __( 'Status', 'rudrastyh-product-sync-for-woocommerce' ), 'description' => esc_html__( 'If product statuses aren’t syncing, all new products are going to be created as drafts.', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'featured' => array( 'label' => esc_html__( 'Featured', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'catalog_visibility' => array( 'label' => rtrim( esc_html__( 'Catalog visibility:', 'rudrastyh-product-sync-for-woocommerce' ), ':' ) ),
                'description' => array( 'label' => esc_html__( 'Product description', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'short_description' => array( 'label' => esc_html__( 'Product short description', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'image_id' => array( 'label' => esc_html__( 'Product image', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'gallery_image_ids' => array( 'label' => esc_html__( 'Product gallery', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'category_ids' => array( 'label' => esc_html__( 'Product categories', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'tag_ids' => array( 'label' => esc_html__( 'Product tags', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'brand_ids' => array( 'label' => esc_html__( 'Product brands', 'rudrastyh-product-sync-for-woocommerce' ) ),
              )
            ),
            // Prices
            array(
              'title' => esc_html__( 'Price', 'rudrastyh-product-sync-for-woocommerce' ),
              'fields' => array(
                'regular_price' => array( 'label' => esc_html__( 'Regular price', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'sale_price' => array( 'label' => esc_html__( 'Sale price', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'sale_price_dates' => array( 'label' => esc_html__( 'Sale price dates', 'rudrastyh-product-sync-for-woocommerce' ) ),
              )
            ),
            // Inventory
            array(
              'title' => esc_html__( 'Inventory', 'rudrastyh-product-sync-for-woocommerce' ),
              'fields' => array(
                'sku' => array( 'label' => esc_html__( 'SKU', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'global_unique_id' => array( 'label' => esc_html__( 'GTIN, UPC, EAN, or ISBN', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'stock' => array( 'label' => esc_html__( 'Stock', 'rudrastyh-product-sync-for-woocommerce' ), 'description' => esc_html__( 'This option manages “Stock status”, “Stock management”, “Stock quantity”, “Allow backorders” and “Low stock threshold”.', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'sold_individually' => array( 'label' => esc_html__( 'Sold individually', 'rudrastyh-product-sync-for-woocommerce' ) ),
              )
            ),
            // Shipping
            array(
              'title' => esc_html__( 'Shipping', 'rudrastyh-product-sync-for-woocommerce' ),
              'fields' => array(
                'weight' => array( 'label' => esc_html__( 'Weight', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'dimensions' => array( 'label' => esc_html__( 'Dimensions', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'shipping_class' => array( 'label' => esc_html__( 'Shipping class', 'rudrastyh-product-sync-for-woocommerce' ) ),
              )
            ),
            // Linked products
            array(
              'title' => esc_html__( 'Linked products', 'rudrastyh-product-sync-for-woocommerce' ),
              'fields' => array(
                'upsell_ids' => array( 'label' => esc_html__( 'Upsells', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'cross_sell_ids' => array( 'label' => esc_html__( 'Cross-sells', 'rudrastyh-product-sync-for-woocommerce' ) ),
              )
            ),
            // Attributes
            array(
              'title' => esc_html__( 'Attributes', 'rudrastyh-product-sync-for-woocommerce' ),
              'fields' => array(
                'attributes' => array( 'label' => esc_html__( 'Product attributes', 'rudrastyh-product-sync-for-woocommerce' ) ),
              )
            ),
            // Variations
            array(
              'title' => esc_html__( 'Variations', 'rudrastyh-product-sync-for-woocommerce' ),
              'fields' => array(
                'variations' => array( 'label' => esc_html__( 'Variations', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'default_attributes' => array( 'label' => ucfirst( strtolower( esc_html__( 'Default Form Values', 'rudrastyh-product-sync-for-woocommerce' ) ) ) ),
              )
            ),
            // Advanced
            array(
              'title' => esc_html__( 'Advanced', 'rudrastyh-product-sync-for-woocommerce' ),
              'fields' => array(
                'purchase_note' => array( 'label' => esc_html__( 'Purchase note', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'menu_order' => array( 'label' => esc_html__( 'Menu order', 'rudrastyh-product-sync-for-woocommerce' ) ),
                'enable_reviews' => array( 'label' => esc_html__( 'Enable reviews', 'rudrastyh-product-sync-for-woocommerce' ) ),
              )
            ),
          );

          foreach( $product_fields as $product_fields_section ) {

            // echo title if needed
            if( $product_fields_section[ 'title' ] ) {
              ?><h3><?php echo esc_html( $product_fields_section[ 'title' ] ) ?></h3><?php
            }

            ?><table class="form-table psfw-fields-table"><?php
              foreach( $product_fields_section[ 'fields' ] as $id => $field ) :
                ?>
                  <tr valign="top">
                    <th scope="row"><label for="ps_fields_<?php echo esc_attr( $id ) ?>"><?php echo esc_html( $field[ 'label' ] ) ?></label></th>
                    <td>
	                      <select id="ps_fields_<?php echo esc_attr( $id ) ?>" name="excluded_fields[<?php echo esc_attr( $id ) ?>]" class="wc-enhanced-select">
	                        <option value=""><?php esc_html_e( 'Yes', 'rudrastyh-product-sync-for-woocommerce' ) ?></option>
	                        <option value="no"<?php if( in_array( $id, $excluded_fields ) ) { echo ' selected="selected"'; } ?>><?php esc_html_e( 'No', 'rudrastyh-product-sync-for-woocommerce' ) ?></option>
	                      </select>
							<?php echo ! empty( $field[ 'description' ] ) ? '<p class="description">' . esc_html( $field[ 'description' ] ) . '</p>' : '' ?>
                    </td>
                  </tr>
                <?php
              endforeach;
            ?></table><?php

          }
 
		} else {

      $default_product_statuses = array( 'publish', 'future', 'draft', 'private' );
      $post_stati = get_post_stati( array(), 'objects' );
      $product_statuses = array();

      foreach( $default_product_statuses as $name ) {
        if( array_key_exists( $name, $post_stati ) ) {
          $product_statuses[ $name ] = $post_stati[ $name ]->label;
        }
      }
      //print_r( $order_statuses );
      $allowed_product_statuses = get_option( '_psfw_allowed_statuses', array( 'publish' ) );
			?>
				<h2><?php esc_html_e( 'Product Sync Settings', 'rudrastyh-product-sync-for-woocommerce' ) ?></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Allowed product statuses', 'rudrastyh-product-sync-for-woocommerce' ) ?></th>
							<td class="forminp forminp-checkbox">
								<fieldset>
									<?php foreach( $product_statuses as $name => $label ) : ?>
										<label>
											<input name="allowed_product_statuses[]" type="checkbox" <?php echo in_array( $name, $allowed_product_statuses ) ? 'checked="checked"' : '' ?> value="<?php echo esc_attr( $name ) ?>">&nbsp;<?php echo esc_html( $label ) ?>
										</label><br />
									<?php endforeach ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Select product statuses that should be synced with the connected stores.', 'rudrastyh-product-sync-for-woocommerce' ) ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Product deletion', 'rudrastyh-product-sync-for-woocommerce' ) ?></th>
							<td>
								<label>
									<input type="checkbox" name="handle_deletion"<?php checked( 'yes', get_option( '_psfw_handle_deletion' ) ) ?>>&nbsp;<?php esc_html_e( 'Yes', 'rudrastyh-product-sync-for-woocommerce' ) ?>
								</label>
								<p class="description"><?php esc_html_e( 'By default, the plugin syncs only product updates, but with this option enabled, when you delete a product, its synced copy will be deleted as well.', 'rudrastyh-product-sync-for-woocommerce' ) ?></p>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Auto mode', 'rudrastyh-product-sync-for-woocommerce' ) ?></th>
							<td>
								<label><input type="checkbox" name="is_auto_mode" disabled="disabled">&nbsp;<?php esc_html_e( 'Yes', 'rudrastyh-product-sync-for-woocommerce' ) ?></label>
								<p class="description">(<a href="https://rudrastyh.com/plugins/simple-wordpress-crossposting">Pro</a>) <?php esc_html_e( 'With this option enabled the plugin will sync products automatically to all connected stores.', 'rudrastyh-product-sync-for-woocommerce' ) ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="connection_type"><?php esc_html_e( 'Product connection type', 'rudrastyh-product-sync-for-woocommerce' ) ?></label></th>
							<td>
								<select id="connection_type" class="wc-enhanced-select">
            						<option value="" disabled="disabled"><?php esc_html_e( 'Meta field', 'rudrastyh-product-sync-for-woocommerce' ) ?></option>
									<option value="sku" selected="selected"><?php esc_html_e( 'SKU', 'rudrastyh-product-sync-for-woocommerce' ) ?></option>
								</select>
								<p class="description">(<a href="https://rudrastyh.com/plugins/simple-wordpress-crossposting">Pro</a>) <?php esc_html_e( 'Meta fields are faster and work in most cases but if you need a more accurate connection use SKU.', 'rudrastyh-product-sync-for-woocommerce' ) ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			<?php

		}

	}

	// saving settings
	public function settings_save() {

		$section = ! empty( $_GET[ 'section' ] ) ? sanitize_text_field( wp_unslash( $_GET[ 'section' ] ) ) : 'general';

		if( 'general' === $section ) {
			// allowed statuses
			$allowed_product_statuses = isset( $_REQUEST[ 'allowed_product_statuses' ] ) && is_array( $_REQUEST[ 'allowed_product_statuses' ] ) ? array_map( 'sanitize_text_field', $_REQUEST[ 'allowed_product_statuses' ] ) : array();
			update_option( '_psfw_allowed_statuses', $allowed_product_statuses );

			// deletion
			$handle_deletion = ! empty( $_POST[ 'handle_deletion' ] ) && 'on' == $_POST[ 'handle_deletion' ] ? 'yes' : 'no';
			update_option( '_psfw_handle_deletion', $handle_deletion );

		}

		if( 'fields' === $section ) {
			// woo fields
			$excluded_fields = array();
			if( ! empty( $_POST[ 'excluded_fields' ] ) && is_array( $_POST[ 'excluded_fields' ] ) ) {
				foreach( $_POST[ 'excluded_fields' ] as $key => $value ) {
				if( 'no' === $value ) {
					$excluded_fields[] = sanitize_text_field( $key );
				}
				}
			}
			update_option( '_psfw_excluded_fields', $excluded_fields );
		}

	}

	// quick settings link
	public function settings_link( $links, $plugin_file_name ){

		if( strpos( $plugin_file_name, basename(__FILE__) ) ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%s">%s</a>',
					add_query_arg(
						array(
							'page' => 'wc-settings',
							'tab' => self::TAB,
						),
						'admin.php'
					),
					esc_html__( 'Settings', 'rudrastyh-product-sync-for-woocommerce' )
				)
			);
		}

		return $links;

	}

	public function metabox() {

		// no crossposting if no sites has been added
		if( ! $this->get_stores() ) {
			return;
		}

		add_meta_box(
			'psfw_metabox',
			__( 'Sync to', 'rudrastyh-product-sync-for-woocommerce' ),
			array( $this, 'metabox_callback' ),
			'product',
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);

	}

	public function metabox_callback( $post ) {

		$stores = $this->get_stores();

		if( ! $stores || ! is_array( $stores ) ) {
			return;
		}

		wp_nonce_field( 'psfw-metabox-check', 'psfw_custom_nonce' );

		echo '<ul>';
//	print_r($stores);
		foreach( $stores as $store ) {
			?><li><label><input type="checkbox" name="<?php echo esc_attr( self::META_KEY . $this->get_store_id( $store ) ) ?>"<?php checked( true, get_post_meta( $post->ID, self::META_KEY . $this->get_store_id( $store ), true ) ) ?> /> <?php echo esc_html( $this->get_store_name( $store ) ) ?></label></li><?php
		}
		echo '</ul>';

	}

	public function save( $post_id, $post ) {

		// regular checks
		if( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if( 'product' !== $post->post_type ) {
			return;
		}

		// Only set for specific statuses
		$allowed_product_statuses = ( $allowed_product_statuses = get_option( '_psfw_allowed_statuses' ) ) ? $allowed_product_statuses : array( 'publish' );
		if ( ! in_array( $post->post_status, $allowed_product_statuses ) ) {
			return;
		}

		$allowed_stores = $this->get_stores();

		if( isset( $_POST[ 'action' ] ) && 'editpost' == $_POST[ 'action' ] ) {

			$stores = array();
			$nonce = isset( $_POST[ 'psfw_custom_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'psfw_custom_nonce' ] ) ) : '';

			if( wp_verify_nonce( $nonce, 'psfw-metabox-check' ) ) {
				foreach( $allowed_stores as $store ) {
					$id = $this->get_store_id( $store ); // we get it from url you know
					if( isset( $_POST[ self::META_KEY . $id ] ) && $_POST[ self::META_KEY . $id ] ) {
						$stores[] = $store;
						update_post_meta( $post_id, self::META_KEY . $id, true );
					} else {
						update_post_meta( $post_id, self::META_KEY . $id, false );
					}
				}
			} else {
				foreach( $allowed_stores as $store ) {
					if( true == get_post_meta( $post_id, self::META_KEY . $this->get_store_id( $store ), true ) ) {
						$stores[] = $store;
					}
				}
			}


			$this->sync_product( $post_id, $stores );

		}

	}

	public function is_synced_product( $product, $store, $woocommerce ) {

		$product_sku = $product->get_sku();

		try {
			$products = $woocommerce->get(
				'products', 
				array(
					'sku' => $product_sku,
				) 
			);

			$product = $products[0];

			return $product->id;

		} catch (Exception $e) {
			return 0;
		}

	}

	public function get_synced_product_ids( $product_ids, $store, $woocommerce ) {

		$synced_ids = array();

		if( ! is_array( $product_ids ) ) {
			return $synced_ids;
		}

		foreach( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if( ! $product ) {
				continue;
			}
			$synced_id = $this->is_synced_product( $product, $store, $woocommerce );
			if( ! $synced_id ) {
				continue;
			}
			if( is_wp_error( $synced_id ) ) {
				continue;
			}
			$synced_ids[] = $synced_id;
		}
		return $synced_ids;

	}

	public function is_synced_image( $image_id, $store ) {

		$store_id = $this->get_store_id( $store );

		$synced = get_post_meta( $image_id, self::META_KEY . 'data', true );

		if( $synced && is_array( $synced ) && array_key_exists( $store_id, $synced ) ) {
			return $synced[ $store_id ];
		}

		return 0;
	}

	public function add_synced_image_data( $image_id, $new_image_id, $store_id ) {
		$synced = get_post_meta( $image_id, self::META_KEY . 'data', true );
		$synced = $synced && is_array( $synced ) ? $synced : array();
		$synced[ $store_id ] = (int) $new_image_id;
		update_post_meta( $image_id, self::META_KEY . 'data', $synced );
	}

	public function get_crossposted_variations( $product_id, $product, $available_variations, $store, $woocommerce ) {

		$variations = array(
			'delete' => array(),
		);
		$wc_logger = wc_get_logger();

		// 1. let's loop available variations first and create an array like sku=>id
		$variations1 = array();
		if( $available_variations ){
			foreach( $available_variations as $available_variation_id ) {

				$available_variation = wc_get_product( $available_variation_id );

				if( ! $available_variation ) {
					continue;
				}

				if( ! $sku = get_post_meta( $available_variation->get_id(), '_sku', true ) ) {
					continue;
				}

				$variations1[ $sku ] = $available_variation->get_id();

			}
		}

		// 2. now lets connect to remote store and get variations from there
		try {
			$variations2 = $woocommerce->get(
				"products/{$product_id}/variations",
	      array(
					'per_page' => 100,
				)
	    );

			if( $variations2 ) {
				foreach( $variations2 as $variation2 ) {

					$sku = isset( $variation2[ 'sku' ] ) && $variation2[ 'sku' ] ? $variation2[ 'sku' ] : '';
					if( $sku && isset( $variations1[ $sku ] ) ) {
						// $variation[ current variation ID ] == remote variation ID
						$variations[ $variations1[ $sku ] ] = $variation2[ 'id' ];
						continue;
					}

					$variations[ 'delete' ][] = array( 'id' => $variation2->id );
				}
			}

		} catch( Exception $error ) {
			$wc_logger->error( $error->getMessage(), array( 'source' => 'product-sync' ) );
		}

		return $variations;

	}

	public function sync_product( $product_id, $stores ) {

		$product = wc_get_product( $product_id );

		if( ! $product ) {
			return false;
		}

		if( ! $product->get_sku() ) {
			return false;
		}

		if( empty( $stores ) ) {
			return false;
		}

		$wc_logger = wc_get_logger();
		$excluded = get_option( '_psfw_excluded_fields', array() );

		$product_data = array(
			'name'              => $product->get_title(),
			'slug'              => $product->get_slug(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'status'            => $product->get_status(),
			'type'              => $product->get_type(),
			'sold_individually' => $product->get_sold_individually(),
			'purchase_note'			=> $product->get_purchase_note(),
			'menu_order'        => (int) $product->get_menu_order(),
			'reviews_allowed'   => $product->get_reviews_allowed(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'featured'           => $product->get_featured(),
			//'source_product_id'  => (int) $product->get_id(),
		);

		$product_data = $this->add_prices( $product_data, $product );
		$product_data = $this->add_stock_and_shipping_info( $product_data, $product );
		$product_data = $this->add_downloads( $product_data, $product );

		foreach( $stores as $store ) {

			$store_id = $this->get_store_id( $store );

			$store_url = $store[ 'url' ];
			$consumer_key = $store[ 'consumer_key' ];
			$consumer_secret = $store[ 'consumer_secret' ];

			if( empty( $store_url ) || empty( $consumer_key ) || empty( $consumer_secret ) ) {
				return false;
			}

			$woocommerce = new Client( $store_url, $consumer_key, $consumer_secret, array( 'version' => 'wc/v3', 'timeout' => 30 ) );

			$product_data = $this->add_images( $product_data, $product, $store, $excluded );
			$product_data = $this->add_attributes( $product_data, $product, $store, $woocommerce, $excluded );
			$product_data = $this->add_linked_products( $product_data, $product, $store, $woocommerce, $excluded );

			$synced_product_id = $this->is_synced_product( $product, $store, $woocommerce );
			if( $synced_product_id && ! is_wp_error( $synced_product_id ) ) {
				try {
					$updated_product = $woocommerce->put( "products/{$synced_product_id}", $product_data );

					$wc_logger->debug( sprintf( 'The product %s has been updated.', $updated_product->name ), array( 'source' => 'product-sync' ) );

					$product->update_meta_data( self::META_KEY . $store_id, 1 );
					$product->save_meta_data();

					if( ! in_array( 'variations', $excluded ) ) {
						$this->add_product_variations( $updated_product->id, $product, $store, $woocommerce );
					}

				} catch( Exception $error ) {
					$wc_logger->error( $error->getMessage(), array( 'source' => 'product-sync' ) );
				}
			} else {

				try {
					$new_product = $woocommerce->post( 'products', $product_data );

					$wc_logger->debug( sprintf( 'The product %s has been created.', $new_product->name ), array( 'source' => 'product-sync' ) );

					$product->update_meta_data( self::META_KEY . $store_id, 1 );
					$product->save_meta_data();

					if( ! empty( $new_product->images ) ) {
						// assume that the images are in the same order!

						$this->add_synced_image_data( $product->get_image_id(), $new_product->images[0]->id, $store_id );
						// foreach( $new_product->images as $new_image ) {
						// 	foreach( $product_data[ 'images' ] as
						// }
					}

					if( ! in_array( 'variations', $excluded ) ) {
						$this->add_product_variations( $new_product->id, $product, $store, $woocommerce );
					}

				} catch( Exception $error ) {
					$wc_logger->error( $error->getMessage(), array( 'source' => 'product-sync' ) );
				}
			}

		}

	}

	public static function add_prices( $data, $product ) {

		// it makes sense for simple product and variations
		if( in_array( $product->get_type(), array( 'simple', 'variation' ) ) ) {
			$data[ 'regular_price' ] = $product->get_regular_price();
			$data[ 'sale_price' ] = $product->get_sale_price();
			$data[ 'date_on_sale_from' ] = $product->get_date_on_sale_from();
			$data[ 'date_on_sale_to' ] = $product->get_date_on_sale_to();
		}

		return $data;

	}


	/**
	 * Allows to add stock and shipping info for product and product variations
	 */
	public static function add_stock_and_shipping_info( $data, $product ) {

		// let's do for everyone anyway
		// we can not use $product->get_sku() cause for variations it returns product sku
		$data[ 'sku' ] = get_post_meta( $product->get_id(), '_sku', true );
		$data[ 'global_unique_id' ] = get_post_meta( $product->get_id(), '_global_unique_id', true );
		$data[ 'manage_stock' ] = $product->get_manage_stock();
		$data[ 'stock_quantity' ] = (int) $product->get_stock_quantity();
		$data[ 'backorders' ] = $product->get_backorders();
		$data[ 'low_stock_amount' ] = $product->get_low_stock_amount() ? (int) $product->get_low_stock_amount() : null;
		$data[ 'stock_status' ] = $product->get_stock_status();

		if( $product->is_virtual() ) {
			$data[ 'is_virtual' ] = true;
			return $data;
		}

		// shipping info (both products and variations)
		$data[ 'weight' ] = $product->get_weight();
		$data[ 'dimensions' ] = $product->get_dimensions( false );
		$data[ 'shipping_class' ] = $product->get_shipping_class(); // we are using SLUG here, it is not blog-dependent

		return $data;

	}


	/**
	 * Adds images to a crossposting array for product or product_variation
	 */
	public function add_images( $data, $product, $store, $excluded = array() ) {

		// no matter if it is a product or product variation we have to get main image ID
		// we can not use get_image_id() cause it prints the main product variation when used on WC_Product_Variation
		$image = array();
		$wc_logger = wc_get_logger();

		// featured image
		if( ! in_array( 'image_id', $excluded ) && ( $featured_image_id = get_post_meta( $product->get_id(), '_thumbnail_id', true ) ) ) {
			if( $synced_image_id = $this->is_synced_image( $featured_image_id, $store ) ) {
				$image = array( 'id' => (int) $synced_image_id );
			} else {
				$featured_image_url = wp_get_attachment_url( $featured_image_id );
				if( $featured_image_url ) {
					if( $this->is_localhost_url( $featured_image_url ) ) {
						$wc_logger->warning(
							sprintf( 'The image #%d can not be synced, because WooCommerce REST API can not access it on localhost.', $featured_image_id ),
							array( 'source' => 'product-sync' )
						);
					} else {
						$image = array( 'src' => $featured_image_url );
					}
				}
			}
		}

		// if it is a variable product, we are ready to return the data
		if( 'variation' === $product->get_type() ) {
			$data[ 'image' ] = $image;
			return $data;
		}

		$data[ 'images' ] = array();
		$data[ 'images' ][] = $image;

		// gallery images
		if( ! in_array( 'gallery_image_ids', $excluded ) && ( $gallery_image_ids = $product->get_gallery_image_ids() ) ) {
			foreach( $gallery_image_ids as $gallery_image_id ) {
				if( $synced_gallery_image_id = $this->is_synced_image( $gallery_image_id, $store ) ) {
					$data[ 'images' ][] = array( 'id' => (int) $synced_gallery_image_id );
				} else {
					$gallery_image_url = wp_get_attachment_url( $gallery_image_id );
					if( $gallery_image_url ) {
						if( $this->is_localhost_url( $gallery_image_url ) ) {
							$wc_logger->warning(
								sprintf( 'The image #%d can not be synced, because WooCommerce REST API can not access it on localhost.', $gallery_image_id ),
								array( 'source' => 'product-sync' )
							);
						} else {
							$data[ 'images' ][] = array( 'src' => $gallery_image_url );
						}
					}
				}
			}
		}

		return $data;

	}


	/**
	 * Adds downloads to a crossposting array for product or product_variation
	 */
	public function add_downloads( $data, $product ) {

		// first check if it is downloadable anyway
		if( ! $product->is_downloadable() ) {
			return $data;
		}

		// add more
		$data[ 'downloadable' ] = true;
		$data[ 'download_limit' ] = $product->get_download_limit();
		$data[ 'download_expiry' ] = $product->get_download_expiry();

		$data[ 'downloads' ] = array();

		$downloads = $product->get_downloads();
		if( $downloads ) {
			foreach( $downloads as $download ) {
				$data[ 'downloads' ][] = array(
					'file' => $download->get_file(),
					'name' => $download->get_name()
				);
			}
		}

		return $data;

	}

	public function add_linked_products( $product_data, $product, $store, $woocommerce, $excluded = array() ) {

		if( ! in_array( 'upsell_ids', $excluded ) ) {
			$product_data[ 'upsell_ids' ] = $this->get_synced_product_ids( $product->get_upsell_ids(), $store, $woocommerce );
		}
		if( 'grouped' === $product_data[ 'type' ] ) {
			$product_data[ 'grouped_products' ] = $this->get_synced_product_ids( $product->get_children(), $store, $woocommerce );
		} else {
			if( ! in_array( 'cross_sell_ids', $excluded ) ) {
				$product_data[ 'cross_sell_ids' ] = $this->get_synced_product_ids( $product->get_cross_sell_ids(), $store, $woocommerce );
			}
		}

		return $product_data;

	}


	public function add_attributes( $product_data, $product, $store, $woocommerce, $excluded = array() ) {

		$product_data[ 'attributes' ] = array();
		if( ! in_array( 'attributes', $excluded ) && ( $product_attributes = $product->get_attributes() ) ) {

			foreach( $product_attributes as $attribute ) {
				// some basics
				$name = $attribute->get_name();
				$attr_data = $attribute->get_data();

				// taxonomy-based one
				if( 'pa_' === substr( $name, 0, 3 ) ) {
					$synced_attribute_id = $this->get_synced_attribute_id( $name, $store, $woocommerce );
					if( $synced_attribute_id ) {
						$attribute_terms = array();
						foreach( $attr_data[ 'options' ] as $attribute_term_id ) {
							if( $attribute_term = get_term_by( 'id', $attribute_term_id, $name ) ) {
								$attribute_terms[ $attribute_term_id ] = $attribute_term;
							}
						}
						$product_data[ 'attributes' ][] = array(
							'id' => $synced_attribute_id,
							'position' => $attr_data[ 'position' ],
							'variation' => $attr_data[ 'variation' ],
							'visible' => $attr_data[ 'visible' ],
							'options' => wp_list_pluck( array_values( $attribute_terms ), 'name' ),
						);
					}
				} else { // custom one
					$product_data[ 'attributes' ][] = array(
						'name' => $name,
						'position' => $attr_data[ 'position' ],
						'variation' => $attr_data[ 'variation' ],
						'visible' => $attr_data[ 'visible' ],
						'options' => $attr_data[ 'options' ],
					);
				}
			}
		}

		// default variations
		if( ! in_array( 'default_attributes', $excluded ) ) {
			$product_data[ 'default_attributes' ] = $this->get_synced_default_attributes( $product->get_default_attributes(), $store, $woocommerce );
		}

		return $product_data;

	}

	public function get_synced_attributes( $store, $woocommerce ) {

		$wc_logger = wc_get_logger();
		$object_cache_key = self::META_KEY . $this->get_store_id( $store ) . '_attributes';
		$crossposted_attributes = wp_cache_get( $object_cache_key );

		if( false === $crossposted_attributes ) {
			try {
				$crossposted_attributes = $woocommerce->get( 'products/attributes' );
				if( $crossposted_attributes ) {
					$crossposted_attributes = wp_list_pluck( $crossposted_attributes, 'id', 'slug' );
					wp_cache_set( $object_cache_key, $crossposted_attributes );
				}
			} catch( Exception $error ) {
				$wc_logger->error( $error->getMessage(), array( 'source' => 'product-sync' ) );
			}
		}

		return $crossposted_attributes;

	}

	// get the attribute ID from the cache or try to index attributes via the REST API
	public function get_synced_attribute_id( $attribute_name, $store, $woocommerce ) {

		$cache_key = self::META_KEY . $this->get_store_id( $store ) . '_attribute_' . $attribute_name;

		// store the attribute ID in transients
		$crossposted_attribute_id = get_transient( $cache_key );

		if( false === $crossposted_attribute_id ) {
			$crossposted_attributes = $this->get_synced_attributes( $store, $woocommerce );
			// do we have this specific attribute in the attribute cache
			if( ! empty( $crossposted_attributes[ $attribute_name ] ) ) {
				$crossposted_attribute_id = $crossposted_attributes[ $attribute_name ];
				set_transient( $cache_key, $crossposted_attribute_id, WEEK_IN_SECONDS );
			}
		}

		return $crossposted_attribute_id;

	}

	public function get_synced_default_attributes( $default_attributes, $store, $woocommerce ) {

		$crossposted_default_attributes = array();

		if( empty( $default_attributes ) || ! is_array( $default_attributes ) ) {
			return $crossposted_default_attributes;
		}

		foreach( $default_attributes as $attribute_name => $attribute_value ) {
			if( 'pa_' === substr( $attribute_name, 0, 3 ) ) {
				if( $id = $this->get_synced_attribute_id( $attribute_name, $store, $woocommerce ) ) {
					$crossposted_default_attributes[] = array(
						'id' => $id,
						'option' => $attribute_value,
					);
				}
			} else {
				$crossposted_default_attributes[] = array(
					'name' => $attribute_name,
					'option' => $attribute_value,
				);
			}
		}

		return $crossposted_default_attributes;

	}

	public function add_variation_attributes( $variation_data, $variation_product, $store, $woocommerce ) {
		// our goal is convert  Array( [attribute_pa_color] => black [attribute_ahaha] => Yes )
		// to Array( Array( 'id' => 1, 'option' => black ), Array( 'name' => 'ahaha', option' => 'Yes' )
		$variation_data[ 'attributes' ] = $this->get_synced_default_attributes( $variation_product->get_attributes(), $store, $woocommerce );
		return $variation_data;
	}


	public function add_product_variations( $product_id, $product, $store, $woocommerce ) {

		if( ! $product->is_type( 'variable' ) ) {
			return;
		}

		// TODO nothing happens if product has no variations
		$available_variations = $product->get_children();
		if( ! $available_variations ) {
			return;
		}

		$crossposted_variations = $this->get_crossposted_variations( $product_id, $product, $available_variations, $store, $woocommerce );

		$wc_logger = wc_get_logger();
		// let's be ready to create new ones
		$body = array(
			'create' => array(),
			'update' => array(),
			'delete' => array(),
		);
		$created_ones = array();
		$updated_ones = array();

		// get excluded fields
		$excluded = get_option( '_psfw_excluded_fields', array() );

		// loop what we get from product
		foreach( $available_variations as $variation_id ) {

			$variation_product = wc_get_product( $variation_id );

			if( ! $variation_product ) {
				continue;
			}

			$variation_data = array(
				'description'  => $variation_product->get_description(),
				'menu_order' => $variation_product->get_menu_order(),
			);

			$variation_data = $this->add_prices( $variation_data, $variation_product );
			$variation_data = $this->add_stock_and_shipping_info( $variation_data, $variation_product );
			$variation_data = $this->add_downloads( $variation_data, $variation_product );

			$variation_data = $this->add_images( $variation_data, $variation_product, $store, $excluded );
			$variation_data = $this->add_variation_attributes( $variation_data, $variation_product, $store, $woocommerce );

			// if( ! in_array( 'meta', $excluded_post_fields ) ) {
			// 	$variation_data = self::add_meta_data( $variation_data, $variation_product, $blog );
			// }

			if( isset( $crossposted_variations[ $variation_id ] ) ) {
				// here we have to provide actual variation ID
				$variation_data[ 'id' ] = $crossposted_variations[ $variation_id ];
				$body[ 'update' ][] = $variation_data;
				// just the same
				$updated_ones[ $variation_id ] = $crossposted_variations[ $variation_id ];
			} else {
				$body[ 'create' ][] = $variation_data;
				$created_ones[] = $variation_id;
			}
			// here we need to modify image IDs if necessary
			unset( $crossposted_variations[ $variation_id ] );
		}
		// add it is time to remove variations
		if( ! empty( $crossposted_variations[ 'delete' ] ) ) {
			$body[ 'delete' ] = $crossposted_variations[ 'delete' ];
		}

		try {
			$response = $woocommerce->post( "products/{$product_id}/variations/batch", $body );
		} catch( Exception $error ) {
			$wc_logger->error( $error->getMessage(), array( 'source' => 'product-sync' ) );
		}

	}


}
new PSFW_Product_Sync;
