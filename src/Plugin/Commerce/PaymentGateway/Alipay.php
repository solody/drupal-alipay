<?php

namespace Drupal\alipay\Plugin\Commerce\PaymentGateway;

use Alipay\EasySDK\Kernel\CertEnvironment;
use Alipay\EasySDK\Kernel\EasySDKKernel;
use Alipay\EasySDK\Kernel\Util\ResponseChecker;
use Alipay\EasySDK\Kernel\Util\Signer;
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
    $values = $form_state->getValue($form['#parents']);
    if (!file_exists($values['app_private_key_path'])) {
      $form_state->setErrorByName('app_private_key_path', 'App private key path does not exist.');
    }
    if (!file_exists($values['app_cert_public_key_path'])) {
      $form_state->setErrorByName('app_cert_public_key_path', 'App cert public key path does not exist.');
    }
    if (!file_exists($values['alipay_cert_public_key_path'])) {
      $form_state->setErrorByName('alipay_cert_public_key_path', 'Alipay cert public key path does not exist.');
    }
    if (!file_exists($values['alipay_root_cert_path'])) {
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

    $config = $options;

    if (!empty($config->alipayCertPath)) {
      $certEnvironment = new CertEnvironment();
      $certEnvironment->certEnvironment(
        $config->merchantCertPath,
        $config->alipayCertPath,
        $config->alipayRootCertPath
      );
      $config->merchantCertSN = $certEnvironment->getMerchantCertSN();
      $config->alipayRootCertSN = $certEnvironment->getRootCertSN();
      $config->alipayPublicKey = $certEnvironment->getCachedAlipayPublicKey();
    }

    $this->kernel = new EasySDKKernel($config);

    $this->easySDKInitialized = TRUE;
  }

  /**
   * Get pay order number.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   *
   * @return string
   *   The pay order number.
   */
  private function getPaymentOrderNumber(PaymentInterface $payment): string {
    return (new \DateTime())->format('YmdHis') . mt_rand(1000, 9999) . '-' . $payment->id();
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

    $result = Factory::payment()->app()->pay($this->getOrderItemNames($payment), $this->getPaymentOrderNumber($payment), $this->getPaymentAmount($payment));
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

    // Call alipay\.trade\.page\.pay.
    $payment = $this->createPayment($commerce_order);
    $response = Factory::payment()->page()->pay($this->getOrderItemNames($payment), $this->getPaymentOrderNumber($payment), $this->getPaymentAmount($payment), '@todo');

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

    // Call alipay\.trade\.page\.pay.
    $payment = $this->createPayment($commerce_order);
    $response = Factory::payment()->faceToFace()->preCreate($this->getOrderItemNames($payment), $this->getPaymentOrderNumber($payment), $this->getPaymentAmount($payment));

    return $response->qr_code;
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL) {

  }

  /**
   * Verify content of alipay.
   *
   * @param string $content
   *   The content to verify.
   *
   * @return bool
   *   Indicated if success.
   */
  public function verifyContent(string $content, string $signature) {
    $signer = new Signer();
    $alipay_public_key = $this->kernel->isCertMode() ? $this->kernel->extractAlipayPublicKey("") : $this->kernel->getConfig("alipayPublicKey");
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

    try {
      if (isset($request->toArray()['sync_notify_from_app'])) {
        $rs = json_decode($request->toArray()['rs'], TRUE);

        if ($this->verifyContent(json_encode($rs['alipay_trade_app_pay_response']), $rs['sign'])) {
          $this->logger->notice('App sync notification verified successfully.');
        }
        else {
          $this->logger->notice('App sync notification verified fails.');
        }
      }
      else {
        if (Factory::payment()->common()->verifyNotify($request->toArray())) {
          $this->logger->notice('通知验证成功。');

          // 处理订单状态
          // load the payment.
          $payment_id = NULL;
          $id_info = explode('-', $request->get('out_trade_no'));
          if ($id_info && count($id_info) === 2) {
            $payment_id = $id_info[1];
          }
          else {
            $this->logger->error('out_trade_no不是预期格式[' . $request->get('out_trade_no') . ']: ');
            die('fail');
          }

          /** @var \Drupal\commerce_payment\Entity\Payment $payment_entity */
          $payment_entity = Payment::load($payment_id);
          if ($payment_entity instanceof PaymentInterface) {
            $payment_entity->setState('completed');
            $payment_entity->setRemoteId($request->get('trade_no'));
            $payment_entity->save();

            $order = $payment_entity->getOrder();
            $transition = $order->getState()->getWorkflow()->getTransition('place');
            $order->getState()->applyTransition($transition);
            $order->save();
          }
          else {
            // Payment doesn't exist.
            $this->logger->error('找不到支付订单[' . $payment_id . ']: ');
            die('fail');
          }

          die('success');
        }
        else {
          $this->logger->notice('通知验证失败。');
          die('fail');
        }
      }

    }
    catch (\Exception $e) {
      $this->logger->notice('通知验证请求没有成功：' . $e->getMessage());
      die('fail');
    }
  }

}
