<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\Tests\automatic_updates\Kernel\ReadinessValidation\SettingsValidatorTest
 *
 * @group automatic_updates
 */
class SettingsValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testSettingsValidation().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerSettingsValidation(): array {
    $result = ValidationResult::createError([
      'The <code>update_fetch_with_http_fallback</code> setting must be disabled for automatic updates.',
    ]);

    return [
      [TRUE, [$result]],
      [FALSE, []],
    ];
  }

  /**
   * Tests settings validation before starting an update.
   *
   * @param bool $setting
   *   The value of the update_fetch_with_http_fallback setting.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerSettingsValidation
   */
  public function testSettingsValidation(bool $setting, array $expected_results): void {
    $this->setSetting('update_fetch_with_http_fallback', $setting);

    $this->assertCheckerResultsFromManager($expected_results, TRUE);
    try {
      $this->container->get('automatic_updates.updater')->begin([
        'drupal' => '9.8.1',
      ]);
      // If there was no exception, ensure we're not expecting any errors.
      $this->assertSame([], $expected_results);
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

}
