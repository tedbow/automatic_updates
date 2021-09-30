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
  protected static $modules = ['update', 'update_test'];

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

}
