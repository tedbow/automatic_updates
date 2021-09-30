<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\UpdateRecommender;

/**
 * @covers \Drupal\automatic_updates\UpdateRecommender
 *
 * @group automatic_updates
 */
class UpdateRecommenderTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'package_manager',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('update');
  }

  /**
   * Tests fetching the recommended release when an update is available.
   */
  public function testUpdateAvailable(): void {
    $this->setCoreVersion('9.8.0');
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.0.0.xml');

    $recommender = new UpdateRecommender();
    $recommended_release = $recommender->getRecommendedRelease(TRUE);
    $this->assertNotEmpty($recommended_release);
    $this->assertSame('9.8.1', $recommended_release->getVersion());
    // Getting the recommended release again should not trigger another request.
    $this->assertNotEmpty($recommender->getRecommendedRelease());
  }

  /**
   * Tests fetching the recommended release when there is no update available.
   */
  public function testNoUpdateAvailable(): void {
    $this->setCoreVersion('9.8.1');
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.0.0.xml');

    $recommender = new UpdateRecommender();
    $recommended_release = $recommender->getRecommendedRelease(TRUE);
    $this->assertNull($recommended_release);
    // Getting the recommended release again should not trigger another request.
    $this->assertNull($recommender->getRecommendedRelease());
  }

}
