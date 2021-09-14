<?php

namespace Drupal\Tests\automatic_updates\Build;

/**
 * Tests an end-to-end core update via the core-recommended metapackage.
 *
 * @group automatic_updates
 */
class CoreRecommendedUpdateTest extends CoreUpdateTest {

  /**
   * {@inheritdoc}
   */
  protected $webRoot = 'docroot/';

  /**
   * {@inheritdoc}
   */
  protected function getConfigurationForUpdate(string $version): array {
    $changes = parent::getConfigurationForUpdate($version);

    // Create a fake version of drupal/core-recommended which requires the
    // target version of drupal/core.
    $dir = $this->copyPackage($this->getDrupalRoot() . '/composer/Metapackage/CoreRecommended');
    $this->alterPackage($dir, [
      'version' => $version,
      'require' => [
        'drupal/core' => $version,
      ],
    ]);
    $changes['repositories']['drupal/core-recommended']['url'] = $dir;

    return $changes;
  }

  /**
   * {@inheritdoc}
   */
  protected function getInitialConfiguration(): array {
    $configuration = parent::getInitialConfiguration();

    // Use drupal/core-recommended to build the test site, instead of directly
    // requiring drupal/core.
    $require = &$configuration['require'];
    $require['drupal/core-recommended'] = $require['drupal/core'];
    unset($require['drupal/core']);

    $configuration['repositories']['drupal/core-recommended'] = [
      'type' => 'path',
      'url' => implode(DIRECTORY_SEPARATOR, [
        $this->getDrupalRoot(),
        'composer',
        'Metapackage',
        'CoreRecommended',
      ]),
    ];
    return $configuration;
  }

}
