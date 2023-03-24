<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Exception\StageFailureMarkerException;
use Drupal\package_manager\FailureMarker;

/**
 * @coversDefaultClass \Drupal\package_manager\FailureMarker
 * @group package_manager
 * @internal
 */
class FailureMarkerTest extends PackageManagerKernelTestBase {
  use StringTranslationTrait;

  /**
   * @covers ::assertNotExists
   */
  public function testExceptionIfExists(): void {
    $failure_marker = $this->container->get(FailureMarker::class);
    $failure_marker->write($this->createStage(), $this->t('Disastrous catastrophe!'));

    $this->expectException(StageFailureMarkerException::class);
    $this->expectExceptionMessage('Disastrous catastrophe!');
    $failure_marker->assertNotExists();
  }

  /**
   * Tests that an exception is thrown if the marker file contains invalid JSON.
   *
   * @covers ::assertNotExists
   */
  public function testExceptionForInvalidJson(): void {
    $failure_marker = $this->container->get(FailureMarker::class);
    // Write the failure marker with invalid JSON.
    file_put_contents($failure_marker->getPath(), '{}}');

    $this->expectException(StageFailureMarkerException::class);
    $this->expectExceptionMessage('Failure marker file exists but cannot be decoded.');
    $failure_marker->assertNotExists();
  }

}
