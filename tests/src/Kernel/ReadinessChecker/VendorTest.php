<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessChecker;

use Drupal\automatic_updates\ReadinessChecker\Vendor;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests locating vendor folder.
 *
 * @group automatic_updates
 */
class VendorTest extends KernelTestBase {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'automatic_updates',
  ];

  /**
   * Tests vendor folder existing.
   */
  public function testVendor() {
    $vendor = $this->container->get('automatic_updates.vendor');
    $this->assertEmpty($vendor->run());

    $missing_vendor = $this->getMockBuilder(Vendor::class)
      ->setConstructorArgs([
        $this->container->get('app.root'),
      ])
      ->setMethods([
        'exists',
      ])
      ->getMock();
    $missing_vendor
      ->method('exists')
      ->withAnyParameters()
      ->will($this->onConsecutiveCalls(
        TRUE,
        FALSE
      )
    );
    $expected_messages = [];
    $expected_messages[] = $this->t('The vendor folder could not be located.');
    $this->assertEquals($expected_messages, $missing_vendor->run());
  }

}
