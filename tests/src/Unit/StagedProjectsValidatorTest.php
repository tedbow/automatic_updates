<?php

namespace Drupal\Tests\automatic_updates\Unit;

use Drupal\automatic_updates\Event\PreCommitEvent;
use Drupal\automatic_updates\Validator\StagedProjectsValidator;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\ComposerUtility;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Validator\StagedProjectsValidator
 *
 * @group automatic_updates
 */
class StagedProjectsValidatorTest extends UnitTestCase {

  /**
   * Creates a pre-commit event object for testing.
   *
   * @param string $active_dir
   *   The active directory.
   * @param string $stage_dir
   *   The stage directory.
   *
   * @return \Drupal\automatic_updates\Event\PreCommitEvent
   *   The event object.
   */
  private function createEvent(string $active_dir, string $stage_dir): PreCommitEvent {
    return new PreCommitEvent(
      ComposerUtility::createForDirectory($active_dir),
      ComposerUtility::createForDirectory($stage_dir)
    );
  }

  /**
   * Tests that if an exception is thrown, the update event will absorb it.
   */
  public function testUpdateEventConsumesExceptionResults(): void {
    $message = 'An exception thrown by Composer at runtime.';

    $composer = $this->prophesize(ComposerUtility::class);
    $composer->getDrupalExtensionPackages()
      ->willThrow(new \RuntimeException($message));
    $event = new PreCommitEvent($composer->reveal(), $composer->reveal());

    $validator = new StagedProjectsValidator(new TestTranslationManager());
    $validator->validateStagedProjects($event);
    $results = $event->getResults();
    $this->assertCount(1, $results);
    $messages = reset($results)->getMessages();
    $this->assertCount(1, $messages);
    $this->assertSame($message, (string) reset($messages));
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
   *
   * @covers ::validateStagedProjects
   */
  public function testErrors(string $fixtures_dir, string $expected_summary, array $expected_messages): void {
    $this->assertNotEmpty($fixtures_dir);
    $this->assertDirectoryExists($fixtures_dir);

    $event = $this->createEvent("$fixtures_dir/active", "$fixtures_dir/staged");
    $validator = new StagedProjectsValidator(new TestTranslationManager());
    $validator->validateStagedProjects($event);
    $results = $event->getResults();
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
    $fixtures_folder = realpath(__DIR__ . '/../../fixtures/project_staged_validation');
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
   *
   * @covers ::validateStagedProjects
   */
  public function testNoErrors(): void {
    $fixtures_dir = realpath(__DIR__ . '/../../fixtures/project_staged_validation/no_errors');
    $event = $this->createEvent("$fixtures_dir/active", "$fixtures_dir/staged");
    $validator = new StagedProjectsValidator(new TestTranslationManager());
    $validator->validateStagedProjects($event);
    $results = $event->getResults();
    $this->assertIsArray($results);
    $this->assertEmpty($results);
  }

  /**
   * Tests validation when a composer.lock file is not found.
   */
  public function testNoLockFile(): void {
    $fixtures_dir = realpath(__DIR__ . '/../../fixtures/project_staged_validation/no_errors');

    $event = $this->createEvent("$fixtures_dir/active", $fixtures_dir);
    $validator = new StagedProjectsValidator(new TestTranslationManager());
    $validator->validateStagedProjects($event);
    $results = $event->getResults();
    $this->assertCount(1, $results);
    $result = array_pop($results);
    $this->assertSame("No lockfile found. Unable to read locked packages", (string) $result->getMessages()[0]);
    $this->assertSame('', (string) $result->getSummary());
  }

}

/**
 * Implements a translation manager in tests.
 *
 * @todo Copied from core/modules/user/tests/src/Unit/PermissionHandlerTest.php
 *   when moving to core open an issue consolidate this test class.
 */
class TestTranslationManager implements TranslationInterface {

  /**
   * {@inheritdoc}
   */
  public function translate($string, array $args = [], array $options = []) {
    return new TranslatableMarkup($string, $args, $options, $this);
  }

  /**
   * {@inheritdoc}
   */
  public function translateString(TranslatableMarkup $translated_string) {
    return $translated_string->getUntranslatedString();
  }

  /**
   * {@inheritdoc}
   */
  public function formatPlural($count, $singular, $plural, array $args = [], array $options = []) {
    return new PluralTranslatableMarkup($count, $singular, $plural, $args, $options, $this);
  }

}
