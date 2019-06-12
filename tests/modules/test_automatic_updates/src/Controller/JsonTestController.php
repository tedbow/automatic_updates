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
    $feed = [];
    $feed[] = [
      'title' => 'Critical Release - SA-2019-02-19',
      'link' => 'https://www.drupal.org/sa-2019-02-19',
      'project' => 'drupal',
      'type' => 'core',
      'insecure' => [
        '7.65',
        '8.5.14',
        '8.5.14',
        '8.6.13',
        '8.7.0-alpha2',
        '8.7.0-beta1',
        '8.7.0-beta2',
        '8.6.14',
        '8.6.15',
        '8.6.15',
        '8.5.15',
        '8.5.15',
        '7.66',
        '8.7.0',
        \Drupal::VERSION,
      ],
      'is_psa' => '0',
      'pubDate' => 'Tue, 19 Feb 2019 14:11:01 +0000',
    ];
    $feed[] = [
      'title' => 'Critical Release - PSA-Really Old',
      'link' => 'https://www.drupal.org/psa',
      'project' => 'drupal',
      'type' => 'core',
      'is_psa' => '1',
      'insecure' => [],
      'pubDate' => 'Tue, 19 Feb 2019 14:11:01 +0000',
    ];
    $feed[] = [
      'title' => 'Node - Moderately critical - Access bypass - SA-CONTRIB-2019',
      'link' => 'https://www.drupal.org/sa-contrib-2019',
      'project' => 'node',
      'type' => 'module',
      'is_psa' => '0',
      'insecure' => ['7.x-7.22', '8.x-8.2.0'],
      'pubDate' => 'Tue, 19 Mar 2019 12:50:00 +0000',
    ];
    $feed[] = [
      'title' => 'Standard - Moderately critical - Access bypass - SA-CONTRIB-2019',
      'link' => 'https://www.drupal.org/sa-contrib-2019',
      'project' => 'standard',
      'type' => 'profile',
      'insecure' => ['8.x-8.6.13', '8.x-' . \Drupal::VERSION],
      'is_psa' => '0',
      'pubDate' => 'Tue, 19 Mar 2019 12:50:00 +0000',
    ];
    $feed[] = [
      'title' => 'Seven - Moderately critical - Access bypass - SA-CONTRIB-2019',
      'link' => 'https://www.drupal.org/sa-contrib-2019',
      'project' => 'seven',
      'type' => 'theme',
      'is_psa' => '0',
      'insecure' => ['8.x-8.7.0', '8.x-' . \Drupal::VERSION],
      'pubDate' => 'Tue, 19 Mar 2019 12:50:00 +0000',
    ];
    $feed[] = [
      'title' => 'Foobar - Moderately critical - Access bypass - SA-CONTRIB-2019',
      'link' => 'https://www.drupal.org/sa-contrib-2019',
      'project' => 'foobar',
      'type' => 'foobar',
      'is_psa' => '1',
      'insecure' => [],
      'pubDate' => 'Tue, 19 Mar 2019 12:50:00 +0000',
    ];
    $feed[] = [
      'title' => 'Token - Moderately critical - Access bypass - SA-CONTRIB-2019',
      'link' => 'https://www.drupal.org/sa-contrib-2019',
      'project' => 'token',
      'type' => 'module',
      'is_psa' => '0',
      'insecure' => ['7.x-1.7', '8.x-1.4'],
      'pubDate' => 'Tue, 19 Mar 2019 12:50:00 +0000',
    ];
    $feed[] = [
      'title' => 'Views - Moderately critical - Access bypass - SA-CONTRIB-2019',
      'link' => 'https://www.drupal.org/sa-contrib-2019',
      'project' => 'views',
      'type' => 'module',
      'insecure' => [
        '7.x-3.16',
        '7.x-3.17',
        '7.x-3.18',
        '7.x-3.19',
        '7.x-3.19',
        '8.x-8.7.0',
      ],
      'is_psa' => '0',
      'pubDate' => 'Tue, 19 Mar 2019 12:50:00 +0000',
    ];
    return new JsonResponse($feed);
  }

}
