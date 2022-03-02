<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\Core\Site\Settings;
use Drupal\Tests\BrowserTestBase;

/**
 * Base class for functional tests of the Automatic Updates module.
 */
abstract class AutomaticUpdatesFunctionalTestBase extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates_test_disable_validators',
    'package_manager_bypass',
    'update',
    'update_test',
  ];

  /**
   * The service IDs of any validators to disable.
   *
   * @var string[]
   */
  protected $disableValidators = [
    // Disable the filesystem permissions validators, since we cannot guarantee
    // that the current code base will be writable in all testing situations. We
    // test these validators in our build tests, since those do give us control
    // over the filesystem permissions.
    // @see \Drupal\Tests\automatic_updates\Build\CoreUpdateTest::assertReadOnlyFileSystemError()
    'automatic_updates.validator.file_system_permissions',
    'package_manager.validator.file_system',
    // Disable the Composer executable validator, since it may cause the tests
    // to fail if a supported version of Composer is unavailable to the web
    // server. This should be okay in most situations because, apart from the
    // validator, only Composer Stager needs run Composer, and
    // package_manager_bypass is disabling those operations.
    'automatic_updates.composer_executable_validator',
    'package_manager.validator.composer_executable',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->disableValidators($this->disableValidators);
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
      ->set('fetch.url', $this->baseUrl . '/automatic-update-test')
      ->save();

    [$project, $fixture] = explode('.', basename($file, '.xml'), 2);
    $this->config('update_test.settings')
      ->set('xml_map', [
        $project => $fixture,
      ])
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
   */
  protected function assertUpdateReady(): void {
    $this->assertSession()
      ->addressMatches('/\/admin\/automatic-update-ready\/[a-zA-Z0-9_\-]+$/');
  }

}
