<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Traits;

use Drupal\package_manager\ValidationResult;

/**
 * Contains helpful methods for testing stage validators.
 *
 * @internal
 */
trait ValidationTestTrait {

  /**
   * Asserts two validation result sets are equal.
   *
   * This assertion is sensitive to the order of results. For example,
   * ['a', 'b'] is not equal to ['b', 'a'].
   *
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   * @param \Drupal\package_manager\ValidationResult[] $actual_results
   *   The actual validation results.
   */
  protected function assertValidationResultsEqual(array $expected_results, array $actual_results): void {
    $expected_results = $this->getValidationResultsAsArray($expected_results);
    $actual_results = $this->getValidationResultsAsArray($actual_results);

    self::assertSame($expected_results, $actual_results);
  }

  /**
   * Gets an array representation of validation results for easy comparison.
   *
   * @param \Drupal\package_manager\ValidationResult[] $results
   *   An array of validation results.
   *
   * @return array
   *   An array of validation results details:
   *   - severity: (int) The severity code.
   *   - messages: (array) An array of strings.
   *   - summary: (string|null) A summary string if there is one or NULL if not.
   */
  protected function getValidationResultsAsArray(array $results): array {
    return array_values(array_map(static function (ValidationResult $result) {
      $messages = array_map(static function ($message): string {
        return (string) $message;
      }, $result->getMessages());

      $summary = $result->getSummary();
      if ($summary !== NULL) {
        $summary = (string) $result->getSummary();
      }

      return [
        'severity' => $result->getSeverity(),
        'messages' => $messages,
        'summary' => $summary,
      ];
    }, $results));
  }

}
