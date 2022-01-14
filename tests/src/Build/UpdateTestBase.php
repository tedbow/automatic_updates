<?php

namespace Drupal\Tests\automatic_updates\Build;

use Drupal\Component\Utility\Html;

/**
 * Base class for tests that perform in-place updates.
 */
abstract class UpdateTestBase extends TemplateProjectSiteTestBase {

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

    // BEGIN: DELETE FROM CORE MERGE REQUEST
    // Install Automatic Updates into the test project and ensure it wasn't
    // symlinked.
    if (__NAMESPACE__ === 'Drupal\Tests\automatic_updates\Build') {
      $dir = 'project';
      $this->runComposer('composer config repo.automatic_updates path ' . __DIR__ . '/../../..', $dir);
      $this->runComposer('composer require --no-update "drupal/automatic_updates:@dev"', $dir);
      $output = $this->runComposer('COMPOSER_MIRROR_PATH_REPOS=1 composer update --with-all-dependencies', $dir);
      $this->assertStringNotContainsString('Symlinking', $output);
    }
    // END: DELETE FROM CORE MERGE REQUEST
    // Install Drupal. Always allow test modules to be installed in the UI and,
    // for easier debugging, always display errors in their dubious glory.
    $this->installQuickStart('minimal');
    $php = <<<END
\$settings['extension_discovery_scan_tests'] = TRUE;
\$config['system.logging']['error_level'] = 'verbose';
END;
    $this->writeSettings($php);

    // Install Automatic Updates and other modules needed for testing.
    $this->formLogin($this->adminUsername, $this->adminPassword);
    $this->installModules([
      'automatic_updates',
      'automatic_updates_test',
      'update_test',
    ]);
  }

  /**
   * Appends PHP code to the test site's settings.php.
   *
   * @param string $php
   *   The PHP code to append to the test site's settings.php.
   */
  protected function writeSettings(string $php): void {
    // Ensure settings are writable, since this is the only way we can set
    // configuration values that aren't accessible in the UI.
    $file = $this->getWebRoot() . '/sites/default/settings.php';
    $this->assertFileExists($file);
    chmod(dirname($file), 0744);
    chmod($file, 0744);
    $this->assertFileIsWritable($file);

    $stream = fopen($file, 'a');
    $this->assertIsResource($stream);
    $this->assertIsInt(fwrite($stream, $php));
    $this->assertTrue(fclose($stream));
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
\$config['update.settings']['fetch']['url'] = 'http://localhost:$port/automatic-update-test';
END;
    }
    $this->writeSettings($code);
  }

  /**
   * Installs modules in the UI.
   *
   * Assumes that a user with the appropriate permissions is logged in.
   *
   * @param string[] $modules
   *   The machine names of the modules to install.
   */
  protected function installModules(array $modules): void {
    $mink = $this->getMink();
    $page = $mink->getSession()->getPage();
    $assert_session = $mink->assertSession();

    $this->visit('/admin/modules');
    foreach ($modules as $module) {
      $page->checkField("modules[$module][enable]");
    }
    $page->pressButton('Install');

    $form_id = $assert_session->elementExists('css', 'input[type="hidden"][name="form_id"]')
      ->getValue();
    if (preg_match('/^system_modules_(experimental_|non_stable_)?confirm_form$/', $form_id)) {
      $page->pressButton('Continue');
      $assert_session->statusCodeEquals(200);
    }
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
