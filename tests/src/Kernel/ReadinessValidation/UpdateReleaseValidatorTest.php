<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\UpdateReleaseValidator
 *
 * @group automatic_updates
 */
class UpdateReleaseValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Tests that an error is raised when trying to update to an unknown release.
   */
  public function testUnknownReleaseRaisesError(): void {
    $result = ValidationResult::createError([
      'Cannot update Drupal core to 9.8.99 because it is not in the list of installable releases.',
    ]);

    try {
      $this->container->get('automatic_updates.updater')->begin([
        'drupal' => '9.8.99',
      ]);
      $this->fail('Expected an exception to be thrown, but it was not.');
    }
    catch (UpdateException $e) {
      $this->assertValidationResultsEqual([$result], $e->getResults());
    }
  }

}
