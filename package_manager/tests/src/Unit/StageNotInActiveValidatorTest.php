<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Unit;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Path;
use Drupal\package_manager\PathLocator;
use Drupal\package_manager\Stage;
use Drupal\package_manager\ValidationResult;
use Drupal\package_manager\Validator\StageNotInActiveValidator;
use Drupal\Tests\package_manager\Traits\ValidationTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\package_manager\Validator\StageNotInActiveValidator
 * @group package_manager
 * @internal
 */
class StageNotInActiveValidatorTest extends UnitTestCase {
  use ValidationTestTrait;

  /**
   * @covers ::checkNotInActive
   *
   * @param \Drupal\package_manager\ValidationResult[] $expected
   *   The expected result.
   * @param string $project_root
   *   The project root.
   * @param string $staging_root
   *   The staging root.
   *
   * @dataProvider providerTestCheckNotInActive
   */
  public function testCheckNotInActive(array $expected, string $project_root, string $staging_root) {
    $path_locator_prophecy = $this->prophesize(PathLocator::class);
    $path_locator_prophecy->getProjectRoot()->willReturn(Path::canonicalize($project_root));
    $path_locator_prophecy->getStagingRoot()->willReturn(Path::canonicalize($staging_root));
    $path_locator = $path_locator_prophecy->reveal();
    $stage = $this->prophesize(Stage::class)->reveal();

    $stage_not_in_active_validator = new StageNotInActiveValidator($path_locator);
    $stage_not_in_active_validator->setStringTranslation($this->getStringTranslationStub());
    $event = new PreCreateEvent($stage, ['some/path']);
    $stage_not_in_active_validator->checkNotInActive($event);
    $this->assertValidationResultsEqual($expected, $event->getResults());
  }

  /**
   * Data provider for testCheckNotInActive().
   *
   * @return mixed[]
   *   The test cases.
   */
  public function providerTestCheckNotInActive(): array {
    $expected_symlink_validation_error = ValidationResult::createError([
      t('Stage directory is a subdirectory of the active directory.'),
    ]);

    return [
      "Absolute paths which don't satisfy" => [
        [$expected_symlink_validation_error],
        "/var/root",
        "/var/root/xyz",
      ],
      "Absolute paths which satisfy" => [
        [],
        "/var/root",
        "/home/var/root",
      ],

      // @todo Replace `vfs://` with `/var/ in https://www.drupal.org/i/3328234.
      'Stage with .. segments, outside active' => [
        [],
        "vfs://root/active",
        "vfs://root/active/../stage",
      ],
      'Stage without .. segments, outside active' => [
        [],
        "vfs://root/active",
        "vfs://root/stage",
      ],
      'Stage with .. segments, inside active' => [
        [$expected_symlink_validation_error],
        "vfs://root/active",
        "vfs://root/active/../active/stage",
      ],
      'Stage without .. segments, inside active' => [
        [$expected_symlink_validation_error],
        "vfs://root/active",
        "vfs://root/active/stage",
      ],
      'Stage with .. segments, outside active, active with .. segments' => [
        [],
        "vfs://root/active",
        "vfs://root/active/../stage",
      ],
      'Stage without .. segments, outside active, active with .. segments' => [
        [],
        "vfs://root/random/../active",
        "vfs://root/stage",
      ],
      'Stage with .. segments, inside active, active with .. segments' => [
        [$expected_symlink_validation_error],
        "vfs://root/random/../active",
        "vfs://root/active/../active/stage",
      ],
      'Stage without .. segments, inside active, active with .. segments' => [
        [$expected_symlink_validation_error],
        "vfs://root/random/../active",
        "vfs://root/active/stage",
      ],
    ];
  }

}
