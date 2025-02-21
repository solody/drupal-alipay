<?php

namespace Drupal\alipay\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * The form to edit transfer settings.
 */
class TransferSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'alipay.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'alipay_transfer_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('alipay.settings');

    $form['transfer_poundage_enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable transfer poundage'),
      '#description' => $this->t('Automatically subtract poundage before transfer.'),
      '#default_value' => $config->get('transfer_poundage.enable'),
      '#weight' => '0',
    ];
    $form['transfer_poundage_percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Percentage'),
      '#description' => $this->t('Percentage of poundage will be subtract.'),
      '#default_value' => $config->get('transfer_poundage.percentage'),
      '#min' => 0.00,
      '#max' => 100.00,
      '#step' => 0.01,
      '#field_suffix' => '%',
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('alipay.settings')
      ->set('transfer_poundage.enable', $form_state->getValue('transfer_poundage_enable'))
      ->set('transfer_poundage.percentage', $form_state->getValue('transfer_poundage_percentage'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
