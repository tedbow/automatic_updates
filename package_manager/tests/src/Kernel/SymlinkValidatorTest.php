<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Exception\StageEventException;
use Drupal\package_manager\PathLocator;
use Drupal\package_manager\ValidationResult;
use PhpTuf\ComposerStager\Domain\Service\Host\HostInterface;

/**
 * @covers \Drupal\package_manager\Validator\SymlinkValidator
 * @group package_manager
 * @internal
 */
class SymlinkValidatorTest extends PackageManagerKernelTestBase {

  /**
   * Tests that relative symlinks within the same package are supported.
   */
  public function testSymlinksWithinSamePackage(): void {
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();

    $drush_dir = $project_root . '/vendor/drush/drush';
    mkdir($drush_dir . '/docs', 0777, TRUE);
    touch($drush_dir . '/drush_logo-black.png');
    // Relative symlinks must be made from their actual directory to be
    // correctly evaluated.
    chdir($drush_dir . '/docs');
    symlink('../drush_logo-black.png', 'drush_logo-black.png');
    // Switch back to the project root to ensure that the check isn't affected
    // by which directory we happen to be in.
    chdir($project_root);

    $this->assertStatusCheckResults([]);
  }

  /**
   * Tests that hard links are not supported.
   */
  public function testHardLinks(): void {
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();

    link($project_root . '/composer.json', $project_root . '/composer.link');
    $result = ValidationResult::createError([
      t('The active directory at "@dir" contains hard links, which is not supported. The first one is "@dir/composer.json".', [
        '@dir' => $project_root,
      ]),
    ]);
    $this->assertStatusCheckResults([$result]);
  }

  /**
   * Tests that symlinks with absolute paths are not supported.
   */
  public function testAbsoluteSymlinks(): void {
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();

    symlink($project_root . '/composer.json', $project_root . '/composer.link');
    $result = ValidationResult::createError([
      t('The active directory at "@dir" contains absolute links, which is not supported. The first one is "@dir/composer.link".', [
        '@dir' => $project_root,
      ]),
    ]);
    $this->assertStatusCheckResults([$result]);
  }

  /**
   * Tests that relative symlinks cannot point outside the project root.
   */
  public function testSymlinkPointingOutsideProjectRoot(): void {
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();

    $parent_dir = dirname($project_root);
    touch($parent_dir . '/hello.txt');
    // Relative symlinks must be made from their actual directory to be
    // correctly evaluated.
    chdir($project_root);
    symlink('../hello.txt', 'fail.txt');
    $result = ValidationResult::createError([
      t('The active directory at "@dir" contains links that point outside the codebase, which is not supported. The first one is "@dir/fail.txt".', [
        '@dir' => $project_root,
      ]),
    ]);
    $this->assertStatusCheckResults([$result]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

  /**
   * Tests that relative symlinks cannot point outside the stage directory.
   */
  public function testSymlinkPointingOutsideStageDirectory(): void {
    // The same check should apply to symlinks in the stage directory that
    // point outside of it.
    $stage = $this->createStage();
    $stage->create();
    $stage->require(['ext-json:*']);

    $stage_dir = $stage->getStageDirectory();
    $parent_dir = dirname($stage_dir);
    touch($parent_dir . '/hello.txt');
    // Relative symlinks must be made from their actual directory to be
    // correctly evaluated.
    chdir($stage_dir);
    symlink('../hello.txt', 'fail.txt');

    $result = ValidationResult::createError([
      t('The staging directory at "@dir" contains links that point outside the codebase, which is not supported. The first one is "@dir/fail.txt".', [
        '@dir' => $stage_dir,
      ]),
    ]);
    try {
      $stage->apply();
      $this->fail('Expected an exception, but none was thrown.');
    }
    catch (StageEventException $e) {
      $this->assertExpectedResultsFromException([$result], $e);
    }
  }

  /**
   * Data provider for ::testSymlinkToDirectory().
   *
   * @return array[]
   *   The test cases.
   */
  public function providerSymlinkToDirectory(): array {
    return [
      'php' => [
        'php',
        [
          ValidationResult::createError([
            t('The active directory at "<PROJECT_ROOT>" contains symlinks that point to a directory, which is not supported. The first one is "<PROJECT_ROOT>/modules/custom/example_module".'),
          ]),
        ],
      ],
      'rsync' => [
        'rsync',
        [],
      ],
    ];
  }

  /**
   * Tests what happens when there is a symlink to a directory.
   *
   * @param string $file_syncer
   *   The file syncer to use. Can be `php` or `rsync`.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerSymlinkToDirectory
   */
  public function testSymlinkToDirectory(string $file_syncer, array $expected_results): void {
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();

    mkdir($project_root . '/modules/custom');
    // Relative symlinks must be made from their actual directory to be
    // correctly evaluated.
    chdir($project_root . '/modules/custom');
    symlink('../example', 'example_module');

    $this->config('package_manager.settings')
      ->set('file_syncer', $file_syncer)
      ->save();

    $this->assertStatusCheckResults($expected_results);
  }

  /**
   * Tests that symlinks are not supported on Windows, even if they're safe.
   */
  public function testSymlinksNotAllowedOnWindows(): void {
    $host = $this->prophesize(HostInterface::class);
    $host->isWindows()->willReturn(TRUE);
    $this->container->set(HostInterface::class, $host->reveal());

    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();
    // Relative symlinks must be made from their actual directory to be
    // correctly evaluated.
    chdir($project_root);
    symlink('composer.json', 'composer.link');

    $result = ValidationResult::createError([
      t('The active directory at "@dir" contains links, which is not supported on Windows. The first one is "@dir/composer.link".', [
        '@dir' => $project_root,
      ]),
    ]);
    $this->assertStatusCheckResults([$result]);
  }

  /**
   * Tests that unsupported links are excluded if they're under excluded paths.
   *
   * @depends testAbsoluteSymlinks
   *
   * @covers \Drupal\package_manager\PathExcluder\GitExcluder
   * @covers \Drupal\package_manager\PathExcluder\NodeModulesExcluder
   */
  public function testUnsupportedLinkUnderExcludedPath(): void {
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();

    // Create absolute symlinks (which are not supported by Composer Stager) in
    // both `node_modules`, which is a regular directory, and `.git`, which is a
    // hidden directory.
    mkdir($project_root . '/node_modules');
    symlink($project_root . '/composer.json', $project_root . '/node_modules/composer.link');
    symlink($project_root . '/composer.json', $project_root . '/.git/composer.link');

    $this->assertStatusCheckResults([]);
  }

}
