<?php

namespace Drupal\Tests\automatic_updates_extensions\Traits;

use Behat\Mink\WebAssert;

/**
 * Common methods for testing the update form.
 */
trait FormTestTrait {

  /**
   * Asserts the table shows the updates.
   *
   * @param \Behat\Mink\WebAssert $assert
   *   The web assert tool.
   * @param string $expected_project_title
   *   The expected project title.
   * @param string $expected_installed_version
   *   The expected installed version.
   * @param string $expected_target_version
   *   The expected target version.
   */
  private function assertUpdateTableRow(WebAssert $assert, string $expected_project_title, string $expected_installed_version, string $expected_target_version): void {
    $assert->elementTextContains('css', '.update-recommended td:nth-of-type(2)', $expected_project_title);
    $assert->elementTextContains('css', '.update-recommended td:nth-of-type(3)', $expected_installed_version);
    $assert->elementTextContains('css', '.update-recommended td:nth-of-type(4)', $expected_target_version);
    $assert->elementsCount('css', '.update-recommended tbody tr', 1);
  }

}
