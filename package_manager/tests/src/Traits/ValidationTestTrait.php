<?php

namespace Drupal\Tests\package_manager\Traits;

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
      $this->assertSame($expected_result->getSeverity(), $actual_result->getSeverity());
      $this->assertSame((string) $expected_result->getSummary(), (string) $actual_result->getSummary());
      $this->assertSame(
        array_map('strval', $expected_result->getMessages()),
        array_map('strval', $actual_result->getMessages())
      );
    }
  }

}
