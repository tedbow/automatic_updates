<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessChecker;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests automatic updates readiness checking.
 *
 * @group automatic_updates
 */
class ReadinessCheckerTest extends KernelTestBase {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'automatic_updates',
  ];

  /**
   * Tests the functionality of readiness checks.
   */
  public function testReadinessChecker() {
    /** @var \Drupal\automatic_updates\ReadinessChecker\ReadinessCheckerManagerInterface $checker */
    $checker = $this->container->get('automatic_updates.readiness_checker');
    foreach ($checker->getCategories() as $category) {
      $this->assertEmpty($checker->run($category));
    }
  }

}
