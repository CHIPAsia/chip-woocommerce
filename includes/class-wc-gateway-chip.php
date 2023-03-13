<?php

class WC_Gateway_Chip extends WC_Payment_Gateway
{
  public $id; // wc_gateway_chip
  protected $secret_key;
  protected $brand_id;
  protected $due_strict;
  protected $due_str_t;
  protected $purchase_sr;
  protected $purchase_tz;
  protected $update_clie;
  protected $system_url_;
  protected $force_token;
  protected $disable_rec;
  protected $payment_met;
  protected $disable_red;
  protected $disable_cal;
  protected $public_key;
  protected $arecuring_p;
  protected $a_payment_m;
  protected $webhook_pub;
  protected $debug;
  
  protected $cached_api;
  protected $cached_payment_method;

  public function __construct() {
    $this->init_id();
    $this->init_icon();
    $this->init_title();
    $this->init_method_title();
    $this->init_method_description();
    $this->init_currency_check();
    $this->init_supports();
    $this->init_has_fields();

    $this->secret_key  = $this->get_option( 'secret_key' );
    $this->brand_id    = $this->get_option( 'brand_id' );
    $this->due_strict  = $this->get_option( 'due_strict', true );
    $this->due_str_t   = $this->get_option( 'due_strict_timing', 60 );
    $this->purchase_sr = $this->get_option( 'purchase_send_receipt', true );
    $this->purchase_tz = $this->get_option( 'purchase_time_zone', 'Asia/Kuala_Lumpur' );
    $this->update_clie = $this->get_option( 'update_client_information' );
    $this->system_url_ = $this->get_option( 'system_url_scheme', 'https' );
    $this->force_token = $this->get_option( 'force_tokenization' );
    $this->disable_rec = $this->get_option( 'disable_recurring_support' );
    $this->payment_met = $this->get_option( 'payment_method_whitelist' );
    $this->disable_red = $this->get_option( 'disable_redirect' );
    $this->disable_cal = $this->get_option( 'disable_callback' );
    $this->debug       = $this->get_option( 'debug' );
    $this->public_key  = $this->get_option( 'public_key' );
    $this->arecuring_p = $this->get_option( 'available_recurring_payment_method' );
    $this->a_payment_m = $this->get_option( 'available_payment_method' );
    $this->description = $this->get_option( 'description' );
    $this->webhook_pub = $this->get_option( 'webhook_public_key' );

    $this->init_form_fields();
    $this->init_settings();
    $this->init_one_time_gateway();
    
    if ( $this->get_option( 'title' ) ) {
      $this->title = $this->get_option( 'title' );  
    }

    if ( $this->get_option( 'method_title' ) ) {
      $this->method_title = $this->get_option( 'method_title' );
    }

    $this->add_actions();
  }

  protected function init_id() {
    $this->id = strtolower( get_class( $this ) );
  }

  protected function init_icon() {
    $logo = $this->get_option( 'display_logo', 'logo' );
    $this->icon = apply_filters( 'wc_' . $this->id . '_load_icon' , plugins_url("assets/{$logo}.png", WC_CHIP_FILE ) );
  }

  protected function init_title() {
    $this->title = __( 'Online Banking / E-Wallet / Credit Card / Debit Card (CHIP)', 'chip-for-woocommerce' );
  }

  protected function init_method_title() {
    if ( $this->id == 'wc_gateway_chip' ) {
      $this->method_title = __('CHIP', 'chip-for-woocommerce');
    } else {
      $this->method_title = sprintf( __( 'CHIP - (%1$s)', 'chip-for-woocommerce'), get_class( $this ) );
    }
  }

  protected function init_method_description() {
    if ( $this->id == 'wc_gateway_chip' ) {
      $this->method_description = __( 'CHIP - Better Payment & Business Solutions', 'chip-for-woocommerce' );
    } else {
      $this->method_description = sprintf( __( 'CHIP - Better Payment & Business Solutions (%1$s)', 'chip-for-woocommerce' ), get_class( $this ) );
    }
  }

  protected function init_currency_check() {
    $woocommerce_currency = get_woocommerce_currency();
    $supported_currencies = apply_filters( 'wc_' . $this->id . '_supported_currencies', array( 'MYR' ), $this );
    
    if ( !in_array( $woocommerce_currency, $supported_currencies, true ) ){
      $this->enabled = 'no';
    }
  }

  protected function init_supports() {
    $supports = array( 'refunds', 'tokenization', 'subscriptions', 'subscription_cancellation',  'subscription_suspension',  'subscription_reactivation', 'subscription_amount_changes', 'subscription_date_changes', 'subscription_payment_method_change', 'subscription_payment_method_change_customer', 'subscription_payment_method_change_admin', 'multiple_subscriptions' );
    $this->supports = array_merge( $this->supports, $supports );
  }

  protected function init_has_fields() {
    $this->has_fields = true;
  }

  protected function init_one_time_gateway() {
    $one_time_gateway = false;

    if ( is_array( $this->payment_met ) AND !empty( $this->payment_met ) ) {
      foreach( [ 'visa', 'mastercard' ] as $card_network ) {
        if ( in_array( $card_network, $this->payment_met ) ) {
          $one_time_gateway = false;
          break;
        }
        $one_time_gateway = true;
      }
    }

    if ( $one_time_gateway OR $this->disable_rec == 'yes' ) {
      $this->supports = [ 'products', 'refunds' ];
    }
  }

