<?php

namespace Drupal\Tests\automatic_updates\Unit;

use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\Validation\StagedProjectsValidation;
use Drupal\Component\FileSystem\FileSystem;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Validation\StagedProjectsValidation
 */
class StagedProjectsValidationTest extends UnitTestCase {

  /**
   * Tests that if an exception is thrown, the update event will absorb it.
   */
  public function testUpdateEventConsumesExceptionResults(): void {
    $prefix = FileSystem::getOsTemporaryDirectory() . DIRECTORY_SEPARATOR;
    $active_dir = uniqid($prefix);
    $stage_dir = uniqid($prefix);

    $updater = $this->prophesize(Updater::class);
    $updater->getActiveDirectory()->willReturn($active_dir);
    $updater->getStageDirectory()->willReturn($stage_dir);
    $validator = new StagedProjectsValidation(new TestTranslationManager(), $updater->reveal());

    $event = new UpdateEvent();
    $validator->validateStagedProjects($event);
    $results = $event->getResults();
    $this->assertCount(1, $results);
    $messages = reset($results)->getMessages();
    $this->assertCount(1, $messages);
    $this->assertSame("composer.lock file '$active_dir/composer.lock' not found.", (string) reset($messages));
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
    $updater = $this->prophesize(Updater::class);
    $this->assertNotEmpty($fixtures_dir);
    $this->assertDirectoryExists($fixtures_dir);

    $updater->getActiveDirectory()->willReturn("$fixtures_dir/active");
    $updater->getStageDirectory()->willReturn("$fixtures_dir/staged");
    $validator = new StagedProjectsValidation(new TestTranslationManager(), $updater->reveal());
    $event = new UpdateEvent();
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
          "module 'drupal/testmodule2' installed.",
          "custom module 'drupal/dev-testmodule2' installed.",
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
          "module 'drupal/testmodule' from 1.3.0 to  1.3.1.",
          "module 'drupal/dev-testmodule' from 1.3.0 to  1.3.1.",
        ],
      ],
    ];
  }

  /**
   * Tests validation when there are no errors.
   *
   * @covers ::validateStagedProjects
   */
  public function testNoErrors() {
    $fixtures_dir = realpath(__DIR__ . '/../../fixtures/project_staged_validation/no_errors');
    $updater = $this->prophesize(Updater::class);
    $updater->getActiveDirectory()->willReturn("$fixtures_dir/active");
    $updater->getStageDirectory()->willReturn("$fixtures_dir/staged");
    $validator = new StagedProjectsValidation(new TestTranslationManager(), $updater->reveal());
    $event = new UpdateEvent();
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
    $updater = $this->prophesize(Updater::class);
    $updater->getActiveDirectory()->willReturn("$fixtures_dir/active");
    $updater->getStageDirectory()->willReturn("$fixtures_dir");
    $validator = new StagedProjectsValidation(new TestTranslationManager(), $updater->reveal());
    $event = new UpdateEvent();
    $validator->validateStagedProjects($event);
    $results = $event->getResults();
    $this->assertCount(1, $results);
    $result = array_pop($results);
    $this->assertMatchesRegularExpression(
      "/.*automatic_updates\/tests\/fixtures\/project_staged_validation\/no_errors\/composer.lock' not found/",
        (string) $result->getMessages()[0]
      );
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
