<?php

namespace Drupal\impexium_api\Controller;

use Drupal\impexium_api\Service\ImpexiumApiService;
use Drupal\Core\Controller\ControllerBase;

class UserDataController extends ControllerBase {

  public function render()
  {
    $impexiumApiService = ImpexiumApiService::getInstance();
    $user = $impexiumApiService->getUserSessionData();
    if ($user) {
      $userData = json_encode($user);
      $userData = json_encode(json_decode($userData, TRUE), JSON_PRETTY_PRINT);
    } else {
      $userData = 'No user is currently logged in to Impexium';
    }
    return [
      '#theme' => 'impexium_user_data',
      '#userData' => $userData
    ];
  }
}
