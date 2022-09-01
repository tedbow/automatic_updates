<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\Core\Site\Settings;
use Drupal\package_manager_bypass\Beginner;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Base class for functional tests of the Automatic Updates module.
 */
abstract class AutomaticUpdatesFunctionalTestBase extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates_test_cron',
    'automatic_updates_test_disable_validators',
    'package_manager_bypass',
  ];

  /**
   * The service IDs of any validators to disable.
   *
   * @var string[]
   */
  protected $disableValidators = [
    // Disable the Composer executable validator, since it may cause the tests
    // to fail if a supported version of Composer is unavailable to the web
    // server. This should be okay in most situations because, apart from the
    // validator, only Composer Stager needs run Composer, and
    // package_manager_bypass is disabling those operations.
    'automatic_updates.composer_executable_validator',
    'package_manager.validator.composer_executable',
    // Always allow tests to run with Xdebug on.
    'automatic_updates.validator.xdebug',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->disableValidators($this->disableValidators);
    $this->useFixtureDirectoryAsActive(__DIR__ . '/../../fixtures/fake-site');
  }

  /**
   * {@inheritdoc}
   */
  protected function installModulesFromClassProperty(ContainerInterface $container): void {
    $container->get('module_installer')->install([
      'automatic_updates_test_release_history',
    ]);
    $this->container = $container->get('kernel')->getContainer();

    // To prevent tests from making real requests to the Internet, use fake
    // release metadata that exposes a pretend Drupal 9.8.2 release.
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.2.xml');

    parent::installModulesFromClassProperty($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // If automatic_updates is installed, ensure any staging area created during
    // the test is cleaned up.
    $service_id = 'automatic_updates.updater';
    if ($this->container->has($service_id)) {
      $this->container->get($service_id)->destroy(TRUE);
    }
    parent::tearDown();
  }

  /**
   * Disables validators in the test site's settings.
   *
   * This modifies the service container such that the disabled validators are
   * not defined at all. This method will have no effect unless the
   * automatic_updates_test_disable_validators module is installed.
   *
   * @param string[] $validators
   *   The service IDs of the validators to disable.
   *
   * @see \Drupal\automatic_updates_test_disable_validators\AutomaticUpdatesTestDisableValidatorsServiceProvider::alter()
   */
  protected function disableValidators(array $validators): void {
    $key = 'automatic_updates_test_disable_validators';
    $disabled_validators = Settings::get($key, []);

    foreach ($validators as $service_id) {
      $disabled_validators[] = $service_id;
    }
    $this->writeSettings([
      'settings' => [
        $key => (object) [
          'value' => $disabled_validators,
          'required' => TRUE,
        ],
      ],
    ]);
    $this->rebuildContainer();
  }

  /**
   * Sets the current (running) version of core, as known to the Update module.
   *
   * @param string $version
   *   The current version of core.
   */
  protected function setCoreVersion(string $version): void {
    $this->config('update_test.settings')
      ->set('system_info.#all.version', $version)
      ->save();
  }

  /**
   * Sets the release metadata file to use when fetching available updates.
   *
   * @param string $file
   *   The path of the XML metadata file to use.
   */
  protected function setReleaseMetadata(string $file): void {
    $this->config('update.settings')
      ->set('fetch.url', $this->baseUrl . '/test-release-history')
      ->save();

    [$project] = explode('.', basename($file, '.xml'), 2);
    $xml_map = $this->config('update_test.settings')->get('xml_map') ?? [];
    $xml_map[$project] = $file;
    $this->config('update_test.settings')
      ->set('xml_map', $xml_map)
      ->save();
  }

  /**
   * Checks for available updates.
   *
   * Assumes that a user with appropriate permissions is logged in.
   */
  protected function checkForUpdates(): void {
    $this->drupalGet('/admin/reports/updates');
    $this->getSession()->getPage()->clickLink('Check manually');
    $this->checkForMetaRefresh();
  }

  /**
   * Asserts that we are on the "update ready" form.
   *
   * @param string $target_version
   *   The target version of Drupal core.
   */
  protected function assertUpdateReady(string $target_version): void {
    $assert_session = $this->assertSession();
    $assert_session->addressMatches('/\/admin\/automatic-update-ready\/[a-zA-Z0-9_\-]+$/');
    $assert_session->pageTextContainsOnce('Drupal core will be updated to ' . $target_version);
  }

  /**
   * Copies a fixture directory to a temporary directory.
   *
   * @param string $fixture_directory
   *   The fixture directory.
   *
   * @return string
   *   The temporary directory.
   */
  protected function copyFixtureToTempDirectory(string $fixture_directory): string {
    $temp_directory = $this->root . DIRECTORY_SEPARATOR . $this->siteDirectory . DIRECTORY_SEPARATOR . $this->randomMachineName(20);
    (new Filesystem())->mirror($fixture_directory, $temp_directory);
    $this->assertDirectoryIsWritable($temp_directory);
    return $temp_directory;
  }

  /**
   * Sets a fixture directory to use as the active directory.
   *
   * @param string $fixture_directory
   *   The fixture directory.
   */
  protected function useFixtureDirectoryAsActive(string $fixture_directory): void {
    // Create a temporary directory from our fixture directory that will be
    // unique for each test run. This will enable changing files in the
    // directory and not affect other tests.
    $active_dir = $this->copyFixtureToTempDirectory($fixture_directory);
    Beginner::setFixturePath($active_dir);
    $this->container->get('package_manager.path_locator')
      ->setPaths($active_dir, $active_dir . '/vendor', '', NULL);
  }

}
