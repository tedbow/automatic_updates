<?php

namespace Drupal\automatic_updates\Services;

use Drupal\Core\Archiver\ArchiverInterface;
use Drupal\Core\Archiver\ArchiverManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use DrupalFinder\DrupalFinder;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Class to apply in-place updates.
 */
class InPlaceUpdate implements UpdateInterface {

  /**
   * The manifest file that lists all file deletions.
   */
  const DELETION_MANIFEST = 'DELETION_MANIFEST.txt';

  /**
   * The checksum file with hashes of archive file contents.
   */
  const CHECKSUM_LIST = 'checksumlist.csig';

  /**
   * The directory inside the archive for file additions and modifications.
   */
  const ARCHIVE_DIRECTORY = 'files/';

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The archive manager.
   *
   * @var \Drupal\Core\Archiver\ArchiverManager
   */
  protected $archiveManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The HTTP client service.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The root file path.
   *
   * @var string
   */
  protected $rootPath;

  /**
   * The vendor file path.
   *
   * @var string
   */
  protected $vendorPath;

  /**
   * The folder where files are backed up.
   *
   * @var string
   */
  protected $backup;

  /**
   * The temporary extract directory.
   *
   * @var string
   */
  protected $tempDirectory;

  /**
   * Constructs an InPlaceUpdate.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Archiver\ArchiverManager $archive_manager
   *   The archive manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The filesystem service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   * @param \DrupalFinder\DrupalFinder $drupal_finder
   *   The Drupal finder service.
   */
  public function __construct(LoggerInterface $logger, ArchiverManager $archive_manager, ConfigFactoryInterface $config_factory, FileSystemInterface $file_system, ClientInterface $http_client, DrupalFinder $drupal_finder) {
    $this->logger = $logger;
    $this->archiveManager = $archive_manager;
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $drupal_finder->locateRoot(getcwd());
    $this->rootPath = $drupal_finder->getDrupalRoot();
    $this->vendorPath = rtrim($drupal_finder->getVendorDir(), '/\\') . DIRECTORY_SEPARATOR;
  }

  /**
   * {@inheritdoc}
   */
  public function update($project_name, $project_type, $from_version, $to_version) {
    $success = FALSE;
    if ($project_name === 'drupal') {
      $project_root = $this->rootPath;
    }
    else {
      $project_root = drupal_get_path($project_type, $project_name);
    }
    if ($archive = $this->getArchive($project_name, $from_version, $to_version)) {
      if ($this->backup($archive, $project_root)) {
        $success = $this->processUpdate($archive, $project_root);
      }
      if (!$success) {
        $this->rollback($project_root);
      }
    }
    return $success;
  }

  /**
   * Get an archive with the quasi-patch contents.
   *
   * @param string $project_name
   *   The project name.
   * @param string $from_version
   *   The current project version.
   * @param string $to_version
   *   The desired next project version.
   *
   * @return \Drupal\Core\Archiver\ArchiverInterface|null
   *   The archive or NULL if download fails.
   */
  protected function getArchive($project_name, $from_version, $to_version) {
    $url = $this->buildUrl($project_name, $this->getQuasiPatchFileName($project_name, $from_version, $to_version));
    $destination = $this->fileSystem->realpath($this->fileSystem->getDestinationFilename("temporary://$project_name.zip", FileSystemInterface::EXISTS_RENAME));
    $this->doGetArchive($url, $destination);
    /** @var \Drupal\Core\Archiver\ArchiverInterface $archive */
    return $this->archiveManager->getInstance(['filepath' => $destination]);
  }

  /**
   * Perform retrieval of archive, with delay if archive is still being created.
   *
   * @param string $url
   *   The URL to retrieve.
   * @param string $destination
   *   The destination to download the archive.
   * @param null|int $delay
   *   The delay, defaults to NULL.
   */
  protected function doGetArchive($url, $destination, $delay = NULL) {
    try {
      $this->httpClient->get($url, [
        'sink' => $destination,
        'delay' => $delay,
      ]);
    }
    catch (RequestException $exception) {
      if ($exception->getResponse()->getStatusCode() === 429 && ($retry = $exception->getResponse()->getHeader('Retry-After'))) {
        $this->doGetArchive($url, $destination, $retry[0] * 1000);
      }
      else {
        $this->logger->error('Retrieval of "@url" failed with: @message', [
          '@url' => $url,
          '@message' => $exception->getMessage(),
        ]);
      }
    }
  }

