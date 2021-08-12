<?php

namespace Drupal\Tests\automatic_updates\Traits;

use Drupal\automatic_updates\Validation\ValidationResult;

/**
 * Common methods for testing validation.
 */
trait ValidationTestTrait {

  /**
   * Test validation results.
   *
   * @var \Drupal\automatic_updates\Validation\ValidationResult[][][]
   */
  protected $testResults;

  /**
   * Creates ValidationResult objects to be used in tests.
   */
  protected function createTestValidationResults(): void {
    // Set up various validation results for the test checkers.
    foreach ([1, 2] as $listener_number) {
      // Set test validation results.
      $this->testResults["checker_$listener_number"]['1 error'] = [
        ValidationResult::createError(
          [t("$listener_number:OMG 🚒. Your server is on 🔥!")],
          t("$listener_number:Summary: 🔥")
        ),
      ];
      $this->testResults["checker_$listener_number"]['1 error 1 warning'] = [
        "$listener_number:error" => ValidationResult::createError(
          [t("$listener_number:OMG 🔌. Some one unplugged the server! How is this site even running?")],
          t("$listener_number:Summary: 🔥"),
        ),
        "$listener_number:warning" => ValidationResult::createWarning(
          [t("$listener_number:It looks like it going to rain and your server is outside.")],
          t("$listener_number:Warnings summary not displayed because only 1 warning message.")
        ),
      ];
      $this->testResults["checker_$listener_number"]['2 errors 2 warnings'] = [
        "$listener_number:errors" => ValidationResult::createError(
          [
            t("$listener_number:😬Your server is in a cloud, a literal cloud!☁️."),
            t("$listener_number:😂PHP only has 32k memory."),
          ],
          t("$listener_number:Errors summary displayed because more than 1 error message")
        ),
        "$listener_number:warnings" => ValidationResult::createWarning(
          [
            t("$listener_number:Your server is a smart fridge. Will this work?"),
            t("$listener_number:Your server case is duct tape!"),
          ],
          t("$listener_number:Warnings summary displayed because more than 1 warning message.")
        ),

      ];
      $this->testResults["checker_$listener_number"]['2 warnings'] = [
        ValidationResult::createWarning(
          [
            t("$listener_number:The universe could collapse in on itself in the next second, in which case automatic updates will not run."),
            t("$listener_number:An asteroid could hit your server farm, which would also stop automatic updates from running."),
          ],
          t("$listener_number:Warnings summary displayed because more than 1 warning message.")
        ),
      ];
      $this->testResults["checker_$listener_number"]['1 warning'] = [
        ValidationResult::createWarning(
          [t("$listener_number:This is your one and only warning. You have been warned.")],
          t("$listener_number:No need for this summary with only 1 warning."),
        ),
      ];
    }
  }

  /**
   * Asserts two validation result sets are equal.
   *
   * @param \Drupal\automatic_updates\Validation\ValidationResult[] $expected_results
   *   The expected validation results.
   * @param \Drupal\automatic_updates\Validation\ValidationResult[]|null $actual_results
   *   The actual validation results or NULL if known are available.
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
