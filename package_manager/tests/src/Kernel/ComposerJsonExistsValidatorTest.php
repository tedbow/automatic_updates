<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreApplyEvent;
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
      $this->assertEventPropagationStopped($event_class, [$this->container->get('package_manager.validator.composer_json_exists'), 'validateComposerJson']);
    }
    $this->assertStatusCheckResults([$result]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

  /**
   * Tests that active composer.json is not present during pre-apply.
   */
  public function testComposerRequirementDuringPreApply(): void {
    $result = ValidationResult::createError([
      'No composer.json file can be found at vfs://root/active',
    ]);
    $this->addEventTestListener(function (): void {
      unlink($this->container->get('package_manager.path_locator')
        ->getProjectRoot() . '/composer.json');
    });
    $this->assertEventPropagationStopped(PreApplyEvent::class, [$this->container->get('package_manager.validator.composer_json_exists'), 'validateComposerJson']);
    $this->assertResults([$result], PreApplyEvent::class);
  }

}
