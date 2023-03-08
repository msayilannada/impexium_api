<?php

namespace Drupal\impexium_api\Service;

use Drupal\facets\Exception\Exception;
use Drupal\nada_utility\Service\NadaUtilityService;
use GuzzleHttp\Exception\ClientException;
use Http\Client\Exception\RequestException;

class ImpexiumApiService
{
  private static $instances = [];
  protected $tempStore;
  private $appId;
  private $appPassword;
  protected $apiUserEmail;
  protected $apiUserPassword;
  private $accessEndpoint;
  private $appAuth;
  protected $authExpire;
  private $config;
  public $utilityService;
  protected $client;

  public function __construct()
  {
    $this->tempStore = \Drupal::service('tempstore.private')->get('impexium_api');
    $this->config = \Drupal::configFactory()->getEditable('impexium_api.settings');
    $this->appId = $this->config->get('app_id');
    $this->appPassword = $this->config->get('app_password');
    $this->apiUserEmail = $this->config->get('user_email');
    $this->apiUserPassword = $this->config->get('user_password');
    $this->accessEndpoint = $this->config->get('access_endpoint');
    $this->appAuth = [
      'AppName' => $this->appId,
      'AppKey' => $this->appPassword
    ];
    $this->utilityService = new NadaUtilityService();
    $this->client = \Drupal::httpClient();
  }

  protected function __clone() { }

  public function __wakeup()
  {
    throw new \Exception("Cannot unserialize a singleton.");
  }

  public static function getInstance(): ImpexiumApiService
  {
    $cls = static::class;
    if (!isset(self::$instances[$cls])) {
      self::$instances[$cls] = new static();
    }
    return self::$instances[$cls];
  }

  public function updateApiTokens()
  {
    $apiAuth = $this->getApiAuth($this->apiUserEmail, $this->apiUserPassword);
    if ($apiAuth && is_array($apiAuth)) {
      $dt = new \DateTime();
      $dt->add(new \DateInterval('PT23H'));
      $this->config->set('auth_expire', $dt->format('Y-m-d H:i:s'));
      $this->config->set('auth_app_token', $apiAuth['appToken']);
      $this->config->set('auth_user_token', $apiAuth['userToken']);
      $this->config->set('auth_base_uri', $apiAuth['baseUri']);
      $this->config->set('auth_user_id', $apiAuth['userId']);
      $this->config->save();
      return [
        'success' => true,
        'data' => $apiAuth
      ];
    } elseif (!empty($apiAuth)) {
      \Drupal::logger('impexium_api')->error($apiAuth);
      return [
        'success' => false,
        'data' => null
      ];
    } else {
      return [
        'success' => false,
        'data' => null
      ];
    }
  }

  private function checkApiAuth()
  {
    $authExpire = $this->config->get('auth_expire');
    $appToken = $this->config->get('auth_app_token');
    $userToken = $this->config->get('auth_user_token');
    $userId = $this->config->get('auth_user_id');
    $baseUri = $this->config->get('auth_base_uri');

    $needsValidation = true;

    if (!empty($authExpire) && !empty($appToken) && !empty($userToken) && !empty($userId) && !empty($baseUri)) {
      $authExpire = new \DateTime($authExpire);
      $now = new \DateTime();
      if ($authExpire > $now) {
        $needsValidation = false;
      }
    }

    if ($needsValidation) {
      $auth = $this->updateApiTokens();
      if ($auth['success']) {
        return $auth['data'];
      } else {
        return false;
      }
    } else {
      return [
        'appToken' => $appToken,
        'baseUri' => $baseUri,
        'userToken' => $userToken,
        'userId' => $userId
      ];
    }
  }

  private function getApiAuth($username, $password)
  {
    $auth = FALSE;
    // Step 1: Get Web API End Point and Access Token
    $data = $this->sendRequest($this->accessEndpoint, $this->appAuth);
    if (!empty($data) && is_array($data)) {
      $apiEndPoint = $data['uri'];
      $accessToken = $data['accessToken'];
      // Step 2: Authenticate App, Get App Token, Get User Token (SSO Token)
      $data = [
        'AppId' => $this->appId,
        'AppPassword' => $this->appPassword,
        'AppUserEmail' => $username,
        'AppUserPassword' => $password
      ];
      $response = $this->sendRequest($apiEndPoint, $data, [
        'AccessToken' => $accessToken
      ]);
      if (!empty($response) && is_array($response)) {
        $auth = [
          'appToken' => $response['appToken'],
          'baseUri' => $response['uri'],
          'userToken' => $response['userToken'],
          'userId' => $response['userId'],
          'ssoToken' => $response['ssoToken']
        ];
      } elseif (!empty($response)) {
        return $response;
      } else {
        \Drupal::logger('Impexium API')->notice('Failed to authenticate application. Please notify the web administrator of this issue.');
      }
    } elseif (!empty($data)) {
      return $data;
    } else {
      \Drupal::logger('Impexium API')->notice('Failed to get Web API End Point and Access Token. Please notify the web administrator of this issue.');
    }
    return $auth;
  }

