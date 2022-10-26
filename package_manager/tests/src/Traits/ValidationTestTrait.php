<?php

namespace Drupal\Tests\package_manager\Traits;

use Drupal\package_manager\ValidationResult;

/**
 * Contains helpful methods for testing stage validators.
 */
trait ValidationTestTrait {

  /**
   * Asserts two validation result sets are equal.
   *
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   * @param \Drupal\package_manager\ValidationResult[] $actual_results
   *   The actual validation results.
   */
  protected function assertValidationResultsEqual(array $expected_results, array $actual_results): void {
    $this->assertCount(count($expected_results), $actual_results);

    foreach ($expected_results as $expected_result) {
      $actual_result = array_shift($actual_results);
      $this->assertTrue(ValidationResult::isEqual($expected_result, $actual_result));
    }
  }

}
