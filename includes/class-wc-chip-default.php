<?php

class WC_Chip_Gateway extends WC_Payment_Gateway
{
  public $id           = 'chip';
  public $title        = "";
  public $description  = "";
  public $debug        = false;
  public $method_title = 'CHIP';
  
  public $method_description = "";
  
  private $cached_api;

  public function __construct()
  {
    $this->init_form_fields();
    $this->init_settings();

    $this->title    = $this->get_option( 'label' );
    $this->icon     = plugins_url("../assets/logo.png", __FILE__);
    $this->supports = apply_filters( 'wc_chip_supports', ['products', 'refunds'] );
    $this->hid      = $this->get_option( 'hid' );
    $this->label    = $this->get_option( 'label' );
    $this->debug    = $this->get_option( 'label' );
    
    $this->method_desc        = $this->get_option( 'method_desc' );
    $this->method_description = $this->method_desc;
    $this->public_key         = $this->get_option( 'public-key' );
    
    if ($this->title === '') {
      $this->title = __('Online Banking and Cards', 'chip-for-woocommerce');
    };

    if ($this->hid == 'yes' && $this->id == 'chip') {
      $this->method_title = __('CHIP. Accept Payment with Online Banking (Personal)', 'chip-for-woocommerce');
      $this->icon = plugins_url("../assets/fpx.png", __FILE__);
    }

    if ($this->method_description === '') {
      $this->method_description = __('Pay with Online Banking or Card', 'chip-for-woocommerce');
    };

    if ($this->unsupported_currency()) {
      $this->enabled = 'no';
    }

    $this->is_advance_checkout = 'yes' === $this->get_option('is-advance-checkout');

    add_action(
      'woocommerce_update_options_payment_gateways_' . $this->id,
      array($this, 'process_admin_options')
    );

    // TODO: delete in upcoming release
    add_action(
      'woocommerce_api_wc_gateway_' . $this->id,
      array($this, 'handle_callback')
    );

    add_action("woocommerce_api_wc_{$this->id}_gateway", array(&$this, 'handle_callback'));

    add_action('before_woocommerce_pay_form', array( &$this, 'before_woocommerce_pay_form' ), 10, 3 );
  }

