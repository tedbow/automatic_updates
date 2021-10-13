<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Prophecy\Argument;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Updater
 *
 * @group automatic_updates
 */
class UpdaterTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'automatic_updates_test',
    'package_manager',
    'package_manager_bypass',
  ];

  /**
   * Tests that correct versions are staged after calling ::begin().
   */
  public function testCorrectVersionsStaged() {
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.1-security.xml');

    $this->container->get('automatic_updates.updater')->begin([
      'drupal' => '9.8.1',
    ]);
    // Rebuild the container to ensure the project versions are kept in state.
    /** @var \Drupal\Core\DrupalKernel $kernel */
    $kernel = $this->container->get('kernel');
    $kernel->rebuildContainer();
    $this->container = $kernel->getContainer();
    $stager = $this->prophesize('\PhpTuf\ComposerStager\Domain\StagerInterface');
    $command = [
      'require',
      'drupal/core:9.8.1',
      // These two plugins are in the root composer.json that ships with a
      // git clone of Drupal core, so they will be included when determining
      // which core packages to update.
      // @see \Drupal\automatic_updates\Updater::getCorePackageNames()
      'drupal/core-project-message:9.8.1',
      'drupal/core-vendor-hardening:9.8.1',
      '--update-with-all-dependencies',
    ];
    $stager->stage($command, Argument::cetera())->shouldBeCalled();
    $this->container->set('package_manager.stager', $stager->reveal());
    $this->container->get('automatic_updates.updater')->stage();
  }

  /**
   * @covers ::begin
   *
   * @dataProvider providerInvalidProjectVersions
   */
  public function testInvalidProjectVersions(array $project_versions): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Currently only updates to Drupal core are supported.');
    $this->container->get('automatic_updates.updater')->begin($project_versions);
  }

  /**
   * Data provider for testInvalidProjectVersions().
   *
   * @return array
   *   The test cases for testInvalidProjectVersions().
   */
  public function providerInvalidProjectVersions(): array {
    return [
      'only not drupal' => [['not_drupal' => '1.1.3']],
      'not drupal and drupal' => [['drupal' => '9.8.0', 'not_drupal' => '1.2.3']],
      'empty' => [[]],
    ];
  }

}
