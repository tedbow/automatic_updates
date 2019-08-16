<?php

namespace Drupal\automatic_updates\Services;

use Drupal\automatic_updates\IgnoredPathsTrait;
use Drupal\automatic_updates\ProjectInfoTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use DrupalFinder\DrupalFinder;
use GuzzleHttp\ClientInterface;
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
   * The drupal finder service.
   *
   * @var \DrupalFinder\DrupalFinder
   */
  protected $drupalFinder;

  /**
   * ModifiedCode constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \DrupalFinder\DrupalFinder $drupal_finder
   *   The Drupal finder.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(LoggerInterface $logger, DrupalFinder $drupal_finder, ClientInterface $http_client, ConfigFactoryInterface $config_factory) {
    $this->logger = $logger;
    $this->drupalFinder = $drupal_finder;
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->drupalFinder->locateRoot(getcwd());
  }

  /**
   * {@inheritdoc}
   */
  public function getModifiedFiles(array $extensions = []) {
    $modified_files = [];
    /** @var \GuzzleHttp\Promise\PromiseInterface[] $promises */
    $promises = $this->getHashRequests($extensions);
    // Wait until all the requests are finished.
    (new EachPromise($promises, [
      'concurrency' => 4,
      'fulfilled' => function ($resource) use (&$modified_files) {
        $this->processHashes($resource, $modified_files);
      },
    ]))->promise()->wait();
    return $modified_files;
  }

  /**
   * Process checking hashes of files from external URL.
   *
   * @param array $resource
   *   An array of response resource and project info.
   * @param array $modified_files
   *   The list of modified files.
   */
  protected function processHashes(array $resource, array &$modified_files) {
    $response = $resource['response'];
    $info = $resource['info'];
    $file_root = $info['install path'];
    while (($line = fgets($response)) !== FALSE) {
      list($hash, $file) = preg_split('/\s+/', $line, 2);
      $file = trim($file);
      // If the line is empty, proceed to the next line.
      if (empty($hash) && empty($file)) {
        continue;
      }
      // If one of the values is invalid, log and continue.
      if (!$hash || !$file) {
        $this->logger->error('@hash or @file is empty; the hash file is malformed for this line.', ['@hash' => $hash, '@file' => $file]);
        continue;
      }
      if ($this->isIgnoredPath($file)) {
        continue;
      }
      if ($info['project'] === 'drupal') {
        $file_root = $this->drupalFinder->getDrupalRoot();
      }
      $file_path = $file_root . DIRECTORY_SEPARATOR . $file;
      if (!file_exists($file_path) || hash_file('sha512', $file_path) !== $hash) {
        $modified_files[] = $file_path;
      }
    }
    if (!feof($response)) {
      $this->logger->error('Stream for resource closed prematurely.');
    }
    fclose($response);
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
    ])
      ->then(function (ResponseInterface $response) use ($info) {
        return [
          'response' => $response->getBody()->detach(),
          'info' => $info,
        ];
      });
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
    return Url::fromUri($uri . "/$project_name/$version/$hash_name")->toString();
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
    $hash_name = 'contents-sha512sums';
    if ($info['packaged']) {
      $hash_name .= '-packaged';
    }
    return $hash_name . '.txt';
  }

}
