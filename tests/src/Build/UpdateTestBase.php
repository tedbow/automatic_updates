<?php

namespace Drupal\Tests\automatic_updates\Build;

use Drupal\BuildTests\QuickStart\QuickStartTestBase;
use Drupal\Component\Serialization\Json;
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
   *
   * @see ::createTestSite()
   */
  private $webRoot;

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
   *
   * @param string $template
   *   The template project from which to build the test site. Can be
   *   'drupal/recommended-project' or 'drupal/legacy-project'.
   */
  protected function createTestSite(string $template): void {
    // Create the test site using one of the core project templates, but don't
    // install dependencies just yet.
    $template_dir = implode(DIRECTORY_SEPARATOR, [
      $this->getDrupalRoot(),
      'composer',
      'Template',
    ]);
    $recommended_template = $this->createPathRepository($template_dir . DIRECTORY_SEPARATOR . 'RecommendedProject');
    $legacy_template = $this->createPathRepository($template_dir . DIRECTORY_SEPARATOR . 'LegacyProject');

    $dir = $this->getWorkspaceDirectory();
    $command = sprintf(
      "composer create-project %s %s --no-install --stability dev --repository '%s' --repository '%s'",
      $template,
      $dir,
      Json::encode($recommended_template),
      Json::encode($legacy_template)
    );
    $this->executeCommand($command);
    $this->assertCommandSuccessful();

    $composer = $dir . DIRECTORY_SEPARATOR . 'composer.json';
    $data = $this->readJson($composer);

    // Allow the test to configure the test site as necessary.
    $data = $this->getInitialConfiguration($data);

    // We need to know the path of the web root, relative to the project root,
    // in order to install Drupal or visit the test site at all. Luckily, both
    // template projects define this because the scaffold plugin needs to know
    // it as well.
    // @see ::visit()
    // @see ::formLogin()
    // @see ::installQuickStart()
    $this->webRoot = $data['extra']['drupal-scaffold']['locations']['web-root'];

    // Update the test site's composer.json.
    $this->writeJson($composer, $data);
    // Don't install drupal/core-dev, which is defined as a dev dependency in
    // both project templates.
    // @todo Handle dev dependencies properly once
    //   https://www.drupal.org/project/automatic_updates/issues/3244412 is
    //   is resolved.
    $this->executeCommand('composer remove --dev --no-update drupal/core-dev');
    $this->assertCommandSuccessful();
    // Install production dependencies.
    $this->executeCommand('composer install --no-dev');
    $this->assertCommandSuccessful();
  }

  /**
   * Returns the initial data to write to the test site's composer.json.
   *
   * This configuration will be used to build the pre-update test site.
   *
   * @param array $data
   *   The current contents of the test site's composer.json.
   *
   * @return array
   *   The data that should be written to the test site's composer.json.
   */
  protected function getInitialConfiguration(array $data): array {
    $drupal_root = $this->getDrupalRoot();
    $core_composer_dir = $drupal_root . DIRECTORY_SEPARATOR . 'composer';
    $repositories = [];

    // Add all the metapackages that are provided by Drupal core.
    $metapackage_dir = $core_composer_dir . DIRECTORY_SEPARATOR . 'Metapackage';
    $repositories['drupal/core-recommended'] = $this->createPathRepository($metapackage_dir . DIRECTORY_SEPARATOR . 'CoreRecommended');
    $repositories['drupal/core-dev'] = $this->createPathRepository($metapackage_dir . DIRECTORY_SEPARATOR . 'DevDependencies');

    // Add all the Composer plugins that are provided by Drupal core.
    $plugin_dir = $core_composer_dir . DIRECTORY_SEPARATOR . 'Plugin';
    $repositories['drupal/core-project-message'] = $this->createPathRepository($plugin_dir . DIRECTORY_SEPARATOR . 'ProjectMessage');
    $repositories['drupal/core-composer-scaffold'] = $this->createPathRepository($plugin_dir . DIRECTORY_SEPARATOR . 'Scaffold');
    $repositories['drupal/core-vendor-hardening'] = $this->createPathRepository($plugin_dir . DIRECTORY_SEPARATOR . 'VendorHardening');

    $repositories = array_merge($repositories, $this->getLocalPackageRepositories($drupal_root));
    // To ensure the test runs entirely offline, don't allow Composer to contact
    // Packagist.
    $repositories['packagist.org'] = FALSE;

    $repositories['drupal/automatic_updates'] = [
      'type' => 'path',
      'url' => __DIR__ . '/../../..',
    ];
    // Use whatever the current branch of automatic_updates is.
    $data['require']['drupal/automatic_updates'] = '*';

    $data['repositories'] = $repositories;

    // Since Drupal 9 requires PHP 7.3 or later, these packages are probably
    // not installed, which can cause trouble during dependency resolution.
    // The drupal/drupal package (defined with a composer.json that is part
    // of core's repository) replaces these, so we need to emulate that here.
    $data['replace']['symfony/polyfill-php72'] = '*';
    $data['replace']['symfony/polyfill-php73'] = '*';

    return $data;
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
