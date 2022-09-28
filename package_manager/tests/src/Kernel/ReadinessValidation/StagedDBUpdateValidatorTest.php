<?php

namespace Drupal\Tests\package_manager\Kernel\ReadinessValidation;

use Drupal\package_manager\ValidationResult;
use Drupal\Tests\package_manager\Kernel\PackageManagerKernelTestBase;

/**
 * @covers \Drupal\package_manager\Validator\StagedDBUpdateValidator
 *
 * @group package_manager
 */
class StagedDBUpdateValidatorTest extends PackageManagerKernelTestBase {

  /**
   * The suffixes of the files that can contain database updates.
   *
   * @var string[]
   */
  private const SUFFIXES = ['install', 'post_update.php'];

  /**
   * {@inheritdoc}
   */
  protected function createVirtualProject(?string $source_dir = NULL): void {
    parent::createVirtualProject($source_dir);

    $drupal_root = $this->getDrupalRoot();
    $virtual_active_dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    // Copy the .install and .post_update.php files from all extensions used in
    // this test class, in the *actual* Drupal code base that is running this
    // test, into the virtual project (i.e., the active directory).
    $module_list = $this->container->get('extension.list.module');
    $extensions = [];
    $extensions['system'] = $module_list->get('system');
    $extensions['views'] = $module_list->get('views');
    $extensions['package_manager_bypass'] = $module_list->get('package_manager_bypass');
    $theme_list = $this->container->get('extension.list.theme');
    // Theme with updates.
    $extensions['olivero'] = $theme_list->get('olivero');
    // Theme without updates.
    $extensions['stark'] = $theme_list->get('stark');
    foreach ($extensions as $name => $extension) {
      $path = $extension->getPath();
      @mkdir("$virtual_active_dir/$path", 0777, TRUE);

      foreach (static::SUFFIXES as $suffix) {
        if ($name === 'olivero') {
          @touch("$virtual_active_dir/$path/$name.$suffix");
          continue;
        }
        // If the source file doesn't exist, silence the warning it raises.
        @copy("$drupal_root/$path/$name.$suffix", "$virtual_active_dir/$path/$name.$suffix");
      }
    }
  }

  /**
   * Data provider for testFileChanged().
   *
   * @return mixed[]
   *   The test cases.
   */
  public function providerFileChanged(): array {
    $scenarios = [];
    foreach (static::SUFFIXES as $suffix) {
      $scenarios["$suffix kept"] = [$suffix, FALSE];
      $scenarios["$suffix deleted"] = [$suffix, TRUE];
    }
    return $scenarios;
  }

  /**
   * Tests that an error is raised if install or post-update files are changed.
   *
   * @param string $suffix
   *   The suffix of the file to change. Can be either 'install' or
   *   'post_update.php'.
   * @param bool $delete
   *   Whether or not to delete the file.
   *
   * @dataProvider providerFileChanged
   */
  public function testFileChanged(string $suffix, bool $delete): void {
    $stage = $this->createStage();
    $stage->create();
    $dir = $stage->getStageDirectory();
    $this->container->get('theme_installer')->install(['olivero']);
    $theme = $this->container->get('theme_handler')
      ->getTheme('olivero');
    $module_file = "$dir/core/modules/system/system.$suffix";
    $theme_file = "$dir/{$theme->getPath()}/{$theme->getName()}.$suffix";
    if ($delete) {
      unlink($module_file);
      unlink($theme_file);
    }
    else {
      file_put_contents($module_file, $this->randomString());
      file_put_contents($theme_file, $this->randomString());
    }
    $error = ValidationResult::createWarning(['System', 'Olivero'], t('Possible database updates have been detected in the following extensions.'));
    $this->assertStatusCheckResults([$error], $stage);

  }

  /**
   * Tests that no errors are raised if staged files have no DB updates.
   */
  public function testNoUpdates(): void {
    // Since we're testing with a modified version of 'views' and
    // 'olivero', these should not be installed.
    $this->assertFalse($this->container->get('module_handler')->moduleExists('views'));
    $this->assertFalse($this->container->get('theme_handler')->themeExists('olivero'));

    // Create bogus staged versions of Views' and
    // Package Manager Theme with Updates .install and .post_update.php
    // files. Since these extensions are not installed, the changes should not
    // raise any validation errors.
    $stage = $this->createStage();
    $stage->create();
    $dir = $stage->getStageDirectory();
    $module_list = $this->container->get('extension.list.module')->getList();
    $theme_list = $this->container->get('extension.list.theme')->getList();
    $module_dir = $dir . '/' . $module_list['views']->getPath();
    $theme_dir = $dir . '/' . $theme_list['olivero']->getPath();
    foreach (static::SUFFIXES as $suffix) {
      file_put_contents("$module_dir/views.$suffix", $this->randomString());
      file_put_contents("$theme_dir/olivero.$suffix", $this->randomString());
    }
    // There should not have been any errors.
    $this->assertStatusCheckResults([], $stage);
  }

  /**
   * Tests that an error is raised if install or post-update files are added.
   */
  public function testUpdatesAddedInStage(): void {
    $module = $this->container->get('module_handler')
      ->getModule('package_manager_bypass');
    $theme_installer = $this->container->get('theme_installer');
    $theme_installer->install(['stark']);
    $theme = $this->container->get('theme_handler')
      ->getTheme('stark');

    $stage = $this->createStage();
    $stage->create();
    $dir = $stage->getStageDirectory();
    foreach (static::SUFFIXES as $suffix) {
      $module_file = sprintf('%s/%s/%s.%s', $dir, $module->getPath(), $module->getName(), $suffix);
      $theme_file = sprintf('%s/%s/%s.%s', $dir, $theme->getPath(), $theme->getName(), $suffix);
      // The files we're creating shouldn't already exist in the staging area
      // unless it's a file we actually ship, which is a scenario covered by
      // ::testFileChanged().
      $this->assertFileDoesNotExist($module_file);
      $this->assertFileDoesNotExist($theme_file);
      file_put_contents($module_file, $this->randomString());
      file_put_contents($theme_file, $this->randomString());
    }
    $error = ValidationResult::createWarning(['Package Manager Bypass', 'Stark'], t('Possible database updates have been detected in the following extensions.'));

    $this->assertStatusCheckResults([$error], $stage);
  }

}
