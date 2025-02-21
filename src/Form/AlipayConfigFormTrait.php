<?php

namespace Drupal\alipay\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Alipay config form helper trait.
 */
trait AlipayConfigFormTrait {

  /**
   * Form constructor.
   *
   * Plugin forms are embedded in other forms. In order to know where the plugin
   * form is located in the parent form, #parents and #array_parents must be
   * known, but these are not available during the initial build phase. In order
   * to have these properties available when building the plugin form's
   * elements, let this method return a form element that has a #process
   * callback and build the rest of the form in the callback. By the time the
   * callback is executed, the element's #parents and #array_parents properties
   * will have been set by the form API. For more documentation on #parents and
   * #array_parents, see \Drupal\Core\Render\Element\FormElementBase.
   *
   * @param array $form
   *   An associative array containing the initial structure of the plugin form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form. Calling code should pass on a subform
   *   state created through
   *   \Drupal\Core\Form\SubformState::createForSubform().
   *
   * @return array
   *   The form structure.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
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
   * Form validation handler.
   *
   * @param array $form
   *   An associative array containing the structure of the plugin form as built
   *   by static::buildConfigurationForm().
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form. Calling code should pass on a subform
   *   state created through
   *   \Drupal\Core\Form\SubformState::createForSubform().
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
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the plugin form as built
   *   by static::buildConfigurationForm().
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form. Calling code should pass on a subform
   *   state created through
   *   \Drupal\Core\Form\SubformState::createForSubform().
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['app_id'] = $values['app_id'];
      $this->configuration['app_private_key_path'] = $values['app_private_key_path'];
      $this->configuration['app_cert_public_key_path'] = $values['app_cert_public_key_path'];
      $this->configuration['alipay_cert_public_key_path'] = $values['alipay_cert_public_key_path'];
      $this->configuration['alipay_root_cert_path'] = $values['alipay_root_cert_path'];
    }
  }

}
