<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;
use Drupal\package_manager\Validator\SymlinkValidator;

/**
 * @covers \Drupal\package_manager\Validator\SymlinkValidator
 *
 * @group package_manager
 */
class SymlinkValidatorTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    $container->getDefinition('package_manager.validator.symlink')
      ->setClass(TestSymlinkValidator::class);
  }

  /**
   * Tests that a symlink in the project root raises an error.
   */
  public function testSymlinkInProjectRoot(): void {
    $result = ValidationResult::createError([
      'Symbolic links were found in the active directory, which are not supported at this time.',
    ]);

    $active_dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
    // @see \Drupal\Tests\package_manager\Kernel\TestSymlinkValidator::isLink()
    touch($active_dir . '/modules/a_link');
    $this->assertStatusCheckResults([$result]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

  /**
   * Tests that a symlink in the staging area raises an error.
   */
  public function testSymlinkInStagingArea(): void {
    $result = ValidationResult::createError([
      'Symbolic links were found in the staging area, which are not supported at this time.',
    ]);

    $stage = $this->createStage();
    $stage->create();
    $stage->require(['composer/semver:^3']);

    // @see \Drupal\Tests\package_manager\Kernel\TestSymlinkValidator::isLink()
    touch($stage->getStageDirectory() . '/modules/a_link');

    try {
      $stage->apply();
      $this->fail('Expected a validation error.');
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual([$result], $e->getResults());
    }
  }

  /**
   * Tests that symlinks in the project root and staging area raise an error.
   */
  public function testSymlinkInProjectRootAndStagingArea(): void {
    $expected_results = [
      ValidationResult::createError([
        'Symbolic links were found in the active directory, which are not supported at this time.',
      ]),
      ValidationResult::createError([
        'Symbolic links were found in the staging area, which are not supported at this time.',
      ]),
    ];

    $stage = $this->createStage();
    $stage->create();
    $stage->require(['composer/semver:^3']);

    $active_dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
    // @see \Drupal\Tests\package_manager\Kernel\TestSymlinkValidator::isLink()
    touch($active_dir . '/modules/a_link');
    touch($stage->getStageDirectory() . '/modules/a_link');

    try {
      $stage->apply();
      $this->fail('Expected a validation error.');
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

}

/**
 * A test validator that considers anything named 'a_link' to be a symlink.
 */
class TestSymlinkValidator extends SymlinkValidator {

  /**
   * {@inheritdoc}
   */
  protected function isLink(\SplFileInfo $file): bool {
    return $file->getBasename() === 'a_link' || parent::isLink($file);
  }

}
