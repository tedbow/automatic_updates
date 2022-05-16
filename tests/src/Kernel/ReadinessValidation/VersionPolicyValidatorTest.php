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
   * @param string $installed_version
   *   The installed version of Drupal core, as known to the update system.
   * @param string[] $release_metadata
   *   The paths of the XML release metadata files to use, keyed by project
   *   name.
   *
   * @dataProvider providerAttended
   *
   * @see parent::setReleaseMetadata()
   */
  public function testAttended(string $installed_version, array $release_metadata): void {
    $this->setCoreVersion($installed_version);
    $this->setReleaseMetadata($release_metadata);
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
   * @param string $installed_version
   *   The installed version of Drupal core, as known to the update system.
   * @param string[] $release_metadata
   *   The paths of the XML release metadata files to use, keyed by project
   *   name.
   *
   * @dataProvider providerUnattended
   *
   * @see parent::setReleaseMetadata()
   */
  public function testUnattended(string $installed_version, array $release_metadata): void {
    $this->setCoreVersion($installed_version);
    $this->setReleaseMetadata($release_metadata);
  }

}
