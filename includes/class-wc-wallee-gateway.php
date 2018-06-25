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
 * This class implements the wallee gateways
 */
class WC_Wallee_Gateway extends WC_Payment_Gateway {
	private $payment_method_configuration_id;
	
	//Contains a users saved tokens for this gateway.
	protected $tokens = array();
	//We prefix out private variables as other plugins do strange things
	private $wle_payment_method_configuration_id;
	private $wle_payment_method_configuration = null;
	private $wle_translated_title = null;
	private $wle_translated_description = null;
	private $wle_show_description = 'yes';
	private $wle_show_icon = 'yes';
	private $wle_image = null;
	

	public function __construct(WC_Wallee_Entity_Method_Configuration $method){
		$this->wle_payment_method_configuration_id = $method->get_id();
		$this->id = 'wallee_' . $method->get_id();
		$this->has_fields = false;
		$this->method_title = $method->get_configuration_name();
		$this->method_description = WC_Wallee_Helper::instance()->translate($method->get_description());
		$this->wle_image = $method->get_image();
		$this->wle_image_base = $method->get_image_base();
		$this->icon = WC_Wallee_Helper::instance()->get_resource_url($this->wle_image, $this->wle_image_base);
		
		//We set the title and description here, as some plugin access the variables directly.
		$this->title = $method->get_configuration_name();
		$this->description = "";
		
		$this->wle_translated_title = $method->get_title();
		$this->wle_translated_description = $method->get_description();
		
		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();
		
		// Define user set variables.
		$this->enabled = $this->get_option('enabled');
		$this->wle_show_description = $this->get_option('show_description');
		$this->wle_show_icon = $this->get_option('show_icon');
		
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options' 
		));
		
	
		$this->supports = array(
		    'products',
		    'refunds'
		);
	}

	/**
	 * Returns the payment method configuration.
	 *
	 * @return WC_Wallee_Entity_Method_Configuration
	 */
	public function get_payment_method_configuration(){
		if ($this->wle_payment_method_configuration === null) {
		    $this->wle_payment_method_configuration = WC_Wallee_Entity_Method_Configuration::load_by_id(
					$this->wle_payment_method_configuration_id);
		}
		return $this->wle_payment_method_configuration;
	}

	/**
	 * Return the gateway's title fontend.
	 *
	 * @return string
	 */
	public function get_title(){
		$title = $this->title;
		$translated = WC_Wallee_Helper::instance()->translate($this->wle_translated_title);
		if ($translated !== null) {
			$title = $translated;
		}
		return apply_filters('woocommerce_gateway_title', $title, $this->id);
	}

	/**
	 * Return the gateway's description frontend.
	 *
	 * @return string
	 */
	public function get_description(){
		$description = "";
		if ($this->wle_show_description == 'yes') {
		    $translated = WC_Wallee_Helper::instance()->translate($this->wle_translated_description);
			if ($translated !== null) {
				$description = $translated;
			}
		}
		return apply_filters('woocommerce_gateway_description', $description, $this->id);
	}

	/**
	 * Return the gateway's icon.
	 * @return string
	 */
	public function get_icon(){
		$icon = "";
		if ($this->wle_show_icon == 'yes') {
			$space_id = $this->get_payment_method_configuration()->get_space_id();
			$space_view_id = get_option(WooCommerce_Wallee::CK_SPACE_VIEW_ID);
			$language = WC_Wallee_Helper::instance()->get_cleaned_locale();
			
			$url = WC_Wallee_Helper::instance()->get_resource_url($this->wle_image_base, $this->wle_image, $language, $space_id, $space_view_id);
			$icon = '<img src="' . WC_HTTPS::force_https_url($url) . '" alt="' . esc_attr($this->get_title()) . '" width="35px" />';
		}
		
		return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields(){
		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'woocommerce'),
				'type' => 'checkbox',
				'label' => sprintf(__('Enable %s', 'woo-wallee'), $this->method_title),
				'default' => 'yes' 
			),
			'title_value' => array(
				'title' => __('Title', 'woocommerce'),
				'type' => 'info',
				'value' => $this->get_title(),
				'description' => __('This controls the title which the user sees during checkout.', 'woo-wallee') 
			),
			'description_value' => array(
				'title' => __('Description', 'woocommerce'),
				'type' => 'info',
				'value' => !empty($this->get_description()) ? $this->get_description() : __('[not set]', 'woo-wallee'),
				'description' => __('Payment method description that the customer will see on your checkout.', 'woo-wallee') 
			),
			'show_description' => array(
				'title' => __('Show description', 'woo-wallee'),
				'type' => 'checkbox',
				'label' => __('Yes', 'woo-wallee'),
				'default' => 'yes',
				'description' => __("Show the payment method's description on the checkout page.", 'woo-wallee'),
				'desc_tip' => true 
			),
			'show_icon' => array(
				'title' => __('Show method image', 'woo-wallee'),
				'type' => 'checkbox',
				'label' => __('Yes', 'woo-wallee'),
				'default' => 'yes',
				'description' => __("Show the payment method's image on the checkout page.", 'woo-wallee'),
				'desc_tip' => true 
			) 
		);
	}

	/**
	 * Generate info HTML.
	 *
	 * @param  mixed $key
	 * @param  mixed $data
	 * @return string
	 */
	public function generate_info_html($key, $data){
		$field_key = $this->get_field_key($key);
		$defaults = array(
			'title' => '',
			'class' => '',
			'css' => '',
			'placeholder' => '',
			'desc_tip' => true,
			'description' => '',
			'custom_attributes' => array() 
		);
		
		$data = wp_parse_args($data, $defaults);
		
		ob_start();
		?>
<tr valign="top">
	<th scope="row" class="titledesc">
							<?php echo $this->get_tooltip_html( $data ); ?>
							<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
	</th>
	<td class="forminp">
		<fieldset>
			<legend class="screen-reader-text">
				<span><?php echo wp_kses_post( $data['title'] ); ?></span>
			</legend>
			<div class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>" id="<?php echo esc_attr( $field_key ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" <?php echo $this->get_custom_attribute_html( $data ); ?> >
								<?php echo esc_attr($data['value']); ?>
						</div>
		</fieldset>
	</td>
</tr>
<?php
		
		return ob_get_clean();
	}

	/**
	 * Validate Info Field.
	 *
	 * @param  string $key Field key
	 * @param  string|null $value Posted Value
	 * @return void
	 */
	public function validate_info_field($key, $value){
		return;
	}

	/**
	 * Check if the gateway is available for use.
	 *
	 * @return bool
	 */
	public function is_available(){
		$is_available = parent::is_available();
		
		if (!$is_available) {
			return false;
		}
		//It is possbile this function is called in the wordpress admin section.
		//There is not a cart, so all active methods are available
		if(is_admin() ) {
		    return $this->get_payment_method_configuration()->get_state() ==  WC_Wallee_Entity_Method_Configuration::STATE_ACTIVE;
		}
		
		//The gateways are always available during order total caluclation, as other plugins could need them.
		if (isset($GLOBALS['_wc_wallee_calculating']) && $GLOBALS['_wc_wallee_calculating']) {
			return true;
		}
	
		try {
		    $possible_methods = WC_Wallee_Service_Transaction::instance()->get_possible_payment_methods();
			$possible = false;
			foreach ($possible_methods as $possible_method) {
				if ($possible_method->getId() == $this->get_payment_method_configuration()->get_configuration_id()) {
					$possible = true;
					break;
				}
			}
			if (!$possible) {
				return false;
			}
		}
		catch(Exception $e){
		    WooCommerce_Wallee::instance()->log($e->getMessage(), WC_Log_Levels::DEBUG);
			return false;
		}
		return true;
	}

	/**
	 * Check if the gateway has fields on the checkout.
	 *
	 * @return bool
	 */
	public function has_fields(){
		return true;
	}

	public function payment_fields(){
	    try{
	        wp_enqueue_script('wallee-remote-checkout-js', WC_Wallee_Service_Transaction::instance()->get_javascript_url(), array(
	            'jquery'
	        ), null, true);
	        wp_enqueue_script('wallee-checkout-js', WooCommerce_Wallee::instance()->plugin_url() . '/assets/js/frontend/checkout.js',
	            array(
	                'jquery',
	                'wallee-remote-checkout-js'
	            ), null, true);
	        $localize = array(
	            'i18n_not_complete' => __('Please fill out all required fields.', 'woo-wallee'),
	        );
	        wp_localize_script('wallee-checkout-js', 'wallee_js_params', $localize);
	    }
	    catch(Exception $e){
	        $this->log($e->getMessage(), WC_Log_Levels::DEBUG);
	    }
	    
		parent::payment_fields();
		?>
		
<div id="payment-form-<?php echo $this->id?>"></div>
<div id="wallee-method-configuration-<?php echo $this->id?>"
	class="wallee-method-configuration" style="display: none;"
	data-method-id="<?php echo $this->id; ?>"
	data-configuration-id="<?php echo $this->get_payment_method_configuration()->get_configuration_id(); ?>"
	data-container-id="payment-form-<?php echo $this->id?>" data-description-available="<?php var_export(!empty($this->get_description()));?>"></div>
<?php
	}

	/**
	 * Validate frontend fields.
	 * @return bool
	 */
	public function validate_fields(){
		return true;
	}

	/**
	 * Process Payment.
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment($order_id){
	    $is_order_pay_endpoint = apply_filters('wc_wallee_is_order_pay_endpoint', is_wc_endpoint_url( 'order-pay'), $order_id);
		try {
		    if($is_order_pay_endpoint){
			    $existing = WC_Wallee_Entity_Transaction_Info::load_by_order_id($order_id);
			    $space_id = $existing->get_space_id();
			    $transaction_id = $existing->get_transaction_id();
			}
			else{
			    $session_handler = WC()->session;
			    $space_id = $session_handler->get('wallee_space_id');
			    $transaction_id = $session_handler->get('wallee_transaction_id');
			    $existing = WC_Wallee_Entity_Transaction_Info::load_by_order_id($order_id);
			    if($existing->get_id() > 0 && $existing->get_state() != \Wallee\Sdk\Model\TransactionState::PENDING){
			        WooCommerce_Wallee::instance()->add_notice(__('There was an issue, while processing your order. Please try again or use another payment method.', 'woo-wallee'), 'error');
			        $order->update_status( 'failed' );
			        WC()->session->set('reload_checkout', true);
			        return array(
			            'result' => 'failure'
			        );
			    }			    
			}
						
			$order = wc_get_order($order_id);
			$transaction_service = WC_Wallee_Service_Transaction::instance();
			
			$transaction = $transaction_service->confirm_transaction($transaction_id, $space_id, $order);
			$transaction_service->update_transaction_info($transaction, $order);
			
			$order->add_meta_data('_wallee_linked_ids', array('space_id' =>  $transaction->getLinkedSpaceId(), 'transaction_id' => $transaction->getId()), false);
			$order->delete_meta_data('_wc_wallee_restocked');
			
			$order->save();
			$result =array(
			    'result' => 'success',
			    'wallee' => 'true'
			);
			if($is_order_pay_endpoint){
			    wp_send_json( $result );
			    exit;
			}
			else{
    			WC_Wallee_Helper::instance()->destroy_current_cart_id();
    			return $result;
			}
		}
		catch (Exception $e) {
			$message = $e->getMessage();
			$cleaned = preg_replace("/^\[[A-Fa-f\d\-]+\] /", "", $message);
			WooCommerce_Wallee::instance()->add_notice($cleaned, 'error');
			$order->update_status( 'failed' );
			if($is_order_pay_endpoint){
			    $result =array(
			        'result' => 'failure',
			        'reload' => 'true'
			    );
			    wp_send_json( $result );
			    exit;
			}
			WC()->session->set('reload_checkout', true);
			return array(
				'result' => 'failure'
			);
		}
	}

	/**
	 * Process refund.
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund.
	 * a passed in amount.
	 *
	 * @param  int $order_id
	 * @param  float $amount
	 * @param  string $reason
	 * @return boolean True or false based on success, or a WP_Error object.
	 */
	public function process_refund($order_id, $amount = null, $reason = ''){
		global $wpdb;
		
		if (!isset($GLOBALS['wallee_refund_id'])) {
			return new WP_Error('wallee_error', __('There was a problem creating the refund.', 'woo-wallee'));
		}
		/**
		 * @var WC_Order_Refund $refund
		 */
		$refund = WC_Order_Factory::get_order($GLOBALS['wallee_refund_id']);
		$order = WC_Order_Factory::get_order($order_id);
		
		try {
		    WC_Wallee_Admin_Refund::execute_refund($order, $refund);
		}
		catch (Exception $e) {
			return new WP_Error('wallee_error', $e->getMessage());
		}
		
		$refund_job_id =  $refund->get_meta('_wallee_refund_job_id', true);
		
		$wait = 0;
		while($wait < 5){
		    $refund_job = WC_Wallee_Entity_Refund_Job::load_by_id($refund_job_id);
		    if($refund_job->get_state() == WC_Wallee_Entity_Refund_Job::STATE_FAILURE){
		        return new WP_Error('wallee_error', $refund_job->get_failure_reason());
		    }
		    elseif ($refund_job->get_state() == WC_Wallee_Entity_Refund_Job::STATE_SUCCESS){
		        return true;
		    }
		    $wait++;
		    sleep(1);
		}		
		return true;
	}
}