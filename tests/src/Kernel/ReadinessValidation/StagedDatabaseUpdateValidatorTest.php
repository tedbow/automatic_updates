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

    // Copy the .install and .post_update.php files from every installed module,
    // in the *actual* Drupal code base that is running this test, into the
    // virtual project (i.e., the active directory).
    $module_list = $this->container->get('module_handler')->getModuleList();
    foreach ($module_list as $name => $module) {
      $path = $module->getPath();
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
    // Since we're testing with a modified version of Views, it should not be
    // installed.
    $this->assertFalse($this->container->get('module_handler')->moduleExists('views'));

    // Create bogus staged versions of Views' .install and .post_update.php
    // files. Since it's not installed, the changes should not raise any
    // validation errors.
    $updater = $this->container->get('automatic_updates.cron_updater');
    $module_dir = $updater->getStageDirectory() . '/core/modules/views';
    mkdir($module_dir, 0777, TRUE);
    foreach (static::SUFFIXES as $suffix) {
      file_put_contents("$module_dir/views.$suffix", $this->randomString());
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
    /** @var \Drupal\Tests\automatic_updates\Kernel\ReadinessValidation\TestCronUpdater $updater */
    $updater = $this->container->get('automatic_updates.cron_updater');

    $file = $updater->getStageDirectory() . "/core/modules/system/system.$suffix";
    if ($delete) {
      unlink($file);
    }
    else {
      file_put_contents($file, $this->randomString());
    }

    $expected_results = [
      ValidationResult::createError(['System'], t('The update cannot proceed because possible database updates have been detected in the following modules.')),
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

    /** @var \Drupal\Tests\automatic_updates\Kernel\ReadinessValidation\TestCronUpdater $updater */
    $updater = $this->container->get('automatic_updates.cron_updater');

    foreach (static::SUFFIXES as $suffix) {
      $file = sprintf('%s/%s/%s.%s', $updater->getStageDirectory(), $module->getPath(), $module->getName(), $suffix);
      // The file we're creating shouldn't already exist in the staging area
      // unless it's a file we actually ship, which is a scenario covered by
      // ::testFileChanged().
      $this->assertFileDoesNotExist($file);
      file_put_contents($file, $this->randomString());
    }

    $expected_results = [
      ValidationResult::createError(['Package Manager Bypass'], t('The update cannot proceed because possible database updates have been detected in the following modules.')),
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
