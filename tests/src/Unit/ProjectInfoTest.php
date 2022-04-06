<?php

namespace Drupal\Tests\automatic_updates\Unit;

use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates_9_3_shim\ProjectRelease;
use Drupal\Tests\UnitTestCase;
use Drupal\update\UpdateManagerInterface;

/**
 * @coversDefaultClass \Drupal\automatic_updates\ProjectInfo
 *
 * @group automatic_updates
 */
class ProjectInfoTest extends UnitTestCase {

  /**
   * Creates release data for testing.
   *
   * @return string[][]
   *   The release information.
   */
  private static function createTestReleases(): array {
    $versions = ['8.2.5', '8.2.4', '8.2.3', '8.2.3-alpha'];
    foreach ($versions as $version) {
      $release_arrays[$version] = [
        'status' => 'published',
        'version' => $version,
        'release_link' => "https://example.drupal.org/project/drupal/releases/$version",
      ];
    }
    return $release_arrays;
  }

  /**
   * Data provider for testGetInstallableReleases().
   *
   * @return array[][]
   *   The test cases.
   */
  public function providerGetInstallableReleases(): array {
    $release_arrays = static::createTestReleases();
    foreach ($release_arrays as $version => $release_array) {
      $release_objects[$version] = ProjectRelease::createFromArray($release_array);
    }
    return [
      'current' => [
        [
          'status' => UpdateManagerInterface::CURRENT,
          'existing_version' => '1.2.3',
        ],
        [],
      ],
      '1 release' => [
        [
          'status' => UpdateManagerInterface::NOT_CURRENT,
          'existing_version' => '8.2.4',
          'recommended' => '8.2.5',
          'releases' => [
            '8.2.5' => $release_arrays['8.2.5'],
          ],
        ],
        [
          '8.2.5' => $release_objects['8.2.5'],
        ],
      ],
      '1 releases, also security' => [
        [
          'status' => UpdateManagerInterface::NOT_CURRENT,
          'existing_version' => '8.2.4',
          'recommended' => '8.2.5',
          'releases' => [
            '8.2.5' => $release_arrays['8.2.5'],
          ],
          'security updates' => [
            $release_arrays['8.2.5'],
          ],
        ],
        [
          '8.2.5' => $release_objects['8.2.5'],
        ],
      ],
      '1 release, other security' => [
        [
          'status' => UpdateManagerInterface::NOT_CURRENT,
          'existing_version' => '8.2.2',
          'recommended' => '8.2.5',
          'releases' => [
            '8.2.5' => $release_arrays['8.2.5'],
          ],
          'security updates' => [
            // Set out of order security releases to ensure results are sorted.
            $release_arrays['8.2.3-alpha'],
            $release_arrays['8.2.3'],
            $release_arrays['8.2.4'],
          ],
        ],
        [
          '8.2.5' => $release_objects['8.2.5'],
          '8.2.4' => $release_objects['8.2.4'],
          '8.2.3' => $release_objects['8.2.3'],
          '8.2.3-alpha' => $release_objects['8.2.3-alpha'],
        ],
      ],
      '1 releases, other security lower than current version' => [
        [
          'status' => UpdateManagerInterface::NOT_CURRENT,
          'existing_version' => '8.2.3',
          'recommended' => '8.2.5',
          'releases' => [
            '8.2.5' => $release_arrays['8.2.5'],
          ],
          'security updates' => [
            // Set out of order security releases to ensure results are sorted.
            $release_arrays['8.2.3-alpha'],
            $release_arrays['8.2.3'],
            $release_arrays['8.2.4'],
          ],
        ],
        [
          '8.2.5' => $release_objects['8.2.5'],
          '8.2.4' => $release_objects['8.2.4'],
        ],
      ],
      [
        NULL,
        NULL,
      ],
    ];
  }

  /**
   * @covers ::getInstallableReleases
   *
   * @param array|null $project_data
   *   The project data to return from ::getProjectInfo().
   * @param \Drupal\automatic_updates_9_3_shim\ProjectRelease[]|null $expected_releases
   *   The expected releases.
   *
   * @dataProvider providerGetInstallableReleases
   */
  public function testGetInstallableReleases(?array $project_data, ?array $expected_releases): void {
    $project_info = $this->getMockedProjectInfo($project_data);
    $this->assertEqualsCanonicalizing($expected_releases, $project_info->getInstallableReleases());
  }

  /**
   * @covers ::getInstallableReleases
   */
  public function testInvalidProjectData(): void {
    $release_arrays = static::createTestReleases();
    $project_data = [
      'status' => UpdateManagerInterface::NOT_CURRENT,
      'existing_version' => '1.2.3',
      'releases' => [
        '8.2.5' => $release_arrays['8.2.5'],
      ],
      'security updates' => [
        $release_arrays['8.2.4'],
        $release_arrays['8.2.3'],
        $release_arrays['8.2.3-alpha'],
      ],
    ];
    $project_info = $this->getMockedProjectInfo($project_data);
    $this->expectException('LogicException');
    $this->expectExceptionMessage("The 'drupal' project is out of date, but the recommended version could not be determined.");
    $project_info->getInstallableReleases();
  }

  /**
   * @covers ::getInstalledVersion
   */
  public function testGetInstalledVersion(): void {
    $project_info = $this->getMockedProjectInfo(['existing_version' => '1.2.3']);
    $this->assertSame('1.2.3', $project_info->getInstalledVersion());
    $project_info = $this->getMockedProjectInfo(NULL);
    $this->assertSame(NULL, $project_info->getInstalledVersion());
  }

  /**
   * Mocks a ProjectInfo object.
   *
   * @param array|null $project_data
   *   The project info that should be returned by the mock's ::getProjectInfo()
   *   method.
   *
   * @return \Drupal\automatic_updates\ProjectInfo
   *   The mocked object.
   */
  private function getMockedProjectInfo(?array $project_data): ProjectInfo {
    $project_info = $this->getMockBuilder(ProjectInfo::class)
      ->setConstructorArgs(['drupal'])
      ->onlyMethods(['getProjectInfo'])
      ->getMock();
    $project_info->expects($this->any())
      ->method('getProjectInfo')
      ->willReturn($project_data);
    return $project_info;
  }

}