  /**
   * https://members.nada.org/api/help/Api/GET-api-v1-Individuals-Profile-idOrRecordNumberOrEmail-pageNumber_loadPrimaryOrgDetails_loadRelationships_IncludeDetails
   */
  public function getImpexiumUser($username, $password)
  {
    $result = $this->getApiAuth($username, $password);
    if (!empty($result) && is_array($result)) {
      $getIndividualProfile = '/Individuals/Profile/' . $result['userId'] . '/1?loadPrimaryOrgDetails=true&loadRelationships=true&IncludeDetails=true';
      $data = $this->sendRequest($result['baseUri'] . $getIndividualProfile, null, array(
        'usertoken' => $result['userToken'],
        'apptoken' => $result['appToken'],
      ));
      if ($data && !empty($data['dataList'])) {
        $user = $data['dataList'][0];
        $user['ssoToken'] = $result['ssoToken'];
      } else {
        $user = false;
      }
    } elseif (!empty($result)) {
      return $result;
    } else {
      $user = false;
    }
    return $user;
  }

  /**
   * Get Impexium user session data.
   *
   * @return mixed
   */
  public function getUserSessionData() {
    return $this->tempStore->get('impexium_user_data');
  }

  /**
   * Set Impexium user session data.
   *
   * @param $data
   */
  public function setUserSessionData($data) {
    $this->tempStore->set('impexium_user_data', $data);
  }

  /**
   * Check if Impexium User Session Data Exists.
   *
   * @return bool
   */
  public function isUserLoggedIn() {
    return !empty($this->tempStore->get('impexium_user_data'));
  }

  /**
   * Destroy Impexium User Session Data.
   */
  public function userLogout() {
    \Drupal::messenger()->addStatus('You have been logged out.');
    if ($this->tempStore) {
      $this->tempStore->delete('impexium_user_data');
    }
  }

