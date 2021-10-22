<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\automatic_updates\Validator\WritableFileSystemValidator;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\PathLocator;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use org\bovigo\vfs\vfsStream;

/**
 * Unit tests the file system permissions validator.
 *
 * This validator is tested functionally in our build tests, since those give
 * us control over the file system permissions.
 *
 * @see \Drupal\Tests\automatic_updates\Build\CoreUpdateTest::assertReadOnlyFileSystemError()
 *
 * @covers \Drupal\automatic_updates\Validator\WritableFileSystemValidator
 *
 * @group automatic_updates
 */
class WritableFileSystemValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'package_manager',
  ];

  /**
   * {@inheritdoc}
   */
  protected function disableValidators(ContainerBuilder $container): void {
    // No need to disable any validators in this test.
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
    $files = vfsStream::setup('root', $root_permissions);
    $files->addChild(vfsStream::newDirectory('vendor', $vendor_permissions));

    $path_locator = $this->prophesize(PathLocator::class);
    $path_locator->getVendorDirectory()->willReturn(vfsStream::url('root/vendor'));

    $validator = new WritableFileSystemValidator(
      $path_locator->reveal(),
      vfsStream::url('root'),
      $this->container->get('string_translation')
    );
    $this->container->set('automatic_updates.validator.file_system_permissions', $validator);
    $this->assertCheckerResultsFromManager($expected_results, TRUE);
  }

}
