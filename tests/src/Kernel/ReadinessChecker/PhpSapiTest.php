<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessChecker;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests what happens when PHP SAPI changes from one value to another.
 *
 * @group automatic_updates
 */
class PhpSapiTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'automatic_updates',
  ];

  /**
   * Tests PHP SAPI changes.
   */
  public function testPhpSapiChanges() {
    $messages = $this->container->get('automatic_updates.php_sapi')->run();
    $this->assertEmpty($messages);
    $messages = $this->container->get('automatic_updates.php_sapi')->run();
    $this->assertEmpty($messages);

    $this->container->get('state')->set('automatic_updates.php_sapi', 'foo');
    $messages = $this->container->get('automatic_updates.php_sapi')->run();
    self::assertEquals('PHP changed from running as "foo" to "cli". This can lead to inconsistent and misleading results.', $messages[0]);
  }

}
