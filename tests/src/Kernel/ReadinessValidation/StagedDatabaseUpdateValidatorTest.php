<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use Psr\Log\Test\TestLogger;

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
   * The test logger channel.
   *
   * @var \Psr\Log\Test\TestLogger
   */
  private $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('automatic_updates')
      ->addLogger($this->logger);
  }

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

    $listener = function (PreApplyEvent $event): void {
      // Create bogus staged versions of Views' and
      // Automatic Updates Theme with Updates .install and .post_update.php
      // files. Since these extensions are not installed, the changes should not
      // raise any validation errors.
      $dir = $event->getStage()->getStageDirectory();
      $module_list = $this->container->get('extension.list.module')->getList();
      $theme_list = $this->container->get('extension.list.theme')->getList();
      $module_dir = $dir . '/' . $module_list['views']->getPath();
      $theme_dir = $dir . '/' . $theme_list['automatic_updates_theme_with_updates']->getPath();
      foreach (static::SUFFIXES as $suffix) {
        file_put_contents("$module_dir/views.$suffix", $this->randomString());
        file_put_contents("$theme_dir/automatic_updates_theme_with_updates.$suffix", $this->randomString());
      }
    };
    $this->container->get('event_dispatcher')
      ->addListener(PreApplyEvent::class, $listener, PHP_INT_MAX);

    $this->container->get('cron')->run();
    // There should not have been any errors.
    $this->assertFalse($this->logger->hasRecords(RfcLogLevel::ERROR));
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
    $listener = function (PreApplyEvent $event) use ($suffix, $delete): void {
      $dir = $event->getStage()->getStageDirectory();
      $theme_installer = $this->container->get('theme_installer');
      $theme_installer->install(['automatic_updates_theme_with_updates']);
      $theme = $this->container->get('theme_handler')
        ->getTheme('automatic_updates_theme_with_updates');
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
    };
    $this->container->get('event_dispatcher')
      ->addListener(PreApplyEvent::class, $listener, PHP_INT_MAX);

    $this->container->get('cron')->run();
    $this->assertTrue($this->logger->hasRecordThatContains("The update cannot proceed because possible database updates have been detected in the following extensions.\nSystem\nAutomatic Updates Theme With Updates", RfcLogLevel::ERROR));
  }

  /**
   * Tests that an error is raised if install or post-update files are added.
   */
  public function testUpdatesAddedInStage(): void {
    $listener = function (PreApplyEvent $event): void {
      $module = $this->container->get('module_handler')
        ->getModule('package_manager_bypass');
      $theme_installer = $this->container->get('theme_installer');
      $theme_installer->install(['automatic_updates_theme']);
      $theme = $this->container->get('theme_handler')
        ->getTheme('automatic_updates_theme');

      $dir = $event->getStage()->getStageDirectory();

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
    };
    $this->container->get('event_dispatcher')
      ->addListener(PreApplyEvent::class, $listener, PHP_INT_MAX);

    $this->container->get('cron')->run();
    $this->assertTrue($this->logger->hasRecordThatContains("The update cannot proceed because possible database updates have been detected in the following extensions.\nPackage Manager Bypass\nAutomatic Updates Theme", RfcLogLevel::ERROR));
  }

}
