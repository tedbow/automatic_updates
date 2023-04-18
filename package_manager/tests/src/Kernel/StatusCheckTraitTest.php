<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\CollectPathsToExcludeEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\StatusCheckTrait;
use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\StatusCheckTrait
 * @group package_manager
 * @internal
 */
class StatusCheckTraitTest extends PackageManagerKernelTestBase {

  use StatusCheckTrait;

  /**
   * Tests that StatusCheckTrait will collect paths to exclude.
   */
  public function testPathsToExcludeCollected(): void {
    $this->addEventTestListener(function (CollectPathsToExcludeEvent $event): void {
      $event->add(['/junk/drawer']);
    }, CollectPathsToExcludeEvent::class);

    $status_check_called = FALSE;
    $this->addEventTestListener(function (StatusCheckEvent $event) use (&$status_check_called): void {
      $this->assertContains('/junk/drawer', $event->getExcludedPaths());
      $status_check_called = TRUE;
    }, StatusCheckEvent::class);
    $this->runStatusCheck($this->createStage(), $this->container->get('event_dispatcher'));
    $this->assertTrue($status_check_called);
  }

  /**
   * Tests that any error will be added to the status check event.
   */
  public function testNoErrorIfPathsToExcludeCannotBeCollected(): void {
    $this->addEventTestListener(function (): void {
      throw new \Exception('Not a chance, friend.');
    }, CollectPathsToExcludeEvent::class);
    $result = ValidationResult::createError(
      [t('Not a chance, friend.')],
      t('Unable to collect the paths to exclude.'),
    );
    $this->assertStatusCheckResults([$result]);
  }

}