  public function get_icon()
  {
    // how to override inline style
    // https://stackoverflow.com/questions/16813220/how-can-i-override-inline-styles-with-external-css

    $style = apply_filters( 'wc_chip_get_icon_style', 'max-height: 25px; width: auto');
    $icon = '<img class="chip-for-woocommerce-" ' . $this->id . ' src="' . WC_HTTPS::force_https_url( $this->icon ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="' . esc_attr($style) . '" />';
    return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
  }

  private function chip_api()
  {
    if (!$this->cached_api) {
      $this->cached_api = new WC_Chip_API(
        $this->settings['secret-key'],
        $this->settings['brand-id'],
        new ChipWCLogger(),
        $this->debug
      );
    }
    return $this->cached_api;
  }

  private function log_order_info($msg, $o)
  {
    $this->chip_api()->log_info($msg . ': ' . $o->get_order_number());
  }

  public function handle_callback()
  {
    // Docs http://docs.woothemes.com/document/payment-gateway-api/
    // http://127.0.0.1/wordpress/?wc-api=wc_gateway_chip&id=&action={paid,sent}
    // The new URL scheme
    // (http://127.0.0.1/wordpress/wc-api/wc_gateway_chip) is broken
    // for some reason.
    // Old one still works.
    $order_id = intval($_GET["id"]);

    $this->chip_api()->log_info('received callback for order id: ' . $order_id);

    $GLOBALS['wpdb']->get_results(
      "SELECT GET_LOCK('chip_payment_$order_id', 15);"
    );

    $order = new WC_Order($order_id);

    $this->log_order_info('received success callback', $order);

    $payment_id = WC()->session->get( 'chip_payment_id_' . $order_id );
    if ( !$payment_id && isset($_SERVER['HTTP_X_SIGNATURE']) ) {
      // since it doesn't get from session, this is callback
      $content = file_get_contents('php://input');

      if (openssl_verify( $content,  base64_decode($_SERVER['HTTP_X_SIGNATURE']), $this->get_public_key(), 'sha256WithRSAEncryption' ) != 1) {
        $message = __('Success callback failed to be processed due to failure in verification.', 'chip-for-woocommerce');
        $this->log_order_info( $message, $order );
        exit( $message );
      }

      $payment    = json_decode($content, true);
      $payment_id = array_key_exists('id', $payment) ? sanitize_key($payment['id']) : '';
    } else if ( $payment_id ) {
      $payment = $this->chip_api()->get_payment($payment_id);
    } else {
      exit( __('Unexpected response', 'chip-for-woocommerce') );
    }

    if ($payment['status'] == 'paid') {
      if (!$order->is_paid()) {
        $chip_payment_method = $payment['transaction_data']['payment_method'];
        $payment_gateway_id = $order->get_payment_method(); //chip/chip-fpxb2b1/chip-card

        $pg_id_mapper = array(
          'chip'         => 'fpx',
          'chip-fpxb2b1' => 'fpx_b2b1',
          'chip-card'    => 'card'
        );

        if ($this->hid == 'yes' && $pg_id_mapper[$payment_gateway_id] != $chip_payment_method) {

          $class_id_mapper = array(
            'fpx'        => WC_Chip_Gateway::class,
            'fpx_b2b1'   => WC_Chip_Fpxb2b1::class,
            'mastercard' => WC_Chip_Card::class,
            'visa'       => WC_Chip_Card::class,
            'maestro'    => WC_Chip_Card::class
          );

          $this->log_order_info('order payment method updated', $order);

          $payment_method_class = WC_Chip_Gateway::class;
          if ( isset( $class_id_mapper[$chip_payment_method] ) ) {
            $payment_method_class = $class_id_mapper[$chip_payment_method];
          }

          $order->set_payment_method( new $payment_method_class );
        }

        $order->payment_complete($payment_id);
        $order->add_order_note(
          sprintf( __( 'Payment Successful. Transaction ID: %s', 'chip-for-woocommerce' ), $payment_id )
        );

        if ( $payment['is_test'] === true ) {
          $order->add_order_note(
            sprintf( __( 'The payment (%s) made in test mode where it does not involve real payment.', 'chip-for-woocommerce' ), $payment_id )
          );
        }
      }
      WC()->cart->empty_cart();

      $this->log_order_info('payment processed', $order);
    } else {
      if (!$order->is_paid()) {
        if ( !empty($payment['transaction_data']['attempts']) && !empty( $payment_extra = $payment['transaction_data']['attempts'][0]['extra'] ) ) {
          if ( isset($payment_extra['payload']) && isset($payment_extra['payload']['fpx_debitAuthCode']) ) {

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
        $this->log_order_info('payment not successful', $order);
      }
    }

    $GLOBALS['wpdb']->get_results(
      "SELECT RELEASE_LOCK('chip_payment_$order_id');"
    );

    header("Location: " . $this->get_return_url($order));
  }

  public function init_form_fields()
  {
    $this->form_fields = array(
      'enabled' => array(
        'title'       => __('Enable API', 'chip-for-woocommerce'),
        'label'       => __('Enable API', 'chip-for-woocommerce'),
        'type'        => 'checkbox',
        'description' => '',
        'default'     => 'no',
      ),
      'hid' => array(
        'title'       => __('Enable payment method selection', 'chip-for-woocommerce'),
        'label'       => __('Enable payment method selection', 'chip-for-woocommerce'),
        'type'        => 'checkbox',
        'description' => 'If set, buyers will be able to choose the desired payment method directly in WooCommerce',
        'default'     => 'yes',
      ),
      'method_desc' => array(
        'title'       => __('Change payment method description', 'chip-for-woocommerce'),
        'label'       => __('', 'chip-for-woocommerce'),
        'type'        => 'text',
        'description' => 'If not set, "Pay with Online Banking or Card" will be used',
        'default'     => 'Pay with Online Banking, Cards. You will choose your payment option on the next page',
      ),
      'label' => array(
        'title'       => __('Change payment method title', 'chip-for-woocommerce'),
        'type'        => 'text',
        'description' => 'Payment method title.',
        'default'     => 'Online Banking and Cards',
      ),
      'brand-id' => array(
        'title'       => __('Brand ID', 'chip-for-woocommerce'),
        'type'        => 'text',
        'description' => __(
          'Please enter your brand ID',
          'chip-for-woocommerce'
        ),
        'default'     => '',
      ),
      'secret-key' => array(
        'title'       => __('Secret key', 'chip-for-woocommerce'),
        'type'        => 'text',
        'description' => __(
          'Please enter your secret key',
          'chip-for-woocommerce'
        ),
        'default'     => '',
      ),
      'is-advance-checkout' => array(
        'title'       => __('Advanced Checkout', 'chip-for-woocommerce'),
        'type'        => 'checkbox',
        'label'       => __('Tick this checkbox if you are using any advanced checkout plugin', 'chip-for-woocommerce'),
        'description' => __('Add compatibility for advanced checkout plugin. eg: WooCommerce Fast Cart', 'chip-for-woocommerce'),
      ),
      'debug' => array(
        'title'       => __('Debug Log', 'chip-for-woocommerce'),
        'type'        => 'checkbox',
        'label'       => __('Enable logging', 'chip-for-woocommerce'),
        'default'     => 'no',
        'description' =>
          sprintf(
            __(
              'Log events to <code>%s</code>',
              'chip-for-woocommerce'
            ),
            wc_get_log_file_path('chip')
          ),
      ),
    );
  }

  public function payment_fields() {
    if (has_action('wc_chip_payment_fields')) {
      do_action('wc_chip_payment_fields', $this);
    } else {
      echo wp_kses_post( wptexturize( $this->get_method_description() ) );
    }
  }

  public function get_payment_method_redirect() {
    if ($this->hid == 'no') {
      return '';
    }

    switch ($this->id) {
      case 'chip':
        return 'fpx';
      case 'chip-fpxb2b1':
        return 'fpx_b2b1';
      case 'chip-card':
        return 'card';
      default:
        return '';
    }
  }

  public function get_language()
  {
    if (defined('ICL_LANGUAGE_CODE')) {
      $ln = ICL_LANGUAGE_CODE;
    } else {
      $ln = get_locale();
    }
    switch ($ln) {
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

  public function process_payment($order_id)
  {
    $order = new WC_Order($order_id);
    $total = round( $order->get_total() * 100 );
    $notes = $this->get_notes();

    $chip = $this->chip_api();
    $url  = add_query_arg(
      array(
        'id' => $order_id,
      ),
      WC()->api_request_url(get_class(new WC_Chip_Gateway))
    );

    if ( defined( 'WC_CHIP_OLD_URL_SCHEME' ) AND WC_CHIP_OLD_URL_SCHEME ) {
      $url = home_url( '/?wc-api=WC_Chip_Gateway&id=' . $order_id );
    }

    $params = [
      'success_callback' => $url . "&action=paid",
      'success_redirect' => $url . "&action=paid",
      'failure_redirect' => $url . "&action=cancel",
      'send_receipt'     => true,
      'cancel_redirect'  => $url . "&action=cancel",
      'creator_agent'    => 'Chip Woocommerce module: ' . WC_CHIP_MODULE_VERSION,
      'reference'        => (string)$order->get_order_number(),
      'platform'         => 'woocommerce',
      'due'              => apply_filters( 'wc_chip_due_timestamp', $this->get_due_timestamp() ),
      'purchase' => [
        'timezone'   => apply_filters( 'wc_chip_purchase_timezone', $this->get_timezone() ),
        "currency"   => apply_filters( 'wc_chip_purchase_currency', $order->get_currency()),
        "language"   => $this->get_language(),
        "notes"      => $notes,
        "due_strict" => apply_filters( 'wc_chip_purchase_due_strict', true ),
        "products"   => [
          [
            'name'     => 'Order #' . $order_id . ' '. home_url(),
            'price'    => apply_filters( 'wc_chip_purchase_products_price', $total, $order->get_currency()),
            'quantity' => 1,
          ],
        ],
      ],
      'brand_id' => $this->settings['brand-id'],
      'client' => [
        'email'                   => $order->get_billing_email(),
        'phone'                   => $order->get_billing_phone(),
        'full_name'               => substr( $order->get_billing_first_name() . ' '
            . $order->get_billing_last_name(), 0 , 128 ) ,
        'street_address'          => substr( $order->get_billing_address_1() . ' '
            . $order->get_billing_address_2(), 0, 128 ) ,
        'country'                 => $order->get_billing_country(),
        'city'                    => substr( $order->get_billing_city(), 0, 128 ) ,
        'zip_code'                => $order->get_shipping_postcode(),
        'shipping_street_address' => substr( $order->get_shipping_address_1()
            . ' ' . $order->get_shipping_address_2(), 0, 128 ) ,
        'shipping_country'        => $order->get_shipping_country(),
        'shipping_city'           => substr( $order->get_shipping_city(), 0, 128 ) ,
        'shipping_zip_code'       => $order->get_shipping_postcode(),
      ],
    ];

    $payment = $chip->create_payment( $params );

    if (!array_key_exists('id', $payment)) {
      $this->log_order_info('create payment failed. message: ' . print_r( $payment, true ), $order);
      return array(
        'result' => 'failure',
      );
    }
    
    WC()->session->set(
      'chip_payment_id_' . $order_id,
      $payment['id']
    );
    
    $this->log_order_info('got checkout url, redirecting', $order);
    
    $checkout_url = $payment['checkout_url'];      
    
    if ($this->hid == 'yes') {
      $checkout_url .= '?preferred=' . $this->get_payment_method_redirect();
    }

    if ( $this->is_advance_checkout && !is_checkout_pay_page() ) {
      WC()->session->set('chip_checkout_url_' . $order_id, $checkout_url);

      return array(
        'result' => 'success',
        'redirect' => add_query_arg( 'payment_method', $this->id, $order->get_checkout_payment_url() ),
      );
    }
    
    return array(
        'result' => 'success',
        'redirect' => esc_url($checkout_url),
    );
  }

  public function get_notes() {
    $cart       = WC()->cart->get_cart();
    $nameString = '';

    foreach ($cart as $key => $cart_item) {
      $cart_product = $cart_item['data'];
      $name         = method_exists( $cart_product, 'get_name' ) === true ? $cart_product->get_name() : $cart_product->name;
      
      if (array_keys($cart)[0] == $key) {
        $nameString = $name;
      } else {
        $nameString = $nameString . ';' . $name;
      }
    }
    return $nameString;
  }

  public function get_timezone(){
    if (preg_match('/^[A-z]+\/[A-z\_\/\-]+$/', wp_timezone_string())) {
      return wp_timezone_string();
    }

    return 'UTC';
  }

  public function get_due_timestamp(){
    return time() + (absint( get_option( 'woocommerce_hold_stock_minutes', '60' ) ) * 60);
  }

  public function can_refund_order( $order ) {
    $has_api_creds    = $this->get_option( 'enabled' ) && $this->get_option( 'secret-key' ) && $this->get_option( 'brand-id' );
    $can_refund_order = $order && $order->get_transaction_id() && $has_api_creds;
    
    return apply_filters( 'wc_chip_can_refund_order', $can_refund_order, $order );
  }

  public function process_refund( $order_id, $amount = null, $reason = '' ) {
    $order = wc_get_order( $order_id );

    if ( ! $this->can_refund_order( $order ) ) {
      $this->log_order_info( 'Cannot refund order', $order );
      return new WP_Error( 'error', __( 'Refund failed.', 'chip-for-woocommerce' ) );
    }

    $chip = $this->chip_api();
    $params = [ 'amount' => round($amount * 100) ];

    $result = $chip->refund_payment($order->get_transaction_id(), $params);
    
    if ( is_wp_error( $result ) || isset($result['__all__']) ) {
      $this->chip_api()
        ->log_error(var_export($result['__all__'], true) . ': ' . $order->get_order_number());
      return new WP_Error( 'error', var_export($result['__all__'], true) );
    }

    $this->log_order_info( 'Refund Result: ' . wc_print_r( $result, true ), $order );
    switch ( strtolower( $result['status'] ?? 'failed' ) ) {
      case 'success':
        $refund_amount = round($result['payment']['amount'] / 100, 2) . $result['payment']['currency'];
        $order->add_order_note(
        /* translators: 1: Refund amount, 2: Refund ID */
            sprintf( __( 'Refunded %1$s - Refund ID: %2$s', 'chip-for-woocommerce' ), $refund_amount, $result['id'] )
        );
        return true;
    }
    
    return true;
  }

  public function unsupported_currency() {
    $woocommerce_currency = get_woocommerce_currency();
    $supported_currencies = apply_filters('wc_chip_supported_currencies', array('MYR'));
    
    if (!in_array($woocommerce_currency, $supported_currencies, true)){
      return true;
    }
    return false;
  }

  public function get_public_key() {
    if ( empty($this->public_key) ){
      $this->public_key = str_replace('\n',"\n",$this->chip_api()->public_key());
      $this->update_option( 'public-key', $this->public_key );
    }

    return $this->public_key;
  }

  public function process_admin_options() {
    parent::process_admin_options();

    if ($this->id != 'chip') {
      return;
    }

    $this->update_option( 'public-key', null );

    $post_data = $this->get_post_data();
    $this->settings['brand-id']   = $post_data['woocommerce_chip_brand-id'];
    $this->settings['secret-key'] = $post_data['woocommerce_chip_secret-key'];

    $payment_methods = $this->chip_api()->payment_methods(
      get_woocommerce_currency(),
      $this->get_language(),
      200
    );

    if (is_null($payment_methods)) {
      $this->chip_api()->log_error('Failed to get payment methods based on the secret key: ' . $this->settings['secret-key'] . ' and brand id: ' . $this->settings['brand_id']);
      return;
    }

    if (!array_key_exists("by_country", $payment_methods)) {
      $this->chip_api()->log_error('by_country array key does not exists.');
      return;
    }

    $available_methods = array();
    foreach ($payment_methods["by_country"] as $country => $pms) {
      if (in_array('billplz', $pms)){
        continue;
      }
      foreach ($pms as $pm) {
        if (!array_key_exists($pm, $available_methods)) {
          $methods[$pm] = array(
            'payment_method' => sanitize_key($pm),
            'countries'      => [],
          );

          if (!in_array($country, $methods[$pm]["countries"])) {
            $methods[$pm]["countries"][] = $country;
          }
        }
      }
    }

    $payment_method_keys = array();

    foreach ($methods as $key => $data) {
      // $key possible values: fpx, fpx_b2b1, card
      $payment_method_keys[] = $key;
    }

    update_option('chip_woocommerce_payment_method', $payment_method_keys);
  }

  public function before_woocommerce_pay_form($order, $order_button_text, $available_gateways) {
    $selected_method = isset( $_GET['payment_method'] ) ? wp_unslash( $_GET['payment_method'] ) : null;

    if ( $selected_method == $this->id ) {
        
        $url = WC()->session->get( 'chip_checkout_url_' . $order->get_id() );

        if ( $url ) {
            wp_redirect( $url );
            exit;
        }
    }
  }
}
