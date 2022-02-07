<?php

namespace Drupal\Tests\automatic_updates\Unit;

use Drupal\automatic_updates\Validation\ReadinessTrait;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\ValidationResult;
use Drupal\system\SystemManager;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Validation\ReadinessTrait
 *
 * @group automatic_updates
 */
class ReadinessTraitTest extends UnitTestCase {

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
    $messenger = new Messenger(new FlashBag(), new KillSwitch());

    $translation = new TestTranslationManager();
    $this->setStringTranslation($translation);

    // An error and a warning should display the error preamble, and the result
    // messages as errors and warnings, respectively.
    $results = [
      ValidationResult::createError(['Boo!']),
      ValidationResult::createError(['Wednesday', 'Pugsley'], $this->t('The Addams Family')),
      ValidationResult::createWarning(['Moo!']),
      ValidationResult::createWarning(['Shaggy', 'Scooby'], $this->t('Mystery Mobile')),
    ];
    $this->displayResults($results, $messenger);

    $expected_errors = [
      (string) $this->getFailureMessageForSeverity(SystemManager::REQUIREMENT_ERROR),
      'Boo!',
      'The Addams Family',
    ];
    $actual_errors = array_map('strval', $messenger->deleteByType(Messenger::TYPE_ERROR));
    $this->assertSame($expected_errors, $actual_errors);

    // Even though there were warnings, we shouldn't see the warning preamble.
    $expected_warnings = ['Moo!', 'Mystery Mobile'];
    $actual_warnings = array_map('strval', $messenger->deleteByType(Messenger::TYPE_WARNING));
    $this->assertSame($expected_warnings, $actual_warnings);

    // There shouldn't be any more messages.
    $this->assertEmpty($messenger->all());

    // If there are only warnings, we should see the warning preamble.
    $results = array_slice($results, -2);
    $this->displayResults($results, $messenger);

    $expected_warnings = [
      (string) $this->getFailureMessageForSeverity(SystemManager::REQUIREMENT_WARNING),
      'Moo!',
      'Mystery Mobile',
    ];
    $actual_warnings = array_map('strval', $messenger->deleteByType(Messenger::TYPE_WARNING));
    $this->assertSame($expected_warnings, $actual_warnings);

    // There shouldn't be any more messages.
    $this->assertEmpty($messenger->all());
  }

}

/**
 * Implements a translation manager in tests.
 */
class TestTranslationManager implements TranslationInterface {

  /**
   * {@inheritdoc}
   */
  public function translate($string, array $args = [], array $options = []) {
    return new TranslatableMarkup($string, $args, $options, $this);
  }

  /**
   * {@inheritdoc}
   */
  public function translateString(TranslatableMarkup $translated_string) {
    return $translated_string->getUntranslatedString();
  }

  /**
   * {@inheritdoc}
   */
  public function formatPlural($count, $singular, $plural, array $args = [], array $options = []) {
    return new PluralTranslatableMarkup($count, $singular, $plural, $args, $options, $this);
  }

}
