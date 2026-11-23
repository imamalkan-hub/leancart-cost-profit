<?php
/**
 * Plugin Name: LeanCart Cost & Profit
 * Plugin URI:  https://chalazionguide.com/
 * Description: Record product costs, see the profit margin in the product list, and track each order’s net profit in the order list. No accounting needed.
 * Version:     1.0.0
 * Author:      LeanCart
 * Author URI:  https://leancart.dev
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cost-profit
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LEANCART_COST_PROFIT_DEMO_SITE' ) ) {
	define( 'LEANCART_COST_PROFIT_DEMO_SITE', 'https://chalazionguide.com' );
}

require_once __DIR__ . '/includes/class-leancart-framework.php';

final class LeanCart_Cost_Profit {

	const SLUG = 'cost-profit';

	public static function init() {
		LeanCart_Framework::boot( array(
			'slug'    => self::SLUG,
			'name'    => 'LeanCart Cost & Profit',
			'version' => '1.0.0',
			'file'    => __FILE__,
			'site'    => LEANCART_COST_PROFIT_DEMO_SITE,
		) );

		// WooCommerce may load after these plugins; defer the check to runtime.
		add_action( 'admin_notices', array( __CLASS__, 'wc_notice' ) );

		load_plugin_textdomain( 'cost-profit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action( 'leancart_' . self::SLUG . '_settings_fields', array( __CLASS__, 'fields' ) );

		// WooCommerce may load after this plugin; hooks are registered once WC is ready.
		if ( LeanCart_Framework::is_wc() ) {
			self::hooks();
		} else {
			add_action( 'woocommerce_loaded', array( __CLASS__, 'hooks' ) );
		}
	}

	public static function wc_notice() {
		if ( LeanCart_Framework::is_wc() ) { return; }
		echo '<div class="notice notice-warning"><p><strong>' . esc_html( 'LeanCart Cost & Profit' ) . '</strong>: ';
		esc_html_e( 'This plugin requires WooCommerce to be active.', 'cost-profit' );
		echo '</p></div>';
	}

	public static function fields() {
		LeanCart_Framework::field_text( 'default_margin', __('Default margin %', 'cost-profit'), __('Suggested margin for new products.', 'cost-profit'), 'number' );
		LeanCart_Framework::field_checkbox( 'show_column', __('Profit column in product list', 'cost-profit'), __('Yes', 'cost-profit') );
	}


	public static function hooks() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product' ), 20 );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'product_column' ) );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'product_column_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'capture_cost' ), 10, 4 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'order_column_data' ), 10, 2 );
		// HPOS (High-Performance Order Storage) equivalent.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'order_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'hpos_column' ), 10, 2 );
	}

	public static function product_field() {
		echo '<div class="options_group">';
		woocommerce_wp_text_input( array(
			'id'                => '_lc_cost',
			'label'             => __( 'Unit cost', 'cost-profit' ),
			'description'       => __( 'What this product costs you.', 'cost-profit' ),
			'desc_tip'          => true,
			'type'              => 'number',
			'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
		) );
		echo '</div>';
	}

	public static function variation_field( $loop, $variation_data, $variation ) {
		woocommerce_wp_text_input( array(
			'id'                => '_lc_cost_' . $loop,
			'label'             => __( 'Unit cost', 'cost-profit' ),
			'value'             => get_post_meta( $variation->ID, '_lc_cost', true ),
			'wrapper_class'     => 'form-row form-row-full',
			'type'              => 'number',
			'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
		) );
	}

	public static function save_product( $id ) {
		if ( ! current_user_can( 'edit_product', $id ) ) { return; }
		if ( isset( $_POST['woocommerce_meta_nonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) { return; }
		if ( isset( $_POST['_lc_cost'] ) ) {
			$cost = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_lc_cost'] ) ) );
			update_post_meta( $id, '_lc_cost', $cost );
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( $product && '' === $product->get_regular_price() && (float) $cost > 0 ) {
				$raw_margin = LeanCart_Framework::setting( 'default_margin', 30 );
				$margin = ( '' === $raw_margin || ! is_numeric( $raw_margin ) ) ? 30 : (float) $raw_margin;
				$margin = max( 0, min( 95, $margin ) );
				$product->set_regular_price( round( (float) $cost / ( 1 - $margin / 100 ), 2 ) );
				$product->save();
			}
		}
	}

	public static function save_variation( $id, $loop ) {
		if ( ! current_user_can( 'edit_product', $id ) ) { return; }
		if ( isset( $_POST['woocommerce_meta_nonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) { return; }
		$key = '_lc_cost_' . $loop;
		if ( isset( $_POST[ $key ] ) ) {
			update_post_meta( $id, '_lc_cost', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) );
		}
	}

	public static function product_column( $cols ) {
		if ( 'yes' !== LeanCart_Framework::setting( 'show_column', 'yes' ) ) { return $cols; }
		$cols['lc_profit'] = __( 'Profit', 'cost-profit' );
		return $cols;
	}

	public static function product_column_data( $col, $id ) {
		if ( 'lc_profit' !== $col ) { return; }
		$product = wc_get_product( $id );
		if ( ! $product ) { return; }
		$price = (float) $product->get_price();
		$raw   = get_post_meta( $id, '_lc_cost', true );
		if ( '' === $raw || null === $raw || $price <= 0 ) { echo '&mdash;'; return; }
		$cost  = (float) $raw;
		$profit = $price - $cost;
		$pct    = $price > 0 ? round( $profit / $price * 100 ) : 0;
		echo wp_kses_post( LeanCart_Framework::money( $profit ) . ' (%' . $pct . ')' );
	}

	public static function capture_cost( $item, $key, $values, $order ) {
		$id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		$item->update_meta_data( '_lc_cost', get_post_meta( $id, '_lc_cost', true ) );
	}

	public static function order_column( $cols ) {
		$cols['lc_order_profit'] = __( 'Net profit', 'cost-profit' );
		return $cols;
	}

	public static function order_column_data( $col, $order_id ) {
		if ( 'lc_order_profit' !== $col ) { return; }
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }
		$profit = 0;
		foreach ( $order->get_items() as $item ) {
			$cost = (float) $item->get_meta( '_lc_cost' );
			if ( $cost <= 0 ) {
				$p = $item->get_product();
				$cost = $p ? (float) get_post_meta( $p->get_id(), '_lc_cost', true ) : 0;
			}
			$profit += $item->get_total() - $cost * $item->get_quantity();
		}
		echo wp_kses_post( LeanCart_Framework::money( $profit ) );
	}

	public static function hpos_column( $column, $order ) {
		if ( 'lc_order_profit' !== $column || ! is_object( $order ) ) { return; }
		$profit = 0;
		foreach ( $order->get_items() as $item ) {
			$cost = (float) $item->get_meta( '_lc_cost' );
			if ( $cost <= 0 ) {
				$p = $item->get_product();
				$cost = $p ? (float) get_post_meta( $p->get_id(), '_lc_cost', true ) : 0;
			}
			$profit += $item->get_total() - $cost * $item->get_quantity();
		}
		echo wp_kses_post( LeanCart_Framework::money( $profit ) );
	}


}

LeanCart_Cost_Profit::init();

