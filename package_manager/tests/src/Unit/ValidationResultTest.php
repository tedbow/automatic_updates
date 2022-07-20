<?php

namespace Drupal\Tests\package_manager\Unit;

use Drupal\package_manager\ValidationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\system\SystemManager;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\package_manager\ValidationResult
 *
 * @group package_manager
 */
class ValidationResultTest extends UnitTestCase {

  /**
   * @covers ::createWarning
   *
   * @dataProvider providerValidConstructorArguments
   */
  public function testCreateWarningResult(array $messages, ?string $summary): void {
    $summary = $summary ? t($summary) : NULL;
    $result = ValidationResult::createWarning($messages, $summary);
    $this->assertResultValid($result, $messages, $summary, SystemManager::REQUIREMENT_WARNING);
  }

  /**
   * @covers ::createError
   *
   * @dataProvider providerValidConstructorArguments
   */
  public function testCreateErrorResult(array $messages, ?string $summary): void {
    $summary = $summary ? t($summary) : NULL;
    $result = ValidationResult::createError($messages, $summary);
    $this->assertResultValid($result, $messages, $summary, SystemManager::REQUIREMENT_ERROR);
  }

  /**
   * @covers ::createWarning
   */
  public function testCreateWarningResultException(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('If more than one message is provided, a summary is required.');
    ValidationResult::createWarning(['Something is wrong', 'Something else is also wrong'], NULL);
  }

  /**
   * @covers ::createError
   */
  public function testCreateErrorResultException(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('If more than one message is provided, a summary is required.');
    ValidationResult::createError(['Something is wrong', 'Something else is also wrong'], NULL);
  }

  /**
   * Data provider for testCreateWarningResult().
   *
   * @return mixed[]
   *   The test cases.
   */
  public function providerValidConstructorArguments(): array {
    return [
      '1 message no summary' => [
        'messages' => ['Something is wrong'],
        'summary' => NULL,
      ],
      '2 messages has summary' => [
        'messages' => ['Something is wrong', 'Something else is also wrong'],
        'summary' => 'This sums it up.',
      ],
    ];
  }

  /**
   * Asserts a check result is valid.
   *
   * @param \Drupal\package_manager\ValidationResult $result
   *   The validation result to check.
   * @param array $expected_messages
   *   The expected messages.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $summary
   *   The expected summary or NULL if not summary is expected.
   * @param int $severity
   *   The severity.
   */
  protected function assertResultValid(ValidationResult $result, array $expected_messages, ?TranslatableMarkup $summary, int $severity): void {
    $this->assertSame($expected_messages, $result->getMessages());
    if ($summary === NULL) {
      $this->assertNull($result->getSummary());
    }
    else {
      $this->assertSame($summary->getUntranslatedString(), $result->getSummary()
        ->getUntranslatedString());
    }
    $this->assertSame($severity, $result->getSeverity());
  }

}
