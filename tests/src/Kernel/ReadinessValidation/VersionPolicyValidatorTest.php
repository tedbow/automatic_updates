<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicyValidator
 *
 * @group automatic_updates
 */
class VersionPolicyValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  public function providerReadinessCheck(): array {
  }

  public function testReadinessCheck(): void {
  }

  public function providerApi(): array {
    return [
      'downgrade' => [
        '9.8.1',
        __DIR__ . '/../../../fixtures/release-history/drupal.9.8.2.xml',
        ['drupal' => '9.8.0'],
        [
          ValidationResult::createError([
            'Update version 9.8.0 is lower than 9.8.1, downgrading is not supported.',
          ]),
        ],
      ],
      'major version upgrade' => [
        '8.9.1',
        __DIR__ . '/../../../fixtures/release-history/drupal.9.8.2.xml',
        ['drupal' => '9.8.2'],
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from its current version, 8.9.1, to the recommended version, 9.8.2, because automatic updates from one major version to another are not supported.',
          ]),
        ],
      ],
    ];
  }

  /**
   * Tests validation of explicitly specified target versions.
   *
   * @param string $installed_version
   * @param string $release_metadata
   * @param string[] $project_versions
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *
   * @dataProvider providerApi
   */
  public function testApi(string $installed_version, string $release_metadata, array $project_versions, array $expected_results): void {
    $this->setCoreVersion($installed_version);
    $this->setReleaseMetadata(['drupal' => $release_metadata]);

    try {
      $this->container->get('automatic_updates.updater')
        ->begin($project_versions);
      // Ensure that we did not, in fact, expect any errors.
      $this->assertEmpty($expected_results);
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

}
