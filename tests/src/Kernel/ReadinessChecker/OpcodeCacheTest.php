<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessChecker;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests opcode caching and execution via CLI.
 *
 * @group automatic_updates
 */
class OpcodeCacheTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'automatic_updates',
  ];

  /**
   * Tests the functionality of supported PHP version readiness checks.
   *
   * @dataProvider opcodeCacheProvider
   */
  public function testOpcodeCache($ini, $ini_value, $failure) {
    ini_set($ini, $ini_value);
    $messages = $this->container->get('automatic_updates.opcode_cache')->run();
    if ($failure) {
      $this->assertNotEmpty($messages);
      $this->assertEquals((string) $messages[0], 'Automatic updates cannot run via CLI  when opcode file cache is enabled.');
    }
    else {
      $this->assertEmpty($messages);
    }
  }

  /**
   * Data provider for opcode cache testing.
   */
  public function opcodeCacheProvider() {
    $datum[] = [
      'ini' => 'opcache.validate_timestamps',
      'ini_value' => 0,
      'failure' => TRUE,
    ];
    $datum[] = [
      'ini' => 'opcache.validate_timestamps',
      'ini_value' => 1,
      'failure' => FALSE,
    ];
    $datum[] = [
      'ini' => 'opcache.validate_timestamps',
      'ini_value' => FALSE,
      'failure' => TRUE,
    ];
    $datum[] = [
      'ini' => 'opcache.validate_timestamps',
      'ini_value' => TRUE,
      'failure' => FALSE,
    ];
    $datum[] = [
      'ini' => 'opcache.validate_timestamps',
      'ini_value' => 2,
      'failure' => FALSE,
    ];
    $datum[] = [
      'ini' => 'opcache.revalidate_freq',
      'ini_value' => 3,
      'failure' => TRUE,
    ];
    $datum[] = [
      'ini' => 'opcache.revalidate_freq',
      'ini_value' => 2,
      'failure' => FALSE,
    ];

    return $datum;
  }

}
