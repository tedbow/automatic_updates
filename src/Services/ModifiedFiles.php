<?php

namespace Drupal\automatic_updates\Services;

use Drupal\automatic_updates\IgnoredPathsTrait;
use Drupal\automatic_updates\ProjectInfoTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\Signify\ChecksumList;
use Drupal\Signify\FailedCheckumFilter;
use Drupal\Signify\Verifier;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\EachPromise;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Modified files service.
 */
class ModifiedFiles implements ModifiedFilesInterface {
  use IgnoredPathsTrait;
  use ProjectInfoTrait;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * ModifiedFiles constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(LoggerInterface $logger, ClientInterface $http_client, ConfigFactoryInterface $config_factory) {
    $this->logger = $logger;
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $project_root = drupal_get_path('module', 'automatic_updates');
    require_once $project_root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
  }

  /**
   * {@inheritdoc}
   */
  public function getModifiedFiles(array $extensions = [], $exception_on_failure = FALSE) {
    $modified_files = new \ArrayIterator();
    /** @var \GuzzleHttp\Promise\PromiseInterface[] $promises */
    $promises = $this->getHashRequests($extensions);
    // Wait until all the requests are finished.
    (new EachPromise($promises, [
      'concurrency' => 4,
      'fulfilled' => function (array $resource) use ($modified_files) {
        $this->processHashes($resource, $modified_files);
      },
      'rejected' => function (RequestException $exception) use ($exception_on_failure) {
        $this->processFailures($exception, $exception_on_failure);
      },
    ]))->promise()->wait();
    return $modified_files;
  }

  /**
   * Process checking hashes of files from external URL.
   *
   * @param array $hash
   *   An array of http response and project info.
   * @param \ArrayIterator $modified_files
   *   The list of modified files.
   *
   * @throws \SodiumException
   */
  protected function processHashes(array $hash, \ArrayIterator $modified_files) {
    $contents = $hash['contents'];
    $info = $hash['info'];
    $directory_root = $info['install path'];
    if ($info['project'] === 'drupal') {
      $directory_root = '';
    }
    $module_path = drupal_get_path('module', 'automatic_updates');
    $key = file_get_contents($module_path . '/artifacts/keys/root.pub');
    $verifier = new Verifier($key);
    $files = $verifier->verifyCsigMessage($contents);
    $checksums = new ChecksumList($files, TRUE);
    foreach (new FailedCheckumFilter($checksums, $directory_root) as $failed_checksum) {
      $file_path = implode(DIRECTORY_SEPARATOR, array_filter([
        $directory_root,
        $failed_checksum->filename,
      ]));
      if (!file_exists($file_path)) {
        $modified_files->append($file_path);
        continue;
      }
      $actual_hash = @hash_file(strtolower($failed_checksum->algorithm), $file_path);
      if ($actual_hash === FALSE || empty($actual_hash) || strlen($actual_hash) < 64 || strcmp($actual_hash, $failed_checksum->hex_hash) !== 0) {
        $modified_files->append($file_path);
      }
    }
  }

  /**
   * Handle HTTP failures.
   *
   * @param \GuzzleHttp\Exception\RequestException $exception
   *   The request exception.
   * @param bool $exception_on_failure
   *   Throw exception on HTTP failures, defaults to FALSE.
   */
  protected function processFailures(RequestException $exception, $exception_on_failure) {
    if ($exception_on_failure) {
      watchdog_exception('automatic_updates', $exception);
      throw $exception;
    }
  }

  /**
   * Get an iterator of promises that return a resource stream.
   *
   * @param array $extensions
   *   The list of extensions, keyed by extension name and value the info array.
   *
   * @codingStandardsIgnoreStart
   *
   * @return \Generator
   *
   * @@codingStandardsIgnoreEnd
   */
  protected function getHashRequests(array $extensions) {
    foreach ($extensions as $info) {
      // We can't check for modifications if we don't know the version.
      if (!($info['version'])) {
        continue;
      }
      $url = $this->buildUrl($info);
      yield $this->getPromise($url, $info);
    }
  }

  /**
   * Get a promise.
   *
   * @param string $url
   *   The URL.
   * @param array $info
   *   The extension's info.
   *
   * @return \GuzzleHttp\Promise\PromiseInterface
   *   The promise.
   */
  protected function getPromise($url, array $info) {
    return $this->httpClient->requestAsync('GET', $url, [
      'stream' => TRUE,
      'read_timeout' => 30,
    ])->then(
      static function (ResponseInterface $response) use ($info) {
        return [
          'contents' => $response->getBody()->getContents(),
          'info' => $info,
        ];
      }
    );
  }

  /**
   * Build an extension's hash file URL.
   *
   * @param array $info
   *   The extension's info.
   *
   * @return string
   *   The URL endpoint with for an extension.
   */
  protected function buildUrl(array $info) {
    $version = $info['version'];
    $project_name = $info['project'];
    $hash_name = $this->getHashName($info);
    $uri = ltrim($this->configFactory->get('automatic_updates.settings')->get('hashes_uri'), '/');
    return Url::fromUri("$uri/$project_name/$version/$hash_name")->toString();
  }

  /**
   * Get the hash file name.
   *
   * @param array $info
   *   The extension's info.
   *
   * @return string|null
   *   The hash name.
   */
  protected function getHashName(array $info) {
    $hash_name = 'contents-sha256sums';
    if ($info['packaged']) {
      $hash_name .= '-packaged';
    }
    return $hash_name . '.csig';
  }

}
