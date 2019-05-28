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
    return $response;
  }

}
