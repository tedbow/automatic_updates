<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Validator\WritableFileSystemValidator;
use Drupal\package_manager\ValidationResult;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * Unit tests the file system permissions validator.
 *
 * This validator is tested functionally in Automatic Updates' build tests,
 * since those give us control over the file system permissions.
 *
 * @see \Drupal\Tests\automatic_updates\Build\CoreUpdateTest::assertReadOnlyFileSystemError()
 *
 * @covers \Drupal\package_manager\Validator\WritableFileSystemValidator
 *
 * @group package_manager
 */
class WritableFileSystemValidatorTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected $disableValidators = [
    // The parent class disables the validator we're testing, so prevent that
    // here with an empty array.
  ];

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
   * Data provider for ::testWritable().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerWritable(): array {
    // The root and vendor paths are defined by ::createTestProject().
    $root_error = 'The Drupal directory "vfs://root/active" is not writable.';
    $vendor_error = 'The vendor directory "vfs://root/active/vendor" is not writable.';
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
    $this->createTestProject();
    // For reasons unclear, the built-in chmod() function doesn't seem to work
    // when changing vendor permissions, so just call vfsStream's API directly.
    $active_dir = $this->vfsRoot->getChild('active');
    $active_dir->chmod($root_permissions);
    $active_dir->getChild('vendor')->chmod($vendor_permissions);

    /** @var \Drupal\Tests\package_manager\Kernel\TestWritableFileSystemValidator $validator */
    $validator = $this->container->get('package_manager.validator.file_system');
    $validator->appRoot = $active_dir->url();

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
