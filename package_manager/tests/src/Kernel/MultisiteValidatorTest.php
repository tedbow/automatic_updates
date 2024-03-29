<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\Validator\MultisiteValidator
 *
 * @group package_manager
 */
class MultisiteValidatorTest extends PackageManagerKernelTestBase {

  /**
   * Data provider for testMultisite().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerMultisite(): array {
    return [
      'multisite' => [
        TRUE,
        [
          ValidationResult::createError([
            'Drupal multisite is not supported by Package Manager.',
          ]),
        ],
      ],
      'not multisite' => [
        FALSE,
        [],
      ],
    ];
  }

  /**
   * Tests that Package Manager flags an error if run in a multisite.
   *
   * @param bool $is_multisite
   *   Whether the validator will be in a multisite.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerMultisite
   */
  public function testMultisite(bool $is_multisite, array $expected_results = []): void {
    // If we should simulate a multisite, ensure there is a sites.php in the
    // test project.
    // @see \Drupal\package_manager\Validator\MultisiteValidator::isMultisite()
    if ($is_multisite) {
      $project_root = $this->container->get('package_manager.path_locator')
        ->getProjectRoot();
      touch($project_root . '/sites/sites.php');
    }
    $this->assertStatusCheckResults($expected_results);
    $this->assertResults($expected_results, PreCreateEvent::class);
  }

}
