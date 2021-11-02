<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\EventSubscriber\WritableFileSystemValidator;
use Drupal\package_manager\ValidationResult;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\PathLocator;
use org\bovigo\vfs\vfsStream;

/**
 * Unit tests the file system permissions validator.
 *
 * This validator is tested functionally in Automatic Updates' build tests,
 * since those give us control over the file system permissions.
 *
 * @see \Drupal\Tests\automatic_updates\Build\CoreUpdateTest::assertReadOnlyFileSystemError()
 *
 * @covers \Drupal\package_manager\EventSubscriber\WritableFileSystemValidator
 *
 * @group package_manager
 */
class WritableFileSystemValidatorTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    // Replace the file system permissions validator with our test-only
    // implementation.
    $container->getDefinition('package_manager.validator.file_system')
      ->setClass(TestWritableFileSystemValidator::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function disableValidators(ContainerBuilder $container): void {
    // Disable the disk space validator, since it tries to inspect the file
    // system in ways that vfsStream doesn't support, like calling stat() and
    // disk_free_space().
    $container->removeDefinition('package_manager.validator.disk_space');
  }

  /**
   * Data provider for ::testWritable().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerWritable(): array {
    $root_error = t('The Drupal directory "vfs://root" is not writable.');
    $vendor_error = t('The vendor directory "vfs://root/vendor" is not writable.');
    $summary = t('The file system is not writable.');
    $writable_permission = 0777;
    $non_writable_permission = 0444;

    return [
      'root and vendor are writable' => [
        $writable_permission,
        $writable_permission,
        [],
      ],
      'root writable, vendor not writable' => [
        $writable_permission,
        $non_writable_permission,
        [
          ValidationResult::createError([$vendor_error], $summary),
        ],
      ],
      'root not writable, vendor writable' => [
        $non_writable_permission,
        $writable_permission,
        [
          ValidationResult::createError([$root_error], $summary),
        ],
      ],
      'nothing writable' => [
        $non_writable_permission,
        $non_writable_permission,
        [
          ValidationResult::createError([$root_error, $vendor_error], $summary),
        ],
      ],
    ];
  }

  /**
   * Tests the file system permissions validator.
   *
   * @param int $root_permissions
   *   The file permissions for the root folder.
   * @param int $vendor_permissions
   *   The file permissions for the vendor folder.
   * @param array $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerWritable
   */
  public function testWritable(int $root_permissions, int $vendor_permissions, array $expected_results): void {
    $root = vfsStream::setup('root', $root_permissions);
    $vendor = vfsStream::newDirectory('vendor', $vendor_permissions);
    $root->addChild($vendor);

    $path_locator = $this->prophesize(PathLocator::class);
    $path_locator->getActiveDirectory()->willReturn($root->url());
    $path_locator->getStageDirectory()->willReturn('/fake/stage/dir');
    $path_locator->getWebRoot()->willReturn('');
    $path_locator->getVendorDirectory()->willReturn($vendor->url());
    $this->container->set('package_manager.path_locator', $path_locator->reveal());

    /** @var \Drupal\Tests\package_manager\Kernel\TestValidator $validator */
    $validator = $this->container->get('package_manager.validator.file_system');
    $validator->appRoot = $root->url();

    $this->assertResults($expected_results, PreCreateEvent::class);
  }

}

/**
 * A test version of the file system permissions validator.
 */
class TestWritableFileSystemValidator extends WritableFileSystemValidator {

  /**
   * {@inheritdoc}
   */
  public $appRoot;

}
