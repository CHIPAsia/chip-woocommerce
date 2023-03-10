<?php

class Chip_Woocommerce_Receipt_Link {
  private static $_instance;

  public static function get_instance() {
    if ( static::$_instance == null ) {
      static::$_instance = new static();
    }

    return static::$_instance;
  }

  public function __construct() {
    $this->add_actions();
  }

  public function add_actions() {
    add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'invoice_button' ) );
    add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'receipt_button' ) );
    add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'view_button' ) );
  }

  public function invoice_button( $order ) {
    $gateway_id = $order->get_payment_method();
    $wc_gateway_chip = Chip_Woocommerce::get_chip_gateway_class( $gateway_id );

    if ( $wc_gateway_chip AND ( $purchase_id = $order->get_meta( '_' . $gateway_id . '_purchase_id', true ) ) ) {
    ?>
      <a href="https://gate.chip-in.asia/p/<?php echo $purchase_id; ?>/invoice/" target="_blank">
        <button type="button" class="button"><?php esc_html_e( 'CHIP Invoice', 'chip-for-woocommerce' ); ?></button>
      </a>
    <?php
    }
  }

  public function receipt_button( $order ) {
    if ( !$order->is_paid() ) {
      return;
    }

    $gateway_id = $order->get_payment_method();
    $wc_gateway_chip = Chip_Woocommerce::get_chip_gateway_class( $gateway_id );

    if ( $wc_gateway_chip AND ( $purchase_id = $order->get_meta( '_' . $gateway_id . '_purchase_id', true ) ) ) {
      ?>
        <a href="https://gate.chip-in.asia/p/<?php echo $purchase_id; ?>/receipt/" target="_blank">
          <button type="button" class="button"><?php esc_html_e( 'CHIP Receipt', 'chip-for-woocommerce' ); ?></button>
        </a>
      <?php
    }
  }

  public function view_button( $order ) {
    $gateway_id = $order->get_payment_method();
    $wc_gateway_chip = Chip_Woocommerce::get_chip_gateway_class( $gateway_id );

    if ( $wc_gateway_chip AND ( $purchase_id = $order->get_meta( '_' . $gateway_id . '_purchase_id', true ) ) ) {

      $chip = $wc_gateway_chip->api();
      $payment = $chip->get_payment( $purchase_id );
    ?>
      <a href="https://gate.chip-in.asia/t/<?php echo $payment['company_id']; ?>/feed/purchase/<?php echo $purchase_id; ?>/" target="_blank">
        <button type="button" class="button"><?php esc_html_e( 'CHIP Feed', 'chip-for-woocommerce' ); ?></button>
      </a>
    <?php
    }
  }
}

Chip_Woocommerce_Receipt_Link::get_instance();