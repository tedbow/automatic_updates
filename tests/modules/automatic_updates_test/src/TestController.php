<?php

namespace Drupal\automatic_updates_test;

use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\automatic_updates\UpdateRecommender;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\HtmlResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
      $updater->clean();

      $project = (new UpdateRecommender())->getProjectInfo();
      $content = $project['existing_version'];
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

  /**
   * Page callback: Prints mock XML for the Update Manager module.
   *
   * This is a wholesale copy of
   * \Drupal\update_test\Controller\UpdateTestController::updateTest() for
   * testing automatic updates. This was done in order to use a different
   * directory of mock XML files.
   */
  public function metadata($project_name = 'drupal', $version = NULL): Response {
    if ($project_name !== 'drupal') {
      return new Response();
    }
    $xml_map = $this->config('update_test.settings')->get('xml_map');
    if (isset($xml_map[$project_name])) {
      $availability_scenario = $xml_map[$project_name];
    }
    elseif (isset($xml_map['#all'])) {
      $availability_scenario = $xml_map['#all'];
    }
    else {
      // The test didn't specify (for example, the webroot has other modules and
      // themes installed but they're disabled by the version of the site
      // running the test. So, we default to a file we know won't exist, so at
      // least we'll get an empty xml response instead of a bunch of Drupal page
      // output.
      $availability_scenario = '#broken#';
    }

    $file = __DIR__ . "/../../../fixtures/release-history/$project_name.$availability_scenario.xml";
    $headers = ['Content-Type' => 'text/xml; charset=utf-8'];
    if (!is_file($file)) {
      // Return an empty response.
      return new Response('', 200, $headers);
    }
    return new BinaryFileResponse($file, 200, $headers);
  }

}
