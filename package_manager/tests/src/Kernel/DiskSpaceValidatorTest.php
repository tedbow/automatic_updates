<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\EventSubscriber\DiskSpaceValidator;
use Drupal\package_manager\ValidationResult;
use Drupal\Component\Utility\Bytes;

/**
 * @covers \Drupal\package_manager\EventSubscriber\DiskSpaceValidator
 *
 * @group package_manager
 */
class DiskSpaceValidatorTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    // Replace the validator under test with a mocked version which can be
    // rigged up to return specific values for various filesystem checks.
    $container->getDefinition('package_manager.validator.disk_space')
      ->setClass(TestDiskSpaceValidator::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function disableValidators(ContainerBuilder $container): void {
    parent::disableValidators($container);

    // Disable the lock file and Composer settings validators, since in this
    // test we are validating an imaginary file system which doesn't have any
    // Composer files.
    $container->removeDefinition('package_manager.validator.lock_file');
    $container->removeDefinition('package_manager.validator.composer_settings');
  }

  /**
   * Data provider for ::testDiskSpaceValidation().
   *
   * @return mixed[][]
   *   Sets of arguments to pass to the test method.
   */
  public function providerDiskSpaceValidation(): array {
    $root_insufficient = t('Drupal root filesystem "root" has insufficient space. There must be at least 1024 megabytes free.');
    $vendor_insufficient = t('Vendor filesystem "vendor" has insufficient space. There must be at least 1024 megabytes free.');
    $temp_insufficient = t('Directory "temp" has insufficient space. There must be at least 1024 megabytes free.');
    $summary = t("There is not enough disk space to create a staging area.");

    return [
      'shared, vendor and temp sufficient, root insufficient' => [
        TRUE,
        [
          'root' => '1M',
          'vendor' => '2G',
          'temp' => '4G',
        ],
        [
          ValidationResult::createError([$root_insufficient]),
        ],
      ],
      'shared, root and vendor insufficient, temp sufficient' => [
        TRUE,
        [
          'root' => '1M',
          'vendor' => '2M',
          'temp' => '2G',
        ],
        [
          ValidationResult::createError([$root_insufficient]),
        ],
      ],
      'shared, vendor and root sufficient, temp insufficient' => [
        TRUE,
        [
          'root' => '2G',
          'vendor' => '4G',
          'temp' => '1M',
        ],
        [
          ValidationResult::createError([$temp_insufficient]),
        ],
      ],
      'shared, root and temp insufficient, vendor sufficient' => [
        TRUE,
        [
          'root' => '1M',
          'vendor' => '2G',
          'temp' => '2M',
        ],
        [
          ValidationResult::createError([
            $root_insufficient,
            $temp_insufficient,
          ], $summary),
        ],
      ],
      'not shared, root insufficient, vendor and temp sufficient' => [
        FALSE,
        [
          'root' => '5M',
          'vendor' => '1G',
          'temp' => '4G',
        ],
        [
          ValidationResult::createError([$root_insufficient]),
        ],
      ],
      'not shared, vendor insufficient, root and temp sufficient' => [
        FALSE,
        [
          'root' => '2G',
          'vendor' => '10M',
          'temp' => '4G',
        ],
        [
          ValidationResult::createError([$vendor_insufficient]),
        ],
      ],
      'not shared, root and vendor sufficient, temp insufficient' => [
        FALSE,
        [
          'root' => '1G',
          'vendor' => '2G',
          'temp' => '3M',
        ],
        [
          ValidationResult::createError([$temp_insufficient]),
        ],
      ],
      'not shared, root and vendor insufficient, temp sufficient' => [
        FALSE,
        [
          'root' => '500M',
          'vendor' => '75M',
          'temp' => '2G',
        ],
        [
          ValidationResult::createError([
            $root_insufficient,
            $vendor_insufficient,
          ], $summary),
        ],
      ],
    ];
  }

  /**
   * Tests disk space validation.
   *
   * @param bool $shared_disk
   *   Whether the root and vendor directories are on the same logical disk.
   * @param array $free_space
   *   The free space that should be reported for various locations. The keys
   *   are the locations (only 'root', 'vendor', and 'temp' are supported), and
   *   the values are the space that should be reported, in a format that can be
   *   parsed by \Drupal\Component\Utility\Bytes::toNumber().
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerDiskSpaceValidation
   */
  public function testDiskSpaceValidation(bool $shared_disk, array $free_space, array $expected_results): void {
    $path_locator = $this->prophesize('\Drupal\package_manager\PathLocator');
    $path_locator->getProjectRoot()->willReturn('root');
    $path_locator->getWebRoot()->willReturn('');
    $path_locator->getActiveDirectory()->willReturn('root');
    $path_locator->getVendorDirectory()->willReturn('vendor');
    $this->container->set('package_manager.path_locator', $path_locator->reveal());

    /** @var \Drupal\Tests\package_manager\Kernel\TestDiskSpaceValidator $validator */
    $validator = $this->container->get('package_manager.validator.disk_space');
    $validator->sharedDisk = $shared_disk;
    $validator->freeSpace = array_map([Bytes::class, 'toNumber'], $free_space);

    $this->assertResults($expected_results, PreCreateEvent::class);
  }

}

/**
 * A test version of the disk space validator.
 */
class TestDiskSpaceValidator extends DiskSpaceValidator {

  /**
   * Whether the root and vendor directories are on the same logical disk.
   *
   * @var bool
   */
  public $sharedDisk;

  /**
   * The amount of free space, keyed by location.
   *
   * @var float[]
   */
  public $freeSpace = [];

  /**
   * {@inheritdoc}
   */
  protected function stat(string $path): array {
    return [
      'dev' => $this->sharedDisk ? 'disk' : uniqid(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function freeSpace(string $path): float {
    return $this->freeSpace[$path];
  }

  /**
   * {@inheritdoc}
   */
  protected function temporaryDirectory(): string {
    return 'temp';
  }

}
