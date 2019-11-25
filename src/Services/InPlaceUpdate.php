<?php

namespace Drupal\automatic_updates\Services;

use Drupal\automatic_updates\ProjectInfoTrait;
use Drupal\automatic_updates\ReadinessChecker\ReadinessCheckerManagerInterface;
use Drupal\Component\FileSystem\FileSystem;
use Drupal\Core\Archiver\ArchiverInterface;
use Drupal\Core\Archiver\ArchiverManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\Signify\ChecksumList;
use Drupal\Signify\FailedCheckumFilter;
use Drupal\Signify\Verifier;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Class to apply in-place updates.
 */
class InPlaceUpdate implements UpdateInterface {
  use ProjectInfoTrait;

  /**
   * The manifest file that lists all file deletions.
   */
  const DELETION_MANIFEST = 'DELETION_MANIFEST.txt';

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
   * @param string $app_root
   *   The app root.
   */
  public function __construct(LoggerInterface $logger, ArchiverManager $archive_manager, ConfigFactoryInterface $config_factory, FileSystemInterface $file_system, ClientInterface $http_client, $app_root) {
    $this->logger = $logger;
    $this->archiveManager = $archive_manager;
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->rootPath = (string) $app_root;
    $this->vendorPath = $this->rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;
    $project_root = drupal_get_path('module', 'automatic_updates');
    require_once $project_root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
  }

