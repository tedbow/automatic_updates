<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessChecker;

use Drupal\automatic_updates\ReadinessChecker\Vendor;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\KernelTests\KernelTestBase;
use org\bovigo\vfs\vfsStream;

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

    vfsStream::setup('root');
    vfsStream::create([
      'core' => [
        'core.api.php' => 'test',
      ],
    ]);
    $missing_vendor = new Vendor(vfsStream::url('root'));
    $this->assertEquals([], $vendor->run());
    $expected_messages = [$this->t('The vendor folder could not be located.')];
    self::assertEquals($expected_messages, $missing_vendor->run());
  }

}
