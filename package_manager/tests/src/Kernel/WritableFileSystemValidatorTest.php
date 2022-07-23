<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;

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
   * Data provider for testWritable().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerWritable(): array {
    // The root and vendor paths are defined by ::createVirtualProject().
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
    $path_locator = $this->container->get('package_manager.path_locator');

    // We need to set the vendor directory's permissions first because, in the
    // virtual project, it's located inside the project root.
    $this->assertTrue(chmod($path_locator->getVendorDirectory(), $vendor_permissions));
    $this->assertTrue(chmod($path_locator->getProjectRoot(), $root_permissions));

    $this->assertResults($expected_results, PreCreateEvent::class);
  }

}
