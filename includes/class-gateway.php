<?php
/**
 * Copyright (c) 2019-2023 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

define( 'MPGS_MODULE_VERSION', '1.4.0-MCV' ); // Added -MCV to signify modification

require_once dirname( __FILE__ ) . '/class-checkout-builder.php';
require_once dirname( __FILE__ ) . '/class-gateway-service.php';
require_once dirname( __FILE__ ) . '/class-payment-gateway-cc.php';

class Mastercard_Gateway extends WC_Payment_Gateway {

	const ID = 'mpgs_gateway';

	const MPGS_API_VERSION = 'version/69'; // Use a consistent version supported by your MIDs
	const MPGS_API_VERSION_NUM = '69';     // Use a consistent version supported by your MIDs

	const HOSTED_SESSION = 'hostedsession';
	const HOSTED_CHECKOUT = 'newhostedcheckout';

	const HC_TYPE_REDIRECT = 'redirect';
	const HC_TYPE_MODAL = 'modal'; // Legacy
	const HC_TYPE_EMBEDDED = 'embedded';

	const API_EU = 'eu-gateway.mastercard.com';
	const API_AS = 'ap-gateway.mastercard.com';
	const API_NA = 'na-gateway.mastercard.com';
	const API_CUSTOM = 'custom';

	const TXN_MODE_PURCHASE = 'capture';
	const TXN_MODE_AUTH_CAPTURE = 'authorize';

	const THREED_DISABLED = 'no';
	const THREED_V1 = 'yes'; // Backward compatibility with checkbox value
	const THREED_V2 = '2';

    // --- NEW: Define card types ---
    const CARD_TYPE_MASTERCARD = 'mastercard';
    const CARD_TYPE_VISA = 'visa';
    // --- END NEW ---

	/**
	 * @var string
	 */
	protected $order_prefix;

	/**
	 * @var bool
	 */
	protected $sandbox;

	// --- REMOVED: Specific username/password properties - fetch dynamically ---
	// protected $username;
	// protected $password;
    // --- END REMOVED ---

	/**
	 * @var string
	 */
	protected $gateway_url;

	/**
	 * @var Mastercard_GatewayService - REMOVED: Will instantiate dynamically
	 */
	// protected $service;

	/**
	 * @var string
	 */
	protected $hc_interaction;

	/**
	 * @var string
	 *
	 * @todo Remove after removal of Legacy Hosted Checkout
	 */
	protected $hc_type;

	/**
	 * @var bool
	 */
	protected $capture;

	/**
	 * @var string
	 */
	protected $method;

	/**
	 * @var bool
	 */
	protected $threedsecure_v1;

	/**
	 * @var bool
	 */
	protected $threedsecure_v2;

	/**
	 * @var bool
	 */
	protected $saved_cards;

	/**
	 * Mastercard_Gateway constructor.
	 * @throws Exception
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->title              = __( 'Mastercard Payment Gateway Services', 'mastercard' ); // Keep generic title
		$this->method_title       = __( 'Mastercard & Visa (MPGS)', 'mastercard' ); // More specific method title
		$this->has_fields         = true;
		$this->method_description = __( 'Accept Mastercard and Visa payments via Mastercard Payment Gateway Services.', 'mastercard' );

		$this->init_form_fields();
		$this->init_settings();

		$this->order_prefix    = $this->get_option( 'order_prefix' );
		$this->title           = $this->get_option( 'title' ); // Title shown to user
		$this->description     = $this->get_option( 'description' );
		$this->enabled         = $this->get_option( 'enabled', false );
		$this->hc_type         = $this->get_option( 'hc_type', self::HC_TYPE_MODAL ); // Legacy HC
		$this->hc_interaction  = $this->get_option( 'hc_interaction', self::HC_TYPE_EMBEDDED ); // New HC
		$this->capture         = $this->get_option( 'txn_mode', self::TXN_MODE_PURCHASE ) === self::TXN_MODE_PURCHASE;
		$this->threedsecure_v1 = $this->get_option( 'threedsecure', self::THREED_DISABLED ) === self::THREED_V1;
		$this->threedsecure_v2 = $this->get_option( 'threedsecure', self::THREED_DISABLED ) === self::THREED_V2;
		$this->method          = $this->get_option( 'method', self::HOSTED_CHECKOUT );
		$this->saved_cards     = $this->get_option( 'saved_cards', 'yes' ) == 'yes';
		$this->sandbox         = $this->get_option( 'sandbox', false ) === 'yes'; // Ensure boolean context
        $this->gateway_url     = $this->get_gateway_url(); // Get gateway host URL

		$this->supports        = array(
			'products',
			'refunds',
			'tokenization',
		);

        // REMOVED: Service initialization - happens dynamically now
		// $this->service = $this->init_service();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'woocommerce_order_action_mpgs_capture_order', array( $this, 'process_capture' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_mastercard_gateway', array( $this, 'return_handler' ) ); // Keep name generic
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

        // --- NEW: Add checkout validation ---
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_card_type_selection' ) );
        // --- END NEW ---
	}
}

    /**
     * --- NEW: Helper method to get the correct service instance ---
     *
     * @param string $card_type ('mastercard' or 'visa')
     * @return Mastercard_GatewayService
     * @throws Exception If credentials are missing for the selected type/mode.
     */
    protected function get_service_instance($card_type = self::CARD_TYPE_MASTERCARD) {
        $username = '';
        $password = '';
        $mode = $this->sandbox ? 'sandbox' : 'live';
        $type_label = ($card_type === self::CARD_TYPE_VISA) ? 'Visa' : 'Mastercard';

        if ($card_type === self::CARD_TYPE_VISA) {
            $username = $this->sandbox ? $this->get_option('sandbox_username_visa') : $this->get_option('username_visa');
            $password = $this->sandbox ? $this->get_option('sandbox_password_visa') : $this->get_option('password_visa');
        } else { // Default to Mastercard
            $username = $this->sandbox ? $this->get_option('sandbox_username_mastercard') : $this->get_option('username_mastercard');
            $password = $this->sandbox ? $this->get_option('sandbox_password_mastercard') : $this->get_option('password_mastercard');
        }

        if (empty($username) || empty($password)) {
             error_log("MPGS Error: Missing {$type_label} {$mode} credentials."); // Log detailed error
             throw new Exception(sprintf(__('Payment gateway configuration error for %s (%s). Please contact the site administrator.', 'mastercard'), $type_label, $mode));
        }

        $loggingLevel = $this->get_debug_logging_enabled()
            ? \Monolog\Logger::DEBUG
            : \Monolog\Logger::ERROR;

        // Use the already determined gateway URL
        $gateway_host = $this->gateway_url;

        // Use the consistent API version
        $api_version = $this->get_api_version();

        return new Mastercard_GatewayService(
            $gateway_host,
            $api_version,
            $username, // Use the dynamically selected username
            $password, // Use the dynamically selected password
            $this->get_webhook_url(),
            $loggingLevel
        );
    }
    // --- END NEW ---

	/**
     * REMOVED: init_service - replaced by get_service_instance
	 */
	// protected function init_service() { ... }


	/**
	 * @return bool
	 */
	protected function get_debug_logging_enabled() {
		// Keep original logic
		if ( $this->sandbox ) { // Use the boolean property now
			return $this->get_option( 'debug', false ) === 'yes';
		}
		return false;
	}

	/**
	 * @return string
	 */
	protected function get_gateway_url() {
        // Keep original logic, ensure it's called only once if needed
        if (!empty($this->gateway_url)) {
            return $this->gateway_url;
        }
		$gateway_host = $this->get_option( 'gateway_url', self::API_EU );
		if ( $gateway_host === self::API_CUSTOM ) {
			$gateway_host = $this->get_option( 'custom_gateway_url' );
		}
		// Basic validation for custom URL - ensure it's just a hostname
        if ($gateway_host === self::API_CUSTOM && !empty($this->get_option('custom_gateway_url'))) {
             $custom_url = trim($this->get_option('custom_gateway_url'));
             // Remove http(s):// if present
             $custom_url = preg_replace('#^https?://#', '', $custom_url);
             // Remove trailing slash if present
             $custom_url = rtrim($custom_url, '/');
             // Very basic check - should not contain / or : after initial cleanup
             if (strpos($custom_url, '/') !== false || strpos($custom_url, ':') !== false) {
                 $this->add_error(__('Invalid Custom Gateway Host format. Please enter only the hostname (e.g., na.gateway.mastercard.com).', 'mastercard'));
                 return self::API_EU; // Fallback to default
             }
             return $custom_url;
        }

		return $gateway_host;
	}

	/**
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

        // --- MODIFIED: Test connectivity for both sets of credentials if entered ---
		try {
            // Test Mastercard Credentials
            $mc_user = $this->sandbox ? $this->get_option('sandbox_username_mastercard') : $this->get_option('username_mastercard');
            $mc_pass = $this->sandbox ? $this->get_option('sandbox_password_mastercard') : $this->get_option('password_mastercard');
            if (!empty($mc_user) && !empty($mc_pass)) {
			    $service_mc = $this->get_service_instance(self::CARD_TYPE_MASTERCARD); // Will throw exception if config incomplete
			    $service_mc->paymentOptionsInquiry(); // Test API call
                wc_get_logger()->info('MPGS Admin: Mastercard connectivity test successful.', array('source' => $this->id));
            } else {
                 wc_get_logger()->info('MPGS Admin: Skipping Mastercard connectivity test - credentials incomplete.', array('source' => $this->id));
            }

            // Test Visa Credentials
            $visa_user = $this->sandbox ? $this->get_option('sandbox_username_visa') : $this->get_option('username_visa');
            $visa_pass = $this->sandbox ? $this->get_option('sandbox_password_visa') : $this->get_option('password_visa');
             if (!empty($visa_user) && !empty($visa_pass)) {
			    $service_visa = $this->get_service_instance(self::CARD_TYPE_VISA); // Will throw exception if config incomplete
			    $service_visa->paymentOptionsInquiry(); // Test API call
                 wc_get_logger()->info('MPGS Admin: Visa connectivity test successful.', array('source' => $this->id));
             } else {
                 wc_get_logger()->info('MPGS Admin: Skipping Visa connectivity test - credentials incomplete.', array('source' => $this->id));
             }

		} catch ( Exception $e ) {
            // Provide more specific error based on which credential set failed if possible,
            // but a general error is okay for now. Exception message will indicate which failed if thrown from get_service_instance.
			$this->add_error(
				sprintf( __( 'Error communicating with payment gateway API: "%s"', 'mastercard' ), $e->getMessage() )
			);
            wc_get_logger()->error('MPGS Admin Connectivity Test Error: ' . $e->getMessage(), array('source' => $this->id));
		}
        // --- END MODIFIED ---

		return $saved;
	}

	/**
	 * @return void
	 */
	public function admin_scripts() {
		// Keep original logic - JS file will be modified separately
		if ( 'woocommerce_page_wc-settings' !== get_current_screen()->id ) {
			return;
		}
		wp_enqueue_script( 'woocommerce_mastercard_admin', plugins_url( 'assets/js/mastercard-admin.js', dirname(MPGS_PLUGIN_FILE) ), // Use dirname() for better pathing
			array('jquery'), MPGS_MODULE_VERSION, true ); // Add jquery dependency
	}

	/**
	 * @throws \Http\Client\Exception
	 */
	public function process_capture() {
		$order = new WC_Order( $_REQUEST['post_ID'] );
		if ( $order->get_payment_method() != $this->id ) {
			throw new Exception( 'Wrong payment method' );
		}
		if ( $order->get_status() != 'processing' ) {
			throw new Exception( 'Wrong order status, must be \'processing\'' );
		}
		if ( $order->get_meta( '_mpgs_order_captured' ) ) {
			throw new Exception( 'Order already captured' );
		}

        // --- NEW: Get card type used for the original transaction ---
        $card_type = $order->get_meta('_mpgs_card_type', true);
        if (empty($card_type)) {
            // Fallback or error - maybe default to Mastercard if needed, but ideally it should be stored
             wc_get_logger()->warning( sprintf('MPGS Capture Error: Card type not found for order %s. Defaulting to Mastercard.', $order->get_id()), array('source' => $this->id) );
             $card_type = self::CARD_TYPE_MASTERCARD; // Or throw error
             // throw new Exception('Cannot capture payment: Original card type unknown.');
        }
        // --- END NEW ---

		// --- MODIFIED: Use dynamic service instance ---
        try {
            $service = $this->get_service_instance($card_type);
            $result = $service->captureTxn(
                $this->add_order_prefix( $order->get_id() ),
                time(), // Or maybe use a stored transaction ID if required by MPGS API for capture? Check MPGS docs. This looks like it generates a NEW txn id based on time.
                (float) $order->get_total(),
                $order->get_currency()
            );
        } catch (Exception $e) {
            wc_get_logger()->error( sprintf('MPGS Capture Failed for Order %s (%s): %s', $order->get_id(), $card_type, $e->getMessage()), array('source' => $this->id) );
            // Optionally add admin notice here
            throw $e; // Re-throw to let WC handle the notice display
        }
		// --- END MODIFIED ---

		$txn = $result['transaction'];
        $type_label = ($card_type === self::CARD_TYPE_VISA) ? 'Visa' : 'Mastercard';
		$order->add_order_note( sprintf( __( 'Mastercard payment CAPTURED (%s - ID: %s, Auth Code: %s)', 'mastercard' ), // Indicate card type
            $type_label, $txn['id'], $txn['authorizationCode'] ) );

		$order->update_meta_data( '_mpgs_order_captured', true );
		$order->save_meta_data();

		wp_redirect( wp_get_referer() );
        exit; // Good practice after redirect
	}

	/**
	 * admin_notices
	 */
	public function admin_notices() {
        // Keep original logic, but check both sets of potential credentials
		if ( ! $this->enabled ) {
			return;
		}

		$mc_user = $this->sandbox ? $this->get_option('sandbox_username_mastercard') : $this->get_option('username_mastercard');
        $mc_pass = $this->sandbox ? $this->get_option('sandbox_password_mastercard') : $this->get_option('password_mastercard');
        $visa_user = $this->sandbox ? $this->get_option('sandbox_username_visa') : $this->get_option('username_visa');
        $visa_pass = $this->sandbox ? $this->get_option('sandbox_password_visa') : $this->get_option('password_visa');

        $errors = [];
		if ( empty($mc_user) || empty($mc_pass) ) {
            $errors[] = __( 'Mastercard API credentials are not valid or complete.', 'mastercard' );
		}
        if ( empty($visa_user) || empty($visa_pass) ) {
             $errors[] = __( 'Visa API credentials are not valid or complete.', 'mastercard' );
		}

        if (!empty($errors)) {
             echo '<div class="notice notice-warning"><p>' . __( 'MPGS Gateway Notice:', 'mastercard' ) . '<ul>';
             foreach ($errors as $error) {
                 echo '<li>' . esc_html($error) . '</li>';
             }
             echo '</ul>' . __( 'Please check the plugin settings. You only need to configure credentials for the card types you intend to accept.', 'mastercard' ) . '</p></div>';
        }

		$this->display_errors(); // Display errors added by process_admin_options
	}

	/**
	 * @param int $order_id
	 * @param float|null $amount
	 * @param string $reason
	 *
	 * @return bool
	 * @throws \Http\Client\Exception
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order  = new WC_Order( $order_id );

        // --- NEW: Get card type used for the original transaction ---
        $card_type = $order->get_meta('_mpgs_card_type', true);
        if (empty($card_type)) {
             wc_get_logger()->warning( sprintf('MPGS Refund Error: Card type not found for order %s. Defaulting to Mastercard.', $order->get_id()), array('source' => $this->id) );
             $card_type = self::CARD_TYPE_MASTERCARD; // Or throw error
             // return new WP_Error('mpgs_error', __('Cannot refund payment: Original card type unknown.', 'mastercard'));
        }
        // --- END NEW ---

        // --- MODIFIED: Use dynamic service instance ---
        try {
            $service = $this->get_service_instance($card_type);
            $result = $service->refund(
                $this->add_order_prefix( $order_id ),
                (string) time(), // Consider if a specific refund transaction ID is better practice
                $amount,
                $order->get_currency()
            );
        } catch (Exception $e) {
             wc_get_logger()->error( sprintf('MPGS Refund Failed for Order %s (%s): %s', $order->get_id(), $card_type, $e->getMessage()), array('source' => $this->id) );
             $order->add_order_note(sprintf(__('MPGS Refund Failed (%s): %s', 'mastercard'), ($card_type === self::CARD_TYPE_VISA ? 'Visa' : 'Mastercard'), $e->getMessage()));
             return new WP_Error('mpgs_refund_error', $e->getMessage()); // Return WP_Error on failure
        }
        // --- END MODIFIED ---

        $type_label = ($card_type === self::CARD_TYPE_VISA) ? 'Visa' : 'Mastercard';
		$order->add_order_note( sprintf(
			__( 'Mastercard registered refund %s %s (%s - ID: %s)', 'mastercard' ), // Indicate card type
			$result['transaction']['amount'],
			$result['transaction']['currency'],
            $type_label,
			$result['transaction']['id']
		) );

		return true; // Return true on success
	}

    // --- NEW: Validate card type selection ---
    public function validate_card_type_selection() {
        if ($this->id === $_POST['payment_method']) {
            if (!isset($_POST['mpgs_card_type_selection']) || !in_array($_POST['mpgs_card_type_selection'], [self::CARD_TYPE_MASTERCARD, self::CARD_TYPE_VISA])) {
                 wc_add_notice(__('Please select your payment card type (Mastercard or Visa).', 'mastercard'), 'error');
            }
            // Extra check: Ensure credentials exist for the selected type
            $selected_card_type = sanitize_key($_POST['mpgs_card_type_selection']);
            $username = '';
            $password = '';
             if ($selected_card_type === self::CARD_TYPE_VISA) {
                 $username = $this->sandbox ? $this->get_option('sandbox_username_visa') : $this->get_option('username_visa');
                 $password = $this->sandbox ? $this->get_option('sandbox_password_visa') : $this->get_option('password_visa');
                 if (empty($username) || empty($password)) {
                     wc_add_notice( sprintf(__('Payments via %s are currently unavailable. Please select a different payment method or contact support.', 'mastercard'), 'Visa'), 'error' );
                 }
             } else { // Mastercard
                 $username = $this->sandbox ? $this->get_option('sandbox_username_mastercard') : $this->get_option('username_mastercard');
                 $password = $this->sandbox ? $this->get_option('sandbox_password_mastercard') : $this->get_option('password_mastercard');
                  if (empty($username) || empty($password)) {
                     wc_add_notice( sprintf(__('Payments via %s are currently unavailable. Please select a different payment method or contact support.', 'mastercard'), 'Mastercard'), 'error' );
                 }
             }
        }
    }
    // --- END NEW ---

	/**
     * MODIFIED: Needs to handle different card types based on request parameters
	 * @return array|void
	 * @throws \Http\Client\Exception
	 */
	public function return_handler() {
		@ob_clean();
		header( 'HTTP/1.1 200 OK' );

        // --- NEW: Determine card type from request if available ---
        // We need to ensure card_type is passed back, especially for Hosted Session flows
        $card_type = self::CARD_TYPE_MASTERCARD; // Default assumption
        if (isset($_REQUEST['card_type']) && in_array($_REQUEST['card_type'], [self::CARD_TYPE_MASTERCARD, self::CARD_TYPE_VISA])) {
             $card_type = sanitize_key($_REQUEST['card_type']);
        } else {
             // Attempt to retrieve from order meta if not in request (might be needed for some return flows)
             if (isset($_REQUEST['order_id'])) {
                 $order_id_raw = $this->remove_order_prefix($_REQUEST['order_id']);
                 $order_temp = wc_get_order($order_id_raw);
                 if ($order_temp) {
                     $stored_card_type = $order_temp->get_meta('_mpgs_card_type', true);
                     if (!empty($stored_card_type)) {
                         $card_type = $stored_card_type;
                         wc_get_logger()->info( sprintf('MPGS Return Handler: Using stored card type "%s" for order %s', $card_type, $order_id_raw), array('source' => $this->id) );
                     } else {
                         wc_get_logger()->warning( sprintf('MPGS Return Handler: Card type missing in request and order meta for order %s. Defaulting to Mastercard.', $order_id_raw), array('source' => $this->id) );
                     }
                 }
             } else {
                  wc_get_logger()->warning( 'MPGS Return Handler: Card type and Order ID missing in request. Defaulting to Mastercard.', array('source' => $this->id) );
             }
        }
         wc_get_logger()->info( sprintf('MPGS Return Handler: Processing return for card type: %s', $card_type), array('source' => $this->id) );
        // --- END NEW ---

		$three_ds_txn_id = null;
		if ( isset( $_REQUEST['response_gatewayRecommendation'] ) ) {
			// 3DS v2 response handling
			if ( $_REQUEST['response_gatewayRecommendation'] === 'PROCEED' ) {
				$three_ds_txn_id = $_REQUEST['transaction_id'];
                 wc_get_logger()->info( sprintf('MPGS Return Handler (3DSv2): Proceed received for txn %s', $three_ds_txn_id), array('source' => $this->id) );
			} else {
                wc_get_logger()->error( sprintf('MPGS Return Handler (3DSv2): DO_NOT_PROCEED received. Order ID: %s', $_REQUEST['order_id']), array('source' => $this->id) );
				$order = wc_get_order( $this->remove_order_prefix( $_REQUEST['order_id'] ) ); // Use wc_get_order
                if ($order) {
				    $order->update_status( 'failed', __( '3DS authorization was not provided. Payment declined.', 'mastercard' ) );
                }
				wc_add_notice( __( '3DS authorization was not provided. Payment declined.', 'mastercard' ), 'error' );
				wp_redirect( wc_get_checkout_url() );
				exit();
			}
		}

		// Process based on the integration method used
		if ( $this->method === self::HOSTED_SESSION ) {
            // --- MODIFIED: Pass card type ---
			$this->process_hosted_session_payment( $three_ds_txn_id, $card_type );
            // --- END MODIFIED ---
		} elseif ( in_array( $this->method, array( self::HOSTED_CHECKOUT ), true ) ) { // Check against constant
            // --- MODIFIED: Pass card type ---
			$this->process_hosted_checkout_payment( $card_type );
            // --- END MODIFIED ---
		} else {
             wc_get_logger()->error( 'MPGS Return Handler: Unknown integration method configured: ' . $this->method, array('source' => $this->id) );
             wc_add_notice( __( 'An unexpected payment processing error occurred.', 'mastercard' ), 'error' );
             wp_redirect( wc_get_checkout_url() );
             exit();
        }
	}

	/**
     * MODIFIED: Needs card_type
	 * @throws \Http\Client\Exception
	 */
	protected function process_hosted_checkout_payment( $card_type = self::CARD_TYPE_MASTERCARD ) {
		$order_id          = $this->remove_order_prefix( $_REQUEST['order_id'] );
		$result_indicator  = $_REQUEST['resultIndicator'];
		$order             = wc_get_order( $order_id ); // Use wc_get_order

        if (!$order) {
             wc_get_logger()->error( sprintf('MPGS Hosted Checkout Error: Could not retrieve order %s.', $order_id), array('source' => $this->id) );
             wc_add_notice( __( 'An error occurred while processing your payment. Order not found.', 'mastercard' ), 'error' );
			 wp_redirect( wc_get_checkout_url() );
			 exit();
        }

		$success_indicator = $order->get_meta( '_mpgs_success_indicator', true ); // Get single value

		try {
            // --- NEW: Store selected card type ---
            $order->update_meta_data('_mpgs_card_type', $card_type);
            // --- END NEW ---

			if ( $success_indicator !== $result_indicator ) {
                wc_get_logger()->error( sprintf('MPGS Hosted Checkout Error: Result indicator mismatch for order %s. Expected: %s, Received: %s', $order_id, $success_indicator, $result_indicator), array('source' => $this->id) );
				throw new Exception( 'Payment validation failed (Result indicator mismatch).' ); // More generic error
			}

            // --- MODIFIED: Use dynamic service instance ---
            $service = $this->get_service_instance($card_type);
			$mpgs_order = $service->retrieveOrder( $this->add_order_prefix( $order_id ) );
            // --- END MODIFIED ---

			if ( $mpgs_order['result'] !== 'SUCCESS' ) {
                wc_get_logger()->error( sprintf('MPGS Hosted Checkout Error: Order retrieval from MPGS failed or result not SUCCESS for order %s. MPGS Result: %s', $order_id, $mpgs_order['result']), array('source' => $this->id) );
				throw new Exception( 'Payment was declined by the gateway.' );
			}

            // Check if transaction data exists
            if (empty($mpgs_order['transaction']) || !is_array($mpgs_order['transaction'])) {
                 wc_get_logger()->error( sprintf('MPGS Hosted Checkout Error: No transaction data found in MPGS order response for order %s.', $order_id), array('source' => $this->id) );
                 throw new Exception('Payment confirmation incomplete. Transaction data missing.');
            }

			$txn = $mpgs_order['transaction'][0]; // Assuming the relevant transaction is the first one
            // --- MODIFIED: Pass card type to process_wc_order ---
			$this->process_wc_order( $order, $mpgs_order, $txn, $card_type );
            // --- END MODIFIED ---

			wp_redirect( $this->get_return_url( $order ) );
			exit();
		} catch ( Exception $e ) {
             wc_get_logger()->error( sprintf('MPGS Hosted Checkout Processing Exception for order %s: %s', $order_id, $e->getMessage()), array('source' => $this->id) );
			 $order->update_status( 'failed', sprintf(__('Payment failed: %s', 'mastercard'), $e->getMessage()) );
			 wc_add_notice( sprintf(__('Payment failed: %s', 'mastercard'), $e->getMessage()), 'error' );
			 wp_redirect( wc_get_checkout_url() );
			 exit();
		}
	}

	/**
     * MODIFIED: No changes needed here, just used by process_hosted_session_payment
	 * @return array
	 */
	protected function get_token_from_request() {
		// Keep original logic
		$token_key = $this->get_token_key();
		$token_id   = null;
		if ( isset( $_REQUEST[ $token_key ] ) ) {
			$token_id = sanitize_text_field(wp_unslash($_REQUEST[ $token_key ])); // Sanitize input
		}
		$tokens = $this->get_tokens(); // Gets tokens for the current user
		if ( $token_id && isset( $tokens[ $token_id ] ) ) {
             wc_get_logger()->info( sprintf('MPGS: Using token %s for payment.', $token_id), array('source' => $this->id) );
			 return array(
				'token' => $tokens[ $token_id ]->get_token()
			);
		}
         wc_get_logger()->info( 'MPGS: No valid token found in request, proceeding with new card/session data.', array('source' => $this->id) );
		 return array(); // Return empty array if no valid token found
	}

	/**
     * MODIFIED: No changes needed here
	 * @return string
	 */
	protected function get_token_key() {
		// Keep original logic
		return 'wc-' . $this->id . '-payment-token';
	}

	/**
     * MODIFIED: Needs card_type parameter
	 * @param string|null $three_ds_txn_id
     * @param string $card_type
	 *
	 * @throws \Http\Client\Exception
	 */
	protected function process_hosted_session_payment( $three_ds_txn_id = null, $card_type = self::CARD_TYPE_MASTERCARD ) {
        $type_label = ($card_type === self::CARD_TYPE_VISA) ? 'Visa' : 'Mastercard';
        wc_get_logger()->info( sprintf('MPGS Hosted Session: Processing payment for %s. 3DS Txn ID: %s', $type_label, $three_ds_txn_id ?? 'N/A'), array('source' => $this->id) );

        // Ensure required parameters are present
        if (!isset($_REQUEST['order_id']) || !isset($_REQUEST['session_id'])) {
             wc_get_logger()->error('MPGS Hosted Session Error: Missing order_id or session_id in request.', array('source' => $this->id, 'request' => $_REQUEST));
             wc_add_notice( __( 'An error occurred processing the payment session.', 'mastercard' ), 'error' );
             wp_redirect( wc_get_checkout_url() );
             exit();
        }

		$order_id        = $this->remove_order_prefix( sanitize_key($_REQUEST['order_id']) );
		$session_id      = sanitize_text_field($_REQUEST['session_id']);
		$session_version = isset( $_REQUEST['session_version'] ) ? sanitize_text_field($_REQUEST['session_version']) : null;

		$session = array( 'id' => $session_id );
		if ( $session_version !== null ) { // Check specifically for null
			$session['version'] = $session_version;
		}

		$order = wc_get_order( $order_id );
        if (!$order) {
             wc_get_logger()->error( sprintf('MPGS Hosted Session Error: Could not retrieve order %s.', $order_id), array('source' => $this->id) );
             wc_add_notice( __( 'An error occurred while processing your payment. Order not found.', 'mastercard' ), 'error' );
			 wp_redirect( wc_get_checkout_url() );
			 exit();
        }

        // --- NEW: Store selected card type early in the process ---
        $order->update_meta_data('_mpgs_card_type', $card_type);
        $order->save_meta_data();
        // --- END NEW ---

		$check_3ds          = isset( $_REQUEST['check_3ds_enrollment'] ) && $_REQUEST['check_3ds_enrollment'] == '1';
		$process_acs_result = isset( $_REQUEST['process_acs_result'] ) && $_REQUEST['process_acs_result'] == '1';
		$tds_id             = null; // 3DS v1 ID

        // --- MODIFIED: Use dynamic service instance ---
        try {
            $service = $this->get_service_instance($card_type);
        } catch (Exception $e) {
            wc_get_logger()->error( sprintf('MPGS Hosted Session Error getting service instance for Order %s (%s): %s', $order_id, $card_type, $e->getMessage()), array('source' => $this->id) );
            wc_add_notice( $e->getMessage(), 'error' );
            wp_redirect( wc_get_checkout_url() );
            exit();
        }
        // --- END MODIFIED ---

		// --- 3DS v1 Flow ---
		if ($check_3ds) {
			wc_get_logger()->info(sprintf('MPGS Hosted Session (3DSv1): Checking enrollment for Order %s (%s).', $order_id, $type_label), array('source' => $this->id));
			$data = array(
				'authenticationRedirect' => array(
					'pageGenerationMode' => 'CUSTOMIZED',
					'responseUrl' => $this->get_payment_return_url($order_id, array(
						'status' => '3ds_done',
						'card_type' => $card_type
					))
				)
			);
			$orderData = array(
				'amount' => (float)$order->get_total(),
				'currency' => $order->get_currency()
			);
			$source_of_funds = $this->get_token_from_request();

			try {
				$response = $service->check3dsEnrollment($data, $orderData, $session, $source_of_funds);
			} catch (Exception $e) {
				wc_get_logger()->error(sprintf('MPGS Hosted Session (3DSv1) Enrollment Check Failed for Order %s (%s): %s', $order_id, $type_label, $e->getMessage()), array('source' => $this->id));
				wc_add_notice(__('An error occurred during the 3D Secure check. Please try again.', 'mastercard'), 'error');
				wp_redirect(wc_get_checkout_url());
				exit();
			}

			if (!isset($response['response']['gatewayRecommendation']) || $response['response']['gatewayRecommendation'] !== 'PROCEED') {
				wc_get_logger()->error(sprintf('MPGS Hosted Session (3DSv1) Enrollment Check Declined for Order %s (%s). Recommendation: %s', $order_id, $type_label, $response['response']['gatewayRecommendation'] ?? 'N/A'), array('source' => $this->id));
				$order->update_status('failed', __('Payment was declined (3DS enrollment check).', 'mastercard'));
				wc_add_notice(__('Payment was declined by your bank (3DS enrollment check).', 'mastercard'), 'error');
				wp_redirect(wc_get_checkout_url());
				exit();
			}

			if (isset($response['3DSecure']['authenticationRedirect'])) {
				wc_get_logger()->info(sprintf('MPGS Hosted Session (3DSv1): Redirecting to ACS for Order %s (%s). 3DSecureId: %s', $order_id, $type_label, $response['3DSecureId'] ?? 'N/A'), array('source' => $this->id));
				$tds_auth = $response['3DSecure']['authenticationRedirect']['customized'];
				$token_key = $this->get_token_key();

				$return_params = array(
					'3DSecureId' => $response['3DSecureId'],
					'process_acs_result' => '1',
					'session_id' => $session_id,
					'card_type' => $card_type
				);
				if ($session_version !== null) {
					$return_params['session_version'] = $session_version;
				}
				$token_id_from_req = isset($_REQUEST[$token_key]) ? sanitize_text_field(wp_unslash($_REQUEST[$token_key])) : null;
				if ($token_id_from_req) {
					$return_params[$token_key] = $token_id_from_req;
				}

				set_query_var('authenticationRedirect', $tds_auth);
				set_query_var('returnUrl', $this->get_payment_return_url($order_id, $return_params));
				set_query_var('order', $order);
				set_query_var('gateway', $this);
				load_template(dirname(MPGS_PLUGIN_FILE) . '/templates/3dsecure/form.php');
				exit();
			}

			wc_get_logger()->info(sprintf('MPGS Hosted Session (3DSv1): Enrollment check passed, no ACS redirect needed for Order %s (%s). Proceeding to pay.', $order_id, $type_label), array('source' => $this->id));
			$this->pay($session, $order, null, $card_type);
		}

		if ($process_acs_result) {
			if (!isset($_POST['PaRes']) || !isset($_REQUEST['3DSecureId'])) {
				wc_get_logger()->error(sprintf('MPGS Hosted Session (3DSv1) Error: Missing PaRes or 3DSecureId in ACS return for Order %s (%s).', $order_id, $type_label), array('source' => $this->id, 'request' => $_REQUEST));
				wc_add_notice(__('Failed to process the 3D Secure response.', 'mastercard'), 'error');
				wp_redirect(wc_get_checkout_url());
				exit();
			}

			$pa_res = wp_unslash($_POST['PaRes']);
			$tds_id = sanitize_text_field($_REQUEST['3DSecureId']);
			wc_get_logger()->info(sprintf('MPGS Hosted Session (3DSv1): Processing ACS Result for Order %s (%s). 3DSecureId: %s', $order_id, $type_label, $tds_id), array('source' => $this->id));

			try {
				$response = $service->process3dsResult($tds_id, $pa_res);
			} catch (Exception $e) {
				wc_get_logger()->error(sprintf('MPGS Hosted Session (3DSv1) Process ACS Result Failed for Order %s (%s): %s', $order_id, $type_label, $e->getMessage()), array('source' => $this->id));
				wc_add_notice(__('An error occurred validating the 3D Secure response. Please try again.', 'mastercard'), 'error');
				wp_redirect(wc_get_checkout_url());
				exit();
			}

			if (!isset($response['response']['gatewayRecommendation']) || $response['response']['gatewayRecommendation'] !== 'PROCEED') {
				wc_get_logger()->error(sprintf('MPGS Hosted Session (3DSv1) ACS Result Declined for Order %s (%s). Recommendation: %s', $order_id, $type_label, $response['response']['gatewayRecommendation'] ?? 'N/A'), array('source' => $this->id));
				$order->update_status('failed', __('Payment was declined (3DS authentication failed).', 'mastercard'));
				wc_add_notice(__('Payment was declined by your bank (3DS authentication failed).', 'mastercard'), 'error');
				wp_redirect(wc_get_checkout_url());
				exit();
			}

			wc_get_logger()->info(sprintf('MPGS Hosted Session (3DSv1): ACS Result successful for Order %s (%s). Proceeding to pay.', $order_id, $type_label, $tds_id), array('source' => $this->id));
			$this->pay($session, $order, $tds_id, $card_type);
		}

		if ($three_ds_txn_id !== null) {
			wc_get_logger()->info(sprintf('MPGS Hosted Session (3DSv2): Received Proceed for Order %s (%s). Txn ID: %s. Proceeding to pay.', $order_id, $type_label, $three_ds_txn_id), array('source' => $this->id));
			$this->pay($session, $order, $three_ds_txn_id, $card_type);
		}

		if (!$check_3ds && !$process_acs_result && $three_ds_txn_id === null) {
			wc_get_logger()->info(sprintf('MPGS Hosted Session: No 3DS flow triggered or fallback for Order %s (%s). Proceeding to pay.', $order_id, $type_label), array('source' => $this->id));
			$this->pay($session, $order, null, $card_type);
		}

		wc_get_logger()->error(sprintf('MPGS Hosted Session Error: Unexpected condition reached in payment processing for Order %s (%s).', $order_id, $type_label), array('source' => $this->id, 'request' => $_REQUEST));
		$order->update_status('failed', __('Unexpected payment processing error.', 'mastercard'));
		wc_add_notice(__('An unexpected error occurred during payment processing. Please contact support.', 'mastercard'), 'error');
		wp_redirect(wc_get_checkout_url());
		exit();
	}