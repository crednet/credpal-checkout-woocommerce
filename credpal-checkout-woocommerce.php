<?php

/*
 * Plugin Name: CredPal Pay
 * Plugin URI: https://credpal.com
 * Description: Pay Using CredPal Pay
 * Author: Emmanuel Okhamafe
 * Author URI: http://credpal.com
 * Version: 1.1.0
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_filter( 'woocommerce_payment_gateways', 'credpal_pay_add_gateway_class' );

function credpal_pay_add_gateway_class( $gateways ) {
    $gateways[] = 'WC_CredPal_Pay_Gateway';
    return $gateways;
}

add_action( 'plugins_loaded', 'credpal_pay_init_gateway_class' );

add_action( 'wp_ajax_order_complete', 'credpal_order_complete');
add_action( 'wp_ajax_nopriv_order_complete', 'credpal_order_complete');

function credpal_order_complete(){
    if ( isset($_POST['order_id']) && $_POST['order_id'] > 0 ) {
        $order = wc_get_order($_POST['order_id']);

        $order->update_status('completed');

        $result = 'success';

        if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            $result = wp_json_encode($result);
            echo $result;
        } else {
            header("Location: ".$_SERVER["HTTP_REFERER"]);
        }

        die();
    }
}

function credpal_pay_init_gateway_class() {

    class WC_CredPal_Pay_Gateway extends WC_Payment_Gateway {
        public $merchantId;

        /**
         * Class constructor, more about it in Step 3
         */
        public function __construct() {
            $this->id = 'credpal_pay';
            $this->icon = plugins_url('/images/credpal-checkOut.png', __FILE__);
            $this->has_fields = true;
            $this->method_title = 'CredPal BNPL';
            $this->method_description = 'Spread payment using Credpal';

            $this->supports = array(
                'products'
            );

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->enabled = $this->get_option( 'enabled' );
            $this->merchantId = $this->get_option( 'merchant_id' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

            add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'show_receipt' ) );
        }

        public function init_form_fields(){
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable CredPal Pay',
                    'type'        => 'checkbox',
                    'description' => 'Enable CredPal pay as a payment option on the checkout page',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'CredPal BNPL',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Spread payment using CredPal.',
                ),
                'merchant_id' => array(
                    'title'       => 'Merchant ID',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'text',
                    'description' => 'Your CredPal Merchant ID',
                    'default'     => ''
                ),
            );
        }

        /**
         * Custom credit card form
         */
        public function payment_fields() {
            $this->description  = trim( $this->description );

            echo wpautop( wp_kses_post( $this->description ) );
        }

        /*
         * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
         */
        public function payment_scripts() {
            if ( ! is_checkout_pay_page() ) {
                return;
            }

            if ( $this->enabled === 'no' ) {
                return;
            }

            $order_key = urldecode( $_GET['key'] );
            $order_id  = absint( get_query_var( 'order-pay' ) );

            $order = wc_get_order( $order_id );
            $products = '';

            foreach ($order->get_items() as $item) {
                $products .= $item['name'];
                $products .= ", ";
            }

            $payment_method = method_exists( $order, 'get_payment_method' ) ? $order->get_payment_method() : $order->payment_method;

            if ( $this->id !== $payment_method ) {
                return;
            }

            wp_enqueue_script( 'credpal_pay_js', 'https://corporate-loans.s3.amazonaws.com/minifiedJS/index.js' );
            wp_enqueue_script('wc_credpal_pay_js', plugins_url('/assets/js/credpal.js', __FILE__));

			$user = $order->get_address();
			$user['phone_no'] = $user['phone'];
			
            $params = [
                'amount' => $order->get_total(),
                'merchant_id' => $this->merchantId,
                'ajaxurl' => admin_url( 'admin-ajax.php'),
                'order_status' => $order->get_status(),
                'order_id' => $order->get_id(),
                'products' => $products,
                'redirect_url' => $this->get_return_url( $order ),
                'user' => $user,
            ];

            wp_localize_script( 'wc_credpal_pay_js', 'credpal_pay_data', $params);
        }

        public function show_receipt($order_id)
        {
            $order = wc_get_order($order_id);

            echo '<div id="wc-credpal-pay">';
            echo '<p> Click the button below to pay with CredPal </p>';
            echo '<div id="credpal_form"><form id="order_review" method="post" action="' . WC()->api_request_url( 'WC_CredPal_Pay_Gateway' ) . '"></form><button class="button" id="credpal-pay-button"> Pay With CredPal </button>';
            echo '  <a class="button cancel" id="credpal-pay-cancel-button" href="' . esc_url( $order->get_cancel_order_url() ) . '"> Cancel order &amp; restore cart</a></div>';
            echo '</div>';
        }

        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url( true ),
            );
        }
    }
}