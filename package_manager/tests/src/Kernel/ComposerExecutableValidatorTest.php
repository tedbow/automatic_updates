<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\Url;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Validator\ComposerExecutableValidator;
use Drupal\package_manager\ValidationResult;
use PhpTuf\ComposerStager\Exception\IOException;
use PhpTuf\ComposerStager\Infrastructure\Process\ExecutableFinderInterface;
use Prophecy\Argument;

/**
 * @covers \Drupal\package_manager\Validator\ComposerExecutableValidator
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

    $this->enableModules(['help']);
    $this->container->set('package_manager.executable_finder', $exec_finder->reveal());
    $this->assertResultsWithHelp([$error], PreCreateEvent::class);
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
      $minimum_version = ComposerExecutableValidator::MINIMUM_COMPOSER_VERSION;

      return ValidationResult::createError([
        "Composer $minimum_version or later is required, but version $version was detected.",
      ]);
    };

    return [
      [
        ComposerExecutableValidator::MINIMUM_COMPOSER_VERSION,
        [],
      ],
      [
        '2.1.6',
        [$unsupported_version('2.1.6')],
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
        [$unsupported_version('2.0.0-alpha3')],
      ],
      [
        '2.1.0-RC1',
        [$unsupported_version('2.1.0-RC1')],
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
    /** @var \PhpTuf\ComposerStager\Domain\Process\Runner\ComposerRunnerInterface|\Prophecy\Prophecy\ObjectProphecy $runner */
    $runner = $this->prophesize('\PhpTuf\ComposerStager\Domain\Process\Runner\ComposerRunnerInterface');

    $runner->run(['--version'], Argument::type(ComposerExecutableValidator::class))
      // Whatever is passed to ::run() will be passed to this mock callback in
      // $arguments, and we know exactly what that will contain: an array of
      // command arguments for Composer, and the validator object.
      ->will(function (array $arguments) use ($reported_version) {
        /** @var \Drupal\package_manager\Validator\ComposerExecutableValidator $validator */
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

    $this->enableModules(['help']);
    $this->container->set('package_manager.composer_runner', $runner->reveal());
    $this->assertResultsWithHelp($expected_results, PreCreateEvent::class);
  }

  /**
   * Asserts that a set of validation results link to the Package Manager help.
   *
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   * @param string|null $event_class
   *   (optional) The class of the event which should return the results. Must
   *   be passed if $expected_results is not empty.
   */
  private function assertResultsWithHelp(array $expected_results, string $event_class = NULL): void {
    $url = Url::fromRoute('help.page', ['name' => 'package_manager'])
      ->setOption('fragment', 'package-manager-requirements')
      ->toString();

    // Reformat the provided results so that they all have the link to the
    // online documentation appended to them.
    $map = function (string $message) use ($url): string {
      return $message . ' See <a href="' . $url . '">the help page</a> for information on how to configure the path to Composer.';
    };
    foreach ($expected_results as $index => $result) {
      $messages = array_map($map, $result->getMessages());
      $expected_results[$index] = ValidationResult::createError($messages);
    }
    $this->assertResults($expected_results, $event_class);
  }

}
