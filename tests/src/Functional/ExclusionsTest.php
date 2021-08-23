<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests exclusion of certain files and directories from the staging area.
 *
 * @group automatic_updates
 */
class ExclusionsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The names of site-specific settings files to mock.
   *
   * @var string[]
   */
  private const SETTINGS_FILES = [
    'settings.php',
    'settings.local.php',
    'services.yml',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (static::SETTINGS_FILES as $settings_file) {
      $settings_file = "$this->siteDirectory/$settings_file";
      touch($settings_file);
      $this->assertFileExists($settings_file);
    }
  }

  /**
   * Tests that certain files and directories are not staged.
   *
   * @covers \Drupal\automatic_updates\Updater::getExclusions
   */
  public function testExclusions(): void {
    /** @var \Drupal\automatic_updates\Updater $updater */
    $updater = $this->container->get('automatic_updates.updater');

    $reflector = new \ReflectionObject($updater);
    $method = $reflector->getMethod('getExclusions');
    $method->setAccessible(TRUE);
    $exclusions = $method->invoke($updater);

    $this->assertContains("$this->siteDirectory/files", $exclusions);
    $this->assertContains("$this->siteDirectory/private", $exclusions);
    foreach (static::SETTINGS_FILES as $settings_file) {
      $this->assertContains("$this->siteDirectory/$settings_file", $exclusions);
    }
    if (is_dir(__DIR__ . '/../../../.git')) {
      $module_path = $this->container->get('extension.list.module')
        ->getPath('automatic_updates');
      $this->assertContains($module_path, $exclusions);
    }
  }

}
