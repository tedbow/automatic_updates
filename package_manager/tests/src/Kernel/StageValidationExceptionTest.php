<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;

/**
 * @coversDefaultClass \Drupal\package_manager\Exception\StageValidationException
 *
 * @group package_manager
 */
class StageValidationExceptionTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'package_manager_test_validation',
  ];

  /**
   * Data provider for testErrors().
   *
   * @return array[]
   *   The test cases.
   */
  public function providerResultsAsText(): array {
    $messages = ['Blam!', 'Kapow!'];
    $summary = t('There was sadness.');

    $result_no_summary = ValidationResult::createError([$messages[0]]);
    $result_with_summary = ValidationResult::createError($messages, $summary);
    $result_with_summary_message = "{$summary->getUntranslatedString()}\n{$messages[0]}\n{$messages[1]}\n";

    return [
      '1 result with summary' => [
        [$result_with_summary],
        $result_with_summary_message,
      ],
      '2 results, with summaries' => [
        [$result_with_summary, $result_with_summary],
        "$result_with_summary_message$result_with_summary_message",
      ],
      '1 result without summary' => [
        [$result_no_summary],
        $messages[0],
      ],
      '2 results without summaries' => [
        [$result_no_summary, $result_no_summary],
        $messages[0] . "\n" . $messages[0],
      ],
      '1 result with summary, 1 result without summary' => [
        [$result_with_summary, $result_no_summary],
        $result_with_summary_message . $messages[0] . "\n",
      ],
    ];
  }

  /**
   * Tests formatting a set of validation results as plain text.
   *
   * @param \Drupal\package_manager\ValidationResult[] $validation_results
   *   The expected validation results which should be logged.
   * @param string $expected_message
   *   The expected exception message.
   *
   * @dataProvider providerResultsAsText
   *
   * @covers ::getResultsAsText()
   */
  public function testResultsAsText(array $validation_results, string $expected_message): void {
    TestSubscriber1::setTestResult($validation_results, PreCreateEvent::class);
    $this->expectException(StageValidationException::class);
    $this->expectExceptionMessage($expected_message);
    $this->createStage()->create();
  }

}
