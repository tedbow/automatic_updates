<?php

namespace Drupal\Tests\package_manager\Kernel;

use Composer\Json\JsonFile;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\Validator\ComposerPatchesValidator
 *
 * @group package_manager
 */
class ComposerPatchesValidatorTest extends PackageManagerKernelTestBase {

  /**
   * Tests that the patcher configuration is validated during pre-create.
   */
  public function testPreCreate(): void {
    // Simulate an active directory where the patcher is installed, but there's
    // no composer-exit-on-patch-failure flag.
    $dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    // Simulate that the patcher is installed in the active directory.
    $file = new JsonFile($dir . '/vendor/composer/installed.json');
    $this->assertTrue($file->exists());
    $data = $file->read();
    $data['packages'][] = [
      'name' => 'cweagans/composer-patches',
      'version' => '1.0.0',
    ];
    $file->write($data);

    $error = ValidationResult::createError([
      'The <code>cweagans/composer-patches</code> plugin is installed, but the <code>composer-exit-on-patch-failure</code> key is not set to <code>true</code> in the <code>extra</code> section of composer.json.',
    ]);
    $this->assertResults([$error], PreCreateEvent::class);
  }

}
