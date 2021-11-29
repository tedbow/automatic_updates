<?php

namespace Drupal\Tests\automatic_updates\Functional;

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
    'update',
    'update_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function prepareSettings() {
    parent::prepareSettings();

    // Disable the filesystem permissions validator, since we cannot guarantee
    // that the current code base will be writable in all testing situations. We
    // test this validator in our build tests, since those do give us control
    // over the filesystem permissions.
    // @see \Drupal\Tests\automatic_updates\Build\CoreUpdateTest::assertReadOnlyFileSystemError()
    $settings['settings']['automatic_updates_disable_validators'] = (object) [
      'value' => [
        'automatic_updates.validator.file_system_permissions',
        'package_manager.validator.file_system',
      ],
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
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
