<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\StagedProjectsValidator
 *
 * @group automatic_updates
 */
class StagedProjectsValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Asserts a set of validation results when staged changes are applied.
   *
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   */
  private function validate(array $expected_results): void {
    /** @var \Drupal\automatic_updates\Updater $updater */
    $updater = $this->container->get('automatic_updates.updater');
    $updater->begin(['drupal' => '9.8.1']);
    $updater->stage();

    try {
      $updater->apply();
      // If no exception occurs, ensure we weren't expecting any errors.
      $this->assertEmpty($expected_results);
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

  /**
   * Tests that exceptions are turned into validation errors.
   */
  public function testEventConsumesExceptionResults(): void {
    $composer_json = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
    $composer_json .= '/composer.json';

    $listener = function (PreApplyEvent $event) use ($composer_json): void {
      unlink($composer_json);
      // Directly invoke the validator under test, which should raise a
      // validation error.
      $this->container->get('automatic_updates.staged_projects_validator')
        ->validateStagedProjects($event);
      // Prevent any other event subscribers from running, since they might try
      // to read the file we just deleted.
      $event->stopPropagation();
    };
    $this->container->get('event_dispatcher')
      ->addListener(PreApplyEvent::class, $listener, PHP_INT_MAX);

    $this->validate([
      ValidationResult::createError(["Composer could not find the config file: $composer_json\n"]),
    ]);
  }

  /**
   * Tests validation errors, or lack thereof.
   *
   * @param string $root_fixture_directory
   *   A directory containing to fixtures sub direcotories, 'active' and
   *   'staged'.
   * @param string|null $expected_summary
   *   The expected error summary, or NULL if no errors are expected.
   * @param string[] $expected_messages
   *   The expected error messages, if any.
   *
   * @dataProvider providerErrors
   */
  public function testErrors(string $root_fixture_directory, ?string $expected_summary, array $expected_messages): void {
    $this->copyFixtureFolderToActiveDirectory("$root_fixture_directory/active");
    $this->copyFixtureFolderToStageDirectoryOnApply("$root_fixture_directory/staged");

    $expected_results = [];
    if ($expected_messages) {
      // @codingStandardsIgnoreLine
      $expected_results[] = ValidationResult::createError($expected_messages, t($expected_summary));
    }
    $this->validate($expected_results);
  }

  /**
   * Data provider for testErrors().
   *
   * @return \string[][]
   *   The test cases.
   */
  public function providerErrors(): array {
    $fixtures_folder = __DIR__ . '/../../../fixtures/StagedProjectsValidatorTest';
    return [
      'new_project_added' => [
        "$fixtures_folder/new_project_added",
        'The update cannot proceed because the following Drupal projects were installed during the update.',
        [
          "module 'drupal/test_module2' installed.",
          "custom module 'drupal/dev-test_module2' installed.",
        ],
      ],
      'project_removed' => [
        "$fixtures_folder/project_removed",
        'The update cannot proceed because the following Drupal projects were removed during the update.',
        [
          "theme 'drupal/test_theme' removed.",
          "custom theme 'drupal/dev-test_theme' removed.",
        ],
      ],
      'version_changed' => [
        "$fixtures_folder/version_changed",
        'The update cannot proceed because the following Drupal projects were unexpectedly updated. Only Drupal Core updates are currently supported.',
        [
          "module 'drupal/test_module' from 1.3.0 to 1.3.1.",
          "module 'drupal/dev-test_module' from 1.3.0 to 1.3.1.",
        ],
      ],
      'no_errors' => [
        "$fixtures_folder/no_errors",
        NULL,
        [],
      ],
    ];
  }

}
