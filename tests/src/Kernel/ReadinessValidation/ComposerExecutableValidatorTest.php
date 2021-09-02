<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use PhpTuf\ComposerStager\Exception\IOException;
use PhpTuf\ComposerStager\Infrastructure\Process\ExecutableFinderInterface;

/**
 * @covers \Drupal\automatic_updates\Validation\ComposerExecutableValidator
 *
 * @group automatic_updates
 */
class ComposerExecutableValidatorTest extends KernelTestBase {

  use ValidationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Tests that an error is raised if the Composer executable isn't found.
   */
  public function testErrorIfComposerNotFound(): void {
    $exception = new IOException("This is your regularly scheduled error.");

    // The executable finder throws an exception if it can't find the requested
    // executable.
    $exec_finder = $this->prophesize(ExecutableFinderInterface::class);
    $exec_finder->find('composer')
      ->willThrow($exception)
      ->shouldBeCalled();
    $this->container->set('automatic_updates.exec_finder', $exec_finder->reveal());

    // The validator should translate that exception into an error.
    $error = ValidationResult::createError([
      $exception->getMessage(),
    ]);
    $results = $this->container->get('automatic_updates.readiness_validation_manager')
      ->run()
      ->getResults();
    $this->assertValidationResultsEqual([$error], $results);
  }

}
