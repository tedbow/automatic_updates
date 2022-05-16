<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

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

  /**
   * Data provider for ::testAttended().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerAttended(): array {
    return [];
  }

  /**
   * Tests version policy for attended updates.
   *
   * @dataProvider providerAttended
   */
  public function testAttended(): void {
  }

  /**
   * Data provider for ::testUnattended().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerUnattended(): array {
    return [];
  }

  /**
   * Tests version policy for unattended updates.
   *
   * @dataProvider providerUnattended
   */
  public function testUnattended(): void {
  }

}
