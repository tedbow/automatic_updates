<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\EventSubscriber\ComposerExecutableValidator;
use Drupal\package_manager\ValidationResult;
use PhpTuf\ComposerStager\Exception\IOException;
use PhpTuf\ComposerStager\Infrastructure\Process\ExecutableFinderInterface;
use Prophecy\Argument;

/**
 * @covers \Drupal\package_manager\EventSubscriber\ComposerExecutableValidator
 *
 * @group package_manager
 */
class ComposerExecutableValidatorTest extends PackageManagerKernelTestBase {

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
    $this->container->set('package_manager.executable_finder', $exec_finder->reveal());

    // The validator should translate that exception into an error.
    $error = ValidationResult::createError([
      $exception->getMessage(),
    ]);
    $this->assertResults([$error], PreCreateEvent::class);
  }

  /**
   * Data provider for ::testComposerVersionValidation().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerComposerVersionValidation(): array {
    // Invalid or undetectable Composer versions will always produce the same
    // error.
    $invalid_version = ValidationResult::createError(['The Composer version could not be detected.']);

    // Unsupported Composer versions will report the detected version number
    // in the validation result, so we need a function to churn out those fake
    // results for the test method.
    $unsupported_version = function (string $version): ValidationResult {
      return ValidationResult::createError([
        "Composer 2 or later is required, but version $version was detected.",
      ]);
    };

    return [
      // A valid 2.x version of Composer should not produce any errors.
      [
        '2.1.6',
        [],
      ],
      [
        '1.10.22',
        [$unsupported_version('1.10.22')],
      ],
      [
        '1.7.3',
        [$unsupported_version('1.7.3')],
      ],
      [
        '2.0.0-alpha3',
        [],
      ],
      [
        '2.1.0-RC1',
        [],
      ],
      [
        '1.0.0-RC',
        [$unsupported_version('1.0.0-RC')],
      ],
      [
        '1.0.0-beta1',
        [$unsupported_version('1.0.0-beta1')],
      ],
      [
        '1.9-dev',
        [$invalid_version],
      ],
      [
        '@package_version@',
        [$invalid_version],
      ],
    ];
  }

  /**
   * Tests validation of various Composer versions.
   *
   * @param string $reported_version
   *   The version of Composer that `composer --version` should report.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerComposerVersionValidation
   */
  public function testComposerVersionValidation(string $reported_version, array $expected_results): void {
    // Mock the output of `composer --version`, will be passed to the validator,
    // which is itself a callback function that gets called repeatedly as
    // Composer produces output.
    /** @var \PhpTuf\ComposerStager\Infrastructure\Process\Runner\ComposerRunnerInterface|\Prophecy\Prophecy\ObjectProphecy $runner */
    $runner = $this->prophesize('\PhpTuf\ComposerStager\Infrastructure\Process\Runner\ComposerRunnerInterface');

    $runner->run(['--version'], Argument::type(ComposerExecutableValidator::class))
      // Whatever is passed to ::run() will be passed to this mock callback in
      // $arguments, and we know exactly what that will contain: an array of
      // command arguments for Composer, and the validator object.
      ->will(function (array $arguments) use ($reported_version) {
        /** @var \Drupal\package_manager\EventSubscriber\ComposerExecutableValidator $validator */
        $validator = $arguments[1];
        // Invoke the validator (which, as mentioned, is a callback function),
        // with fake output from `composer --version`. It should try to tease a
        // recognized, supported version number out of this output.
        $validator($validator::OUT, "Composer version $reported_version");
      });
    $this->container->set('package_manager.composer_runner', $runner->reveal());

    // If the validator can't find a recognized, supported version of Composer,
    // it should produce errors.
    $this->assertResults($expected_results, PreCreateEvent::class);
  }

}