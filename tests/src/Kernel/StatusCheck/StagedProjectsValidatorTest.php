<?php

namespace Drupal\Tests\automatic_updates\Kernel\StatusCheck;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use Drupal\Tests\package_manager\Traits\FixtureUtilityTrait;

/**
 * @covers \Drupal\automatic_updates\Validator\StagedProjectsValidator
 *
 * @group automatic_updates
 */
class StagedProjectsValidatorTest extends AutomaticUpdatesKernelTestBase {

  use FixtureUtilityTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // In this test, we don't care whether the updated projects are secure and
    // supported.
    $this->disableValidators[] = 'package_manager.validator.supported_releases';
    parent::setUp();
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

    /** @var \Drupal\automatic_updates\Updater $updater */
    $updater = $this->container->get('automatic_updates.updater');
    $updater->begin(['drupal' => '9.8.1']);
    $updater->stage();

    $error = ValidationResult::createError(["Composer could not find the config file: $composer_json\n"]);
    try {
      $updater->apply();
      $this->fail('Expected an error, but none was raised.');
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual([$error], $e->getResults());
    }
  }

  /**
   * Tests that an error is raised if Drupal extensions are unexpectedly added.
   */
  public function testProjectsAdded(): void {
    $this->copyFixtureFolderToActiveDirectory(__DIR__ . '/../../../fixtures/StagedProjectsValidatorTest/new_project_added');

    $updater = $this->container->get('automatic_updates.updater');
    $updater->begin(['drupal' => '9.8.1']);
    $updater->stage();

    $stage_dir = $updater->getStageDirectory();
    $this->addPackage($stage_dir, [
      'name' => 'drupal/test_module2',
      'version' => '1.3.1',
      'type' => 'drupal-module',
      'install_path' => '../../modules/test_module2',
    ]);
    $this->addPackage($stage_dir, [
      'name' => 'drupal/dev-test_module2',
      'version' => '1.3.1',
      'type' => 'drupal-custom-module',
      'dev_requirement' => TRUE,
      'install_path' => '../../modules/dev-test_module2',
    ]);
    // The validator shouldn't complain about these packages being added or
    // removed, since it only cares about Drupal modules and themes.
    $this->addPackage($stage_dir, [
      'name' => 'other/new_project',
      'version' => '1.3.1',
      'type' => 'library',
      'install_path' => '../other/new_project',
    ]);
    $this->addPackage($stage_dir, [
      'name' => 'other/dev-new_project',
      'version' => '1.3.1',
      'type' => 'library',
      'dev_requirement' => TRUE,
      'install_path' => '../other/dev-new_project',
    ]);
    $this->removePackage($stage_dir, 'other/removed');
    $this->removePackage($stage_dir, 'other/dev-removed');

    $messages = [
      "module 'drupal/test_module2' installed.",
      "custom module 'drupal/dev-test_module2' installed.",
    ];
    $error = ValidationResult::createError($messages, t('The update cannot proceed because the following Drupal projects were installed during the update.'));
    try {
      $updater->apply();
      $this->fail('Expected an error, but none was raised.');
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual([$error], $e->getResults());
    }
  }

  /**
   * Tests that errors are raised if Drupal extensions are unexpectedly removed.
   */
  public function testProjectsRemoved(): void {
    $this->copyFixtureFolderToActiveDirectory(__DIR__ . '/../../../fixtures/StagedProjectsValidatorTest/project_removed');

    $updater = $this->container->get('automatic_updates.updater');
    $updater->begin(['drupal' => '9.8.1']);
    $updater->stage();

    $stage_dir = $updater->getStageDirectory();
    $this->removePackage($stage_dir, 'drupal/test_theme');
    $this->removePackage($stage_dir, 'drupal/dev-test_theme');
    // The validator shouldn't complain about these packages being removed,
    // since it only cares about Drupal modules and themes.
    $this->removePackage($stage_dir, 'other/removed');
    $this->removePackage($stage_dir, 'other/dev-removed');

    $messages = [
      "theme 'drupal/test_theme' removed.",
      "custom theme 'drupal/dev-test_theme' removed.",
    ];
    $error = ValidationResult::createError($messages, t('The update cannot proceed because the following Drupal projects were removed during the update.'));
    try {
      $updater->apply();
      $this->fail('Expected an error, but none was raised.');
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual([$error], $e->getResults());
    }
  }

  /**
   * Tests that errors are raised if Drupal extensions are unexpectedly updated.
   */
  public function testVersionsChanged(): void {
    $this->copyFixtureFolderToActiveDirectory(__DIR__ . '/../../../fixtures/StagedProjectsValidatorTest/version_changed');

    $updater = $this->container->get('automatic_updates.updater');
    $updater->begin(['drupal' => '9.8.1']);
    $updater->stage();

    $stage_dir = $updater->getStageDirectory();
    $this->modifyPackage($stage_dir, 'drupal/test_module', [
      'version' => '1.3.1',
    ]);
    $this->modifyPackage($stage_dir, 'drupal/dev-test_module', [
      'version' => '1.3.1',
    ]);
    // The validator shouldn't complain about these packages being updated,
    // because it only cares about Drupal modules and themes.
    $this->modifyPackage($stage_dir, 'other/changed', [
      'version' => '1.3.2',
    ]);
    $this->modifyPackage($stage_dir, 'other/dev-changed', [
      'version' => '1.3.2',
    ]);

    $messages = [
      "module 'drupal/test_module' from 1.3.0 to 1.3.1.",
      "module 'drupal/dev-test_module' from 1.3.0 to 1.3.1.",
    ];
    $error = ValidationResult::createError($messages, t('The update cannot proceed because the following Drupal projects were unexpectedly updated. Only Drupal Core updates are currently supported.'));
    try {
      $updater->apply();
      $this->fail('Expected an error, but none was raised.');
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual([$error], $e->getResults());
    }
  }

  /**
   * Tests that no errors occur if only core and its dependencies are updated.
   */
  public function testNoErrors(): void {
    $this->copyFixtureFolderToActiveDirectory(__DIR__ . '/../../../fixtures/StagedProjectsValidatorTest/no_errors');

    $updater = $this->container->get('automatic_updates.updater');
    $updater->begin(['drupal' => '9.8.1']);
    $updater->stage();

    $stage_dir = $updater->getStageDirectory();
    $this->modifyPackage($stage_dir, 'drupal/core', [
      'version' => '9.8.1',
    ]);
    // The validator shouldn't care what happens to these packages, since it
    // only concerns itself with Drupal modules and themes.
    $this->addPackage($stage_dir, [
      'name' => 'other/new_project',
      'version' => '1.3.1',
      'type' => 'library',
      'install_path' => '../other/new_project',
    ]);
    $this->addPackage($stage_dir, [
      'name' => 'other/dev-new_project',
      'version' => '1.3.1',
      'type' => 'library',
      'dev_requirement' => TRUE,
      'install_path' => '../other/dev-new_project',
    ]);
    $this->modifyPackage($stage_dir, 'other/changed', [
      'version' => '1.3.2',
    ]);
    $this->modifyPackage($stage_dir, 'other/dev-changed', [
      'version' => '1.3.2',
    ]);
    $this->removePackage($stage_dir, 'other/removed');
    $this->removePackage($stage_dir, 'other/dev-removed');

    $updater->apply();
  }

}
