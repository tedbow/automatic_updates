<?php

namespace Drupal\test_automatic_updates\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Class JsonTestController.
 */
class JsonTestController extends ControllerBase {

  /**
   * Test JSON controller.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Return JSON feed response.
   */
  public function json() {
    $feed[] = [
      'title' => 'Critical Release - PSA-2019-02-19',
      'link' => 'https://www.drupal.org/psa-2019-02-19',
      'project' => 'drupal/core',
      'modules' => ['forum', 'node'],
      'version' => '>=8.0.0 <8.6.10 || >=8.0.0 <8.5.11',
      'pubDate' => 'Tue, 19 Feb 2019 14:11:01 +0000',
    ];
    $feed[] = [
      'title' => 'Critical Release - PSA-Fictional PSA',
      'link' => 'https://www.drupal.org/psa-fictional-psa',
      'project' => 'drupal/core',
      'modules' => ['system'],
      'version' => '>=8.6.10 || >=8.5.11',
      'pubDate' => 'Tue, 19 Mar 2019 12:50:00 +0000',
    ];
    return new JsonResponse($feed);
  }

}
