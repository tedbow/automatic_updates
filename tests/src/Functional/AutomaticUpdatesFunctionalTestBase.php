<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\BrowserTestBase;

/**
 * Base class for functional tests of the Automatic Updates module.
 */
abstract class AutomaticUpdatesFunctionalTestBase extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['update', 'update_test'];

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->disableValidators($this->disableValidators);
  }

  /**
   * Disables validators in the test site's services.yml.
   *
   * This modifies the service container such that the disabled validators are
   * instances of stdClass, and not subscribed to any events.
   *
   * @param string[] $validators
   *   The service IDs of the validators to disable.
   */
  protected function disableValidators(array $validators): void {
    $services_file = $this->getDrupalRoot() . '/' . $this->siteDirectory . '/services.yml';
    $this->assertFileIsWritable($services_file);
    $services = file_get_contents($services_file);
    $services = Yaml::decode($services);

    foreach ($validators as $service_id) {
      $services['services'][$service_id] = [
        'class' => 'stdClass',
      ];
    }
    file_put_contents($services_file, Yaml::encode($services));
    // Ensure the container is rebuilt ASAP.
    $this->kernel->invalidateContainer();
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
