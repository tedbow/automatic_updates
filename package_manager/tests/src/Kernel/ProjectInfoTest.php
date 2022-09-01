<?php

namespace Drupal\Tests\package_manger\Kernel;

use Drupal\package_manager\ProjectInfo;
use Drupal\Tests\package_manager\Kernel\PackageManagerKernelTestBase;

/**
 * @coversDefaultClass \Drupal\package_manager\ProjectInfo
 *
 * @group automatic_updates
 */
class ProjectInfoTest extends PackageManagerKernelTestBase {

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
  public function testGetInstallableReleases(string $fixture, string $installed_version, array $expected_versions): void {
    [$project] = explode('.', $fixture);
    $fixtures_directory = __DIR__ . '/../../fixtures/release-history/';
    if ($project === 'drupal') {
      $this->setCoreVersion($installed_version);
    }
    else {
      // Update the version and the project of the project.
      $this->enableModules(['aaa_automatic_updates_test']);
      $extension_info_update = [
        'version' => $installed_version,
        'project' => 'aaa_automatic_updates_test',
      ];
      $this->config('update_test.settings')
        ->set("system_info.$project", $extension_info_update)
        ->save();
      // The Update module will always request Drupal core's update XML.
      $metadata_fixtures['drupal'] = $fixtures_directory . 'drupal.9.8.2.xml';
    }
    $metadata_fixtures[$project] = "$fixtures_directory$fixture";
    $this->setReleaseMetadata($metadata_fixtures);
    $project_info = new ProjectInfo($project);
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
   * @return mixed[][]
   *   The test cases.
   */
  public function providerGetInstallableReleases(): array {
    return [
      'core, no updates' => [
        'drupal.9.8.2.xml',
        '9.8.2',
        [],
      ],
      'core, on unsupported branch, updates in multiple supported branches' => [
        'drupal.9.8.2.xml',
        '9.6.0-alpha1',
        ['9.8.2', '9.8.1', '9.8.0', '9.8.0-alpha1', '9.7.1', '9.7.0', '9.7.0-alpha1'],
      ],
      // A test case with an unpublished release, 9.8.0, and unsupported
      // release, 9.8.1, both of these releases should not be returned.
      'core, filter out unsupported and unpublished releases' => [
        'drupal.9.8.2-unsupported_unpublished.xml',
        '9.6.0-alpha1',
        ['9.8.2', '9.8.0-alpha1', '9.7.1', '9.7.0', '9.7.0-alpha1'],
      ],
      'core, supported branches before and after installed release' => [
        'drupal.9.8.2.xml',
        '9.8.0-alpha1',
        ['9.8.2', '9.8.1', '9.8.0'],
      ],
      'core, one insecure release filtered out' => [
        'drupal.9.8.1-security.xml',
        '9.8.0-alpha1',
        ['9.8.1'],
      ],
      'core, skip insecure releases and return secure releases' => [
        'drupal.9.8.2-older-sec-release.xml',
        '9.7.0-alpha1',
        ['9.8.2', '9.8.1', '9.8.1-beta1', '9.8.0-alpha1', '9.7.1'],
      ],
      'contrib, semver and legacy' => [
        'aaa_automatic_updates_test.9.8.2.xml',
        '8.x-6.0-alpha1',
        ['7.0.1', '7.0.0', '7.0.0-alpha1', '8.x-6.2', '8.x-6.1', '8.x-6.0'],
      ],
      'contrib, semver and legacy, some lower' => [
        'aaa_automatic_updates_test.9.8.2.xml',
        '8.x-6.1',
        ['7.0.1', '7.0.0', '7.0.0-alpha1', '8.x-6.2'],
      ],
      'contrib, semver and legacy, on semantic dev' => [
        'aaa_automatic_updates_test.9.8.2.xml',
        '7.0.x-dev',
        ['7.0.1', '7.0.0', '7.0.0-alpha1'],
      ],
      'contrib, semver and legacy, on legacy dev' => [
        'aaa_automatic_updates_test.9.8.2.xml',
        '8.x-6.x-dev',
        ['7.0.1', '7.0.0', '7.0.0-alpha1', '8.x-6.2', '8.x-6.1', '8.x-6.0', '8.x-6.0-alpha1'],
      ],
    ];
  }

  /**
   * Tests a project with a status other than "published".
   *
   * @covers ::getInstallableReleases()
   */
  public function testNotPublishedProject(): void {
    $this->setReleaseMetadata(['drupal' => __DIR__ . '/../../fixtures/release-history/drupal.9.8.2_unknown_status.xml']);
    $project_info = new ProjectInfo('drupal');
    $this->expectException('RuntimeException');
    $this->expectExceptionMessage("The project 'drupal' can not be updated because its status is any status besides published");
    $project_info->getInstallableReleases();
  }

}
