<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\package_manager\Exception\StageException;
use Drupal\package_manager_bypass\Committer;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PhpTuf\ComposerStager\Domain\Exception\InvalidArgumentException;

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
  public function testCorrectVersionsStaged(): void {
    // Simulate that we're running Drupal 9.8.0 and a security update to 9.8.1
    // is available.
    $this->setCoreVersion('9.8.0');
    $this->setReleaseMetadata(['drupal' => __DIR__ . '/../../fixtures/release-history/drupal.9.8.1-security.xml']);

    // Create a user who will own the stage even after the container is rebuilt.
    $user = $this->createUser([], NULL, TRUE, ['uid' => 2]);
    $this->setCurrentUser($user);

    // Point to a fake site which requires Drupal core via a distribution. The
    // lock file should be scanned to determine the core packages, which should
    // result in drupal/core-recommended being updated.
    $fixture_dir = __DIR__ . '/../../fixtures/fake-site';
    $locator = $this->mockPathLocator($fixture_dir, $fixture_dir);

    $id = $this->container->get('automatic_updates.updater')->begin([
      'drupal' => '9.8.1',
    ]);
    // Rebuild the container to ensure the package versions are persisted.
    /** @var \Drupal\Core\DrupalKernel $kernel */
    $kernel = $this->container->get('kernel');
    $kernel->rebuildContainer();
    $this->container = $kernel->getContainer();
    // Keep using the mocked path locator and current user.
    $this->container->set('package_manager.path_locator', $locator);
    $this->setCurrentUser($user);

    /** @var \Drupal\automatic_updates\Updater $updater */
    $updater = $this->container->get('automatic_updates.updater');

    // Ensure that the target package versions are what we expect.
    $expected_versions = [
      'production' => [
        'drupal/core-recommended' => '9.8.1',
      ],
      'dev' => [
        'drupal/core-dev' => '9.8.1',
      ],
    ];
    $this->assertSame($expected_versions, $updater->claim($id)->getPackageVersions());

    // When we call Updater::stage(), the stored project versions should be
    // read from state and passed to Composer Stager's Stager service, in the
    // form of a Composer command. This is done using package_manager_bypass's
    // invocation recorder, rather than a regular mock, in order to test that
    // the invocation recorder itself works.
    // The production requirements are changed first, followed by the dev
    // requirements. Then the installed packages are updated. This is tested
    // functionally in Package Manager.
    // @see \Drupal\Tests\package_manager\Build\StagedUpdateTest
    $expected_arguments = [
      [
        'require',
        '--no-update',
        'drupal/core-recommended:9.8.1',
      ],
      [
        'require',
        '--dev',
        '--no-update',
        'drupal/core-dev:9.8.1',
      ],
      [
        'update',
        '--with-all-dependencies',
        'drupal/core-recommended:9.8.1',
        'drupal/core-dev:9.8.1',
      ],
    ];
    $updater->stage();

    $actual_arguments = $this->container->get('package_manager.stager')
      ->getInvocationArguments();

    $this->assertSame(count($expected_arguments), count($actual_arguments));
    foreach ($actual_arguments as $i => [$arguments]) {
      $this->assertSame($expected_arguments[$i], $arguments);
    }
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
   *   The test cases.
   */
  public function providerInvalidProjectVersions(): array {
    return [
      'only not drupal' => [['not_drupal' => '1.1.3']],
      'not drupal and drupal' => [['drupal' => '9.8.0', 'not_drupal' => '1.2.3']],
      'empty' => [[]],
    ];
  }

  /**
   * Data provider for testCommitException().
   *
   * @return \string[][]
   *   The test cases.
   */
  public function providerCommitException(): array {
    return [
      'RuntimeException' => [
        'RuntimeException',
        UpdateException::class,
      ],
      'InvalidArgumentException' => [
        InvalidArgumentException::class,
        StageException::class,
      ],
      'Exception' => [
        'Exception',
        UpdateException::class,
      ],
    ];
  }

  /**
   * Tests exception handling during calls to Composer Stager commit.
   *
   * @param string $thrown_class
   *   The throwable class that should be thrown by Composer Stager.
   * @param string|null $expected_class
   *   The expected exception class.
   *
   * @dataProvider providerCommitException
   */
  public function testCommitException(string $thrown_class, string $expected_class = NULL): void {
    $updater = $this->container->get('automatic_updates.updater');
    $updater->begin([
      'drupal' => '9.8.1',
    ]);
    $updater->stage();
    $thrown_message = 'A very bad thing happened';
    Committer::setException(new $thrown_class($thrown_message, 123));
    $this->expectException($expected_class);
    $expected_message = $expected_class === UpdateException::class ?
      'The update operation failed to apply. The update may have been partially applied. It is recommended that the site be restored from a code backup.'
      : $thrown_message;
    $this->expectExceptionMessage($expected_message);
    $this->expectExceptionCode(123);
    $updater->apply();
  }

}
