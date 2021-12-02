<?php

namespace Drupal\Tests\package_manager\Kernel;

/**
 * @coversDefaultClass \Drupal\package_manager\Stage
 *
 * @group package_manager
 */
class StageTest extends PackageManagerKernelTestBase {

  /**
   * @covers ::getStageDirectory
   */
  public function testGetStageDirectory(): void {
    $stage = $this->createStage();
    $id = $stage->create();
    $this->assertStringEndsWith("/.package_manager/$id", $stage->getStageDirectory());
  }

  /**
   * @covers ::getStageDirectory
   */
  public function testUncreatedGetStageDirectory(): void {
    $this->expectException('LogicException');
    $this->expectExceptionMessage('Drupal\package_manager\Stage::getStageDirectory() cannot be called because the stage has not been created or claimed.');
    $this->createStage()->getStageDirectory();
  }

}
