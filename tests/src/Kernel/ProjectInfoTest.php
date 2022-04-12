<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\ProjectInfo;

/**
 * @coversDefaultClass \Drupal\automatic_updates\ProjectInfo
 *
 * @group automatic_updates
 */
class ProjectInfoTest extends AutomaticUpdatesKernelTestBase {

  /**
   * @covers ::getInstallableReleases()
   *
   * @param string $fixture
   *   The fixture file name.
   * @param string $installed_version
   *   The installed version core version to set.
   * @param string[] $expected_versions
   *   The expected versions.
   *
   * @dataProvider providerGetInstallableReleases
   */
  public function testGetInstallableReleases(string $fixture, string $installed_version, array $expected_versions) {
    $this->setReleaseMetadata(__DIR__ . "/../../fixtures/release-history/$fixture");
    $this->setCoreVersion($installed_version);
    $project_info = new ProjectInfo('drupal');
    $actual_releases = $project_info->getInstallableReleases();
    // Assert that we returned the correct releases in the expected order.
    $this->assertSame($expected_versions, array_keys($actual_releases));
    // Assert that we version keys match the actual releases.
    foreach ($actual_releases as $version => $release) {
      $this->assertSame($version, $release->getVersion());
    }
  }

  /**
   * Data provider for testGetInstallableReleases().
   *
   * @return array[]
   *   The test cases.
   */
  public function providerGetInstallableReleases() {
    return [
      'no updates' => [
        'drupal.9.8.2.xml',
        '9.8.2',
        [],
      ],
      'on unsupported branch, updates in multiple supported branches' => [
        'drupal.9.8.2.xml',
        '9.6.0-alpha1',
        ['9.8.2', '9.8.1', '9.8.0', '9.8.0-alpha1', '9.7.1', '9.7.0', '9.7.0-alpha1'],
      ],
      // A test case with an unpublished release, 9.8.0, and unsupported
      // release, 9.8.1, both of these releases should not be returned.
      'filter out unsupported and unpublished releases' => [
        'drupal.9.8.2-unsupported_unpublished.xml',
        '9.6.0-alpha1',
        ['9.8.2', '9.8.0-alpha1', '9.7.1', '9.7.0', '9.7.0-alpha1'],
      ],
      'supported branches before and after installed release' => [
        'drupal.9.8.2.xml',
        '9.8.0-alpha1',
        ['9.8.2', '9.8.1', '9.8.0'],
      ],
      'one insecure release filtered out' => [
        'drupal.9.8.1-security.xml',
        '9.8.0-alpha1',
        ['9.8.1'],
      ],
      'skip insecure releases and return secure releases' => [
        'drupal.9.8.2-older-sec-release.xml',
        '9.7.0-alpha1',
        ['9.8.2', '9.8.1', '9.8.0-alpha1', '9.7.1'],
      ],
    ];
  }

  /**
   * Tests a project with a status other than "published".
   *
   * @covers ::getInstallableReleases()
   */
  public function testNotPublishedProject() {
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.2_unknown_status.xml');
    $project_info = new ProjectInfo('drupal');
    $this->expectException('RuntimeException');
    $this->expectExceptionMessage("The project 'drupal' can not be updated because its status is any status besides published");
    $project_info->getInstallableReleases();
  }

}
