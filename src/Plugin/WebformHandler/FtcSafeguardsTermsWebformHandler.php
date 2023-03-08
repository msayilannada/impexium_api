<?php

namespace Drupal\impexium_api\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\impexium_api\Service\ImpexiumApiService;


/**
 * Accept FTC Safeguards Terms.
 *
 * @WebformHandler(
 *   id = "FTC Safeguards Terms Webform handler",
 *   label = @Translation("Accept FTC Safeguards Terms."),
 *   category = @Translation("Entity Creation"),
 *   description = @Translation("Accept FTC Safeguards Terms."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */

class FtcSafeguardsTermsWebformHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */

  /**
   * Function to be fired after submitting the Webform.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   * @param bool $update
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $values = $webform_submission->getData();
    if (!empty($values['full_name']) && $values['yes_i_agree_to_this_disclaimer'] == 1) {
      $impexiumApiService = ImpexiumApiService::getInstance();
      $user = $impexiumApiService->getUserSessionData();
      if (!empty($user) && !empty($user['id'])) {
        $impexiumApiService->acceptFtcSafeguardsTerms($user['id']);
      }
    }
  }
}
