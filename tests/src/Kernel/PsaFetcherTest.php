<?php


use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Services\AutomaticUpdatesPsa
 *
 * @group update
 */
class PsaFetcherTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->installConfig('automatic_updates');
  }

  /**
   * Tests contrib advisories that should be displayed.
   *
   * @param string $existing_version
   *   The existing version of the module.
   * @param int $is_psa
   *   The 'is_psa' value for the feed item.
   * @param array $insecure_versions
   *   The 'insecure_versions' value for the feed item.
   *
   * @dataProvider providerShowAdvisories
   */
  public function testShowContribAdvisories(string $existing_version, int $is_psa, array $insecure_versions): void {
    $this->setProphesizedServices($existing_version, $is_psa, $insecure_versions);
    $fetcher = $this->container->get('automatic_updates.psa');
    /** @var \Drupal\Component\Render\FormattableMarkup[] $links */
    $links = $fetcher->getPublicServiceMessages();
    static::assertCount(1, $links);
    $this->assertSame('<a href="http://thesa.com">SA title</a>', (string) $links[0]);
  }

  /**
   * Dataprovider for testShowContribAdvisories().
   */
  public function providerShowAdvisories() {
    return [
      'exact-non-psa' => [
        'existing_version' => '8.x-1.0',
        'is_psa' => 0,
        'insecure_versions' => ['8.x-1.0'],
      ],
      'not-exact-non-psa' => [
        'existing_version' => '8.x-1.0',
        'is_psa' => 0,
        'insecure_versions' => ['1.0'],
      ],
      'not-exact-non-psa-reversed' => [
        'existing_version' => '8.x-1.0',
        'is_psa' => 0,
        'insecure_versions' => ['1.0'],
      ],
      'semver-non-psa' => [
        'existing_version' => '1.0.0',
        'is_psa' => 0,
        'insecure_versions' => ['1.0.0'],
      ],
      'semver-non-exact-non-psa' => [
        'existing_version' => '1.0.0',
        'is_psa' => 0,
        'insecure_versions' => ['1.0'],
      ],
      'semver-major-match-non-psa' => [
        'existing_version' => '1.0.0',
        'is_psa' => 0,
        'insecure_versions' => ['8.x-1.0'],
      ],
      'existing-extra-non-psa' => [
        'existing_version' => '1.0-extraStringNotSpecial',
        'is_psa' => 0,
        'insecure_versions' => ['8.x-1.0'],
      ],
      'both-extra-non-psa' => [
        'existing_version' => '8.x-1.0-alsoNotSpecialNotMatching',
        'is_psa' => 0,
        'insecure_versions' => ['8.x-1.0-extraStringNotSpecial'],
      ],
      'exact-psa' => [
        'existing_version' => '8.x-1.0',
        'is_psa' => 1,
        'insecure_versions' => ['8.x-1.0'],
      ],
      'not-exact-psa' => [
        'existing_version' => '8.x-1.0',
        'is_psa' => 1,
        'insecure_versions' => ['1.0'],
      ],
      'non-matching-psa' => [
        'existing_version' => '8.x-2.0',
        'is_psa' => 1,
        'insecure_versions' => ['8.x-1.0'],
      ],
      'no-insecure-psa' => [
        'existing_version' => '8.x-2.0',
        'is_psa' => 1,
        'insecure_versions' => [],
      ],
    ];
  }

  /**
   * Tests Advisories that should be ignored.
   *
   * @param string $existing_version
   *   The existing version of the module.
   * @param int $is_psa
   *   The 'is_psa' value for the feed item.
   * @param array $insecure_versions
   *   The 'insecure_versions' value for the feed item.
   *
   * @dataProvider providerIgnoreContribAdvisories
   */
  public function testIgnoreContribAdvisories(string $existing_version, int $is_psa, array $insecure_versions): void {
    $this->setProphesizedServices($existing_version, $is_psa, $insecure_versions);
    $fetcher = $this->container->get('automatic_updates.psa');
    /** @var \Drupal\Component\Render\FormattableMarkup[] $links */
    $links = $fetcher->getPublicServiceMessages();
    static::assertCount(0, $links);
  }

  /**
   * Dataprovider for testIgnoreContribAdvisories().
   */
  public function providerIgnoreContribAdvisories() {
    return [
      'non-matching-non-psa' => [
        'existing_version' => '8.x-1.0',
        'is_psa' => 0,
        'insecure_versions' => ['8.x-1.1'],
      ],
      'non-matching-not-exact-non-psa' => [
        'existing_version' => '8.x-1.0',
        'is_psa' => 0,
        'insecure_versions' => ['1.1'],
      ],
      'semver-7major-match-non-psa' => [
        'existing_version' => '1.0.0',
        'is_psa' => 0,
        'insecure_versions' => ['7.x-1.0'],
      ],
      'semver-different-majors-non-psa' => [
        'existing_version' => '8.x-1.0',
        'is_psa' => 0,
        'insecure_versions' => ['7.x-1.0'],
      ],
      'no-version-non-psa' => [
        'existing_version' => '',
        'is_psa' => 0,
        'insecure_versions' => ['8.x-1.1'],
      ],
      'insecure-extra-non-psa' => [
        'existing_version' => '8.x-1.0',
        'is_psa' => 0,
        'insecure_versions' => ['8.x-1.0-extraStringNotSpecial'],
      ],
      'insecure-dev-non-psa' => [
        'existing_version' => '8.x-1.0',
        'is_psa' => 0,
        'insecure_versions' => ['8.x-1.0-dev'],
      ],
      'exiting-dev-non-psa' => [
        'existing_version' => '8.x-1.0-dev',
        'is_psa' => 0,
        'insecure_versions' => ['8.x-1.0'],
      ],
    ];
  }

  /**
   * Sets the 'http_client' and 'extension.list.module' services for the tests.
   *
   * @param string $existing_version
   *   The existing version of the module.
   * @param int $is_psa
   *   The 'is_psa' value for the feed item.
   * @param array $insecure_versions
   *   The 'insecure_versions' value for the feed item.
   */
  protected function setProphesizedServices(string $existing_version, int $is_psa, array $insecure_versions): void {
    $sa = [
      'title' => 'SA title',
      'project' => 'the_project',
      'type' => 'module',
      'link' => 'http://thesa.com',
      'insecure' => $insecure_versions,
      'is_psa' => $is_psa,
    ];
    $json_string = json_encode([$sa]);
    $stream = $this->prophesize(StreamInterface::class);
    $stream->getContents()->willReturn($json_string);
    $response = $this->prophesize(ResponseInterface::class);
    $response->getBody()->willReturn($stream->reveal());
    $client = $this->prophesize(Client::class);
    $client->get('https://updates.drupal.org/psa.json')
      ->willReturn($response->reveal());
    $this->container->set('http_client', $client->reveal());

    $module_list = $this->prophesize(ModuleExtensionList::class);
    $module_list->exists('the_project')->willReturn(TRUE);
    $module_list->getAllAvailableInfo()->willReturn(['the_project' => [
      'version' => $existing_version,
      'project' => 'the_project',
    ]]);

    $this->container->set('extension.list.module', $module_list->reveal());
  }

}
