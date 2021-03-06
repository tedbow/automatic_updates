<?php

namespace Drupal\Tests\automatic_updates\Build;

use Drupal\Tests\automatic_updates\Build\QuickStart\QuickStartTestBase;
use Drupal\Tests\automatic_updates\Traits\InstallTestTrait;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Services\ModifiedFiles
 *
 * @group Update
 *
 * @requires externalCommand composer
 * @requires externalCommand curl
 * @requires externalCommand git
 * @requires externalCommand tar
 */
class ModifiedFilesTest extends QuickStartTestBase {
  use InstallTestTrait;

  /**
   * Symfony file system.
   *
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  protected $symfonyFileSystem;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->symfonyFileSystem = new SymfonyFilesystem();
  }

  /**
   * @covers ::getModifiedFiles
   * @dataProvider coreProjectProvider
   */
  public function testCoreModified($version, array $modifications = []) {
    $this->installCore($version);

    // Assert modifications.
    $this->assertModifications('core', 'drupal', $modifications);
  }

  /**
   * @covers ::getModifiedFiles
   * @dataProvider contribProjectsProvider
   */
  public function testContribModified($project, $project_type, $version, array $modifications = []) {
    $this->markTestSkipped('Contrib updates are not currently supported');
    $this->copyCodebase();

    // Download the project.
    $this->symfonyFileSystem->mkdir($this->getWorkspaceDirectory() . "/{$project_type}s/contrib/$project");
    $this->executeCommand("curl -fsSL https://ftp.drupal.org/files/projects/$project-$version.tar.gz | tar xvz -C {$project_type}s/contrib/$project --strip 1");
    $this->assertCommandSuccessful();

    // Assert modifications.
    $this->assertModifications($project_type, $project, $modifications);
  }

  /**
   * Core project data provider.
   */
  public function coreProjectProvider() {
    $datum[] = [
      'version' => '8.7.3',
      'modifications' => [
        'core/LICENSE.txt',
      ],
    ];
    return $datum;
  }

  /**
   * Contrib project data provider.
   */
  public function contribProjectsProvider() {
    $datum[] = [
      'project' => 'bootstrap',
      'project_type' => 'theme',
      'version' => '8.x-3.20',
      'modifications' => [
        'themes/contrib/bootstrap/LICENSE.txt',
      ],
    ];
    $datum[] = [
      'project' => 'token',
      'project_type' => 'module',
      'version' => '8.x-1.5',
      'modifications' => [
        'modules/contrib/token/LICENSE.txt',
      ],
    ];
    return $datum;
  }

  /**
   * Assert modified files.
   *
   * @param string $project_type
   *   The project type.
   * @param string $project
   *   The project to assert.
   * @param array $modifications
   *   The modified files to assert.
   */
  protected function assertModifications($project_type, $project, array $modifications) {
    // Validate project is not modified.
    $this->visit("/automatic_updates/modified-files/$project_type/$project");
    $assert = $this->getMink()->assertSession();
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('No modified files!');

    // Assert modifications.
    $this->assertNotEmpty($modifications);
    foreach ($modifications as $modification) {
      $file = $this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $modification;
      $this->fileExists($file);
      $this->symfonyFileSystem->appendToFile($file, PHP_EOL . '// file is modified' . PHP_EOL);
    }
    $this->visit("/automatic_updates/modified-files/$project_type/$project");
    $assert->pageTextContains('Modified files include:');
    foreach ($modifications as $modification) {
      $assert->pageTextContains($modification);
    }
  }

}
