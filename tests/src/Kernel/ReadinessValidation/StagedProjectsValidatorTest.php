<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use Drupal\Tests\package_manager\Kernel\TestStage;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Filesystem\Filesystem;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // This test deals with fake sites that don't necessarily have lock files,
    // so disable lock file validation.
    $this->disableValidators[] = 'package_manager.validator.lock_file';
    parent::setUp();
  }

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
    $this->mockPathLocator($active_dir, $active_dir);

    $stage_dir_exists = is_dir($stage_dir);
    if ($stage_dir_exists) {
      // If we are testing a fixture with existing stage directory then we
      // need to use a virtual file system directory, so we can create a
      // subdirectory using the stage ID after it is created below.
      $vendor = vfsStream::newDirectory('au_stage');
      $this->vfsRoot->addChild($vendor);
      TestStage::$stagingRoot = $vendor->url();
    }
    else {
      // If we are testing non-existent staging directory we can use the path
      // directly.
      TestStage::$stagingRoot = $stage_dir;
    }

    $updater = $this->container->get('automatic_updates.updater');
    $stage_id = $updater->begin(['drupal' => '9.8.1']);
    if ($stage_dir_exists) {
      // Copy the fixture's staging directory into a subdirectory using the
      // stage ID as the directory name.
      $sub_directory = vfsStream::newDirectory($stage_id);
      $vendor->addChild($sub_directory);
      (new Filesystem())->mirror($stage_dir, $sub_directory->url());
    }

    // The staged projects validator only runs before staged updates are
    // applied. Since the active and stage directories may not exist, we don't
    // want to invoke the other stages of the update because they may raise
    // errors that are outside of the scope of what we're testing here.
    try {
      $updater->apply();
      return [];
    }
    catch (StageValidationException $e) {
      return $e->getResults();
    }
  }

  /**
   * Tests that if an exception is thrown, the event will absorb it.
   */
  public function testEventConsumesExceptionResults(): void {
    // Prepare a fake site in the virtual file system which contains valid
    // Composer data.
    $fixture = __DIR__ . '/../../../fixtures/fake-site';
    copy("$fixture/composer.json", 'public://composer.json');
    mkdir('public://vendor/composer', 0777, TRUE);
    copy("$fixture/vendor/composer/installed.json", 'public://vendor/composer/installed.json');

    $event_dispatcher = $this->container->get('event_dispatcher');
    // Disable the disk space validator, since it doesn't work with vfsStream,
    // and the excluded paths subscriber, since it won't deal with this tiny
    // virtual file system correctly.
    $disable_subscribers = array_map([$this->container, 'get'], [
      'package_manager.validator.disk_space',
      'package_manager.excluded_paths_subscriber',
    ]);
    array_walk($disable_subscribers, [$event_dispatcher, 'removeSubscriber']);

    // Just before the staged changes are applied, delete the composer.json file
    // to trigger an error. This uses the highest possible priority to guarantee
    // it runs before any other subscribers.
    $listener = function () {
      unlink('public://composer.json');
    };
    $event_dispatcher->addListener(PreApplyEvent::class, $listener, PHP_INT_MAX);

    $results = $this->validate('public://', '/fake/stage/dir');
    $this->assertCount(1, $results);
    $messages = reset($results)->getMessages();
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('Composer could not find the config file: public:///composer.json', (string) reset($messages));
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
          "module 'drupal/test_module' from 1.3.0 to 1.3.1.",
          "module 'drupal/dev-test_module' from 1.3.0 to 1.3.1.",
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

}
