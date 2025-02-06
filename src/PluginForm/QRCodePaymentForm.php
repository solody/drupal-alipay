<?php

namespace Drupal\alipay\PluginForm;

use Drupal\alipay\AlipayGatewayInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\Core\Form\FormStateInterface;
use Com\Tecnick\Barcode\Barcode;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * @link https://github.com/lokielse/omnipay-alipay/wiki/Aop-Face-To-Face-Gateway
 */
class QRCodePaymentForm extends BasePaymentOffsiteForm {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    $order = $payment->getOrder();

    $client_type = $payment->getPaymentGateway()->get('configuration')['client_type'];

    if ($client_type === AlipayGatewayInterface::CLIENT_TYPE_WEBSITE) {
      $redirect_url = $payment_gateway_plugin->requestRedirectUrl($order);
      $form['link'] = [
        '#type' => 'markup',
        '#markup' => $redirect_url
      ];
      $this->buildRedirectForm($form, $form_state, $redirect_url, []);
    } elseif ($client_type === AlipayGatewayInterface::CLIENT_TYPE_FACE_TO_FACE) {

      try {
        $qrcode = $payment_gateway_plugin->requestQrCode($order);

        $barcode = new Barcode();
        // generate a barcode
        $bobj = $barcode->getBarcodeObj(
          'QRCODE,H',                     // barcode type and additional comma-separated parameters
          $qrcode,          // data string to encode
          -4,                             // bar height (use absolute or negative value as multiplication factor)
          -4,                             // bar width (use absolute or negative value as multiplication factor)
          'black',                        // foreground color
          array(-2, -2, -2, -2)           // padding (use absolute or negative values as multiplication factors)
        )->setBackgroundColor('white'); // background color

        $form['commerce_message'] = [
          '#markup' => '<div class="checkout-help">' . t('Please scan the QR-Code below to complete the payment on your mobile Alipay App.') ,
          '#weight' => -10,
        ];

        $form['qrcode'] = [
          '#markup' => Markup::create($bobj->getHtmlDiv()),
        ];

        $form['payment_id'] = [
          '#type' => 'value',
          '#value' => $payment->id(),
        ];

        $form['cancel'] = [
          '#type' => 'button',
          '#value' => $this->t('Cancel'),
        ];

      }
      catch (\Exception $e) {
        $form['commerce_message'] = [
          '#markup' => '<div class="checkout-help">'
          . t('Alipay QR-Code is not available at the moment. Message from Alipay service: ' . $e->getMessage().$qrcode),
          '#weight' => -10,
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    $form_state_values = $form_state->getValues();
    $payment_id = $form_state_values['payment_process']['offsite_payment']['payment_id'];
    $payment_entity = Payment::load($payment_id);
    $order_id = $payment_entity->getOrderId();

    if($form_state_values['op']->getUntranslatedString() == 'Cancel'){
      $payment_gateway_plugin->cancel($payment_id, $order_id);
    }
  }
}
