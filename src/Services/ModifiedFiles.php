<?php

namespace Drupal\automatic_updates\Services;

use Drupal\automatic_updates\IgnoredPathsTrait;
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
   * @param resource $resource
   *   A resource handle.
   * @param array $modified_files
   *   The list of modified files.
   */
  protected function processHashes($resource, array &$modified_files) {
    while (($line = fgets($resource)) !== FALSE) {
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
      $file_path = $this->drupalFinder->getDrupalRoot() . DIRECTORY_SEPARATOR . $file;
      if (!file_exists($file_path) || hash_file('sha512', $file_path) !== $hash) {
        $modified_files[] = $file_path;
      }
    }
    if (!feof($resource)) {
      $this->logger->error('Stream for resource closed prematurely.');
    }
    fclose($resource);
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
    foreach ($extensions as $extension_name => $info) {
      $url = $this->buildUrl($extension_name, $info);
      yield $this->getPromise($url);
    }
  }

  /**
   * Get a promise.
   *
   * @param string $url
   *   The URL.
   *
   * @return \GuzzleHttp\Promise\PromiseInterface
   *   The promise.
   */
  protected function getPromise($url) {
    return $this->httpClient->requestAsync('GET', $url, [
      'stream' => TRUE,
      'read_timeout' => 30,
    ])
      ->then(function (ResponseInterface $response) {
        return $response->getBody()->detach();
      });
  }

  /**
   * Build an extension's hash file URL.
   *
   * @param string $extension_name
   *   The extension name.
   * @param array $info
   *   The extension's info.
   *
   * @return string
   *   The URL endpoint with for an extension.
   */
  protected function buildUrl($extension_name, array $info) {
    $version = $this->getExtensionVersion($extension_name, $info);
    $project_name = $this->getProjectName($extension_name, $info);
    $hash_name = $this->getHashName($info);
    $uri = ltrim($this->configFactory->get('automatic_updates.settings')->get('download_uri'), '/');
    return Url::fromUri($uri . "/$project_name/$version/$hash_name")->toString();
  }

  /**
   * Get the extension version.
   *
   * @param string $extension_name
   *   The extension name.
   * @param array $info
   *   The extension's info.
   *
   * @return string|null
   *   The version or NULL if undefined.
   */
  protected function getExtensionVersion($extension_name, array $info) {
    $version = isset($info['version']) ? $info['version'] : NULL;
    // TODO: consider using ocramius/package-versions to discover the installed
    // version from composer.lock.
    // See https://www.drupal.org/project/automatic_updates/issues/3054002
    return $version;
  }

  /**
   * Get the extension's project name.
   *
   * @param string $extension_name
   *   The extension name.
   * @param array $info
   *   The extension's info.
   *
   * @return string
   *   The project name or fallback to extension name if project is undefined.
   */
  protected function getProjectName($extension_name, array $info) {
    $project_name = isset($info['project']) ? $info['project'] : $extension_name;
    // TODO: parse the composer.json for the name if it isn't set in info.
    // See https://www.drupal.org/project/automatic_updates/issues/3054002.
    return $project_name;
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
    $hash_name = 'SHA512SUMS';
    if (isset($info['project'])) {
      $hash_name .= '-package';
    }
    return $hash_name;
  }

}
