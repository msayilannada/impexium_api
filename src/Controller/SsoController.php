<?php

namespace Drupal\impexium_api\Controller;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\impexium_api\Service\ImpexiumApiService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SsoController extends ControllerBase {

  public function login() {
    $ssoToken = \Drupal::request()->query->get('sso');
    $destinationUrl = \Drupal::request()->query->get('destination_url');
    // Makes sure SSO param is in valid UUID format.
    if ($this->isValidUuid($ssoToken)) {
      $impexiumApiService = ImpexiumApiService::getInstance();
      $user = $impexiumApiService->loginUserBySsoToken($ssoToken);
      if ($user) {
        $impexiumApiService->setUserSessionData($user);
        $impexiumApiService->handleUserActiveCommittee();
        if (!empty($destinationUrl)) {
          $destinationUrl = urldecode($destinationUrl);
          return new TrustedRedirectResponse($destinationUrl);
        } else {
          return new RedirectResponse('/');
        }
      } else {
        $this->redirectToMemberLogin();
      }
    } else {
      $this->redirectToMemberLogin();
    }
  }

  public function myProfileLogin()
  {
    $externalUrl = $this->getDestinationUrl();

    $impexiumApiService = ImpexiumApiService::getInstance();
    if ($impexiumApiService->isUserLoggedIn()) {
      $ssoToken = $impexiumApiService->getUserSessionData()['ssoToken'];
      return new TrustedRedirectResponse($externalUrl . '/my-profile?sso='.$ssoToken);
    } else {
      $ssoToken = \Drupal::request()->query->get('sso');
      if ($ssoToken && $this->isValidUuid($ssoToken)) {
        $user = $impexiumApiService->loginUserBySsoToken($ssoToken);
        if ($user) {
          $impexiumApiService->setUserSessionData($user);
          $impexiumApiService->handleUserActiveCommittee();
          return new TrustedRedirectResponse($externalUrl . '/my-profile?sso='.$ssoToken);
        } else {
          return $this->redirectToMemberLogin('/MyProfile');
        }
      } else {
        return $this->redirectToMemberLogin('/MyProfile');
      }
    }
    return $this->redirectToMemberLogin('/MyProfile');
  }

  private function isValidUuid($string)
  {
    if (empty($string) || !is_string($string) || (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $string) !== 1)) {
      return false;
    } else {
      return true;
    }
  }

  public function redirectToMemberLogin($destination = false)
  {
    if ($destination) {
      $url = '/member/login?destination='.urlencode($destination);
    } else {
      $url = '/member/login';
    }
    $response = new RedirectResponse($url);
    return $response;
  }

  public function getDestinationUrl()
  {
    if (isset($_ENV['PANTHEON_ENVIRONMENT']) && $_ENV['PANTHEON_ENVIRONMENT'] == 'live') {
      $rtn = 'https://members.nada.org';
    } else {
      $rtn = 'https://nada.mpxuat.com';
    }
    return $rtn;
  }
}