  public function add_actions() {
    add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'auto_charge' ), 10, 2);
    add_action( 'woocommerce_api_' . $this->id, array( $this, 'handle_callback' ) );
    add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'change_failing_payment_method' ), 10, 2 );
    add_action( 'admin_notices', array( $this, 'admin_notices' ) );

    // TODO: Delete in future release
    if ( $this->id == 'wc_gateway_chip' ) {
      add_action( 'woocommerce_api_wc_chip_gateway', array( $this, 'handle_callback' ) );
    }
  }

  public function get_icon() {
    $style = apply_filters( 'wc_' . $this->id . '_get_icon_style', 'max-height: 25px; width: auto', $this );
    $icon = '<img class="chip-for-woocommerce-" ' . $this->id . ' src="' . WC_HTTPS::force_https_url( $this->icon ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="' . esc_attr( $style ) . '" />';
    return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
  }

  public function api() {
    if ( !$this->cached_api ) {
      $this->cached_api = new Chip_Woocommerce_API(
        $this->secret_key,
        $this->brand_id,
        new Chip_Woocommerce_Logger(),
        $this->debug
      );
    }

    return $this->cached_api;
  }

  private function log_order_info( $msg, $order ) {
    $this->api()->log_info( $msg . ': ' . $order->get_order_number() );
  }

  public function handle_callback() {
    if ( isset( $_GET['tokenization'] ) AND $_GET['tokenization'] == 'yes' ) {
      $this->handle_callback_token();
    } elseif( isset( $_GET['callback_flag'] ) AND $_GET['callback_flag'] == 'yes' ) {
      $this->handle_callback_event();
    } else {
      $this->handle_callback_order();
    }
  }

  public function handle_callback_token() {
    $payment_id = WC()->session->get( 'chip_preauthorize' );

    if ( !$payment_id && isset($_SERVER['HTTP_X_SIGNATURE']) ) {
      $content = file_get_contents( 'php://input' );

      if ( openssl_verify( $content,  base64_decode( $_SERVER['HTTP_X_SIGNATURE'] ), $this->get_public_key(), 'sha256WithRSAEncryption' ) != 1) {
        $message = __( 'Success callback failed to be processed due to failure in verification.', 'chip-for-woocommerce' );
        $this->log_order_info( $message, $order );
        exit( $message );
      }

      $payment    = json_decode( $content, true );
      $payment_id = array_key_exists( 'id', $payment ) ? sanitize_key( $payment['id'] ) : '';
    } else if ( $payment_id ) {
      $payment = $this->api()->get_payment( $payment_id );
    } else {
      exit( __( 'Unexpected response', 'chip-for-woocommerce' ) );
    }

    if ( $payment['status'] != 'preauthorized' ) {
      wc_add_notice( sprintf( '%1$s %2$s' , __( 'Unable to add payment method to your account.', 'chip-for-woocommerce' ), print_r( $payment['transaction_data']['attempts'][0]['error'], true ) ), 'error' );
      wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
      exit;
    }

    $this->get_lock( $payment_id );

    if ( $this->store_recurring_token( $payment, $payment['reference'] ) ) {
      wc_add_notice( __( 'Payment method successfully added.', 'chip-for-woocommerce' ) );
    } else {
      wc_add_notice( __( 'Unable to add payment method to your account.', 'chip-for-woocommerce' ), 'error' );
    }

    $this->release_lock( $payment_id );

    wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
    exit;
  }

  public function handle_callback_event() {
    if ( !isset($_SERVER['HTTP_X_SIGNATURE']) ) {
      exit;
    }

    $content = file_get_contents( 'php://input' );

    if ( openssl_verify( $content,  base64_decode( $_SERVER['HTTP_X_SIGNATURE'] ), $this->webhook_pub, 'sha256WithRSAEncryption' ) != 1 ) {
      exit;
    }

    $payment = json_decode( $content, true );

    if ( !in_array( $payment['event_type'], array( 'purchase.recurring_token_deleted' ) ) ) {
      exit;
    }

    $user_id = get_user_by( 'email', $payment['client']['email'] )->ID;

    if ( !( $chip_client_id = get_user_meta( $user_id, '_' . $this->id . '_client_id', true ) ) ) {
      exit;
    }

    if ( $chip_client_id != $payment['client_id'] ) {
      exit;
    }

    $chip_token_ids = get_user_meta( $user_id, '_' . $this->id . '_client_token_ids', true );

    if ( !isset( $chip_token_ids[$payment['id']] ) ) {
      exit;
    }

    $token_id = $chip_token_ids[$payment['id']];

    WC_Payment_Tokens::delete( $token_id );

    exit;
  }

  public function handle_callback_order() {
    $order_id = intval( $_GET['id'] );

    $this->api()->log_info( 'received callback for order id: ' . $order_id );

    $this->get_lock( $order_id );

    $order = new WC_Order( $order_id );

    $this->log_order_info( 'received success callback', $order );

    $payment_id = WC()->session->get( 'chip_payment_id_' . $order_id );
    if ( !$payment_id AND isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
      $content = file_get_contents( 'php://input' );

      if ( openssl_verify( $content,  base64_decode( $_SERVER['HTTP_X_SIGNATURE'] ), $this->get_public_key(), 'sha256WithRSAEncryption' ) != 1 ) {
        $message = __( 'Success callback failed to be processed due to failure in verification.', 'chip-for-woocommerce' );
        $this->log_order_info( $message, $order );
        exit( $message );
      }

      $payment    = json_decode( $content, true );
      $payment_id = array_key_exists( 'id', $payment ) ? sanitize_key( $payment['id'] ) : '';
    } else if ( $payment_id ) {
      $payment = $this->api()->get_payment( $payment_id );
    } else {
      exit( __( 'Unexpected response', 'chip-for-woocommerce' ) );
    }

    if ( $payment['status'] == 'paid' ) {
      if ( !$order->is_paid() ) {
        $this->payment_complete( $order, $payment );
      }
      WC()->cart->empty_cart();

      $this->log_order_info( 'payment processed', $order );
    } else {
      if ( !$order->is_paid() ) {
        if ( !empty( $payment['transaction_data']['attempts'] ) AND !empty( $payment_extra = $payment['transaction_data']['attempts'][0]['extra'] ) ) {
          if ( isset($payment_extra['payload']) AND isset($payment_extra['payload']['fpx_debitAuthCode']) ) {
            $debit_auth_code = $payment_extra['payload']['fpx_debitAuthCode'][0];
            $fpx_txn_id = $payment_extra['payload']['fpx_fpxTxnId'][0];
            $fpx_seller_order_no = $payment_extra['payload']['fpx_sellerOrderNo'][0];

            $order->add_order_note(
              sprintf( __( 'FPX Debit Auth Code: %1$s. FPX Transaction ID: %2$s. FPX Seller Order Number: %3$s.','chip-for-woocommerce' ), $debit_auth_code, $fpx_txn_id, $fpx_seller_order_no )
            );
          }
        }

        $order->update_status(
          'wc-failed'
        );
        $this->log_order_info( 'payment not successful', $order );
      }
    }

    $this->release_lock( $order_id );

    wp_safe_redirect( $this->get_return_url( $order ) );
    exit;
  }

  public function init_form_fields() {
    $this->form_fields['enabled'] = array(
      'title'   => __( 'Enable/Disable', 'chip-for-woocommerce' ),
      'label'   => sprintf( '%1$s %2$s', __( 'Enable', 'chip-for-woocommerce' ), $this->method_title ),
      'type'    => 'checkbox',
      'default' => 'no',
    );

    $this->form_fields['title'] = array(
      'title'       => __( 'Title', 'chip-for-woocommerce' ),
      'type'        => 'text',
      'description' => __( 'This controls the title which the user sees during checkout.', 'chip-for-woocommerce' ),
      'default'     => __( 'Online Banking / E-Wallet / Credit Card / Debit Card (CHIP)', 'chip-for-woocommerce' ),
    );

    $this->form_fields['method_title'] = array(
      'title'       => __( 'Method Title', 'chip-for-woocommerce' ),
      'type'        => 'text',
      'description' => __( 'This controls the title in WooCommerce Admin.', 'chip-for-woocommerce' ),
      'default'     => $this->method_title,
    );

    $this->form_fields['description'] = array(
      'title'       => __( 'Description', 'chip-for-woocommerce' ),
      'type'        => 'text',
      'description' => __( 'This controls the description which the user sees during checkout.', 'chip-for-woocommerce' ),
      'default'     => __( 'Pay with Online Banking / E-Wallet / Credit Card / Debit Card. You will choose your payment option on the next page', 'chip-for-woocommerce' ),
    );

    $this->form_fields['credentials'] = array(
      'title'       => __( 'Credentials', 'chip-for-woocommerce' ),
      'type'        => 'title',
      'description' => __( 'Options to set Brand ID and Secret Key.', 'chip-for-woocommerce' ),
    );

    $this->form_fields['brand_id'] = array(
      'title'       => __( 'Brand ID', 'chip-for-woocommerce' ),
      'type'        => 'text',
      'description' => __( 'Brand ID can be obtained from CHIP Collect Dashboard >> Developers >> Brands', 'chip-for-woocommerce' ),
    );

    $this->form_fields['secret_key'] = array(
      'title'       => __( 'Secret key', 'chip-for-woocommerce' ),
      'type'        => 'text',
      'description' => __( 'Secret key can be obtained from CHIP Collect Dashboard >> Developers >> Keys', 'chip-for-woocommerce' ),
    );

    $this->form_fields['miscellaneous'] = array(
      'title'       => __( 'Miscellaneous', 'chip-for-woocommerce' ),
      'type'        => 'title',
      'description' => __( 'Options to set display logo, due strict, send receipt, time zone, tokenization and payment method whitelist.', 'chip-for-woocommerce' ),
    );

    $this->form_fields['display_logo'] = array(
      'title'       => __( 'Display Logo', 'chip-for-woocommerce' ),
      'type'        => 'select',
      'class'       => 'wc-enhanced-select',
      'description' => sprintf(__('This controls which logo appeared on checkout page. <a target="_blank" href="%s">Logo</a>. <a target="_blank" href="%s">FPX B2C</a>. <a target="_blank" href="%s">FPX B2B1</a>. <a target="_blank" href="%s">E-Wallet</a>. <a target="_blank" href="%s">Card</a>.', 'bfw' ), WC_CHIP_URL.'assets/logo.png', WC_CHIP_URL.'assets/fpx.png', WC_CHIP_URL.'assets/fpx_b2b1.png', WC_CHIP_URL.'assets/ewallet.png', WC_CHIP_URL.'assets/card.png' ),
      'default'     => 'logo',
      'options'     => array(
        'logo'     => 'Logo',
        'fpx'      => 'FPX B2C',
        'fpx_b2b1' => 'FPX B2B1',
        'ewallet'  => 'E-Wallet',
        'card'     => 'Card',
      ),
    );

    $this->form_fields['due_strict'] = array(
      'title'       => __( 'Due Strict', 'chip-for-woocommerce' ),
      'type'        => 'checkbox',
      'description' => __( 'Enforce due strict payment timeframe to block payment after due strict timing is passed.', 'chip-for-woocommerce' ),
      'default'     => 'yes',
    );

    $this->form_fields['due_strict_timing'] = array(
      'title'       => __( 'Due Strict Timing (minutes)', 'chip-for-woocommerce' ),
      'type'        => 'number',
      'description' => sprintf( __( 'Due strict timing in minutes. Default to hold stock minutes: <code>%1$s</code>. This will only be enforced if Due Strict option is activated.', 'chip-for-woocommerce' ), get_option( 'woocommerce_hold_stock_minutes', '60' ) ),
      'default'     => get_option( 'woocommerce_hold_stock_minutes', '60' ),
    );

    $this->form_fields['purchase_send_receipt'] = array(
      'title'       => __( 'Purchase Send Receipt', 'chip-for-woocommerce' ),
      'type'        => 'checkbox',
      'description' => __( 'Tick to ask CHIP to send receipt upon successful payment. If activated, CHIP will send purchase receipt upon payment completion.', 'chip-for-woocommerce' ),
      'default'     => 'yes',
    );

    $this->form_fields['purchase_time_zone'] = array(
      'title'       => __( 'Purchase Time Zone', 'chip-for-woocommerce' ),
      'type'        => 'select',
      'class'       => 'wc-enhanced-select',
      'description' => __( 'Time zone setting for receipt page.', 'chip-for-woocommerce' ),
      'default'     => 'Asia/Kuala_Lumpur',
      'options'     => $this->get_timezone_list()
    );

    $this->form_fields['update_client_information'] = array(
      'title'       => __( 'Update client information', 'chip-for-woocommerce' ),
      'type'        => 'checkbox',
      'description' => __( 'Tick to update client information on purchase creation.', 'chip-for-woocommerce' ),
      'default'     => 'no',
    );

    $this->form_fields['system_url_scheme'] = array(
      'title'       => __( 'System URL Scheme', 'chip-for-woocommerce' ),
      'type'        => 'select',
      'class'       => 'wc-enhanced-select',
      'description' => __( 'Choose https if you are facing issue with payment status update due to http to https redirection', 'chip-for-woocommerce' ),
      'default'     => 'https',
      'options'     => array(
        'default' => __( 'System Default', 'chip-for-woocommerce' ),
        'https'   => __( 'HTTPS', 'chip-for-woocommerce' ),
      )
    );

    $this->form_fields['disable_recurring_support'] = array(
      'title'       => __( 'Disable card recurring support', 'chip-for-woocommerce' ),
      'type'        => 'checkbox',
      'description' =>__( 'Tick to disable card recurring support.', 'chip-for-woocommerce' ),
      'default'     => 'no',
    );

    $this->form_fields['force_tokenization'] = array(
      'title'       => __( 'Force Tokenization', 'chip-for-woocommerce' ),
      'type'        => 'checkbox',
      'description' =>__( 'Tick to force tokenization if possible.', 'chip-for-woocommerce' ),
      'default'     => 'no',
      'disabled'    => empty( $this->arecuring_p )
    );

    $this->form_fields['payment_method_whitelist'] = array(
      'title'       => __( 'Payment Method Whitelist', 'chip-for-woocommerce' ),
      'type'        => 'multiselect',
      'class'       => 'wc-enhanced-select',
      'description' => __( 'Choose payment method to enforce payment method whitelisting if possible.', 'chip-for-woocommerce' ),
      'options'     => $this->a_payment_m,
      'disabled'    => empty( $this->a_payment_m )
    );

    $this->form_fields['public_key'] = array(
      'title'       => __( 'Public Key', 'chip-for-woocommerce' ),
      'type'        => 'textarea',
      'description' => __( 'Public key for validating callback will be auto-filled upon successful configuration.', 'chip-for-woocommerce' ),
      'disabled'    => true,
    );

    $this->form_fields['webhooks'] = array(
      'title'       => __( 'Webhooks', 'chip-for-woocommerce' ),
      'type'        => 'title',
      'description' => sprintf( __( 'Option to set public key. The supported event is <code>%1$s</code>', 'chip-for-woocommerce' ), 'Purchase Recurring Token Deleted' ),
    );

    $callback_url = preg_replace( "/^http:/i", "https:", add_query_arg( [ 'callback_flag' => 'yes' ], WC()->api_request_url( $this->id ) ) );

    $this->form_fields['webhook_public_key'] = array(
      'title'       => __( 'Public Key', 'chip-for-woocommerce' ),
      'type'        => 'textarea',
      'description' => sprintf( __( 'This option to set public key that are generated through CHIP Dashboard >> Webhooks page. The callback url is: <code>%s</code>', 'chip-for-woocommerce' ), $callback_url ),
    );

    $this->form_fields['troubleshooting'] = array(
      'title'       => __( 'Troubleshooting', 'chip-for-woocommerce' ),
      'type'        => 'title',
      'description' => __( 'Options to disable redirect, disable callback and turn on debugging.', 'chip-for-woocommerce' ),
    );

    $this->form_fields['disable_redirect'] = array(
      'title'       => __( 'Disable Redirect', 'chip-for-woocommerce' ),
      'type'        => 'checkbox',
      'description' => __( 'Disable redirect for troubleshooting purpose.', 'chip-for-woocommerce' ),
    );

    $this->form_fields['disable_callback'] = array(
      'title'       => __( 'Disable Callback', 'chip-for-woocommerce' ),
      'type'        => 'checkbox',
      'description' => __( 'Disable callback for troubleshooting purpose.', 'chip-for-woocommerce' ),
    );

    $this->form_fields['debug'] = array(
      'title'       => __( 'Debug Log', 'chip-for-woocommerce' ),
      'type'        => 'checkbox',
      'label'       => __( 'Enable logging', 'chip-for-woocommerce' ),
      'default'     => 'no',
      'description' =>
        sprintf( __( 'Log events to <code>%s</code>', 'chip-for-woocommerce' ), wc_get_log_file_path( $this->id ) ),
    );
  }

  private function get_timezone_list() {
    $list_time_zones = DateTimeZone::listIdentifiers( DateTimeZone::ALL );

    $formatted_time_zones = array();
    foreach ( $list_time_zones as $mtz ) {
      $formatted_time_zones[$mtz] = str_replace( "_"," ",$mtz );;
    }
    
    return $formatted_time_zones;
  }

  public function payment_fields() {
    if ( has_action( 'wc_' . $this->id . '_payment_fields' ) ) {
      do_action( 'wc_' . $this->id . '_payment_fields', $this );
    } elseif ( $this->supports( 'tokenization' ) && is_checkout() ) {
      if ( !empty( $description = $this->get_description() ) ) {
        echo wpautop( wptexturize( $description ) );
      }
      $this->tokenization_script();
      $this->saved_payment_methods();
      $this->save_payment_method_checkbox();
    } else {
      parent::payment_fields();
    }
  }

  public function get_language() {
    if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
      $ln = ICL_LANGUAGE_CODE;
    } else {
      $ln = get_locale();
    }
    switch ( $ln ) {
    case 'et_EE':
      $ln = 'et';
      break;
    case 'ru_RU':
      $ln = 'ru';
      break;
    case 'lt_LT':
      $ln = 'lt';
      break;
    case 'lv_LV':
      $ln = 'lv';
      break;
    case 'et':
    case 'lt':
    case 'lv':
    case 'ru':
      break;
    default:
      $ln = 'en';
    }
    return $ln;
  }

  public function process_payment( $order_id ) {
    $order = new WC_Order( $order_id );
    
    $callback_url  = add_query_arg( [ 'id' => $order_id ], WC()->api_request_url( $this->id ) );
    if ( defined( 'WC_CHIP_OLD_URL_SCHEME' ) AND WC_CHIP_OLD_URL_SCHEME ) {
      $callback_url = home_url( '/?wc-api=' . get_class( $this ). '&id=' . $order_id );
    }

    $params = [
      'success_callback' => $callback_url,
      'success_redirect' => $callback_url,
      'failure_redirect' => $callback_url,
      'cancel_redirect'  => $callback_url,
      'force_recurring'  => $this->force_token == 'yes',
      'send_receipt'     => $this->purchase_sr == 'yes',
      'creator_agent'    => 'WooCommerce: ' . WC_CHIP_MODULE_VERSION,
      'reference'        => $order->get_id(),
      'platform'         => 'woocommerce',
      'due'              => $this->get_due_timestamp(),
      'purchase' => [
        'total_override' => round( $order->get_total() * 100 ),
        'due_strict'     => $this->due_strict == 'yes',
        'timezone'       => $this->purchase_tz,
        'currency'       => $order->get_currency(),
        'language'       => $this->get_language(),
        'products'       => [],
      ],
      'brand_id' => $this->brand_id,
      'client' => [
        'email'                   => $order->get_billing_email(),
        'phone'                   => substr( $order->get_billing_phone(), 0, 32 ),
        'full_name'               => substr( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), 0 , 128 ),
        'street_address'          => substr( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(), 0, 128 ) ,
        'country'                 => substr( $order->get_billing_country(), 0, 2 ),
        'city'                    => substr( $order->get_billing_city(), 0, 128 ) ,
        'zip_code'                => substr( $order->get_shipping_postcode(), 0, 32 ),
        'shipping_street_address' => substr( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2(), 0, 128 ) ,
        'shipping_country'        => substr( $order->get_shipping_country(), 0, 2 ),
        'shipping_city'           => substr( $order->get_shipping_city(), 0, 128 ),
        'shipping_zip_code'       => substr( $order->get_shipping_postcode(), 0, 32 ),
      ],
    ];

    $items = $order->get_items();

    foreach ( $items as $item ) {
      $params['purchase']['products'][] = array(
        'name'     => substr( $item->get_name(), 0, 256 ),
        'price'    => round( $item->get_total() * 100 ),
      );
    }

    $chip = $this->api();

    if ( is_user_logged_in() ) {
      $params['client']['email'] = wp_get_current_user()->user_email;
      $client_with_params = $params['client'];
      $old_client_records = true;
      unset( $params['client'] );

      $params['client_id'] = get_user_meta( $order->get_user_id(), '_' . $this->id . '_client_id', true );

      if ( empty( $params['client_id'] ) ) {
        $get_client = $chip->get_client_by_email( $client_with_params['email'] );

        if ( array_key_exists( '__all__', $get_client ) ) {
          return array(
            'result' => 'failure',
          );
        }

        if ( is_array($get_client['results']) AND !empty( $get_client['results'] ) ) {
          $client = $get_client['results'][0];
        } else {
          $old_client_records = false;
          $client = $chip->create_client( $client_with_params );
        }

        update_user_meta( $order->get_user_id(), '_' . $this->id . '_client_id', $client['id'] );

        $params['client_id'] = $client['id'];
      }

      if ( $this->update_clie == 'yes' AND $old_client_records ) {
        $chip->patch_client( $params['client_id'], $client_with_params );
      }
    }

    if ( is_array( $this->payment_met ) AND !empty( $this->payment_met ) ) {
      $params['payment_method_whitelist'] = $this->payment_met;
    }

    if ( isset( $_POST["wc-{$this->id}-new-payment-method"] ) AND in_array( $_POST["wc-{$this->id}-new-payment-method"], [ 'true', 1 ] ) ) {
      $params['payment_method_whitelist'] = ['visa', 'mastercard'];
      $params['force_recurring'] = true;
    }

    if ( $this->system_url_ == 'https' ) {
      $params['success_callback'] = preg_replace( "/^http:/i", "https:", $params['success_callback'] );
    }

    if ( $this->disable_cal == 'yes' ) {
      unset( $params['success_callback'] );
    }

    if ( $this->disable_red == 'yes' ) {
      unset( $params['success_redirect'] );
    }

    if ( !empty( $order->get_customer_note() ) ) {
      $params['purchase']['notes'] = substr( $order->get_customer_note(), 0, 10000 );
    }

    $params = apply_filters( 'wc_' . $this->id . '_purchase_params', $params, $this );

    $payment = $chip->create_payment( $params );

    if ( !array_key_exists( 'id', $payment ) ) {
      $this->log_order_info('create payment failed. message: ' . print_r( $payment, true ), $order );
      return array(
        'result' => 'failure',
      );
    }
    
    WC()->session->set( 'chip_payment_id_' . $order_id, $payment['id'] );
    
    $this->log_order_info('got checkout url, redirecting', $order);

    $payment_requery_status = 'due';

    if ( isset( $_POST["wc-{$this->id}-payment-token"] ) AND 'new' !== $_POST["wc-{$this->id}-payment-token"] ) {
      $token_id = wc_clean( $_POST["wc-{$this->id}-payment-token"] );

      if ( $token = WC_Payment_Tokens::get( $token_id ) ) {
        if ( $token->get_user_id() !== get_current_user_id() ) {
          return array( 'result' => 'failure' );
        }

        $this->add_payment_token( $order->get_id(), $token );

        $chip->charge_payment( $payment['id'], array( 'recurring_token' => $token->get_token() ) );

        $get_payment = $chip->get_payment( $payment['id'] );
        $payment_requery_status = $get_payment['status'];
      }
    }

    $order->update_meta_data( '_' . $this->id . '_purchase', $payment );
    $order->save();

    if ( $payment_requery_status != 'paid' ) {
      $this->schedule_requery( $payment['id'], $order_id );
    }
    
    return array(
      'result' => 'success',
      'redirect' => esc_url( $payment['checkout_url'] ),
    );
  }

  public function get_due_timestamp() {
    $due_strict_timing = $this->due_str_t;
    if ( empty( $this->due_str_t ) ) {
      $due_strict_timing = 60;
    }
    return time() + ( absint ( $due_strict_timing ) * 60 );
  }

  public function can_refund_order( $order ) {
    $has_api_creds    = $this->enabled AND $this->secret_key AND $this->brand_id;
    $can_refund_order = $order AND $order->get_transaction_id() AND $has_api_creds;
    
    return apply_filters( 'wc_' . $this->id . '_can_refund_order', $can_refund_order, $order, $this );
  }

  public function process_refund( $order_id, $amount = null, $reason = '' ) {
    $order = wc_get_order( $order_id );

    if ( ! $this->can_refund_order( $order ) ) {
      $this->log_order_info( 'Cannot refund order', $order );
      return new WP_Error( 'error', __( 'Refund failed.', 'chip-for-woocommerce' ) );
    }

    $chip = $this->api();
    $params = [ 'amount' => round( $amount * 100 ) ];

    $result = $chip->refund_payment( $order->get_transaction_id(), $params );
    
    if ( is_wp_error( $result ) || isset( $result['__all__'] ) ) {
      $chip->log_error( var_export( $result['__all__'], true ) . ': ' . $order->get_order_number() );
      return new WP_Error( 'error', var_export( $result['__all__'], true ) );
    }

    $this->log_order_info( 'Refund Result: ' . wc_print_r( $result, true ), $order );
    switch ( strtolower( $result['status'] ?? 'failed' ) ) {
      case 'success':
        $refund_amount = round($result['payment']['amount'] / 100, 2) . $result['payment']['currency'];
        $order->add_order_note(
            sprintf( __( 'Refunded %1$s - Refund ID: %2$s', 'chip-for-woocommerce' ), $refund_amount, $result['id'] )
        );
        return true;
    }
    
    return true;
  }

  public function get_public_key() {
    if ( empty( $this->public_key ) ){
      $this->public_key = str_replace( '\n', "\n", $this->api()->public_key() );
      $this->update_option( 'public_key', $this->public_key );
    }

    return $this->public_key;
  }

  public function process_admin_options() {
    parent::process_admin_options();
    $post  = $this->get_post_data();
    
    $brand_id   = wc_clean( $post["woocommerce_{$this->id}_brand_id"] );
    $secret_key = wc_clean( $post["woocommerce_{$this->id}_secret_key"] );

    $chip = $this->api();
    $chip->set_key( $secret_key, $brand_id );
    $public_key = $chip->public_key();

    if ( is_array( $public_key ) ) {
      $this->add_error( sprintf( __( 'Configuration error: %1$s', 'chip-for-woocommerce' ), current( $public_key['__all__'] )['message'] ) );
      $this->update_option( 'public_key', '' );
      $this->update_option( 'available_payment_method', array() );
      $this->update_option( 'available_recurring_payment_method', array() );
      return false;
    }

    $public_key = str_replace( '\n', "\n", $public_key );

    $get_available_payment_method = $chip->payment_methods( get_woocommerce_currency(), $this->get_language(), 200 );

    if ( !array_key_exists( 'available_payment_methods', $get_available_payment_method ) OR empty( $get_available_payment_method['available_payment_methods'] ) ) {
      $this->add_error( sprintf( __( 'Configuration error: No payment method available for the Brand ID: %1$s', 'chip-for-woocommerce' ), $brand_id ) );
      $this->update_option( 'public_key', '' );
      $this->update_option( 'available_payment_method', array() );
      $this->update_option( 'available_recurring_payment_method', array() );
      return false;
    }

    $available_payment_method = array();
    $available_recurring_payment_method = array();

    $get_available_recurring_payment_method = $chip->payment_recurring_methods( get_woocommerce_currency(), $this->get_language(), 200 );

    foreach( $get_available_payment_method['available_payment_methods'] as $apm ) {
      $available_payment_method[$apm] = ucwords( str_replace( '_', ' ', $apm == 'razer' ? 'e-Wallet' : $apm ) );
    }

    foreach( $get_available_recurring_payment_method['available_payment_methods'] as $apm ) {
      $available_recurring_payment_method[$apm] = ucwords( str_replace( '_', ' ', $apm ) );
    }

    $this->update_option( 'public_key', $public_key );
    $this->update_option( 'available_payment_method', $available_payment_method );
    $this->update_option( 'available_recurring_payment_method', $available_recurring_payment_method );

    $webhook_public_key = $post["woocommerce_{$this->id}_webhook_public_key"];

    if ( !empty( $webhook_public_key ) ) {
      $webhook_public_key = str_replace( '\n', "\n", $webhook_public_key );

      if ( !openssl_pkey_get_public( $webhook_public_key ) ) {
        $this->add_error( __( 'Configuration error: Webhook Public Key is invalid format', 'chip-for-woocommerce' ) );
        $this->update_option( 'webhook_public_key', '' );
      }
    }

    return true;
  }

  public function auto_charge( $total_amount, $renewal_order ) {
    $renewal_order_id = $renewal_order->get_id();
    if ( empty( $tokens = WC_Payment_Tokens::get_order_tokens( $renewal_order_id ) ) ) {
      $renewal_order->update_status( 'failed' );
      $renewal_order->add_order_note( __( 'No card token available to charge.', 'chip-for-woocommerce' ) );
      return;
    }

    $callback_url = add_query_arg( [ 'id' => $renewal_order_id ], WC()->api_request_url( $this->id ) );
    if ( defined( 'WC_CHIP_OLD_URL_SCHEME' ) AND WC_CHIP_OLD_URL_SCHEME ) {
      $callback_url = home_url( '/?wc-api=' . get_class( $this ). '&id=' . $renewal_order_id );
    }

    $params = [
      'success_callback' => $callback_url,
      'send_receipt'     => $this->purchase_sr == 'yes',
      'creator_agent'    => 'WooCommerce: ' . WC_CHIP_MODULE_VERSION,
      'reference'        => $renewal_order_id,
      'platform'         => 'woocommerce_subscriptions',
      'due'              => $this->get_due_timestamp(),
      'brand_id'         => $this->brand_id,
      'client_id'        => get_user_meta( $renewal_order->get_user_id(), '_' . $this->id . '_client_id', true ),
      'purchase' => [
        'timezone'   => $this->purchase_tz,
        'currency'   => $renewal_order->get_currency(),
        'language'   => $this->get_language(),
        'due_strict' => $this->due_strict == 'yes',
        'total_override' => round( $total_amount * 100 ),
        'products'   => [],
      ],
    ];

    $items = $renewal_order->get_items();

    foreach ( $items as $item ) {
      $params['purchase']['products'][] = array(
        'name'     => substr( $item->get_name(), 0, 256 ),
        'price'    => round( $item->get_total() * 100 ),
      );
    }

    $chip    = $this->api();
    $payment = $chip->create_payment( $params );

    $token = new WC_Payment_Token_CC;
    foreach ( $tokens as $key => $t ) {
      if ( $t->get_gateway_id() == $this->id ) {
        $token = $t;
        break;
      }
    }

    $this->get_lock( $renewal_order_id );

    $charge_payment = $chip->charge_payment( $payment['id'], array( 'recurring_token' => $token->get_token() ) );

    if ( array_key_exists( '__all__', $charge_payment ) ){
      $renewal_order->update_status( 'failed' );
      $renewal_order->add_order_note( sprintf( __( 'Automatic charge attempt failed. Details: %1$s', 'chip-for-woocommerce' ), var_export( $charge_payment, true ) ) );
    } elseif ( $charge_payment['status'] == 'paid' ) {
      $this->payment_complete( $renewal_order, $charge_payment );
      $renewal_order->add_order_note( sprintf( __( 'Payment Successful by tokenization. Transaction ID: %s', 'chip-for-woocommerce' ), $payment['id'] ) );
    } elseif ( $charge_payment['status'] == 'pending_charge' ) {
      $renewal_order->update_status( 'on-hold' );
    } else {
      $renewal_order->update_status( 'failed' );
      $renewal_order->add_order_note( __( 'Automatic charge attempt failed.', 'chip-for-woocommerce' ) );
    }

    $this->release_lock( $renewal_order_id );
  }

  public function get_lock( $order_id ) {
    $GLOBALS['wpdb']->get_results( "SELECT GET_LOCK('chip_payment_$order_id', 15);" );
  }

  public function release_lock( $order_id ) {
    $GLOBALS['wpdb']->get_results( "SELECT RELEASE_LOCK('chip_payment_$order_id');" );
  }

  public function store_recurring_token( $payment, $user_id ) {
    $chip_token_ids = get_user_meta( $user_id, '_' . $this->id . '_client_token_ids', true );

    if ( is_string( $chip_token_ids ) ) {
      $chip_token_ids = array();
    }

    $chip_tokenized_purchase_id = $payment['id'];

    if ( !$payment['is_recurring_token'] ) {
      $chip_tokenized_purchase_id = $payment['recurring_token'];
    }

    foreach( $chip_token_ids as $purchase_id => $token_id ) {
      if ( $purchase_id == $chip_tokenized_purchase_id AND ( $wc_payment_token = WC_Payment_Tokens::get( $token_id ) ) ) {
        return $wc_payment_token;
      }
    }

    $token = new WC_Payment_Token_CC();
    $token->set_token( $chip_tokenized_purchase_id );
    $token->set_gateway_id( $this->id );
    $token->set_card_type( $payment['transaction_data']['extra']['card_brand'] );
    $token->set_last4( substr( $payment['transaction_data']['extra']['masked_pan'], -4 ) );
    $token->set_expiry_month( $payment['transaction_data']['extra']['expiry_month'] );
    $token->set_expiry_year( '20' . $payment['transaction_data']['extra']['expiry_year'] );
    $token->set_user_id( $user_id );

    /**
     * Store optional card data for later use-case
     */
    $token->add_meta_data( 'cardholder_name', $payment['transaction_data']['extra']['cardholder_name'] );
    $token->add_meta_data( 'card_issuer_country', $payment['transaction_data']['extra']['card_issuer_country'] );
    $token->add_meta_data( 'masked_pan', $payment['transaction_data']['extra']['masked_pan'] );
    $token->add_meta_data( 'card_type', $payment['transaction_data']['extra']['card_type'] );
    if ( $token->save() ) {
      $chip_token_ids[$chip_tokenized_purchase_id] = $token->get_id();
      update_user_meta( $user_id, '_' . $this->id . '_client_token_ids', $chip_token_ids );
      return $token;
    }
    return false;
  }

  public function add_payment_method() {
    $customer = new WC_Customer( get_current_user_id() );

    $url  = add_query_arg(
      array(
        'tokenization' => 'yes',
      ),
      WC()->api_request_url( $this->id )
    );

    $params = array(
      'payment_method_whitelist' => ['mastercard', 'visa'],
      'success_callback' => $url . '&action=success',
      'success_redirect' => $url . '&action=success',
      'failure_redirect' => $url . '&action=failed',
      'force_recurring'  => true,
      'reference'        => get_current_user_id(),
      'brand_id'         => $this->brand_id,
      'skip_capture'     => true,
      'client' => [
        'email'     => wp_get_current_user()->user_email,
        'full_name' => substr( $customer->get_first_name() . ' ' . $customer->get_last_name(), 0 , 128 )
      ],
      'purchase' => [
        'currency' => 'MYR',
        'products' => [
          [
            'name'  => 'Add payment method',
            'price' => 0
          ]
        ]
      ],
    );

    $chip = $this->api();

    $params['client_id'] = get_user_meta( get_current_user_id(), '_' . $this->id . '_client_id', true );

    if ( empty( $params['client_id'] ) ) {
      $get_client = $chip->get_client_by_email( $params['client']['email'] );

      if ( array_key_exists( '__all__', $get_client ) ) {
        return array(
          'result' => 'failure',
        );
      }

      if ( is_array($get_client['results']) AND !empty( $get_client['results'] ) ) {
        $client = $get_client['results'][0];

      } else {
        $client = $chip->create_client( $params['client'] );
      }

      update_user_meta( get_current_user_id(), '_' . $this->id . '_client_id', $client['id'] );

      $params['client_id'] = $client['id'];
    }

    unset( $params['client'] );

    if ( $this->system_url_ == 'https' ) {
      $params['success_callback'] = preg_replace( "/^http:/i", "https:", $params['success_callback'] );
    }

    if ( $this->disable_cal == 'yes' ) {
      unset( $params['success_callback'] );
    }

    if ( $this->disable_red == 'yes' ) {
      unset( $params['success_redirect'] );
    }

    $payment = $chip->create_payment( $params );

    WC()->session->set( 'chip_preauthorize', $payment['id'] );

    return array(
      'result'   => 'redirect',
      'redirect' => $payment['checkout_url'],
    );
  }

  public function add_payment_token( $order_id, $token ) {
    $data_store = WC_Data_Store::load( 'order' );

    $order = new WC_Order( $order_id );
    $data_store->update_payment_token_ids( $order, array() );
    $order->add_payment_token( $token );

    if ( class_exists( 'WC_Subscriptions' ) ) {
      $subscriptions = wcs_get_subscriptions_for_order( $order_id );

      foreach ( $subscriptions as $subscription ) {
        $data_store->update_payment_token_ids( $subscription, array() );

        $subscription->add_payment_token( $token );
      }
    }
  }

  public function change_failing_payment_method( $subscription, $renewal_order ) {
    $token_ids = $renewal_order->get_payment_tokens();

    if ( empty( $token_ids ) ) {
      return;
    }

    $token = WC_Payment_Tokens::get( current( $token_ids ) );

    if ( empty( $token ) ) {
      return;
    }

    $data_store = WC_Data_Store::load( 'order' );
    $data_store->update_payment_token_ids( $subscription, array() );
    $subscription->add_payment_token( $token );
  }

  public function payment_complete( $order, $payment ) {
    if ( $payment['is_recurring_token'] OR !empty( $payment['recurring_token'] ) ) {
      $token = $this->store_recurring_token( $payment, $order->get_user_id() );

      $this->add_payment_token( $order->get_id(), $token );
    }

    $order->payment_complete( $payment['id'] );
    $order->update_meta_data( '_' . $this->id . '_purchase', $payment );
    $order->save();
    $order->add_order_note( sprintf( __( 'Payment Successful. Transaction ID: %s', 'chip-for-woocommerce' ), $payment['id'] ) );

    if ( $payment['is_test'] == true ) {
      $order->add_order_note( sprintf( __( 'The payment (%s) made in test mode where it does not involve real payment.', 'chip-for-woocommerce' ), $payment['id'] ) );
    }
  }

  public function schedule_requery( $purchase_id, $order_id, $attempt = 1 ) {
    WC()->queue()->schedule_single( time() + $attempt * HOUR_IN_SECONDS , 'wc_chip_check_order_status', array( $purchase_id, $order_id, $attempt, $this->id ), "{$this->id}_single_requery" );
  }

  public function payment_token_deleted( $token_id, $token ) {
    $user_id = $token->get_user_id();
    $token_id = $token->get_id();
    $payment_id = $token->get_token();

    $chip_token_ids = get_user_meta( $user_id, '_' . $this->id . '_client_token_ids', true );

    if ( is_string( $chip_token_ids ) ) {
      $chip_token_ids = array();
    }

    foreach( $chip_token_ids as $purchase_id => $c_token_id ) {
      if ( $token_id == $c_token_id ) {
        unset( $chip_token_ids[$payment_id] );
        update_user_meta( $user_id, '_' . $this->id . '_client_token_ids', $chip_token_ids );
        break;
      }
    }

    WC()->queue()->schedule_single( time(), 'wc_chip_delete_payment_token', array( $token->get_token(), $this->id ), "{$this->id}_delete_token" );
  }

  public function delete_payment_token( $purchase_id ) {
    $this->api()->delete_token( $purchase_id );
  }

  public function check_order_status( $purchase_id, $order_id, $attempt ) {
    $this->get_lock( $order_id );

    try {
      $order = new WC_Order( $order_id );
    } catch (Exception $e) {
      $this->release_lock( $order_id );
      return;
    }

    if ( $order->is_paid() ) {
      $this->release_lock( $order_id );
      return;
    }

    $chip = $this->api();

    $payment = $chip->get_payment( $purchase_id );

    if ( $payment['status'] == 'paid' ){
      $this->payment_complete( $order, $payment );
      $this->release_lock( $order_id );
      return;
    }

    $order->add_order_note( sprintf( __( 'Order status checked and the status is %1$s', 'chip-for-woocommerce' ), $payment['status'] ) );

    if ( $attempt < 8 ) {
      $this->schedule_requery( $purchase_id, $order_id, ++$attempt );
    }
  }

  public function admin_notices() {
    foreach ( $this->errors as $error ) {
    ?>
      <div class="notice notice-error">
      <p><?php echo esc_html_e( $error ); ?></p>
      </div>
    <?php
    }
  }
}
