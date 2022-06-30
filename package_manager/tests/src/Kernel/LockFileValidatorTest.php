<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\Validator\LockFileValidator;
use Drupal\package_manager\ValidationResult;
use Drupal\package_manager_bypass\Stager;

/**
 * @coversDefaultClass \Drupal\package_manager\Validator\LockFileValidator
 *
 * @group package_manager
 */
class LockFileValidatorTest extends PackageManagerKernelTestBase {

  /**
   * The path of the active directory in the virtual file system.
   *
   * @var string
   */
  private $activeDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->activeDir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
  }

  /**
   * Tests that if no active lock file exists, a stage cannot be created.
   *
   * @covers ::storeHash
   */
  public function testCreateWithNoLock(): void {
    unlink($this->activeDir . '/composer.lock');

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
    $this->assertResults([]);

    // Change the lock file to ensure the stored hash of the previous version
    // has been deleted.
    file_put_contents($this->activeDir . '/composer.lock', '{"changed": true}');
    $this->assertResults([]);
  }

  /**
   * Tests validation when the lock file has changed.
   *
   * @dataProvider providerValidateStageEvents
   */
  public function testLockFileChanged(string $event_class): void {
    // Add a listener with an extremely high priority to the same event that
    // should raise the validation error. Because the validator uses the default
    // priority of 0, this listener changes lock file before the validator
    // runs.
    $this->addListener($event_class, function () {
      file_put_contents($this->activeDir . '/composer.lock', 'changed');
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
    // Add a listener with an extremely high priority to the same event that
    // should raise the validation error. Because the validator uses the default
    // priority of 0, this listener deletes lock file before the validator
    // runs.
    $this->addListener($event_class, function () {
      unlink($this->activeDir . '/composer.lock');
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
   * Tests validation when the staged and active lock files are identical.
   */
  public function testApplyWithNoChange(): void {
    // Leave the staged lock file alone.
    Stager::setLockFileShouldChange(FALSE);

    $result = ValidationResult::createError([
      'There are no pending Composer operations.',
    ]);
    $this->assertResults([$result], PreApplyEvent::class);
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
