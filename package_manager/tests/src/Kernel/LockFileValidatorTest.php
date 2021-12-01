<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\EventSubscriber\LockFileValidator;
use Drupal\package_manager\PathLocator;
use Drupal\package_manager\ValidationResult;
use org\bovigo\vfs\vfsStream;

/**
 * @coversDefaultClass \Drupal\package_manager\EventSubscriber\LockFileValidator
 *
 * @group package_manager
 */
class LockFileValidatorTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $vendor = vfsStream::newDirectory('vendor');
    $this->vfsRoot->addChild($vendor);

    $path_locator = $this->prophesize(PathLocator::class);
    $path_locator->getActiveDirectory()->willReturn($this->vfsRoot->url());
    $path_locator->getProjectRoot()->willReturn($this->vfsRoot->url());
    $path_locator->getWebRoot()->willReturn('');
    $path_locator->getVendorDirectory()->willReturn($vendor->url());
    $this->container->set('package_manager.path_locator', $path_locator->reveal());
  }

  /**
   * {@inheritdoc}
   */
  protected function disableValidators(ContainerBuilder $container): void {
    parent::disableValidators($container);

    // Disable the disk space validator, since it tries to inspect the file
    // system in ways that vfsStream doesn't support, like calling stat() and
    // disk_free_space().
    $container->removeDefinition('package_manager.validator.disk_space');
  }

  /**
   * Tests that if no active lock file exists, a stage cannot be created.
   *
   * @covers ::storeHash
   */
  public function testCreateWithNoLock(): void {
    $no_lock = ValidationResult::createError(['Could not hash the active lock file.']);
    $this->assertResults([$no_lock], PreCreateEvent::class);
  }

  /**
   * Tests that if an active lock file exists, a stage can be created.
   *
   * @covers ::storeHash
   * @covers ::deleteHash
   */
  public function testCreateWithLock(): void {
    $this->createActiveLockFile();
    $this->assertResults([]);

    // Change the lock file to ensure the stored hash of the previous version
    // has been deleted.
    $this->vfsRoot->getChild('composer.lock')->setContent('"changed"');
    $this->assertResults([]);
  }

  /**
   * Tests validation when the lock file has changed.
   *
   * @dataProvider providerValidateStageEvents
   */
  public function testLockFileChanged(string $event_class): void {
    $this->createActiveLockFile();

    // Add a listener with an extremely high priority to the same event that
    // should raise the validation error. Because the validator uses the default
    // priority of 0, this listener changes lock file before the validator
    // runs.
    $this->addListener($event_class, function () {
      $this->vfsRoot->getChild('composer.lock')->setContent('"changed"');
    });
    $result = ValidationResult::createError([
      'Stored lock file hash does not match the active lock file.',
    ]);
    $this->assertResults([$result], $event_class);
  }

  /**
   * Tests validation when the lock file is deleted.
   *
   * @dataProvider providerValidateStageEvents
   */
  public function testLockFileDeleted(string $event_class): void {
    $this->createActiveLockFile();

    // Add a listener with an extremely high priority to the same event that
    // should raise the validation error. Because the validator uses the default
    // priority of 0, this listener deletes lock file before the validator
    // runs.
    $this->addListener($event_class, function () {
      $this->vfsRoot->removeChild('composer.lock');
    });
    $result = ValidationResult::createError([
      'Could not hash the active lock file.',
    ]);
    $this->assertResults([$result], $event_class);
  }

  /**
   * Tests validation when a stored hash of the active lock file is unavailable.
   *
   * @dataProvider providerValidateStageEvents
   */
  public function testNoStoredHash(string $event_class): void {
    $this->createActiveLockFile();

    $reflector = new \ReflectionClassConstant(LockFileValidator::class, 'STATE_KEY');
    $state_key = $reflector->getValue();

    // Add a listener with an extremely high priority to the same event that
    // should raise the validation error. Because the validator uses the default
    // priority of 0, this listener deletes stored hash before the validator
    // runs.
    $this->addListener($event_class, function () use ($state_key) {
      $this->container->get('state')->delete($state_key);
    });
    $result = ValidationResult::createError([
      'Could not retrieve stored hash of the active lock file.',
    ]);
    $this->assertResults([$result], $event_class);
  }

  /**
   * Data provider for test methods that validate the staging area.
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerValidateStageEvents(): array {
    return [
      'pre-require' => [
        PreRequireEvent::class,
      ],
      'pre-apply' => [
        PreApplyEvent::class,
      ],
    ];
  }

  /**
   * Creates a 'composer.lock' file in the active directory.
   */
  private function createActiveLockFile(): void {
    $lock_file = vfsStream::newFile('composer.lock')->setContent('{}');
    $this->vfsRoot->addChild($lock_file);
  }

  /**
   * Adds an event listener with the highest possible priority.
   *
   * @param string $event_class
   *   The event class to listen for.
   * @param callable $listener
   *   The listener to add.
   */
  private function addListener(string $event_class, callable $listener): void {
    $this->container->get('event_dispatcher')
      ->addListener($event_class, $listener, PHP_INT_MAX);
  }

}
