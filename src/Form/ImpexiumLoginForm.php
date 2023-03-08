<?php

namespace Drupal\impexium_api\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\impexium_api\Service\ImpexiumApiService;

class ImpexiumLoginForm extends FormBase {

  public function getFormId() {
    return 'impexium_login_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#placeholder' => $this->t('your@email.com'),
      '#required' => TRUE
    ];
    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#placeholder' => $this->t('password'),
      '#required' => TRUE
    ];
    $form['actions'] = [
      '#type' => 'actions'
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Login')
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateForm($form, $form_state);

    $username = $form_state->getValue('username');
    $password = $form_state->getValue('password');

    if (empty($username)) {
      $form_state->setErrorByName('username', 'A username must be provided.');
    }

    if (empty($password)) {
      $form_state->setErrorByName('password', 'A password must be provided.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // Clear session just in case it was not fully removed on logout.
    $tempStore = \Drupal::service('tempstore.private')->get('impexium_api');;
    $tempStore->delete('impexium_user_data');
    $messenger = \Drupal::messenger();
    $destination_url = \Drupal::request()->query->get('destination');
    $impexiumApiService = ImpexiumApiService::getInstance();
    $userResponse = $impexiumApiService->getImpexiumUser($form_state->getValue('username'), $form_state->getValue('password'));
    if (!empty($userResponse) && is_array($userResponse)) {
      $messenger->addStatus(t('You have been logged in.'));
      $impexiumApiService->setUserSessionData($userResponse);
      $impexiumApiService->notifyActOn($userResponse['firstName'], $userResponse['lastName'], $userResponse['email']);
      $impexiumApiService->handleUserActiveCommittee();
      // handle redirect tokens
      $token_service = \Drupal::token();
      $destination_url = $token_service->replace($destination_url);
      \Drupal::request()->query->set('destination', $destination_url);
    } elseif(!empty($userResponse)) {
      $messenger->addError(t($userResponse));
    } else {
      $messenger->addError('Failed to log in.');
    }
  }
}
