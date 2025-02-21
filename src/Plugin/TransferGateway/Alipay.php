<?php

namespace Drupal\alipay\Plugin\TransferGateway;

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
    // Return true; // 直接成功，方便测试.
    $transfer = $this->getSDK();

    /** @var AopTransferToAccountRequest $request */
    $request = $transfer->transfer();

    $config = \Drupal::config('alipay.settings');

    // 计算手续费.
    $fee = $withdraw->getAmount()->multiply((string) ((float) $config->get('transfer_poundage.percentage') / 100));
    $fee = new Price((string) (ceil($fee->getNumber() * 100) / 100), $fee->getCurrencyCode());

    $amount = $withdraw->getAmount();
    $remark = $withdraw->getName();
    if ($config->get('transfer_poundage.enable') && !$fee->isZero()) {
      $amount = $amount->subtract($fee);
      $remark .= '(已扣除手续费' . $this->getCurrencyFormatter()->format($fee->getNumber(), $fee->getCurrencyCode()) . ')';
    }

    $request->setBizContent([
      'out_biz_no'      => $withdraw->id() . '-' . time(),
      'payee_type' => 'ALIPAY_LOGONID',
      'payee_account' => $withdraw->getTransferMethod()->get('alipay_account')->value,
      'amount' => round($amount->getNumber(), 2),
      'payer_show_name' => \Drupal::config('system.site')->get('name') . '：' . $withdraw->getName(),
      'payee_real_name' => $withdraw->getTransferMethod()->get('alipay_name')->value,
      'remark' => $remark,
    ]);

    /** @var AopTransferToAccountResponse $response */
    $response = $request->send();

    if (!$response->isSuccessful()) {
      \Drupal::logger('alipay')->notice(var_export($response->getData(), TRUE));
      throw new \Exception(
        $response->data('alipay_fund_trans_toaccount_transfer_response.code') . ' ' .
        $response->data('alipay_fund_trans_toaccount_transfer_response.msg') . ' ' .
        $response->data('alipay_fund_trans_toaccount_transfer_response.sub_code') . ' ' .
        $response->data('alipay_fund_trans_toaccount_transfer_response.sub_msg'));
    }
    else {
      $order_id = $response->data('alipay_fund_trans_toaccount_transfer_response.order_id');
      $withdraw->setTransactionNumber($order_id);
    }

    return $response->isSuccessful();
  }

  /**
   * @return \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface
   */
  private function getCurrencyFormatter() {
    return \Drupal::getContainer()->get('commerce_price.currency_formatter');
  }

}