  /**
   * Process update.
   *
   * @param \Drupal\Core\Archiver\ArchiverInterface $archive
   *   The archive.
   * @param string $project_root
   *   The project root directory.
   *
   * @return bool
   *   Return TRUE if update succeeds, FALSE otherwise.
   */
  protected function processUpdate(ArchiverInterface $archive, $project_root) {
    $archive->extract($this->getTempDirectory());
    foreach ($this->getFilesList($this->getTempDirectory()) as $file) {
      $file_real_path = $this->getFileRealPath($file);
      $file_path = substr($file_real_path, strlen($this->getTempDirectory() . self::ARCHIVE_DIRECTORY));
      $project_real_path = $this->getProjectRealPath($file_path, $project_root);
      try {
        $directory = dirname($project_real_path);
        $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
        $this->fileSystem->copy($file_real_path, $project_real_path, FileSystemInterface::EXISTS_REPLACE);
        $this->logger->info('"@file" was updated.', ['@file' => $project_real_path]);
      }
      catch (FileException $exception) {
        return FALSE;
      }
    }
    foreach ($this->getDeletions() as $deletion) {
      try {
        $file_deletion = $this->getProjectRealPath($deletion, $project_root);
        $this->fileSystem->delete($file_deletion);
        $this->logger->info('"@file" was deleted.', ['@file' => $file_deletion]);
      }
      catch (FileException $exception) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Backup before an update.
   *
   * @param \Drupal\Core\Archiver\ArchiverInterface $archive
   *   The archive.
   * @param string $project_root
   *   The project root directory.
   *
   * @return bool
   *   Return TRUE if backup succeeds, FALSE otherwise.
   */
  protected function backup(ArchiverInterface $archive, $project_root) {
    $backup = $this->fileSystem->createFilename('automatic_updates-backup', 'temporary://');
    $this->fileSystem->prepareDirectory($backup);
    $this->backup = $this->fileSystem->realpath($backup) . DIRECTORY_SEPARATOR;
    if (!$this->backup) {
      return FALSE;
    }
    foreach ($archive->listContents() as $file) {
      // Ignore files that aren't in the files directory.
      if (!$this->stripFileDirectoryPath($file)) {
        continue;
      }
      $success = $this->doBackup($file, $project_root);
      if (!$success) {
        return FALSE;
      }
    }
    $archive->extract($this->getTempDirectory(), [self::DELETION_MANIFEST]);
    foreach ($this->getDeletions() as $deletion) {
      $success = $this->doBackup($deletion, $project_root);
      if (!$success) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Remove the files directory path from files from the archive.
   *
   * @param string $file
   *   The file path.
   *
   * @return bool
   *   TRUE if path was removed, else FALSE.
   */
  protected function stripFileDirectoryPath(&$file) {
    if (substr($file, 0, 6) === self::ARCHIVE_DIRECTORY) {
      $file = substr($file, 6);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Execute file backup.
   *
   * @param string $file
   *   The file to backup.
   * @param string $project_root
   *   The project root directory.
   *
   * @return bool
   *   Return TRUE if backup succeeds, FALSE otherwise.
   */
  protected function doBackup($file, $project_root) {
    $directory = $this->backup . dirname($file);
    if (!file_exists($directory) && !$this->fileSystem->mkdir($directory, NULL, TRUE)) {
      return FALSE;
    }
    $project_real_path = $this->getProjectRealPath($file, $project_root);
    if (file_exists($project_real_path) && !is_dir($project_real_path)) {
      try {
        $this->fileSystem->copy($project_real_path, $this->backup . $file, FileSystemInterface::EXISTS_REPLACE);
        $this->logger->info('"@file" was backed up in preparation for an update.', ['@file' => $project_real_path]);
      }
      catch (FileException $exception) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Rollback after a failed update.
   *
   * @param string $project_root
   *   The project root directory.
   */
  protected function rollback($project_root) {
    if (!$this->backup) {
      return;
    }
    foreach ($this->getFilesList($this->backup) as $file) {
      $file_real_path = $this->getFileRealPath($file);
      $file_path = substr($file_real_path, strlen($this->backup));
      try {
        $this->fileSystem->copy($file_real_path, $this->getProjectRealPath($file_path, $project_root), FileSystemInterface::EXISTS_REPLACE);
        $this->logger->info('"@file" was restored due to failure(s) in applying update.', ['@file' => $file_path]);
      }
      catch (FileException $exception) {
        $this->logger->error('@file was not rolled back successfully.', ['@file' => $file_real_path]);
      }
    }
  }

  /**
   * Provide a recursive list of files, excluding directories.
   *
   * @param string $directory
   *   The directory to recurse for files.
   *
   * @return \RecursiveIteratorIterator|\SplFileInfo[]
   *   The iterator of SplFileInfos.
   */
  protected function getFilesList($directory) {
    $filter = function ($file, $file_name, $iterator) {
      /** @var \SplFileInfo $file */
      /** @var string $file_name */
      /** @var \RecursiveDirectoryIterator $iterator */
      if ($iterator->hasChildren() && !in_array($file->getFilename(), ['.git'], TRUE)) {
        return TRUE;
      }
      return $file->isFile() && !in_array($file->getFilename(), [self::DELETION_MANIFEST], TRUE);
    };

    $innerIterator = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
    return new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator($innerIterator, $filter));
  }

  /**
   * Build a project quasi-patch download URL.
   *
   * @param string $project_name
   *   The project name.
   * @param string $file_name
   *   The file name.
   *
   * @return string
   *   The URL endpoint with for an extension.
   */
  protected function buildUrl($project_name, $file_name) {
    $uri = $this->configFactory->get('automatic_updates.settings')->get('download_uri');
    return Url::fromUri($uri . "/$project_name/" . $file_name)->toString();
  }

  /**
   * Get the quasi-patch file name.
   *
   * @param string $project_name
   *   The project name.
   * @param string $from_version
   *   The current project version.
   * @param string $to_version
   *   The desired next project version.
   *
   * @return string
   *   The quasi-patch file name.
   */
  protected function getQuasiPatchFileName($project_name, $from_version, $to_version) {
    return "$project_name-$from_version-to-$to_version.zip";
  }

  /**
   * Get file real path.
   *
   * @param \SplFileInfo $file
   *   The file to retrieve the real path.
   *
   * @return string
   *   The file real path.
   */
  protected function getFileRealPath(\SplFileInfo $file) {
    $real_path = $file->getRealPath();
    if (!$real_path) {
      throw new FileException(sprintf('Could not get real path for "%s"', $file->getFilename()));
    }
    return $real_path;
  }

  /**
   * Get the real path of a file.
   *
   * @param string $file_path
   *   The file path.
   * @param string $project_root
   *   The project root directory.
   *
   * @return string
   *   The real path of a file.
   */
  protected function getProjectRealPath($file_path, $project_root) {
    if (substr($file_path, 0, 6) === 'vendor/') {
      return $this->vendorPath . substr($file_path, 7);
    }
    return rtrim($project_root, '/\\') . DIRECTORY_SEPARATOR . $file_path;
  }

  /**
   * Provides the temporary extraction directory.
   *
   * @return string
   *   The temporary directory.
   */
  protected function getTempDirectory() {
    if (!$this->tempDirectory) {
      $this->tempDirectory = $this->fileSystem->createFilename('automatic_updates-update', 'temporary://');
      $this->fileSystem->prepareDirectory($this->tempDirectory);
      $this->tempDirectory = $this->fileSystem->realpath($this->tempDirectory) . DIRECTORY_SEPARATOR;
    }
    return $this->tempDirectory;
  }

  /**
   * Get an iterator of files to delete.
   *
   * @return \ArrayIterator
   *   Iterator of files to delete.
   */
  protected function getDeletions() {
    $deletions = [];
    if (!file_exists($this->getTempDirectory() . self::DELETION_MANIFEST)) {
      return new \ArrayIterator($deletions);
    }
    $handle = fopen($this->getTempDirectory() . self::DELETION_MANIFEST, 'r');
    if ($handle) {
      while (($deletion = fgets($handle)) !== FALSE) {
        if ($result = trim($deletion)) {
          $deletions[] = $result;
        }
      }
      fclose($handle);
    }
    return new \ArrayIterator($deletions);
  }

}
