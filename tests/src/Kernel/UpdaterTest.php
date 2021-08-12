<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Composer\Autoload\ClassLoader;
use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\automatic_updates_test\ReadinessChecker\TestChecker1;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use PhpTuf\ComposerStager\Domain\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Output\ProcessOutputCallbackInterface;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Updater
 */
class UpdaterTest extends KernelTestBase {

  use ValidationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'update',
    'automatic_updates',
    'automatic_updates_test',
  ];

  /**
   * The directory that should be used for staging an update.
   *
   * @var string
   */
  protected $stageDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $class_loader_reflection = new \ReflectionClass(ClassLoader::class);
    $vendor_directory = dirname($class_loader_reflection->getFileName(), 2);
    $this->stageDirectory = realpath($vendor_directory . '/..') . '/.automatic_updates_stage';
    $this->assertDirectoryDoesNotExist($this->stageDirectory);
    $this->createTestValidationResults();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Clean up the staging area and ensure it's gone. This tests that the
    // cleaner service works as expected, AND keeps the file system in a
    // consistent state if a test fails.
    $this->container->get('automatic_updates.updater')->clean();
    $this->assertDirectoryDoesNotExist($this->stageDirectory);
    parent::tearDown();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    $container->getDefinition('automatic_updates.beginner')
      ->setClass(TestBeginner::class);
  }

  /**
   * Tests that validation errors will stop an update attempt.
   */
  public function testCheckerErrors(): void {
    $expected_results = $this->testResults['checker_1']['1 error'];
    TestChecker1::setTestResult($expected_results, AutomaticUpdatesEvents::PRE_START);
    try {
      $this->container->get('automatic_updates.updater')->begin();
      $this->fail('Updater should fail.');
    }
    catch (UpdateException $exception) {
      $actual_results = $exception->getValidationResults();
      $this->assertValidationResultsEqual($expected_results, $actual_results);
    }
  }

  /**
   * Tests that validation warnings do not stop an update attempt.
   */
  public function testCheckerWarnings() {
    $expected_results = $this->testResults['checker_1']['1 warning'];
    TestChecker1::setTestResult($expected_results, AutomaticUpdatesEvents::PRE_START);
    $updater = $this->container->get('automatic_updates.updater');
    $updater->begin();
    $this->assertDirectoryExists($this->stageDirectory);
  }

}

/**
 * A beginner that creates the staging directly but doesn't copy any files.
 */
class TestBeginner implements BeginnerInterface {

  /**
   * {@inheritdoc}
   */
  public function begin(string $activeDir, string $stagingDir, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = 120): void {
    mkdir($stagingDir);
  }

}
