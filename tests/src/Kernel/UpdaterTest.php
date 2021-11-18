<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\package_manager\PathLocator;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Updater
 *
 * @group automatic_updates
 */
class UpdaterTest extends AutomaticUpdatesKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'automatic_updates_test',
    'package_manager',
    'package_manager_bypass',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * Tests that correct versions are staged after calling ::begin().
   */
  public function testCorrectVersionsStaged() {
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.1-security.xml');

    // Create a user who will own the stage even after the container is rebuilt.
    $user = $this->createUser([], NULL, TRUE, ['uid' => 2]);
    $this->setCurrentUser($user);

    // Point to a fake site which requires Drupal core via a distribution. The
    // lock file should be scanned to determine the core packages, which should
    // result in drupal/core-recommended being updated.
    $fixture_dir = __DIR__ . '/../../fixtures/fake-site';
    $locator = $this->prophesize(PathLocator::class);
    $locator->getActiveDirectory()->willReturn($fixture_dir);
    $locator->getProjectRoot()->willReturn($fixture_dir);
    $locator->getVendorDirectory()->willReturn($fixture_dir);
    $locator->getStageDirectory()->willReturn('/tmp');
    $this->container->set('package_manager.path_locator', $locator->reveal());

    $this->container->get('automatic_updates.updater')->begin([
      'drupal' => '9.8.1',
    ]);
    // Rebuild the container to ensure the project versions are kept in state.
    /** @var \Drupal\Core\DrupalKernel $kernel */
    $kernel = $this->container->get('kernel');
    $kernel->rebuildContainer();
    $this->container = $kernel->getContainer();
    // Keep using the mocked path locator and current user.
    $this->container->set('package_manager.path_locator', $locator->reveal());
    $this->setCurrentUser($user);

    // When we call Updater::stage(), the stored project versions should be
    // read from state and passed to Composer Stager's Stager service, in the
    // form of a Composer command. This is done using package_manager_bypass's
    // invocation recorder, rather than a regular mock, in order to test that
    // the invocation recorder itself works.
    // The production dependencies should be updated first...
    $expected_require_arguments = [
      'require',
      'drupal/core-recommended:9.8.1',
      '--update-with-all-dependencies',
    ];
    // ...followed by the dev dependencies.
    $expected_require_dev_arguments = [
      'require',
      'drupal/core-dev:9.8.1',
      '--update-with-all-dependencies',
      '--dev',
    ];
    $this->container->get('automatic_updates.updater')->stage();

    /** @var \Drupal\package_manager_bypass\InvocationRecorderBase $stager */
    $stager = $this->container->get('package_manager.stager');
    [
      $actual_require_arguments,
      $actual_require_dev_arguments,
    ] = $stager->getInvocationArguments();
    $this->assertSame($expected_require_arguments, $actual_require_arguments[0]);
    $this->assertSame($expected_require_dev_arguments, $actual_require_dev_arguments[0]);
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
