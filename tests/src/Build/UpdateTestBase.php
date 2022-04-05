<?php

namespace Drupal\Tests\automatic_updates\Build;

use Drupal\Component\Utility\Html;
use Drupal\Tests\package_manager\Build\TemplateProjectTestBase;

/**
 * Base class for tests that perform in-place updates.
 */
abstract class UpdateTestBase extends TemplateProjectTestBase {

  /**
   * A secondary server instance, to serve XML metadata about available updates.
   *
   * @var \Symfony\Component\Process\Process
   */
  private $metadataServer;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->metadataServer) {
      $this->metadataServer->stop();
    }
    parent::tearDown();
  }

  /**
   * {@inheritdoc}
   */
  protected function createTestProject(string $template): void {
    parent::createTestProject($template);

    // Install Drupal, Automatic Updates, and other modules needed for testing.
    $this->installQuickStart('minimal');
    $this->formLogin($this->adminUsername, $this->adminPassword);
    $this->installModules([
      'automatic_updates',
      'automatic_updates_test',
      'automatic_updates_test_release_history',
    ]);
  }

  /**
   * Prepares the test site to serve an XML feed of available release metadata.
   *
   * @param array $xml_map
   *   The update XML map, as used by update_test.settings.
   *
   * @see \Drupal\automatic_updates_test\TestController::metadata()
   */
  protected function setReleaseMetadata(array $xml_map): void {
    $xml_map = var_export($xml_map, TRUE);
    $code = <<<END
\$config['update_test.settings']['xml_map'] = $xml_map;
END;

    // When checking for updates, we need to be able to make sub-requests, but
    // the built-in PHP server is single-threaded. Therefore, if needed, open a
    // second server instance on another port, which will serve the metadata
    // about available updates.
    if (empty($this->metadataServer)) {
      $port = $this->findAvailablePort();
      $this->metadataServer = $this->instantiateServer($port);
      $code .= <<<END
\$config['update.settings']['fetch']['url'] = 'http://localhost:$port/test-release-history';
END;
    }
    $this->writeSettings($code);
  }

  /**
   * Checks for available updates.
   *
   * Assumes that a user with the appropriate access is logged in.
   */
  protected function checkForUpdates(): void {
    $this->visit('/admin/reports/updates');
    $this->getMink()->getSession()->getPage()->clickLink('Check manually');
    $this->waitForBatchJob();
  }

  /**
   * Waits for an active batch job to finish.
   */
  protected function waitForBatchJob(): void {
    $refresh = $this->getMink()
      ->getSession()
      ->getPage()
      ->find('css', 'meta[http-equiv="Refresh"], meta[http-equiv="refresh"]');

    if ($refresh) {
      // Parse the content attribute of the meta tag for the format:
      // "[delay]: URL=[page_to_redirect_to]".
      if (preg_match('/\d+;\s*URL=\'?(?<url>[^\']*)/i', $refresh->getAttribute('content'), $match)) {
        $url = Html::decodeEntities($match['url']);
        $this->visit($url);
        $this->waitForBatchJob();
      }
    }
  }

}
