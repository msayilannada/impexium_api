<?php

namespace Drupal\impexium_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class ImpexiumSettingsForm extends ConfigFormBase {

  const SETTINGS = 'impexium_api.settings';

  public function getFormId() {
    return 'impexium_api_config_settings';
  }

  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);
    $form['app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App ID'),
      '#default_value' => $config->get('app_id')
    ];
    $form['app_password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App Password'),
      '#default_value' => $config->get('app_password')
    ];
    $form['access_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Access Endpoint'),
      '#default_value' => $config->get('access_endpoint')
    ];
    $form['user_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User Email'),
      '#default_value' => $config->get('user_email')
    ];
    $form['user_password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User Password'),
      '#default_value' => $config->get('user_password')
    ];
    return parent::buildForm($form, $form_state);
  }
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable(static::SETTINGS)
      ->set('app_id', $form_state->getValue('app_id'))
      ->set('app_password', $form_state->getValue('app_password'))
      ->set('access_endpoint', $form_state->getValue('access_endpoint'))
      ->set('user_email', $form_state->getValue('user_name'))
      ->set('user_password', $form_state->getValue('user_password'))
      ->save();
    parent::submitForm($form, $form_state);
  }
}
