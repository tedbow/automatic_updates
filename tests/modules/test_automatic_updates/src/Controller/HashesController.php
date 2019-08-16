<?php

namespace Drupal\test_automatic_updates\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class HashesController.
 */
class HashesController extends ControllerBase {

  /**
   * Test hashes controller.
   *
   * @param string $extension
   *   The extension name.
   * @param string $version
   *   The version string.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A file with hashes.
   */
  public function hashes($extension, $version) {
    $response = Response::create();
    $response->headers->set('Content-Type', 'text/plain');
    if ($extension === 'core' && $version === '8.7.0') {
      $response->setContent("2cedbfcde76961b1f65536e3c69e13d8ad850619235f4aa2752ae66fe5e5a2d928578279338f099b5318d92c410040e995cb62ba1cc4512ec17cf21715c760a2  core/LICENSE.txt\n");
    }
    elseif ($extension === 'core' && $version === '8.0.0') {
      // Fake out a change in the LICENSE.txt.
      $response->setContent("2d4ce6b272311ca4159056fb75138eba1814b65323c35ae5e0978233918e45e62bb32fdd2e0e8f657954fd5823c045762b3b59645daf83246d88d8797726e02c  core/LICENSE.txt\n");
    }
    elseif ($extension === 'ctools' && $version === '3.2') {
      // Fake out a change in the LICENSE.txt.
      $response->setContent("aee80b1f9f7f4a8a00dcf6e6ce6c41988dcaedc4de19d9d04460cbfb05d99829ffe8f9d038468eabbfba4d65b38e8dbef5ecf5eb8a1b891d9839cda6c48ee957  LICENSE.txt\n");
    }
    elseif ($extension === 'ctools' && $version === '3.1') {
      // Fake out a change in the LICENSE.txt.
      $response->setContent("c82147962109321f8fb9c802735d31aab659a1cc3cd13d36dc5371c8b682ff60f23d41c794f2d9dc970ef9634b7fc8bcf35e3b95132644fe2ec97a341658a3f6  LICENSE.txt\n");
    }
    return $response;
  }

}
