<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\Validator\ComposerJsonExistsValidator
 * @group package_manager
 */
class ComposerJsonExistsValidatorTest extends PackageManagerKernelTestBase {

  /**
   * Tests validation when the active composer.json is not present.
   */
  public function testComposerRequirement(): void {
    unlink($this->container->get('package_manager.path_locator')
      ->getProjectRoot() . '/composer.json');
    $result = ValidationResult::createError([
      'No composer.json file can be found at vfs://root/active',
    ]);
    foreach ([PreCreateEvent::class, StatusCheckEvent::class] as $event_class) {
      $this->container->get('event_dispatcher')->addListener(
        $event_class,
        function () use ($event_class): void {
          $this->fail('Event propagation should have been stopped during ' . $event_class . '.');
        },
        // Execute this listener immediately after the tested validator, which
        // uses priority 190. This ensures informative test failures.
        // @see \Drupal\package_manager\Validator\ComposerJsonExistsValidator::getSubscribedEvents()
        189
      );
    }
    $this->assertStatusCheckResults([$result]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

}
