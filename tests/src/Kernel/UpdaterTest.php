<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Prophecy\Argument;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Updater
 *
 * @group automatic_updates
 */
class UpdaterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'automatic_updates_test',
    'composer_stager_bypass',
    'update',
    'update_test',
  ];

  /**
   * Tests that correct versions are staged after calling ::begin().
   */
  public function testCorrectVersionsStaged() {
    // Ensure that the HTTP client will fetch our fake release metadata.
    $release_data = Utils::tryFopen(__DIR__ . '/../../fixtures/release-history/drupal.0.0.xml', 'r');
    $response = new Response(200, [], Utils::streamFor($release_data));
    $handler = new MockHandler([$response]);
    $client = new Client(['handler' => $handler]);
    $this->container->set('http_client', $client);

    // Set the running core version to 9.8.0.
    $this->config('update_test.settings')
      ->set('system_info.#all.version', '9.8.0')
      ->save();

    $this->container->get('automatic_updates.updater')->begin([
      'drupal' => '9.8.1',
    ]);
    // Rebuild the container to ensure the project versions are kept in state.
    /** @var \Drupal\Core\DrupalKernel $kernel */
    $kernel = $this->container->get('kernel');
    $kernel->rebuildContainer();
    $this->container = $kernel->getContainer();
    $stager = $this->prophesize('\PhpTuf\ComposerStager\Domain\StagerInterface');
    $command = [
      'require',
      'drupal/core:9.8.1',
      '--update-with-all-dependencies',
    ];
    $stager->stage($command, Argument::cetera())->shouldBeCalled();
    $this->container->set('automatic_updates.stager', $stager->reveal());
    $this->container->get('automatic_updates.updater')->stage();
  }

  /**
   * @covers ::begin
   *
   * @dataProvider providerInvalidProjectVersions
   */
  public function testInvalidProjectVersions(array $project_versions): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Currently only updates to Drupal core are supported.');
    $this->container->get('automatic_updates.updater')->begin($project_versions);
  }

  /**
   * Data provider for testInvalidProjectVersions().
   *
   * @return array
   *   The test cases for testInvalidProjectVersions().
   */
  public function providerInvalidProjectVersions(): array {
    return [
      'only not drupal' => [['not_drupal' => '1.1.3']],
      'not drupal and drupal' => [['drupal' => '9.8.0', 'not_drupal' => '1.2.3']],
      'empty' => [[]],
    ];
  }

}
