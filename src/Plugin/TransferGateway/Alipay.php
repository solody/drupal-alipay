<?php

namespace Drupal\alipay\Plugin\TransferGateway;

use Alipay\EasySDK\Kernel\Factory;
use Drupal\alipay\AlipayEasySdkTrait;
use Drupal\alipay\Form\AlipayConfigFormTrait;
use Drupal\commerce_price\Price;
use Drupal\account\Entity\WithdrawInterface;
use Drupal\account\Plugin\TransferGatewayBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\entity\BundleFieldDefinition;

/**
 * Transfer gateway implements in alipay.
 *
 * @TransferGateway(
 *   id = "alipay",
 *   label = @Translation("Alipay transfer")
 * )
 */
class Alipay extends TransferGatewayBase {

  use StringTranslationTrait;
  use AlipayConfigFormTrait;
  use AlipayEasySdkTrait;

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields['alipay_account'] = BundleFieldDefinition::create('string')
      ->setLabel($this->t('Account'))
      ->setDescription($this->t('account of the transfer target.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -9,
      ]);

    $fields['alipay_name'] = BundleFieldDefinition::create('string')
      ->setLabel($this->t('Name'))
      ->setDescription($this->t('Real name of the transfer target.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -9,
      ]);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function transfer(WithdrawInterface $withdraw): bool {
    $this->ensureEasySdkInitialized();

    $config = \Drupal::config('alipay.settings');

    // 计算手续费.
    $fee = $withdraw->getAmount()->multiply((string) ((float) $config->get('transfer_poundage.percentage') / 100));
    $fee = new Price((string) (ceil($fee->getNumber() * 100) / 100), $fee->getCurrencyCode());

    $amount = $withdraw->getAmount();
    $remark = $withdraw->getName();
    if ($config->get('transfer_poundage.enable') && !$fee->isZero()) {
      $amount = $amount->subtract($fee);
      $remark .= $this->t('(Poundage @fee subtracted)', [
        '@fee' => $this->getCurrencyFormatter()->format($fee->getNumber(), $fee->getCurrencyCode()),
      ]);
    }

    $bizParams = [
      'out_biz_no'      => $withdraw->id() . '-' . time(),
      'biz_scene' => 'DIRECT_TRANSFER',
      'trans_amount' => round($amount->getNumber(), 2),
      'product_code' => 'TRANS_ACCOUNT_NO_PWD',
      'order_title' => $remark,
      'payee_info' => [
        'identity' => $withdraw->getTransferMethod()->get('alipay_account')->value,
        'identity_type' => 'ALIPAY_LOGON_ID',
        'name' => $withdraw->getTransferMethod()->get('alipay_name')->value,
      ],
      'remark' => $remark,
    ];
    $response = Factory::util()->generic()->execute('alipay.fund.trans.uni.transfer', [''], $bizParams);

    if ($response->code !== '1000') {
      \Drupal::logger('alipay')->error(var_export($response->httpBody, TRUE));
      return FALSE;
    }
    else {
      $data = $response->toMap();
      $withdraw->setTransactionNumber($data['order_id']);
      return TRUE;
    }
  }

  /**
   * Get the currency formatter.
   *
   * @return \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface
   *   The formatter.
   */
  private function getCurrencyFormatter() {
    return \Drupal::getContainer()->get('commerce_price.currency_formatter');
  }

}
