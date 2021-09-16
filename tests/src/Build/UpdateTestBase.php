<?php

namespace Drupal\Tests\automatic_updates\Build;

use Drupal\BuildTests\QuickStart\QuickStartTestBase;
use Drupal\Component\Utility\Html;
use Drupal\Tests\automatic_updates\Traits\LocalPackagesTrait;
use Drupal\Tests\automatic_updates\Traits\SettingsTrait;

/**
 * Base class for tests that perform in-place updates.
 */
abstract class UpdateTestBase extends QuickStartTestBase {

  use LocalPackagesTrait {
    getPackagePath as traitGetPackagePath;
    copyPackage as traitCopyPackage;
  }
  use SettingsTrait;

  /**
   * A secondary server instance, to serve XML metadata about available updates.
   *
   * @var \Symfony\Component\Process\Process
   */
  private $metadataServer;

  /**
   * The test site's document root, relative to the workspace directory.
   *
   * @var string
   */
  protected $webRoot = './';

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
  protected function copyPackage(string $source_dir, string $destination_dir = NULL): string {
    return $this->traitCopyPackage($source_dir, $destination_dir ?: $this->getWorkspaceDirectory());
  }

  /**
   * {@inheritdoc}
   */
  protected function getPackagePath(array $package): string {
    if ($package['name'] === 'drupal/core') {
      return 'core';
    }

    [$vendor, $name] = explode('/', $package['name']);

    // Assume any contributed module is in modules/contrib/$name.
    if ($vendor === 'drupal' && $package['type'] === 'drupal-module') {
      return implode(DIRECTORY_SEPARATOR, ['modules', 'contrib', $name]);
    }

    return $this->traitGetPackagePath($package);
  }

  /**
   * Returns the full path to the test site's document root.
   *
   * @return string
   *   The full path of the test site's document root.
   */
  protected function getWebRoot(): string {
    return $this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $this->webRoot;
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
      $this->metadataServer = $this->instantiateServer($port, $this->webRoot);
      $code .= <<<END
\$config['update.settings']['fetch']['url'] = 'http://localhost:$port/automatic-update-test';
END;
    }
    $this->addSettings($code, $this->getWebRoot());
  }

  /**
   * Runs a Composer command and asserts that it succeeded.
   *
   * @param string $command
   *   The command to run, excluding the 'composer' prefix.
   */
  protected function runComposer(string $command): void {
    $this->executeCommand("composer $command");
    $this->assertCommandSuccessful();
  }

  /**
   * {@inheritdoc}
   */
  public function visit($request_uri = '/', $working_dir = NULL) {
    return parent::visit($request_uri, $working_dir ?: $this->webRoot);
  }

  /**
   * {@inheritdoc}
   */
  public function formLogin($username, $password, $working_dir = NULL) {
    parent::formLogin($username, $password, $working_dir ?: $this->webRoot);
  }

  /**
   * {@inheritdoc}
   */
  public function installQuickStart($profile, $working_dir = NULL) {
    parent::installQuickStart($profile, $working_dir ?: $this->webRoot);

    // Always allow test modules to be installed in the UI and, for easier
    // debugging, always display errors in their dubious glory.
    $php = <<<END
\$settings['extension_discovery_scan_tests'] = TRUE;
\$config['system.logging']['error_level'] = 'verbose';
END;
    $this->addSettings($php, $this->getWebRoot());
  }

  /**
   * Uses our already-installed dependencies to build a test site to update.
   */
  protected function createTestSite(): void {
    // The project-level composer.json lives in the workspace root directory,
    // which may or may not be the same directory as the web root (where Drupal
    // itself lives).
    $composer = $this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . 'composer.json';
    $this->writeJson($composer, $this->getInitialConfiguration());
    $this->runComposer('update');
  }

  /**
   * Returns the initial data to write to the test site's composer.json.
   *
   * This configuration will be used to build the pre-update test site.
   *
   * @return array
   *   The data that should be written to the test site's composer.json.
   */
  protected function getInitialConfiguration(): array {
    $core_constraint = preg_replace('/\.[0-9]+-dev$/', '.x-dev', \Drupal::VERSION);

    $drupal_root = $this->getDrupalRoot();
    $repositories = [
      'drupal/core-composer-scaffold' => [
        'type' => 'path',
        'url' => implode(DIRECTORY_SEPARATOR, [
          $drupal_root,
          'composer',
          'Plugin',
          'Scaffold',
        ]),
      ],
      'drupal/automatic_updates' => [
        'type' => 'path',
        'url' => __DIR__ . '/../../..',
      ],
    ];
    $repositories = array_merge($repositories, $this->getLocalPackageRepositories($drupal_root));
    // To ensure the test runs entirely offline, don't allow Composer to contact
    // Packagist.
    $repositories['packagist.org'] = FALSE;

    return [
      'require' => [
        // Allow packages to be placed in their right Drupal-findable places.
        'composer/installers' => '^1.9',
        // Use whatever the current branch of automatic_updates is.
        'drupal/automatic_updates' => '*',
        // Ensure we have all files that the test site needs.
        'drupal/core-composer-scaffold' => '*',
        // Require the current version of core, to install its dependencies.
        'drupal/core' => $core_constraint,
      ],
      // Since Drupal 9 requires PHP 7.3 or later, these packages are probably
      // not installed, which can cause trouble during dependency resolution.
      // The drupal/drupal package (defined with a composer.json that is part
      // of core's repository) replaces these, so we need to emulate that here.
      'replace' => [
        'symfony/polyfill-php72' => '*',
        'symfony/polyfill-php73' => '*',
      ],
      'repositories' => $repositories,
      'extra' => [
        'drupal-scaffold' => [
          'locations' => [
            'web-root' => $this->webRoot,
          ],
        ],
        'installer-paths' => [
          $this->webRoot . 'core' => [
            'type:drupal-core',
          ],
          $this->webRoot . 'modules/{$name}' => [
            'type:drupal-module',
          ],
        ],
      ],
      'minimum-stability' => 'dev',
      'prefer-stable' => TRUE,
    ];
  }

  /**
   * Asserts that a specific version of Drupal core is running.
   *
   * Assumes that a user with permission to view the status report is logged in.
   *
   * @param string $expected_version
   *   The version of core that should be running.
   */
  protected function assertCoreVersion(string $expected_version): void {
    $this->visit('/admin/reports/status');
    $item = $this->getMink()
      ->assertSession()
      ->elementExists('css', 'h3:contains("Drupal Version")')
      ->getParent()
      ->getText();
    $this->assertStringContainsString($expected_version, $item);
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
    if ($form_id === 'system_modules_confirm_form') {
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
