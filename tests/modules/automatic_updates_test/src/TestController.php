<?php

namespace Drupal\automatic_updates_test;

use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\HtmlResponse;
use Symfony\Component\HttpFoundation\Response;

class TestController extends ControllerBase {

  /**
   * Performs an in-place update to a given version of Drupal core.
   *
   * This executes the update immediately, in one request, without using the
   * batch system or cron as wrappers.
   *
   * @param string $to_version
   *   The version of core to update to.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function update(string $to_version): Response {
    // Let it take as long as it needs.
    Environment::setTimeLimit(0);

    /** @var \Drupal\automatic_updates\Updater $updater */
    $updater = \Drupal::service('automatic_updates.updater');
    try {
      $updater->begin(['drupal' => $to_version]);
      $updater->stage();
      $updater->apply();
      $updater->destroy();

      // The code base has been updated, but as far as the PHP runtime is
      // concerned, \Drupal::VERSION refers to the old version, until the next
      // request. So check if the updated version is in Drupal.php and return
      // a clear indication of whether it's there or not.
      $drupal_php = file_get_contents(\Drupal::root() . '/core/lib/Drupal.php');
      if (str_contains($drupal_php, "const VERSION = '$to_version';")) {
        $content = "$to_version found in Drupal.php";
      }
      else {
        $content = "$to_version not found in Drupal.php";
      }
      $status = 200;
    }
    catch (UpdateException $e) {
      $messages = [];
      foreach ($e->getResults() as $result) {
        if ($summary = $result->getSummary()) {
          $messages[] = $summary;
        }
        $messages = array_merge($messages, $result->getMessages());
      }
      $content = implode('<br />', $messages);
      $status = 500;
    }
    return new HtmlResponse($content, $status);
  }

}
