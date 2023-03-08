<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Unit;

use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\Stage;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\package_manager\Event\StatusCheckEvent
 * @group package_manager
 */
class StatusCheckEventTest extends UnitTestCase {

  /**
   * @covers ::getExcludedPaths
   */
  public function testNoPathsNoErrorException(): void {
    $event = new StatusCheckEvent(
      $this->prophesize(Stage::class)->reveal(),
      NULL
    );
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('$ignored_paths should only be NULL if the error that caused the paths to not be collected was added to the status check event.');
    $event->getExcludedPaths();
  }

}
