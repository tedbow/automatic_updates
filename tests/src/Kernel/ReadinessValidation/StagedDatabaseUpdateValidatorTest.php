<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\StagedDatabaseUpdateValidator
 *
 * @group automatic_updates
 */
class StagedDatabaseUpdateValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * The suffixes of the files that can contain database updates.
   *
   * @var string[]
   */
  private const SUFFIXES = ['install', 'post_update.php'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createTestProject();

    /** @var \Drupal\Tests\automatic_updates\Kernel\TestCronUpdater $updater */
    $updater = $this->container->get('automatic_updates.cron_updater');
    $updater->begin(['drupal' => '9.8.1']);
    $updater->stage();
  }

  /**
   * {@inheritdoc}
   */
  protected function createTestProject(): void {
    parent::createTestProject();

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
    $extensions['automatic_updates_theme'] = $theme_list->get('automatic_updates_theme');
    $extensions['automatic_updates_theme_with_updates'] = $theme_list->get('automatic_updates_theme_with_updates');
    foreach ($extensions as $name => $extension) {
      $path = $extension->getPath();
      @mkdir("$virtual_active_dir/$path", 0777, TRUE);

      foreach (static::SUFFIXES as $suffix) {
        // If the source file doesn't exist, silence the warning it raises.
        @copy("$drupal_root/$path/$name.$suffix", "$virtual_active_dir/$path/$name.$suffix");
      }
    }
  }

  /**
   * Tests that no errors are raised if staged files have no DB updates.
   */
  public function testNoUpdates(): void {
    // Since we're testing with a modified version of 'views' and
    // 'automatic_updates_theme_with_updates', these should not be installed.
    $this->assertFalse($this->container->get('module_handler')->moduleExists('views'));
    $this->assertFalse($this->container->get('theme_handler')->themeExists('automatic_updates_theme_with_updates'));

    // Create bogus staged versions of Views' and
    // Automatic Updates Theme with Updates .install and .post_update.php files.
    // Since these extensions are not installed, the changes should not raise
    // any validation errors.
    $updater = $this->container->get('automatic_updates.cron_updater');
    $module_list = $this->container->get('extension.list.module')->getList();
    $theme_list = $this->container->get('extension.list.theme')->getList();
    $module_dir = $updater->getStageDirectory() . '/' . $module_list['views']->getPath();
    $theme_dir = $updater->getStageDirectory() . '/' . $theme_list['automatic_updates_theme_with_updates']->getPath();
    foreach (static::SUFFIXES as $suffix) {
      file_put_contents("$module_dir/views.$suffix", $this->randomString());
      file_put_contents("$theme_dir/automatic_updates_theme_with_updates.$suffix", $this->randomString());
    }

    $updater->apply();
  }

  /**
   * Data provider for ::testFileChanged().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
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
    /** @var \Drupal\automatic_updates\CronUpdater $updater */
    $updater = $this->container->get('automatic_updates.cron_updater');
    $theme_installer = $this->container->get('theme_installer');
    $theme_installer->install(['automatic_updates_theme_with_updates']);
    $theme = $this->container->get('theme_handler')
      ->getTheme('automatic_updates_theme_with_updates');
    $module_file = $updater->getStageDirectory() . "/core/modules/system/system.$suffix";
    $theme_file = $updater->getStageDirectory() . "/{$theme->getPath()}/automatic_updates_theme_with_updates.$suffix";
    if ($delete) {
      unlink($module_file);
      unlink($theme_file);
    }
    else {
      file_put_contents($module_file, $this->randomString());
      file_put_contents($theme_file, $this->randomString());
    }

    $expected_results = [
      ValidationResult::createError(['System', 'Automatic Updates Theme With Updates'], t('The update cannot proceed because possible database updates have been detected in the following extensions.')),
    ];

    try {
      $updater->apply();
      $this->fail('Expected a validation error.');
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

  /**
   * Tests that an error is raised if install or post-update files are added.
   */
  public function testUpdatesAddedInStage(): void {
    $module = $this->container->get('module_handler')
      ->getModule('package_manager_bypass');
    $theme_installer = $this->container->get('theme_installer');
    $theme_installer->install(['automatic_updates_theme']);
    $theme = $this->container->get('theme_handler')
      ->getTheme('automatic_updates_theme');

    /** @var \Drupal\automatic_updates\CronUpdater $updater */
    $updater = $this->container->get('automatic_updates.cron_updater');

    foreach (static::SUFFIXES as $suffix) {
      $module_file = sprintf('%s/%s/%s.%s', $updater->getStageDirectory(), $module->getPath(), $module->getName(), $suffix);
      $theme_file = sprintf('%s/%s/%s.%s', $updater->getStageDirectory(), $theme->getPath(), $theme->getName(), $suffix);
      // The files we're creating shouldn't already exist in the staging area
      // unless it's a file we actually ship, which is a scenario covered by
      // ::testFileChanged().
      $this->assertFileDoesNotExist($module_file);
      $this->assertFileDoesNotExist($theme_file);
      file_put_contents($module_file, $this->randomString());
      file_put_contents($theme_file, $this->randomString());
    }

    $expected_results = [
      ValidationResult::createError(['Package Manager Bypass', 'Automatic Updates Theme'], t('The update cannot proceed because possible database updates have been detected in the following extensions.')),
    ];

    try {
      $updater->apply();
      $this->fail('Expected a validation error.');
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

}
