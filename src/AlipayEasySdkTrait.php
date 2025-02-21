<?php

namespace Drupal\alipay;

use Alipay\EasySDK\Kernel\CertEnvironment;
use Alipay\EasySDK\Kernel\Config;
use Alipay\EasySDK\Kernel\EasySDKKernel;
use Alipay\EasySDK\Kernel\Factory;

/**
 * Alipay Sdk trait.
 */
trait AlipayEasySdkTrait {

  /**
   * Indicated easySDK initialized.
   *
   * @var bool
   */
  private $easySDKInitialized = FALSE;

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

}
