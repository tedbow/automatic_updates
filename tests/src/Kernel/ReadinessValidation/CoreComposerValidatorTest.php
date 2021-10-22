<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\PathLocator;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\CoreComposerValidator
 *
 * @group automatic_updates
 */
class CoreComposerValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'package_manager',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    // Disable validators which interfere with the validator under test.
    $container->removeDefinition('automatic_updates.disk_space_validator');
  }

  /**
   * Tests that an error is raised if core is not required in composer.json.
   */
  public function testCoreNotRequired(): void {
    // Point to a valid composer.json with no requirements.
    $locator = $this->prophesize(PathLocator::class);
    $locator->getActiveDirectory()->willReturn(__DIR__ . '/../../../fixtures/project_staged_validation/no_core_requirements');
    $this->container->set('package_manager.path_locator', $locator->reveal());

    $error = ValidationResult::createError([
      'Drupal core does not appear to be required in the project-level composer.json.',
    ]);
    $this->assertCheckerResultsFromManager([$error], TRUE);
  }

}