  /**
   * https://members.nada.org/api/help/Api/GET-api-v1-Individuals-Profile-idOrRecordNumberOrEmail-pageNumber_loadPrimaryOrgDetails_loadRelationships_IncludeDetails
   */
  public function getUserByRecordNumber($recordNumber)
  {
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
      $getIndividualProfile = '/Individuals/Profile/' . $recordNumber . '/1?loadPrimaryOrgDetails=true&loadRelationships=true&IncludeDetails=true';
      $data = $this->sendRequest($apiAuth['baseUri'] . $getIndividualProfile, null, array(
        'usertoken' => $apiAuth['userToken'],
        'apptoken' => $apiAuth['appToken'],
      ));
      $rtn = !empty($data['dataList']) ? $data['dataList'][0] : FALSE;
    } else {
      $rtn = FALSE;
    }
    return $rtn;
  }

  public function loginUserBySsoToken($ssoToken)
  {
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
      $getProfileBySsoToken = '/Individuals/FindBySsoToken/' . $ssoToken;
      $data = $this->sendRequest($apiAuth['baseUri'] . $getProfileBySsoToken, null, array(
        'usertoken' => $apiAuth['userToken'],
        'apptoken' => $apiAuth['appToken'],
      ));
      $data = $data['dataList'][0];
      if ($data && !empty($data['id'])) {
        $user = $this->getUserByRecordNumber($data['id']);
        $user['ssoToken'] = $ssoToken;
        $rtn = $user;
      } else {
        $rtn = FALSE;
      }
    } else {
      $rtn = FALSE;
    }
    return $rtn;
  }

  public function getUserCustomFields($userId)
  {
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
      $getUserCustomFields = '/Individuals/' . $userId . '/CustomFields';
      $data = $this->sendRequest($apiAuth['baseUri'] . $getUserCustomFields, null, array(
        'usertoken' => $apiAuth['userToken'],
        'apptoken' => $apiAuth['appToken'],
      ));
      $rtn = $data;
    } else {
      $rtn = FALSE;
    }
    return $rtn;
  }

  public function getUserRecordNumber()
  {
    return $this->getUserSessionData()['recordNumber'];
  }

  public function getUserMembershipCodes()
  {
    $codes = [];
    foreach ($this->getUserSessionData()['memberships'] as $m) {
      $codes[] = $m['code'];
    }
    return $codes;
  }

  public function getUserCommitteePositionCodes()
  {
    $codes = [];
    foreach ($this->getUserSessionData()['committees'] as $committee) {
      $codes[] = strtolower($committee['positionCode']);
    }
    return $codes;
  }

  public function setActiveCommittee($committee)
  {
    $data = $this->getUserSessionData();
    $data['activeCommittee'] = $committee;
    $this->setUserSessionData($data);
  }

  public function getActiveCommittee()
  {
    return isset($this->getUserSessionData()['activeCommittee']) ? $this->getUserSessionData()['activeCommittee'] : null;
  }

  public function handleUserActiveCommittee()
  {
    $committeeCodes = $this->getUser20GroupCommitteeCodes();
    if (!empty($committeeCodes)) {
      $committeeCodes = array_filter($committeeCodes);
      usort($committeeCodes, function ($code1, $code2) {
        return $code1['committeeCode'] <=> $code2['committeeCode'];
      });
      $this->setActiveCommittee($committeeCodes[0]);
    }
  }

  public function getUserCommitteeMeetingsByCode($code)
  {
    $data = $this->getUserSessionData();
    if (isset($data['meetings'])) {
      return isset($this->getUserSessionData()['meetings'][$code]) ? $this->getUserSessionData()['meetings'][$code] : null;
    } else {
      return false;
    }
  }

  public function setUserCommitteeMeetingsByCode($code, $meetingData)
  {
    $data = $this->getUserSessionData();
    $data['meetings'][$code] = $meetingData;
    $this->setUserSessionData($data);
  }

  public function getUser20GroupCommitteeCodes()
  {
    $codes = [];
    foreach ($this->getUserSessionData()['committees'] as $committee) {
      if ($this->utilityService->contains($committee['committee']['categoryName'], '20 Group') || $this->utilityService->contains($committee['committee']['categoryName'], 'Dealer Academy')) {
        if (!$this->utilityService->contains($committee['committee']['code'], '_SUBBP')) {
          if ($this->utilityService->contains($committee['committee']['code'], '_SUB')) {
            $codes[] = [
              'committeeCode' => $committee['code'] . 'M',
              'positionCode' => $committee['positionCode']
            ];
          } else if ($committee['positionCode'] == 'CONSULT' || $committee['positionCode'] == 'STAFF') {
            $codes[] = [
              'committeeCode' => $committee['committee']['code'],
              'positionCode' => $committee['positionCode']
            ];
          } else {
            $codes[] = [
              'committeeCode' => $committee['code'],
              'positionCode' => $committee['positionCode']
            ];
          }
        }
      }
    }
    return $codes;
  }

  public function getUserCommitteeByCode($code)
  {
    $committeeInfo = false;
    foreach ($this->getUserSessionData()['committees'] as $committee) {
      $committeeCode = substr($committee['code'], 0, 6);
      if ($code == $committeeCode) {
        return $committee;
      }
    }
    return $committeeInfo;
  }

  public function getUserEmail()
  {
    return $this->getUserSessionData()['email'];
  }

  public function getUserEmailDomain()
  {
    $email = $this->getUserEmail();
    if ($email) {
      $emailExplode = explode('@', $email);
      $rtn = $emailExplode[1];
    } else {
      $rtn = '';
    }
    return $rtn;
  }

  /**
   * https://members.nada.org/api/help/Api/GET-api-v1-Committees-idOrCode-Members-pageNumber_term_positionCodes
   */
  public function getCommitteeMembersWithShortCode($committeeCode)
  {
    $rtn = false;
    $committeeCode = substr($committeeCode, 0, 4);
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
      $getCommitteeMembers = '/Committees/'. $committeeCode . '/Members/1';
      $data = $this->sendRequest($apiAuth['baseUri'] . $getCommitteeMembers, null, array(
        'usertoken' => $apiAuth['userToken'],
        'apptoken' => $apiAuth['appToken'],
      ));
      if ($data) {
        if (isset($data['dataList']) && !empty($data['dataList'])) {
          $rtn = $data['dataList'];
        }
      }
    }
    return $rtn;
  }

  public function getCommitteeMembersWithFullCode($committeeCode)
  {
    $rtn = false;
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
      $getCommitteeMembers = '/Committees/'. $committeeCode . '/Members/1';
      $data = $this->sendRequest($apiAuth['baseUri'] . $getCommitteeMembers, null, array(
        'usertoken' => $apiAuth['userToken'],
        'apptoken' => $apiAuth['appToken'],
      ));
      if ($data) {
        if (isset($data['dataList']) && !empty($data['dataList'])) {
          $rtn = $data['dataList'];
        }
      }
    }
    return $rtn;
  }

  /**
   * https://members.nada.org/api/help/Api/GET-api-v1-Organizations-Profile-idOrRecordnumber-pageNumber_includeDescription
   */
  public function getOrganizationProfile($organizationId)
  {
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
      $getOrganizationProfile = '/Organizations/Profile/'. $organizationId;
      $data = $this->sendRequest($apiAuth['baseUri'] . $getOrganizationProfile, null, array(
        'usertoken' => $apiAuth['userToken'],
        'apptoken' => $apiAuth['appToken'],
      ));
      if ($data) {
        $rtn = $data['dataList'][0];
      } else {
        $rtn = false;
      }
    } else {
      $rtn = FALSE;
    }
    return $rtn;
  }

  /**
   * https://members.nada.org/api/help/Api/POST-api-v1-Organizations-id-Customfields
   */
  public function getOrganizationCustomFields($organizationId)
  {
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
      $getOrganizationCustomFields = '/Organizations/'. $organizationId . '/CustomFields';
      $data = $this->sendRequest($apiAuth['baseUri'] . $getOrganizationCustomFields, null, array(
        'usertoken' => $apiAuth['userToken'],
        'apptoken' => $apiAuth['appToken'],
      ));
      $rtn = $data;
    } else {
      $rtn = FALSE;
    }
    return $rtn;
  }

  public function getAtaeRegion($organizationId)
  {
    foreach ($this->getOrganizationCustomFields($organizationId) as $f) {
      if ($f['name'] == 'atae_region') {
        return $f['value'];
      }
    }
    return false;
  }

  /**
   * https://members.nada.org/api/help/Api/GET-api-v1-Events-All-pageNumber_code_name_tag
   */
  public function getAllEvents($code = null, $name = null, $tag = null, $filter = '')
  {
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
//      $getAllEvents = '/Events/All/';
      $getAllEvents = '/Events/Upcoming/';
      $queryBuild = [];
      if (!empty($code)) $queryBuild['code'] = $code;
      if (!empty($name)) $queryBuild['name'] = $name;
      if (!empty($tag)) $queryBuild['tag'] = $tag;
      $query = !empty($queryBuild) ? "?".http_build_query($queryBuild) : "";
      $pageCount = 1;
      $events = [];
      do {
        $apiCall = $apiAuth['baseUri'] . $getAllEvents . $pageCount. $query;
        try {
          $data = $this->sendRequest($apiCall, null, array(
            'usertoken' => $apiAuth['userToken'],
            'apptoken' => $apiAuth['appToken'],
          ));
          if (isset($data['dataList']) && !empty($data['dataList'])) {
            foreach ($data['dataList'] as $d) {
              $events[] = $d;
            }
            $pageCount++;
          }
        } catch (\Exception $e) {
          // Move on
          break;
        }
      } while (!empty($data['dataList']));

      $rtn = $events;
    } else {
      $rtn = FALSE;
    }
    return $rtn;
  }

  /**
   * https://members.nada.org/api/help/Api/POST-api-v1-Individuals-id-Customfields
   *
   */
  public function acceptNadcTerms($userId)
  {
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
      $acceptTerms = ['name' => 'nadc_accepted_terms', 'value' => 'true'];
      $updateUserCustomFields = '/Individuals/'. $userId . '/CustomFields';
      $this->sendRequest($apiAuth['baseUri'] . $updateUserCustomFields, $acceptTerms, array(
        'usertoken' => $apiAuth['userToken'],
        'apptoken' => $apiAuth['appToken'],
      ));
      $userCustomFields = $this->getUserCustomFields($userId);
      if ($userCustomFields) {
        $user = $this->getUserSessionData();
        $user['customFields'] = $userCustomFields;
        $this->setUserSessionData($user);
        $rtn = TRUE;
      } else {
        $rtn = FALSE;
      }
    } else {
      $rtn = FALSE;
    }
    return $rtn;
  }

  /**
   * https://members.nada.org/api/help/Api/POST-api-v1-Individuals-id-Customfields
   *
   */
  public function acceptFtcSafeguardsTerms($userId)
  {
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
      $acceptTerms = ['name' => 'ftc_safeguard_terms', 'value' => 'true'];
      $updateUserCustomFields = '/Individuals/'. $userId . '/CustomFields';
      $this->sendRequest($apiAuth['baseUri'] . $updateUserCustomFields, $acceptTerms, array(
        'usertoken' => $apiAuth['userToken'],
        'apptoken' => $apiAuth['appToken'],
      ));
      $userCustomFields = $this->getUserCustomFields($userId);
      if ($userCustomFields) {
        $user = $this->getUserSessionData();
        $user['customFields'] = $userCustomFields;
        $this->setUserSessionData($user);
        $rtn = TRUE;
      } else {
        $rtn = FALSE;
      }
    } else {
      $rtn = FALSE;
    }
    return $rtn;
  }

  public function getUserMeetings()
  {
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
      $getAllEvents = '/Events/All/1?code=';
      $events = [];
      $committeeCodes = $this->getUserCommitteeCodes();
      foreach ($committeeCodes as $code) {
        $data = $this->sendRequest($apiAuth['baseUri'] . $getAllEvents . $code, null, array(
          'usertoken' => $apiAuth['userToken'],
          'apptoken' => $apiAuth['appToken'],
        ));
        foreach ($data['dataList'] as $d) {
          $events[] = $d;
        }
      }
      $rtn = $events;
    } else {
      $rtn = FALSE;
    }
    return $rtn;
  }

  public function getMeetingByCode($code)
  {
    $apiAuth = $this->checkApiAuth();
    if ($apiAuth) {
      $getAllEvents = '/Events/All/1?code=';
      try {
        $data = $this->sendRequest($apiAuth['baseUri'] . $getAllEvents . $code, null, array(
          'usertoken' => $apiAuth['userToken'],
          'apptoken' => $apiAuth['appToken'],
        ));
      } catch (\Exception $e) {
//        \Drupal::logger('impexium_api')->warning(t($e->getMessage()));
      }
      if (!empty($data['dataList'])) {
        $rtn = $data['dataList'];
      } else {
        $rtn = FALSE;
      }
    } else {
      $rtn = FALSE;
    }
    return $rtn;
  }

  public function getUserCommitteeCodes()
  {
    $committeeCodes = [];
    foreach ($this->getUserSessionData()['committees'] as $c) {
      $committeeCodes[] = $c['committee']['code'];
    }
    return $committeeCodes;
  }

  public function isUserNADC()
  {
    $user = $this->getUserSessionData();
    if ($user) {
      if (!empty($user['relationships'])) {
        foreach ($user['relationships'] as $relationship) {
          if (!empty($relationship['relatedToCustomer'])
            && ($this->utilityService->contains($relationship['relatedToCustomer']['name'], 'National Association of Dealer Counsel')
            || $relationship['relatedToCustomer']['recordNumber'] == '331152191')) {
            return true;
          }
        }
      }
    }
    return false;
  }

  public function hasAcceptedNadcTerms()
  {
    $user = $this->getUserSessionData();
    if ($user) {
      if (!empty($user['customFields'])) {
        foreach ($user['customFields'] as $cf) {
          if ($cf['name'] == 'nadc_accepted_terms' && strtolower($cf['value']) == 'true') {
            return true;
          }
        }
      }
    }
    return false;
  }

  public function hasAcceptedFtcSafeguardTerms()
  {
    $user = $this->getUserSessionData();
    if ($user) {
      if (!empty($user['customFields'])) {
        foreach ($user['customFields'] as $cf) {
          if ($cf['name'] == 'ftc_safeguard_terms' && strtolower($cf['value']) == 'true') {
            return true;
          }
        }
      }
    }
    return false;
  }

  /**
   * This sends the user record number to a REST endpoint that will add the user's committee
   * meetings to the system (if they do not already exist).
   *
   * @param $userRecordNumber
   */
  public function addCommitteeMeetings($userRecordNumber)
  {
    $host = $_SERVER['HTTP_HOST'];
    $protocol = ($host == 'nada.tombrasweb.com') ? 'http://' : 'https://';
    $baseUrl = $protocol . $host;
    $restUrl = $baseUrl . '/rest/api/add-committee-meeting';

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $restUrl.'?_format=hal_json');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, FALSE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['userRecordNumber' => $userRecordNumber]));
    curl_setopt($ch, CURLOPT_USERPWD, 'system.import' . ':' . 'WUURXKsbskwwGkb6DRZwGeAX');

    $headers = [];
    $headers[] = 'Content-Type: application/hal+json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
      \Drupal::logger('NADA 20 Group')->notice('Curl error: ' . curl_error($ch));
    }
    curl_close($ch);
  }

  /**
   * Model array for a Professional Series / Academy class.
   */
  public function getEventModel()
  {
    return [
      'eventId' => null,
      'eventCode' => null,
      'eventName' => null,
      'eventStartDate' => null,
      'eventStartTime' => null,
      'eventStartDateTime' => null,
      'eventEndDate' => null,
      'eventEndTime' => null,
      'eventEndDateTime' => null,
      'eventTimeZone' => null,
      'eventDescription' => null,
      'publicEvent' => null,
      'eventLocationsName' => null,
      'eventLocationsDescription' => null,
      'eventLocationsAddresses' => null,
      'eventCategory' => null,
      'eventCategoryName' => null,
      'eventSubCategoryName' => null,
      'sortCertificates' => null,
    ];
  }

  /**
   * Notify ActOn (marketing platform) of Impexium user login.
   * https://forpci35.actonsoftware.com/app/classic/if/account/externalURLS.jsp
   *
   * @param $firstName
   * @param $lastName
   * @param $email
   */
  public function notifyActOn($firstName, $lastName, $email)
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,"https://marketing.nada.org/acton/eform/4712/010e/d-ext-0001");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('firstname' => $firstName, 'lastname' => $lastName, 'email' => $email)));

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $server_output = curl_exec($ch);
    curl_close ($ch);
  }

  /**
   * @throws \Exception
   * @returns {mixed}
   */
  private function sendRequest($url, $data, $customHeaders = null)
  {
    /** Throttle requests. Impexium returns 429 on calls over 3 per second and 60 per minute. */
    $last_mtime = $this->tempStore->get('impexium_last_request');
    if (!empty($last_mtime)) {
      $diff = microtime(TRUE) - $last_mtime;
      if ($diff < 1 ) {
        usleep(500000); // wait 1/2 second
      }
    }
    /** End throttle */

    $method = !empty($data) ? 'post' : 'get';
    $options = [];
    $headers = [];
    if (!empty($customHeaders)) {
      // in case incoming headers are json string.
      $customHeaders = !is_array($customHeaders) ? json_decode($customHeaders, TRUE) : $customHeaders;
      // prepare headers for request
      $headers = array_merge($headers, $customHeaders);
    }
    $options['headers'] = $headers;

    if ($method == 'post') {
      $options['json'] = $data;
    }

    try {
      // Make request
      $response = $this->client->{$method}($url, $options);
      $return = $response->getBody();
    } catch (\GuzzleHttp\Exception\ClientException $e) {
      $t = $e->getMessage();
      $message = (string) $e->getMessage();
      $exploded = explode("\n", $message);
      $this->tempStore->set('impexium_last_request', microtime(TRUE));
      return !empty($exploded[1]) ? $exploded[1] : "";
    }

    if (!empty($return) && empty(json_decode($return))) {
      $string = (string) $return;
      $this->tempStore->set('impexium_last_request', microtime(TRUE));
      return $string;
    }

    $decode = json_decode($return, TRUE);
    $this->tempStore->set('impexium_last_request', microtime(TRUE));
    return $decode;

    // Legacy curl call
    /*
    $time_start = microtime(true);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if ($customHeaders !== null ) {
      $headers = $customHeaders;
    } else {
      $headers = [];
    }
    if ($data === null ) {
      curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "GET");
    } else {
      curl_setopt( $ch , CURLOPT_CUSTOMREQUEST, "POST");
      $json = json_encode($data);
      $headers[] = 'Content-Length: ' . strlen($json);
      $headers[] = 'Content-Type: application/json; charset=utf-8';
      curl_setopt ($ch, CURLOPT_POSTFIELDS, $json);
    }
    curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt ( $ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    if (!empty($response) && empty(json_decode($response))) {
      return $response;
    }
    return json_decode($response, true);
    */

  }

  public function getImpexiumExternalEnvUrl()
  {
    if (isset($_ENV['PANTHEON_ENVIRONMENT']) && $_ENV['PANTHEON_ENVIRONMENT'] == 'live') {
      $rtn = 'https://members.nada.org';
    } else {
      $rtn = 'https://nada.mpxuat.com';
    }
    return $rtn;
  }
}
