<?php

namespace Drupal\Tests\automatic_updates\Traits;

use Drupal\Component\Utility\Html;

/**
 * Provides common functionality for automatic update test classes.
 */
trait InstallTestTrait {

  /**
   * Helper method that uses Drupal's module page to install a module.
   */
  protected function moduleInstall($module_name) {
    $assert = $this->visit('/admin/modules')
      ->assertSession();
    $field = Html::getClass("edit-modules $module_name enable");
    // No need to install a module if it is already install.
    if ($this->getMink()->getSession()->getPage()->findField($field)->isChecked()) {
      return;
    }
    $assert->fieldExists($field)->check();
    $session = $this->getMink()->getSession();
    $session->getPage()->findButton('Install')->submit();
    $assert->fieldExists($field)->isChecked();
    $assert->statusCodeEquals(200);
  }

  /**
   * Helper method that uses Drupal's theme page to install a theme.
   */
  protected function themeInstall($theme_name) {
    $this->moduleInstall('test_automatic_updates');
    $assert = $this->visit("/admin/appearance/default?theme=$theme_name")
      ->assertSession();
    $assert->pageTextNotContains('theme was not found');
    $assert->statusCodeEquals(200);
  }

}
