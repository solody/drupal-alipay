<?php

namespace Drupal\alipay\Plugin\Commerce\PaymentGateway;

use Alipay\EasySDK\Kernel\EasySDKKernel;
use Alipay\EasySDK\Kernel\Util\ResponseChecker;
use Alipay\EasySDK\Kernel\Util\Signer;
use Drupal\alipay\AlipayEasySdkTrait;
use Drupal\alipay\Form\AlipayConfigFormTrait;
use Drupal\commerce_checkout_api\SupportHeadlessPaymentInterface;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_refund\Entity\Refund;
use Drupal\commerce_refund\Entity\RefundInterface;
use Drupal\commerce_refund\SupportsRefundEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\alipay\AlipayGatewayInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Alipay\EasySDK\Kernel\Factory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alipay CommercePaymentGateway plugin.
 *
 * @CommercePaymentGateway(
 *   id = "alipay",
 *   label = "Alipay",
 *   display_label = "Alipay",
 *   payment_type = "payment_alipay",
 *   modes = {
 *     "test" = @Translation("Testing"),
 *     "live" = @Translation("Live"),
 *    },
 *   forms = {
 *     "offsite-payment" = "Drupal\alipay\PluginForm\QRCodePaymentForm",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class Alipay extends OffsitePaymentGatewayBase implements
  SupportHeadlessPaymentInterface,
  SupportsRefundsInterface,
  SupportsRefundEntityInterface,
  AlipayGatewayInterface {

  use StringTranslationTrait;
  use AlipayConfigFormTrait {
    AlipayConfigFormTrait::buildConfigurationForm as buildAlipayConfigurationForm;
    AlipayConfigFormTrait::submitConfigurationForm as submitAlipayConfigurationForm;
  }
  use AlipayEasySdkTrait;

  /**
   * The logger for this channel.
   */
  private LoggerInterface $logger;

  /**
   * The EasySDKKernel.
   */
  private EasySDKKernel $kernel;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->logger = $container->get('logger.channel.alipay');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['client_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Application type to use this gateway.'),
      '#options' => [
        self::CLIENT_TYPE_WEBSITE => $this->t('Website payment.'),
        self::CLIENT_TYPE_NATIVE_APP => $this->t('Native mobile app'),
        self::CLIENT_TYPE_FACE_TO_FACE => $this->t('Face to face payment'),
      ],
      '#default_value' => $this->configuration['client_type'] ?? self::CLIENT_TYPE_NATIVE_APP,
      '#required' => TRUE,
    ];
    return $this->buildAlipayConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['client_type'] = $values['client_type'];
    }
    $this->submitAlipayConfigurationForm($form, $form_state);
  }

  /**
   * Get entity out trade number.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The pay order number.
   */
  private function getEntityTrackingId(EntityInterface $entity): string {
    return (new \DateTime())->format('YmdHis') . mt_rand(1000, 9999) . '-' . $entity->id();
  }

  /**
   * Get commerce order items labels as a string.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   *
   * @return string
   *   The commerce order items labels.
   */
  private function getOrderItemNames(PaymentInterface $payment): string {
    $commerce_order = $payment->getOrder();

    $order_item_names = '';
    foreach ($commerce_order->getItems() as $order_item) {
      /** @var \Drupal\commerce_order\Entity\OrderItem $order_item */
      $order_item_names .= $order_item->getTitle() . ', ';
    }
    return $order_item_names;
  }

  /**
   * Get number string of the payment amount.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   *
   * @return string
   *   The number string of the payment amount.
   */
  private function getPaymentAmount(PaymentInterface $payment) {
    $total_fee = $payment->getAmount()->getNumber();
    if ($this->getMode() === 'test') {
      $total_fee = '0.01';
    }
    return $total_fee;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  public function getClientLaunchConfig($commerce_order, PaymentInterface $payment) {
    $this->ensureEasySdkInitialized();

    // Call api alipay.trade.app.pay to build to orderStr.
    if ($this->getConfiguration()['client_type'] !== self::CLIENT_TYPE_NATIVE_APP) {
      throw new \Exception('Unsupported client type.');
    }

    $payment->save();
    $trade_tracking_id = $this->getEntityTrackingId($payment);
    $payment->set('trade_tracking_id', $trade_tracking_id);
    $payment->save();

    $result = Factory::payment()->app()->pay($this->getOrderItemNames($payment), $trade_tracking_id, $this->getPaymentAmount($payment));
    $responseChecker = new ResponseChecker();
    if (!$responseChecker->success($result)) {
      throw new \Exception("easySDK 调用失败，原因：" . $result->msg . "，" . $result->subMsg);
    }
    else {
      return ['order_string' => $result->body];
    }
  }

  /**
   * Redirect to alipay web cashier.
   *
   * @return string
   *   Post form html.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function requestRedirectUrl($commerce_order, PaymentInterface $payment) {
    $this->ensureEasySdkInitialized();

    if ($this->getConfiguration()['client_type'] !== self::CLIENT_TYPE_WEBSITE) {
      throw new \Exception('Unsupported client type.');
    }

    $payment->save();
    $trade_tracking_id = $this->getPaymentOrderNumber($payment);
    $payment->set('trade_tracking_id', $trade_tracking_id);
    $payment->save();
    // Call alipay\.trade\.page\.pay.
    $response = Factory::payment()->page()->pay($this->getOrderItemNames($payment), $trade_tracking_id, $this->getPaymentAmount($payment), '@todo');

    return $response->pageRedirectionData;
  }

  /**
   * Customer scan merchant QR-code to pay.
   *
   * @return string
   *   Value to build qr-code.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function requestQrCode($commerce_order, PaymentInterface $payment) {

    $this->ensureEasySdkInitialized();

    if ($this->getConfiguration()['client_type'] !== self::CLIENT_TYPE_FACE_TO_FACE) {
      throw new \Exception('Unsupported client type.');
    }

    $payment->save();
    $trade_tracking_id = $this->getEntityTrackingId($payment);
    $payment->set('trade_tracking_id', $trade_tracking_id);
    $payment->save();
    // Call alipay\.trade\.page\.pay.
    $response = Factory::payment()->faceToFace()->preCreate($this->getOrderItemNames($payment), $trade_tracking_id, $this->getPaymentAmount($payment));

    return $response->qr_code;
  }

  /**
   * {@inheritdoc}
   */
  public function canRefundPayment(PaymentInterface $payment) {
    return $payment->getBalance()->isPositive() && !$payment->get('trade_tracking_id')->isEmpty();
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL) {
    // This method might be called from:
    // 1. Payment entity refund operation form.
    // 2. Refund entity.
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    // Create a refund entity and call executeRefund() on it.
    $refund = Refund::create([
      'payment_id' => $payment->id(),
      'amount' => $amount,
      'state' => 'new',
      'remarks' => $this->t('Refund for payment :payment', [
        ':payment' => $payment->label(),
      ]),
    ]);
    $refund->save();
    $refund->getState()->applyTransitionById('commit');
    $refund->save();
    $refund->executeRefund();
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function executeRefund(RefundInterface $refund) {
    $refund->setRefundNumber($this->getEntityTrackingId($refund));
    $refund->save();

    $rs = $this->callRefundApi($refund->getPayment()->trade_tracking_id->value, $refund->getAmount()->getNumber());

    $refund->setRemoteId($rs['trade_no']);

    $this->updatePaymentRefundedAmountAndState($refund->getPayment(), $refund->getAmount());
  }

  /**
   * Update the payment refunded amount and state.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   * @param \Drupal\commerce_price\Price $amount
   *   The amount.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function updatePaymentRefundedAmountAndState(PaymentInterface $payment, Price $amount) {
    // Update the payment balance.
    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * Call refund api and fetch the response data.
   *
   * @throws \Exception
   */
  public function callRefundApi(string $out_trade_no, float $refund_amount) {

    $this->ensureEasySdkInitialized();
    $rs = Factory::payment()->common()
      ->refund(
        $out_trade_no,
        $this->getMode() === 'test' ? '0.01' : $refund_amount
      );
    // @todo Verify the $rs->httpBody.
    $code = $rs->code;
    $fee = $rs->refundFee;
    $body = $rs->httpBody;
    $trade_no = $rs->tradeNo;
    $out_trade_no = $rs->outTradeNo;
    // $body: {"alipay_trade_refund_response":{"code":"10000","msg":"Success","buyer_logon_id":"129***@qq.com","fund_change":"Y","gmt_refund_pay":"2025-02-10 14:22:14","out_trade_no":"202502101417081282-21","refund_detail_item_list":[{"amount":"0.01","fund_channel":"ALIPAYACCOUNT"}],"refund_fee":"0.01","send_back_fee":"0.01","trade_no":"2025021022001472541430920598","buyer_open_id":"0543v3Ch5stl8XBZW845p4RmmVl8W3YM-Y4eCvAesSaz4Aa"},"alipay_cert_sn":"dda2416c0aa167d93fed1e01e3dca2b1","sign":"gPQf/nUQZaKJdcPwaTf/YGZWYmf6WGT3yFBswdEMQ4usqa6rSIwTT4NHoEFuJKTLH5Q2cCgZ25xiXRMzjZuZvlnrfCcb1GvCXs54+QN3sKwjWVjkIeMnXSE0kS64f4FTtmWAOgxIu7TN+uG3rYmAN+cRox+QCboaoTl32EuLjmjW3YVjx8nk/MZKdgBX9yZHmJNuRkgSWhj3yepDjBC1ptoVmpUjsThLAzs53kXIGsxAuclnx5cdXRL+XrQ3e6tUr/Pr+3iO716wDw/+JHlz7QAxaj9ZO1i9wYJOZ84t/OxA39bNUlqTVRG629AdnRbNi5jFJEoBFXMozrqq5ZvhfQ=="}
    $responseBody = json_decode($rs->httpBody, TRUE);
    if ($this->verifyContent(json_encode($responseBody['alipay_trade_refund_response']), $responseBody['sign'], $responseBody['alipay_cert_sn'])) {
      return $responseBody['alipay_trade_refund_response'];
    }
    else {
      throw new \Exception('alipay_trade_refund_response sign verification failed!');
    }
  }

  /**
   * Verify content of alipay.
   *
   * @param string $content
   *   The content to verify.
   * @param string $signature
   *   The signature to verify.
   * @param string $alipay_cert_sn
   *   The alipay_cert_sn from the response.
   *
   * @return bool
   *   Indicated if success.
   */
  public function verifyContent(string $content, string $signature, string $alipay_cert_sn = '') {
    $signer = new Signer();
    $alipay_public_key = $this->kernel->isCertMode() ? $this->kernel->extractAlipayPublicKey($alipay_cert_sn) : $this->kernel->getConfig("alipayPublicKey");
    return $signer->verify($content, $signature, $alipay_public_key);
  }

  /**
   * {@inheritdoc}
   *
   * Https://opendocs.alipay.com/open/00iki4?pathHash=eab39489 同步通知说明.
   *
   * @throws \Exception
   */
  public function onNotify(Request $request) {
    $this->logger->info('Received alipay notification: ' . print_r($request->toArray(), TRUE));

    $this->ensureEasySdkInitialized();

    $response_content = '';

    try {
      if (isset($request->toArray()['sync_notify_from_app'])) {
        $rs = json_decode($request->toArray()['result'], TRUE);

        if ($this->verifyContent(json_encode($rs['alipay_trade_app_pay_response']), $rs['sign'])) {
          $this->logger->notice('App sync notification verified successfully.');

          // Make payment completed.
          $this->processPayment($rs['alipay_trade_app_pay_response']);
        }
        else {
          $this->logger->notice('App sync notification verified fails.');
        }
      }
      else {
        if (Factory::payment()->common()->verifyNotify($request->toArray())) {
          $this->logger->notice('通知验证成功。');
          if ($this->processPayment($request->toArray())) {
            $response_content = 'success';
          }
          else {
            $response_content = 'fail';
          }
        }
        else {
          $this->logger->notice('通知验证失败。');
          $response_content = 'fail';
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->notice('通知验证请求没有成功：' . $e->getMessage());
      $response_content = 'fail';
    }

    return $this->getNotificationResponse($response_content);
  }

  /**
   * Get response object for the notification.
   *
   * @param string $content
   *   Response body.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response for the notification.
   */
  public function getNotificationResponse(string $content): Response {
    return new Response($content, 200);
  }

  /**
   * Process payment for notify.
   */
  public function processPayment(array $result): bool {

    // Load the payment.
    $payment_id = '';
    $id_info = explode('-', $result['out_trade_no']);
    if ($id_info && count($id_info) === 2) {
      $payment_id = $id_info[1];
    }
    else {
      $this->logger->error('out_trade_no不是预期格式[' . $result['out_trade_no'] . ']: ');
      return FALSE;
    }

    /** @var \Drupal\commerce_payment\Entity\Payment $payment_entity */
    $payment_entity = Payment::load($payment_id);
    if ($payment_entity instanceof PaymentInterface) {
      $payment_entity->setState('completed');
      $payment_entity->setRemoteId($result['trade_no']);
      $payment_entity->save();

      $order = $payment_entity->getOrder();
      $transition = $order->getState()->getWorkflow()->getTransition('place');
      $order->getState()->applyTransition($transition);
      $order->save();
    }
    else {
      // Payment doesn't exist.
      $this->logger->error('找不到支付订单[' . $payment_id . ']: ');
      return FALSE;
    }

    return TRUE;
  }

}
