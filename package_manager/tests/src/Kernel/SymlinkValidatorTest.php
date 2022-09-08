<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Url;
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

    $this->enableModules(['help']);
    $this->assertStatusCheckResults($this->addHelpTextToResults([$result]));
    $this->assertResultsWithHelp([$result], PreCreateEvent::class);
  }

  /**
   * Tests that a symlink in the staging area raises an error.
   *
   * @dataProvider providerHelpEnabledOrNot
   */
  public function testSymlinkInStagingArea(bool $enable_help): void {
    $expected_results = [ValidationResult::createError([
        'Symbolic links were found in the staging area, which are not supported at this time.',
      ]),
    ];

    if ($enable_help) {
      $this->enableModules(['help']);
      $expected_results = $this->addHelpTextToResults($expected_results);
    }

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
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

  /**
   * Tests that symlinks in the project root and staging area raise an error.
   *
   * @dataProvider providerHelpEnabledOrNot
   */
  public function testSymlinkInProjectRootAndStagingArea(bool $enable_help): void {
    $expected_results = [
      ValidationResult::createError([
        'Symbolic links were found in the active directory, which are not supported at this time.',
      ]),
      ValidationResult::createError([
        'Symbolic links were found in the staging area, which are not supported at this time.',
      ]),
    ];

    if ($enable_help) {
      $this->enableModules(['help']);
      $expected_results = $this->addHelpTextToResults($expected_results);
    }

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

  /**
   * Data provider for test methods that test with and without the Help module.
   *
   * @return array[]
   *   The test cases.
   */
  public function providerHelpEnabledOrNot() {
    return [
      'help_module_enabled' => [TRUE],
      'help_module_disabled' => [FALSE],
    ];
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
    $expected_results = $this->addHelpTextToResults($expected_results);
    $this->assertStatusCheckResults($expected_results);
    $this->assertResults($expected_results, $event_class);
  }

  /**
   * Adds help text to results messages.
   *
   * @param \Drupal\package_manager\ValidationResult[] $results
   *   The expected validation results.
   *
   * @return array
   *   The new results.
   */
  public function addHelpTextToResults(array $results): array {
    $url = Url::fromRoute('help.page', ['name' => 'package_manager'])
      ->setOption('fragment', 'package-manager-faq-symlinks-found')
      ->toString();

    // Reformat the provided results so that they all have the link to the
    // online documentation appended to them.
    $map = function (string $message) use ($url): string {
      return $message . ' See <a href="' . $url . '">the help page</a> for information on how to resolve the problem.';
    };
    foreach ($results as $index => $result) {
      $messages = array_map($map, $result->getMessages());
      $results[$index] = ValidationResult::createError($messages);
    }
    return $results;
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
