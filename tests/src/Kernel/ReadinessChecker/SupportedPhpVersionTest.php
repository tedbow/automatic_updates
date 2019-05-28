<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessChecker;

use Drupal\automatic_updates\ReadinessChecker\BlacklistPhp72Versions;
use Drupal\automatic_updates\ReadinessChecker\MinimumPhpVersion;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests supported PHP version readiness checking.
 *
 * @group automatic_updates
 */
class SupportedPhpVersionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'automatic_updates',
  ];

  /**
   * Tests the functionality of supported PHP version readiness checks.
   */
  public function testSupportedPhpVersion() {
    // No unsupported PHP versions.
    $services = [
      'automatic_updates.minimum_php_version',
      'automatic_updates.blacklist_php_72',
    ];
    foreach ($services as $service) {
      $messages = $this->container->get($service)->run();
      $this->assertEmpty($messages);
    }
    $this->assertNotEmpty($services);

    // Unsupported versions.
    $messages = (new TestBlacklistPhp72Versions())->run();
    $this->assertEquals('PHP 7.2.0, 7.2.1 and 7.2.2 have issues with opcache that breaks signature validation. Please upgrade to a newer version of PHP to ensure assurance and security for package signing.', $messages[0]);
    $messages = (new TestMinimumPhpVersion())->run();
    $this->assertEquals('This site is running on an unsupported version of PHP. It cannot be updated. Please update to at least PHP 7.0.8.', $messages[0]);
  }

}

/**
 * Class TestBlacklistPhp72Versions.
 */
class TestBlacklistPhp72Versions extends BlacklistPhp72Versions {

  /**
   * {@inheritdoc}
   */
  protected function getPhpVersion() {
    return '7.2.0';
  }

}

/**
 * Class TestMinimumPhpVersion.
 */
class TestMinimumPhpVersion extends MinimumPhpVersion {

  /**
   * {@inheritdoc}
   */
  protected function getPhpVersion() {
    return '7.0.7';
  }

}
