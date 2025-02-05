<?php

namespace Drupal\alipay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\alipay\AlipayGatewayInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Alipay CommercePaymentGateway plugin.
 *
 * @CommercePaymentGateway(
 *   id = "alipay",
 *   label = "Alipay",
 *   display_label = "Alipay",
 *   forms = {
 *     "offsite-payment" = "Drupal\alipay\PluginForm\QRCodePaymentForm",
 *   }
 * )
 */
class Alipay extends OffsitePaymentGatewayBase implements SupportsRefundsInterface, AlipayGatewayInterface {

  use StringTranslationTrait;

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

    $form['app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App ID'),
      '#description' => $this->t('Alipay created App ID.'),
      '#default_value' => $this->configuration['app_id'] ?? '',
      '#required' => TRUE,
    ];

    $form['app_private_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Private key path'),
      '#description' => $this->t('The app private key'),
      '#default_value' => $this->configuration['app_private_key_path'] ?? '',
    ];

    $form['alipay_public_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public key path'),
      '#description' => $this->t('The alipay public key'),
      '#default_value' => $this->configuration['alipay_public_key_path'] ?? '',
    ];

    $form['app_cert_public_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App Cert path'),
      '#description' => $this->t('App Cert path'),
      '#default_value' => $this->configuration['app_cert_public_key_path'] ?? '',
    ];

    $form['alipay_cert_public_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alipay cert path'),
      '#description' => $this->t('Alipay cert path'),
      '#default_value' => $this->configuration['alipay_cert_public_key_path'] ?? '',
    ];

    $form['alipay_root_cert_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alipay Root cert path'),
      '#description' => $this->t('The Alipay Root cert path'),
      '#default_value' => $this->configuration['alipay_root_cert_path'] ?? '',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {

  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['client_type'] = $values['client_type'];
      $this->configuration['app_id'] = $values['app_id'];
      $this->configuration['app_private_key_path'] = $values['app_private_key_path'];
      $this->configuration['alipay_public_key_path'] = $values['alipay_public_key_path'];
      $this->configuration['app_cert_public_key_path'] = $values['app_cert_public_key_path'];
      $this->configuration['alipay_cert_public_key_path'] = $values['alipay_cert_public_key_path'];
      $this->configuration['alipay_root_cert_path'] = $values['alipay_root_cert_path'];
    }
  }

  /**
   * @param $type
   * @return \Omnipay\Alipay\AopAppGateway
   */
  public function getOmniGateway($type) {
    /** @var \Omnipay\Alipay\AopAppGateway $gateway */
    $gateway = Omnipay::create($type);
    // RSA/RSA2.
    $gateway->setSignType('RSA2');

    $gateway->setAppId($this->getConfiguration()['app_id']);
    $gateway->setPrivateKey($this->getConfiguration()['app_private_key_path']);
    $gateway->setAlipayPublicKey($this->getConfiguration()['alipay_public_key_path']);

    global $base_url;
    $notify_url = $base_url . '/' . $this->getNotifyUrl()->getInternalPath();
    $gateway->setNotifyUrl($notify_url);

    return $gateway;
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL) {

  }

  /**
   * {@inheritdoc}
   *
   * @throws \EasyWeChat\Core\Exceptions\FaultException
   * @throws \Exception
   */
  public function onNotify(Request $request) {
    \Drupal::logger('alipay')->notice('接收到来自支付宝的通知：' . print_r($_POST, TRUE));

    $client_type = $this->getConfiguration()['client_type'];
    $request = NULL;
    switch ($client_type) {
      case self::CLIENT_TYPE_NATIVE_APP:
        $request = $this->getOmniGateway('Alipay_AopApp')->completePurchase();
        break;

      default:
        throw new \Exception('未实现的客户端类型');
    }

    // Optional.
    $request->setParams($_POST);

    /** @var \Omnipay\Alipay\Responses\AopCompletePurchaseResponse $response */
    try {
      $response = $request->send();

      if ($response->isPaid()) {
        \Drupal::logger('alipay')->notice('通知验证成功。');

        // 处理订单状态
        // load the payment.
        $order_id = NULL;
        $payment_id = NULL;
        $id_info = explode('-', $_POST['out_trade_no']);
        if ($id_info && count($id_info) > 2) {
          $order_id = $id_info[0];
          $payment_id = $id_info[1];
        }
        else {
          \Drupal::logger('alipay')->error('out_trade_no不是预期格式[' . $_POST['out_trade_no'] . ']: ' . print_r($_POST, TRUE));
          die('fail');
        }

        /** @var \Drupal\commerce_payment\Entity\Payment $payment_entity */
        $payment_entity = Payment::load($payment_id);
        $order = Order::load($order_id);
        if ($payment_entity && (int) $payment_entity->getOrderId() === (int) $order_id) {
          $payment_entity->setState('completed');
          $payment_entity->setRemoteId($_POST['trade_no']);
          $payment_entity->save();

          $transition = $order->getState()->getWorkflow()->getTransition('place');
          $order->getState()->applyTransition($transition);
          $order->save();
        }
        else {
          // Payment doesn't exist.
          \Drupal::logger('alipay')->error('找不到订单[' . $order_id . ']的支付单[' . $payment_id . ']: ' . print_r($_POST, TRUE));
          die('fail');
        }

        die('success');
      }
      else {
        \Drupal::logger('alipay')->notice('通知验证失败。');
        die('fail');
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('alipay')->notice('通知验证请求没有成功：' . $e->getMessage());
      die('fail');
    }
  }

  /**
   * @param \Drupal\commerce_order\Entity\Order $commerce_order
   * @return \Drupal\commerce_payment\Entity\Payment
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createPayment(Order $commerce_order) {
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');

    $payment = $payment_storage->create([
      'state' => 'new',
      'amount' => $commerce_order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $commerce_order,
      'test' => $this->getMode() === 'test',
    ]);

    $payment->save();

    return $payment;
  }

  /**
   *
   */
  public function requestRedirectUrl($commerce_order, &$payment) {
    require_once __DIR__ . '/../../../../alipay_sdks/alipay-sdk-PHP-4.2.0/aop/request/AlipayTradePagePayRequest.php';
    $request = new \AlipayTradePagePayRequest();
    $data = [
      'product_code' => 'FAST_INSTANT_TRADE_PAY',
    ];
    $payment = $this->createPayment($commerce_order);
    $request->setBizContent(json_encode($this->getBizContent($commerce_order, $payment, $data)));
    return $this->getAopCertClient()->pageExecute($request, 'GET');
  }

  /**
   *
   * @param $commerce_order
   * @param $payment
   * @return void
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function requestQRCode($commerce_order, &$payment) {

    $client_type = $this->getConfiguration()['client_type'];
    if ($client_type !== self::CLIENT_TYPE_FACE_TO_FACE) {
      throw new \Exception('requestQRCode only support [' . self::CLIENT_TYPE_FACE_TO_FACE . '] client type.');
    }

    $request = $this->getOmniGateway('Alipay_AopF2F')->purchase();

    $payment = $this->createPayment($commerce_order);
    $data = [
      'scene' => 'bar_code',
    ];
    $request->setBizContent($this->getBizContent($commerce_order, $payment, $data));

    /** @var \Omnipay\Alipay\Responses\AopTradePreCreateResponse $response */
    $response = $request->send();

    if ($response->getCode() === 1000) {
      return $response->getQrCode();
    }
    else {
      throw new Exception($response->getMessage());
    }
  }

  /**
   * @param \Drupal\commerce_order\Entity\Order $commerce_order
   * @return null
   * @throws \Exception
   */
  public function getClientLaunchConfig($commerce_order) {
    $config = NULL;
    $client_type = $this->getConfiguration()['client_type'];

    $request = NULL;
    switch ($client_type) {
      case self::CLIENT_TYPE_NATIVE_APP:
        $request = $this->getOmniGateway('Alipay_AopApp')->purchase();
        break;

      default:
        throw new \Exception('未实现的客户端类型');
    }

    $payment = $this->createPayment($commerce_order);

    $data = [
      'product_code' => 'QUICK_MSECURITY_PAY',
    ];
    $request->setBizContent($this->getBizContent($commerce_order, $payment, $data));

    /** @var \Omnipay\Alipay\Responses\AopTradeAppPayResponse $response */
    $response = $request->send();
    $orderString = $response->getOrderString();

    $config['order_string'] = $orderString;

    return $config;
  }

  /**
   *
   */
  private function getBizContent($commerce_order, $payment, &$data) {

    $order_item_names = '';
    foreach ($commerce_order->getItems() as $order_item) {
      /** @var \Drupal\commerce_order\Entity\OrderItem $order_item */
      $order_item_names .= $order_item->getTitle() . ', ';
    }

    $total_fee = $commerce_order->getTotalPrice()->getNumber();
    if ($this->getMode() === 'test') {
      $total_fee = '0.01';
    }

    return $data + [
      'subject'      => mb_substr(\Drupal::config('system.site')->get('name') . $this->t(' Order: ') . $commerce_order->getOrderNumber(), 0, 256),
      'body'         => mb_substr($order_item_names, 0, 128),
    // 商户网站唯一订单号.
      'out_trade_no' => $commerce_order->id() . '-' . $payment->id() . '-' . date('YmdHis') . mt_rand(1000, 9999),
      'total_amount' => $total_fee,
    ];
  }

  /**
   * 参考 alipay_sdks/alipay-sdk-PHP-4.2.0.
   *
   * @return \AopCertClient
   */
  private function getAopCertClient() {
    require_once __DIR__ . '/../../../../alipay_sdks/alipay-sdk-PHP-4.2.0/aop/AopCertClient.php';
    require_once __DIR__ . '/../../../../alipay_sdks/alipay-sdk-PHP-4.2.0/aop/AopCertification.php';

    $aop = new \AopCertClient();
    $appCertPath = $this->getConfiguration()['app_cert_public_key_path'];
    $alipayCertPath = $this->getConfiguration()['alipay_cert_public_key_path'];
    $rootCertPath = $this->getConfiguration()['alipay_root_cert_path'];

    $aop->gatewayUrl = 'https://openapi.alipay.com/gateway.do';
    $aop->appId = $this->getConfiguration()['app_id'];
    $aop->rsaPrivateKey = file_get_contents($this->getConfiguration()['app_private_key_path']);
    $aop->alipayrsaPublicKey = $aop->getPublicKey($alipayCertPath);
    $aop->apiVersion = '1.0';
    $aop->signType = 'RSA2';
    $aop->postCharset = 'utf-8';
    $aop->format = 'json';
    // 是否校验自动下载的支付宝公钥证书，如果开启校验要保证支付宝根证书在有效期内.
    $aop->isCheckAlipayPublicCert = TRUE;
    // 调用getCertSN获取证书序列号.
    $aop->appCertSN = $aop->getCertSN($appCertPath);
    // 调用getRootCertSN获取支付宝根证书序列号.
    $aop->alipayRootCertSN = $aop->getRootCertSN($rootCertPath);

    return $aop;
  }

}
