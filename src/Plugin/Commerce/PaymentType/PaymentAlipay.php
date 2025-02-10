<?php

namespace Drupal\alipay\Plugin\Commerce\PaymentType;

use Drupal\commerce_payment\Plugin\Commerce\PaymentType\PaymentTypeBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_payment\Attribute\CommercePaymentType;

/**
 * Provides the manual payment type.
 */
#[CommercePaymentType(
  id: "payment_alipay",
  label: new TranslatableMarkup("Alipay"),
  workflow: "payment_alipay"
)]
class PaymentAlipay extends PaymentTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = [];
    $fields['trade_tracking_id'] = BaseFieldDefinition::create('string')
      ->setLabel($this->t('Trade tracking id'))
      ->setDescription($this->t('Keep the out_trade_no for refund.'))
      ->setDisplayConfigurable('view', TRUE);
    return $fields;
  }

}
