<?php
if (!defined('ABSPATH')) {
	exit();
}
/**
 * wallee WooCommerce
 *
 * This WooCommerce plugin enables to process payments with wallee (https://www.wallee.com).
 *
 * @author customweb GmbH (http://www.customweb.com/)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 */
/**
 * This class handles the customer returns
 */
class WC_Wallee_Return_Handler {

	public static function init(){
		add_action('woocommerce_api_wallee_return', array(
			__CLASS__,
			'process' 
		));
	}

	public static function process(){
		if (isset($_GET['action']) && isset($_GET['order_key']) && isset($_GET['order_id'])) {
			$order_key = $_GET['order_key'];
			$order_id = absint($_GET['order_id']);
			$order = WC_Order_Factory::get_order($order_id);
			$action = $_GET['action'];
			if ($order->get_id() === $order_id && $order->get_order_key() === $order_key) {
				switch ($action) {
					case 'success':
						self::process_success($order);
						break;
					case 'failure':
						self::process_failure($order);
						break;
					default:
				}
			}
		}
		wp_redirect(home_url('/'));
		exit();
	}

	protected static function process_success(WC_Order $order){
	    $transaction_service = WC_Wallee_Service_Transaction::instance();
		
		$transaction_service->wait_for_transaction_state($order, 
				array(
				    \Wallee\Sdk\Model\TransactionState::AUTHORIZED,
				    \Wallee\Sdk\Model\TransactionState::COMPLETED,
				    \Wallee\Sdk\Model\TransactionState::FULFILL,
				), 5);
		$gateway = wc_get_payment_gateway_by_order($order);
		wp_redirect($gateway->get_return_url($order));
		exit();
	}

	protected static function process_failure(WC_Order $order){
	    $transaction_service = WC_Wallee_Service_Transaction::instance();
		$transaction_service->wait_for_transaction_state($order, array(
		    \Wallee\Sdk\Model\TransactionState::FAILED 
		), 5);
		$transaction = WC_Wallee_Entity_Transaction_Info::load_newest_by_mapped_order_id($order->get_id());
		if($transaction->get_state() ==  \Wallee\Sdk\Model\TransactionState::FAILED ){
		    WC()->session->set( 'order_awaiting_payment', $order->get_id());
		}		
		$user_message = $transaction->get_user_failure_message();
		$failure_reason = $transaction->get_failure_reason();
		if(empty($user_message) && $failure_reason !== null){
		    $user_message = $failure_reason;
		}
		if (!empty($user_message)) {
		    WC()->session->set( 'wallee_failure_message', $user_message );
		}
		if($order->get_meta('_wallee_pay_for_order', true, 'edit')){
		    wp_redirect($order->get_checkout_payment_url(false));
		}
		else{
		    wp_redirect(wc_get_checkout_url());
		}
		exit();
	}
}
WC_Wallee_Return_Handler::init();
