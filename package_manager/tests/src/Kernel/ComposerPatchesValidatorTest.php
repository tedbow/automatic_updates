<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\fixture_manipulator\ActiveFixtureManipulator;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\Validator\ComposerPatchesValidator
 * @group package_manager
 * @internal
 */
class ComposerPatchesValidatorTest extends PackageManagerKernelTestBase {

  /**
   * Tests that the patcher configuration is validated during pre-create.
   */
  public function testError(): void {
    // Simulate an active directory where the patcher is installed, but there's
    // no composer-exit-on-patch-failure flag.
    $dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    $this->installPatcherInActive($dir);

    // Because ComposerUtility reads composer.json and passes it to the Composer
    // factory as an array, Composer will assume that the configuration is
    // coming from a config.json file, even if one doesn't exist.
    $error = ValidationResult::createError([
      "The <code>cweagans/composer-patches</code> plugin is installed, but the <code>composer-exit-on-patch-failure</code> key is not set to <code>true</code> in the <code>extra</code> section of $dir/config.json.",
    ]);
    $this->assertStatusCheckResults([$error]);
    $this->assertResults([$error], PreCreateEvent::class);
  }

  /**
   * Tests that the patcher configuration is validated during pre-apply.
   */
  public function testErrorDuringPreApply(): void {
    // Simulate an active directory where the patcher is installed, but there's
    // no composer-exit-on-patch-failure flag.
    $dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    $this->container->get('event_dispatcher')->addListener(
      PreApplyEvent::class,
      function () use ($dir): void {
        $this->installPatcherInActive($dir);
      },
      PHP_INT_MAX
    );
    // Because ComposerUtility reads composer.json and passes it to the Composer
    // factory as an array, Composer will assume that the configuration is
    // coming from a config.json file, even if one doesn't exist.
    $error = ValidationResult::createError([
      "The <code>cweagans/composer-patches</code> plugin is installed, but the <code>composer-exit-on-patch-failure</code> key is not set to <code>true</code> in the <code>extra</code> section of $dir/config.json.",
    ]);
    $this->assertResults([$error], PreApplyEvent::class);
  }

  /**
   * Simulates that the patcher is installed in the active directory.
   *
   * @param string $dir
   *   The active directory.
   */
  private function installPatcherInActive(string $dir): void {
    (new ActiveFixtureManipulator())
      ->addPackage([
        'name' => 'cweagans/composer-patches',
        'version' => '1.0.0',
        'type' => 'composer-plugin',
      ])->commitChanges();
  }

}
