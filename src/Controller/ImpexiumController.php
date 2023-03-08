<?php

namespace Drupal\impexium_api\Controller;

use Drupal\impexium_api\Service\ImpexiumApiService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ImpexiumController extends ControllerBase {

  public function logout() {
    // Log user out (destroy session data).
    $impexiumApiService = ImpexiumApiService::getInstance();
    $impexiumApiService->userLogout();
    // Redirect back home.
    $url = Url::fromRoute('<front>');
    $response = new RedirectResponse($url->toString());
    return $response->send();
  }
}