  /**
   * {@inheritdoc}
   */
  public function update($project_name, $project_type, $from_version, $to_version) {
    // Bail immediately on updates if error category checks fail.
    /** @var \Drupal\automatic_updates\ReadinessChecker\ReadinessCheckerManagerInterface $readiness_check_manager */
    $checker = \Drupal::service('automatic_updates.readiness_checker');
    if ($checker->run(ReadinessCheckerManagerInterface::ERROR)) {
      return FALSE;
    }
    $success = FALSE;
    if ($project_name === 'drupal') {
      $project_root = $this->rootPath;
    }
    else {
      $project_root = drupal_get_path($project_type, $project_name);
    }
    if ($archive = $this->getArchive($project_name, $from_version, $to_version)) {
      $modified = $this->checkModifiedFiles($project_name, $project_type, $archive);
      if (!$modified && $this->backup($archive, $project_root)) {
        $success = $this->processUpdate($archive, $project_root);
        if (!$success) {
          $this->rollback($project_root);
        }
        else {
          $this->clearOpcodeCache();
        }
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
    $quasi_patch = $this->getQuasiPatchFileName($project_name, $from_version, $to_version);
    $url = $this->buildUrl($project_name, $quasi_patch);
    $temp_directory = FileSystem::getOsTemporaryDirectory() . DIRECTORY_SEPARATOR;
    $destination = $this->fileSystem->getDestinationFilename($temp_directory . $quasi_patch, FileSystemInterface::EXISTS_REPLACE);
    $this->doGetResource($url, $destination);
    $csig_file = $quasi_patch . '.csig';
    $csig_url = $this->buildUrl($project_name, $csig_file);
    $csig_destination = $this->fileSystem->getDestinationFilename(FileSystem::getOsTemporaryDirectory() . DIRECTORY_SEPARATOR . $csig_file, FileSystemInterface::EXISTS_REPLACE);
    $this->doGetResource($csig_url, $csig_destination);
    $csig = file_get_contents($csig_destination);
    $this->validateArchive($temp_directory, $csig);
    return $this->archiveManager->getInstance(['filepath' => $destination]);
  }

  /**
   * Check if files are modified before applying updates.
   *
   * @param string $project_name
   *   The project name.
   * @param string $project_type
   *   The project type.
   * @param \Drupal\Core\Archiver\ArchiverInterface $archive
   *   The archive.
   *
   * @return bool
   *   Return TRUE if modified files exist, FALSE otherwise.
   */
  protected function checkModifiedFiles($project_name, $project_type, ArchiverInterface $archive) {
    if ($project_type === 'core') {
      $project_type = 'module';
    }
    $extensions = $this->getInfos($project_type);
    /** @var \Drupal\automatic_updates\Services\ModifiedFilesInterface $modified_files */
    $modified_files = \Drupal::service('automatic_updates.modified_files');
    try {
      $files = iterator_to_array($modified_files->getModifiedFiles([$extensions[$project_name]], TRUE));
    }
    catch (RequestException $exception) {
      // While not strictly true that there are modified files, we can't be sure
      // there aren't any. So assume the worst.
      return TRUE;
    }
    $files = array_unique($files);
    $archive_files = $archive->listContents();
    foreach ($archive_files as $index => &$archive_file) {
      $skipped_files = [
        self::DELETION_MANIFEST,
      ];
      // Skip certain files and all directories.
      if (in_array($archive_file, $skipped_files, TRUE) || substr($archive_file, -1) === '/') {
        unset($archive_files[$index]);
        continue;
      }
      $this->stripFileDirectoryPath($archive_file);
    }
    unset($archive_file);
    if ($intersection = array_intersect($files, $archive_files)) {
      $this->logger->error('Can not update because %count files are modified: %paths', [
        '%count' => count($intersection),
        '%paths' => implode(', ', $intersection),
      ]);
      return TRUE;
    }
    return FALSE;
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
  protected function doGetResource($url, $destination, $delay = NULL) {
    try {
      $this->httpClient->get($url, [
        'sink' => $destination,
        'delay' => $delay,
        // Some of the core quasi-patch zip files are large, increase timeout.
        'timeout' => 120,
      ]);
    }
    catch (RequestException $exception) {
      $response = $exception->getResponse();
      if ($response && $response->getStatusCode() === 429) {
        $delay = 1000 * (isset($response->getHeader('Retry-After')[0]) ? $response->getHeader('Retry-After')[0] : 10);
        $this->doGetResource($url, $destination, $delay);
      }
      else {
        $this->logger->error('Retrieval of "@url" failed with: @message', [
          '@url' => $exception->getRequest()->getUri(),
          '@message' => $exception->getMessage(),
        ]);
        throw $exception;
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
   * Validate the downloaded archive.
   *
   * @param string $directory
   *   The location of the downloaded archive.
   * @param string $csig
   *   The CSIG contents.
   */
  protected function validateArchive($directory, $csig) {
    $module_path = drupal_get_path('module', 'automatic_updates');
    $key = file_get_contents($module_path . '/artifacts/keys/root.pub');
    $verifier = new Verifier($key);
    $files = $verifier->verifyCsigMessage($csig);
    $checksums = new ChecksumList($files, TRUE);
    $failed_checksums = new FailedCheckumFilter($checksums, $directory);
    if (iterator_count($failed_checksums)) {
      throw new \RuntimeException('The downloaded files did not match what was expected.');
    }
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
    if (strpos($file, self::ARCHIVE_DIRECTORY) === 0) {
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
    $filter = static function ($file, $file_name, $iterator) {
      /** @var \SplFileInfo $file */
      /** @var string $file_name */
      /** @var \RecursiveDirectoryIterator $iterator */
      if ($iterator->hasChildren() && $file->getFilename() !== '.git') {
        return TRUE;
      }
      $skipped_files = [
        self::DELETION_MANIFEST,
      ];
      return $file->isFile() && !in_array($file->getFilename(), $skipped_files, TRUE);
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
    return Url::fromUri("$uri/$project_name/$file_name")->toString();
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
    if (strpos($file_path, 'vendor' . DIRECTORY_SEPARATOR) === 0) {
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
      $this->tempDirectory = $this->fileSystem->createFilename('automatic_updates-update', FileSystem::getOsTemporaryDirectory());
      $this->fileSystem->prepareDirectory($this->tempDirectory, FileSystemInterface::CREATE_DIRECTORY);
      $this->tempDirectory .= DIRECTORY_SEPARATOR;
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

  /**
   * Clear Opcode cache on successful update.
   */
  protected function clearOpcodeCache() {
    if (function_exists('opcache_reset')) {
      opcache_reset();
    }
  }

}
