<?php

namespace Drupal\Tests\automatic_updates\Traits;

use PHPUnit\Framework\Assert;

/**
 * Provides methods for manipulating site settings.
 */
trait SettingsTrait {

  /**
   * Appends some PHP code to settings.php.
   *
   * @param string $php
   *   The PHP code to append to settings.php.
   * @param string $drupal_root
   *   The path of the Drupal root.
   * @param string $site
   *   (optional) The name of the site whose settings.php should be amended.
   *   Defaults to 'default'.
   */
  protected function addSettings(string $php, string $drupal_root, string $site = 'default'): void {
    $settings = $this->makeSettingsWritable($drupal_root, $site);
    $settings = fopen($settings, 'a');
    Assert::assertIsResource($settings);
    Assert::assertIsInt(fwrite($settings, $php));
    Assert::assertTrue(fclose($settings));
  }

  /**
   * Ensures that settings.php is writable.
   *
   * @param string $drupal_root
   *   The path of the Drupal root.
   * @param string $site
   *   (optional) The name of the site whose settings should be made writable.
   *   Defaults to 'default'.
   *
   * @return string
   *   The path to settings.php for the specified site.
   */
  private function makeSettingsWritable(string $drupal_root, string $site = 'default'): string {
    $settings = implode(DIRECTORY_SEPARATOR, [
      $drupal_root,
      'sites',
      $site,
      'settings.php',
    ]);
    chmod(dirname($settings), 0744);
    chmod($settings, 0744);
    Assert::assertIsWritable($settings);

    return $settings;
  }

}
