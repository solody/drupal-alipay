<?php

namespace Drupal\alipay\Plugin\Commerce\PaymentGateway;

use Alipay\EasySDK\Kernel\Util\ResponseChecker;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\alipay\AlipayGatewayInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Alipay\EasySDK\Kernel\Factory;
use Alipay\EasySDK\Kernel\Config;

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
   * Indicated easySDK initialized.
   *
   * @var bool
   */
  private $easySDKInitialized = FALSE;

  /**
   * The logger for this channel.
   */
  private LoggerInterface $logger;

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

    $form['app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App ID'),
      '#description' => $this->t('Alipay created App ID.'),
      '#default_value' => $this->configuration['app_id'] ?? '',
      '#required' => TRUE,
    ];

    $form['app_private_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App private key path'),
      '#description' => $this->t('The app private key'),
      '#default_value' => $this->configuration['app_private_key_path'] ?? '',
    ];

    $form['app_cert_public_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App cert public key path'),
      '#description' => $this->t('Something like /foo/appCertPublicKey_2019051064521003.crt.'),
      '#default_value' => $this->configuration['app_cert_public_key_path'] ?? '',
    ];

    $form['alipay_cert_public_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alipay cert public key path'),
      '#description' => $this->t('Something like /foo/alipayCertPublicKey_RSA2.crt'),
      '#default_value' => $this->configuration['alipay_cert_public_key_path'] ?? '',
    ];

    $form['alipay_root_cert_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alipay root cert path'),
      '#description' => $this->t('Something like /foo/alipayRootCert.crt'),
      '#default_value' => $this->configuration['alipay_root_cert_path'] ?? '',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (!file_exists($form_state->getValue('app_private_key_path'))) {
      $form_state->setErrorByName('app_private_key_path', 'App private key path does not exist.');
    }
    if (!file_exists($form_state->getValue('app_cert_public_key_path'))) {
      $form_state->setErrorByName('app_cert_public_key_path', 'App cert public key path does not exist.');
    }
    if (!file_exists($form_state->getValue('alipay_cert_public_key_path'))) {
      $form_state->setErrorByName('alipay_cert_public_key_path', 'Alipay cert public key path does not exist.');
    }
    if (!file_exists($form_state->getValue('alipay_root_cert_path'))) {
      $form_state->setErrorByName('alipay_root_cert_path', 'Alipay root cert path does not exist.');
    }
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
      $this->configuration['app_cert_public_key_path'] = $values['app_cert_public_key_path'];
      $this->configuration['alipay_cert_public_key_path'] = $values['alipay_cert_public_key_path'];
      $this->configuration['alipay_root_cert_path'] = $values['alipay_root_cert_path'];
    }
  }

  /**
   * Initial the easySdk.
   */
  public function ensureEasySdkInitialized(): void {

    if ($this->easySDKInitialized) {
      return;
    }

    // Initialize the easySDK.
    $options = new Config();
    $options->protocol = 'https';
    $options->gatewayHost = 'openapi.alipay.com';
    $options->signType = 'RSA2';

    $options->appId = $this->configuration['app_id'];

    // 为避免私钥随源码泄露，推荐从文件中读取私钥字符串而不是写入源码中.
    $options->merchantPrivateKey = file_get_contents($this->configuration['app_private_key_path']);

    // 请填写您的支付宝公钥证书文件路径，例如：/foo/alipayCertPublicKey_RSA2.crt.
    $options->alipayCertPath = $this->configuration['alipay_cert_public_key_path'];
    // 请填写您的支付宝根证书文件路径，例如：/foo/alipayRootCert.crt.
    $options->alipayRootCertPath = $this->configuration['alipay_root_cert_path'];
    // 请填写您的应用公钥证书文件路径，例如：/foo/appCertPublicKey_2019051064521003.crt.
    $options->merchantCertPath = $this->configuration['app_cert_public_key_path'];

    // 注：如果采用非证书模式，则无需赋值上面的三个证书路径，改为赋值如下的支付宝公钥字符串即可.
    // 请填写您的支付宝公钥，例如：MIIBIjANBg...
    // $options->alipayPublicKey = '';.
    // 可设置异步通知接收服务地址（可选）.
    // 请填写您的支付类接口异步通知接收服务地址，例如：https://www.test.com/callback
    global $base_url;
    $options->notifyUrl = $base_url . '/' . $this->getNotifyUrl()->getInternalPath();

    // 可设置AES密钥，调用AES加解密相关接口时需要（可选）.
    // 请填写您的AES密钥，例如：aa4BtZ4tspm2wnXLb1ThQA==.
    $options->encryptKey = $this->configuration['app_private_key_path'];

    Factory::setOptions($options);
    $this->easySDKInitialized = TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  public function getClientLaunchConfig($commerce_order) {
    // Call api alipay.trade.app.pay to build to orderStr.
    $config = [];

    $client_type = $this->getConfiguration()['client_type'];
    if ($client_type !== self::CLIENT_TYPE_NATIVE_APP) {
      throw new \Exception('Unsupported client type.');
    }

    $payment = $this->createPayment($commerce_order);

    $result = Factory::payment()->app()->pay("iPhone6 16G", "20200326235526001", "88.88");
    $responseChecker = new ResponseChecker();
    if (!$responseChecker->success($result)) {
      throw new \Exception("easySDK 调用失败，原因：" . $result->msg . "，" . $result->subMsg);
    }
    else {
      $config['order_string'] = $result->orderStr;
      return $config;
    }
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

}
