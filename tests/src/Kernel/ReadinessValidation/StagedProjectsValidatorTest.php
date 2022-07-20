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
   * The active directory in the virtual file system.
   *
   * @var string
   */
  private $activeDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->activeDir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
  }

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
    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher */
    $event_dispatcher = $this->container->get('event_dispatcher');

    // Just before the staged changes are applied, delete the composer.json file
    // to trigger an error. This uses the highest possible priority to guarantee
    // it runs before any other subscribers.
    $listener = function (): void {
      unlink("$this->activeDir/composer.json");
    };
    $event_dispatcher->addListener(PreApplyEvent::class, $listener, PHP_INT_MAX);

    // Disable the scaffold file permissions validator because it will try to
    // read composer.json from the active directory, which won't exist thanks to
    // the event listener we just added.
    $validator = $this->container->get('automatic_updates.validator.scaffold_file_permissions');
    $event_dispatcher->removeSubscriber($validator);

    $result = ValidationResult::createError([
      "Composer could not find the config file: $this->activeDir/composer.json\n",
    ]);
    $this->validate([$result]);
  }

  /**
   * Tests validation errors, or lack thereof.
   *
   * @param string $fixtures_dir
   *   A directory containing `active.installed.json` and
   *   `staged.installed.json` files. These will be used as the virtual
   *   project's active and staged `vendor/composer/installed.json` files,
   *   respectively.
   * @param string|null $expected_summary
   *   The expected error summary, or NULL if no errors are expected.
   * @param string[] $expected_messages
   *   The expected error messages, if any.
   *
   * @dataProvider providerErrors
   */
  public function testErrors(string $fixtures_dir, ?string $expected_summary, array $expected_messages): void {
    $this->assertFileIsReadable("$fixtures_dir/active.installed.json");
    $this->assertFileIsReadable("$fixtures_dir/staged.installed.json");

    copy("$fixtures_dir/active.installed.json", "$this->activeDir/vendor/composer/installed.json");

    // Before any other pre-apply listener runs, replaced the staged
    // `vendor/composer/installed.json` with the fixture's
    // `staged.installed.json`.
    $listener = function (PreApplyEvent $event) use ($fixtures_dir): void {
      copy("$fixtures_dir/staged.installed.json", $event->getStage()->getStageDirectory() . "/vendor/composer/installed.json");
    };
    $this->container->get('event_dispatcher')
      ->addListener(PreApplyEvent::class, $listener, PHP_INT_MAX);

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
    $fixtures_folder = __DIR__ . '/../../../fixtures/project_staged_validation';
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
