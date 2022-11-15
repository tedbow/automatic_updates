<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\package_manager\ComposerUtility;
use Drupal\Tests\package_manager\Traits\FixtureUtilityTrait;
use org\bovigo\vfs\vfsStream;

/**
 * @coversDefaultClass \Drupal\package_manager\ComposerUtility
 *
 * @group package_manager
 */
class ComposerUtilityTest extends KernelTestBase {

  use FixtureUtilityTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['package_manager', 'update'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $fixture = vfsStream::newDirectory('fixture');
    $this->vfsRoot->addChild($fixture);
    static::copyFixtureFilesTo(__DIR__ . '/../../fixtures/project_package_conversion', $fixture->url());
  }

  /**
   * Tests that ComposerUtility::CreateForDirectory() validates the directory.
   */
  public function testCreateForDirectoryValidation(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Composer could not find the config file: vfs://root/composer.json');

    $dir = vfsStream::setup()->url();
    ComposerUtility::createForDirectory($dir);
  }

  /**
   * Tests that ComposerUtility disables automatic creation of .htaccess files.
   */
  public function testHtaccessProtectionDisabled(): void {
    $dir = vfsStream::setup()->url();
    file_put_contents($dir . '/composer.json', '{}');

    ComposerUtility::createForDirectory($dir);
    $this->assertFileDoesNotExist($dir . '/.htaccess');
  }

  /**
   * @covers ::getProjectForPackage
   *
   * @param string $package
   *   The package name.
   * @param string|null $expected_project
   *   The expected project if any, otherwise NULL.
   *
   * @dataProvider providerGetProjectForPackage
   */
  public function testGetProjectForPackage(string $package, ?string $expected_project): void {
    $dir = $this->vfsRoot->getChild('fixture')->url();
    $this->assertSame($expected_project, ComposerUtility::createForDirectory($dir)->getProjectForPackage($package));
  }

  /**
   * Data provider for ::testGetProjectForPackage().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerGetProjectForPackage(): array {
    return [
      'package and project match' => [
        'drupal/package_project_match',
        'package_project_match',
      ],
      'package and project do not match' => [
        'drupal/not_match_package',
        'not_match_project',
      ],
      'vendor is not drupal' => [
        'non_drupal/other_project',
        NULL,
      ],
      'missing package' => [
        'drupal/missing',
        NULL,
      ],
      'nested_no_match' => [
        'drupal/nested_no_match_package',
        'nested_no_match_project',
      ],
      'unsupported package type' => [
        'drupal/custom_module',
        NULL,
      ],
    ];
  }

  /**
   * @covers ::getPackageForProject
   *
   * @param string $project
   *   The project name.
   * @param string|null $expected_package
   *   The expected package if any, otherwise NULL.
   *
   * @dataProvider providerGetPackageForProject
   */
  public function testGetPackageForProject(string $project, ?string $expected_package): void {
    $dir = $this->vfsRoot->getChild('fixture')->url();
    $this->assertSame($expected_package, ComposerUtility::createForDirectory($dir)->getPackageForProject($project));
  }

  /**
   * Data provider for ::testGetPackageForProject().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerGetPackageForProject(): array {
    return [
      'package and project match' => [
        'package_project_match',
        'drupal/package_project_match',
      ],
      'package and project do not match' => [
        'not_match_project',
        'drupal/not_match_package',
      ],
      'package and project match + wrong installed path' => [
        'not_match_path_project',
        NULL,
      ],
      'vendor is not drupal' => [
        'other_project',
        NULL,
      ],
      'missing package' => [
        'missing',
        NULL,
      ],
      'nested_no_match' => [
        'nested_no_match_project',
        'drupal/nested_no_match_package',
      ],
    ];
  }

}
