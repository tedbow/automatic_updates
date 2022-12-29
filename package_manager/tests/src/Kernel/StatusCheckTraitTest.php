<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\CollectIgnoredPathsEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\StatusCheckTrait;

/**
 * @covers \Drupal\package_manager\StatusCheckTrait
 * @group package_manager
 * @internal
 */
class StatusCheckTraitTest extends PackageManagerKernelTestBase {

  use StatusCheckTrait;

  /**
   * Tests that StatusCheckTrait will collect ignored paths.
   */
  public function testIgnoredPathsCollected(): void {
    $this->addEventTestListener(function (CollectIgnoredPathsEvent $event): void {
      $event->add(['/junk/drawer']);
    }, CollectIgnoredPathsEvent::class);

    $status_check_called = FALSE;
    $this->addEventTestListener(function (StatusCheckEvent $event) use (&$status_check_called): void {
      $this->assertContains('/junk/drawer', $event->getExcludedPaths());
      $status_check_called = TRUE;
    }, StatusCheckEvent::class);
    $this->runStatusCheck($this->createStage(), $this->container->get('event_dispatcher'));
    $this->assertTrue($status_check_called);
  }

}
