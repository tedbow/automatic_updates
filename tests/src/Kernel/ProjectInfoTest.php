<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\ProjectInfo;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;

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
    $this->setReleaseMetadataForProjects($metadata_fixtures);
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
   * @return array[]
   *   The test cases.
   */
  public function providerGetInstallableReleases() {
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
        ['9.8.2', '9.8.1', '9.8.0-alpha1', '9.7.1'],
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
    ];
  }

  /**
   * Tests a project with a status other than "published".
   *
   * @covers ::getInstallableReleases()
   */
  public function testNotPublishedProject() {
    $this->setReleaseMetadataForProjects(['drupal' => __DIR__ . '/../../fixtures/release-history/drupal.9.8.2_unknown_status.xml']);
    $project_info = new ProjectInfo('drupal');
    $this->expectException('RuntimeException');
    $this->expectExceptionMessage("The project 'drupal' can not be updated because its status is any status besides published");
    $project_info->getInstallableReleases();
  }

  /**
   * Sets the release metadata file to use when fetching available updates.
   *
   * @param string[] $files
   *   The paths of the XML metadata files to use, keyed by project name.
   */
  protected function setReleaseMetadataForProjects(array $files): void {
    $responses = [];

    foreach ($files as $project => $file) {
      $metadata = Utils::tryFopen($file, 'r');
      $responses["/release-history/$project/current"] = new Response(200, [], Utils::streamFor($metadata));
    }
    $callable = function (RequestInterface $request) use ($responses): Response {
      return $responses[$request->getUri()->getPath()] ?? new Response(404);
    };

    // The mock handler's queue consist of same callable as many times as the
    // number of requests we expect to be made for update XML because it will
    // retrieve one item off the queue for each request.
    // @see \GuzzleHttp\Handler\MockHandler::__invoke()
    $handler = new MockHandler(array_fill(0, count($responses), $callable));
    $this->client = new Client([
      'handler' => HandlerStack::create($handler),
    ]);
    $this->container->set('http_client', $this->client);
  }

}
