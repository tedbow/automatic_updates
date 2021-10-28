<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\PathLocator;
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
  protected static $modules = [
    'automatic_updates',
    'package_manager',
  ];

  /**
   * Runs the validator under test against an arbitrary pair of directories.
   *
   * @param string $active_dir
   *   The active directory to validate.
   * @param string $stage_dir
   *   The stage directory to validate.
   *
   * @return \Drupal\package_manager\ValidationResult[]
   *   The validation results.
   */
  private function validate(string $active_dir, string $stage_dir): array {
    $locator = $this->prophesize(PathLocator::class);
    $locator->getActiveDirectory()->willReturn($active_dir);
    $locator->getStageDirectory()->willReturn($stage_dir);
    $this->container->set('package_manager.path_locator', $locator->reveal());

    $event = new PreApplyEvent(
      $this->container->get('automatic_updates.updater')
    );

    $this->container->get('event_dispatcher')->dispatch($event);
    return $event->getResults();
  }

  /**
   * Tests that if an exception is thrown, the event will absorb it.
   */
  public function testEventConsumesExceptionResults(): void {
    $results = $this->validate('/fake/active/dir', '/fake/stage/dir');
    $this->assertCount(1, $results);
    $messages = reset($results)->getMessages();
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('Composer could not find the config file: /fake/active/dir/composer.json', (string) reset($messages));
  }

  /**
   * Tests validations errors.
   *
   * @param string $fixtures_dir
   *   The fixtures directory that provides the active and staged composer.lock
   *   files.
   * @param string $expected_summary
   *   The expected error summary.
   * @param string[] $expected_messages
   *   The expected error messages.
   *
   * @dataProvider providerErrors
   */
  public function testErrors(string $fixtures_dir, string $expected_summary, array $expected_messages): void {
    $this->assertNotEmpty($fixtures_dir);
    $this->assertDirectoryExists($fixtures_dir);

    $results = $this->validate("$fixtures_dir/active", "$fixtures_dir/staged");
    $this->assertCount(1, $results);
    $result = array_pop($results);
    $this->assertSame($expected_summary, (string) $result->getSummary());
    $actual_messages = $result->getMessages();
    $this->assertCount(count($expected_messages), $actual_messages);
    foreach ($expected_messages as $message) {
      $actual_message = array_shift($actual_messages);
      $this->assertSame($message, (string) $actual_message);
    }
  }

  /**
   * Data provider for testErrors().
   *
   * @return \string[][]
   *   Test cases for testErrors().
   */
  public function providerErrors(): array {
    $fixtures_folder = realpath(__DIR__ . '/../../../fixtures/project_staged_validation');
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
          "module 'drupal/test_module' from 1.3.0 to  1.3.1.",
          "module 'drupal/dev-test_module' from 1.3.0 to  1.3.1.",
        ],
      ],
    ];
  }

  /**
   * Tests validation when there are no errors.
   */
  public function testNoErrors(): void {
    $fixtures_dir = realpath(__DIR__ . '/../../../fixtures/project_staged_validation/no_errors');
    $results = $this->validate("$fixtures_dir/active", "$fixtures_dir/staged");
    $this->assertIsArray($results);
    $this->assertEmpty($results);
  }

  /**
   * Tests validation when a composer.lock file is not found.
   */
  public function testNoLockFile(): void {
    $fixtures_dir = realpath(__DIR__ . '/../../../fixtures/project_staged_validation/no_errors');

    $results = $this->validate("$fixtures_dir/active", $fixtures_dir);
    $this->assertCount(1, $results);
    $result = array_pop($results);
    $this->assertSame("No lockfile found. Unable to read locked packages", (string) $result->getMessages()[0]);
    $this->assertSame('', (string) $result->getSummary());
  }

}
