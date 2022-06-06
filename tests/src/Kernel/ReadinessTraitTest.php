<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\Validation\ReadinessTrait;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\ValidationResult;
use Drupal\system\SystemManager;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Validation\ReadinessTrait
 *
 * @group automatic_updates
 */
class ReadinessTraitTest extends AutomaticUpdatesKernelTestBase {

  use ReadinessTrait;
  use StringTranslationTrait;

  /**
   * @covers ::getOverallSeverity
   */
  public function testOverallSeverity(): void {
    // An error and a warning should be counted as an error.
    $results = [
      ValidationResult::createError(['Boo!']),
      ValidationResult::createWarning(['Moo!']),
    ];
    $this->assertSame(SystemManager::REQUIREMENT_ERROR, $this->getOverallSeverity($results));

    // If there are no results, but no errors, the results should be counted as
    // a warning.
    array_shift($results);
    $this->assertSame(SystemManager::REQUIREMENT_WARNING, $this->getOverallSeverity($results));

    // If there are just plain no results, we should get REQUIREMENT_OK.
    array_shift($results);
    $this->assertSame(SystemManager::REQUIREMENT_OK, $this->getOverallSeverity($results));
  }

  /**
   * @covers ::displayResults
   */
  public function testDisplayResults(): void {
    $messenger = $this->container->get('messenger');
    $renderer = $this->container->get('renderer');

    // An error and a warning should display the error preamble, and the result
    // messages as errors and warnings, respectively.
    $results = [
      ValidationResult::createError(['Boo!']),
      ValidationResult::createError(['Wednesday', 'Lurch'], $this->t('The Addams Family')),
      ValidationResult::createWarning(['Moo!']),
      ValidationResult::createWarning(['Shaggy', 'The dog'], $this->t('Mystery Mobile')),
    ];
    $this->displayResults($results, $messenger, $renderer);

    $failure_message = (string) $this->getFailureMessageForSeverity(SystemManager::REQUIREMENT_ERROR);
    $expected_errors = [
      "$failure_message<ul><li>Boo!</li><li>The Addams Family</li><li>Moo!</li><li>Mystery Mobile</li></ul>",
    ];
    $actual_errors = array_map('strval', $messenger->deleteByType(MessengerInterface::TYPE_ERROR));
    $this->assertSame($expected_errors, $actual_errors);

    // Even though there were warnings, they should have been included with the
    // errors.
    $actual_warnings = array_map('strval', $messenger->deleteByType(MessengerInterface::TYPE_WARNING));
    $this->assertEmpty($actual_warnings);

    // There shouldn't be any more messages.
    $this->assertEmpty($messenger->all());

    // If there are only warnings, we should see the warning preamble.
    $results = array_slice($results, -2);
    $this->displayResults($results, $messenger, $renderer);

    $failure_message = (string) $this->getFailureMessageForSeverity(SystemManager::REQUIREMENT_WARNING);
    $expected_warnings = [
      "$failure_message<ul><li>Moo!</li><li>Mystery Mobile</li></ul>",
    ];
    $actual_warnings = array_map('strval', $messenger->deleteByType(MessengerInterface::TYPE_WARNING));
    $this->assertSame($expected_warnings, $actual_warnings);

    // There shouldn't be any more messages.
    $this->assertEmpty($messenger->all());
  }

}
