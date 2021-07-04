<?php


namespace Drupal\automatic_updates;


use Composer\Autoload\ClassLoader;
use PhpTuf\ComposerStager\Domain\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Cleaner;
use PhpTuf\ComposerStager\Domain\CleanerInterface;
use PhpTuf\ComposerStager\Domain\CommitterInterface;
use PhpTuf\ComposerStager\Domain\StagerInterface;

class Updater {

  /**
   * @var \PhpTuf\ComposerStager\Domain\BeginnerInterface
   */
  protected $beginner;

  /**
   * @var \PhpTuf\ComposerStager\Domain\StagerInterface
   */
  protected $stager;

  /**
   * @var \PhpTuf\ComposerStager\Domain\CleanerInterface
   */
  protected $cleaner;

  /**
   * @var \PhpTuf\ComposerStager\Domain\CommitterInterface
   */
  protected $committer;


  /**
   * Updater constructor.
   */
  public function __construct(BeginnerInterface $beginner, StagerInterface $stager, CleanerInterface $cleaner, CommitterInterface $committer) {
    $this->beginner = $beginner;
    $this->stager = $stager;
    $this->cleaner = $cleaner;
    $this->committer = $committer;
  }

  private static function getVendorDirectory(): string {
    try {
      $class_loader_reflection = new \ReflectionClass(ClassLoader::class);
    }
    catch (\ReflectionException $e) {
      throw new \Exception('Cannot find class loader');
    }
    return dirname($class_loader_reflection->getFileName(), 2);
  }

  public function hasActiveUpdate(): bool {
    $staged_dir = static::getStageDirectory();
    if (is_dir($staged_dir)) {
      return TRUE;
    }
    return FALSE;
  }

  protected static function getStageDirectory(): string {
    return static::getVendorDirectory() . '/../.automatic_updates_stage';
  }

  protected static function getActiveDirectory(): string {
    return static::getVendorDirectory() . '/..';
  }

  public function begin(): void {
    $this->beginner->begin(static::getActiveDirectory(), static::getStageDirectory());
  }

  /**
   * @param array $project_versions
   *   The keys are project names and the values are the project versions.
   */
  public function stageVersions(array $project_versions): void {
    $packages = [];
    foreach ($project_versions as $project => $project_version) {
      if ($project === 'drupal') {
        // @todo Determine when to use drupal/core-recommended and when to use
        //   drupal/core
        $packages[] = "drupal/core-recommended:$project_version";
      }
      else {
        $packages[] = "drupal/$project:$project_version";
      }
    }
    $this->stagePackages($packages);

  }

  public function stagePackages(array $packages): void {
    $command = array_merge(['require'], $packages);
    $command[] = '--update-with-all-dependencies';
    $this->stageCommand($command);
  }


  public function commit(): void {
    $this->committer->commit(static::getStageDirectory(), static::getActiveDirectory());
  }

  public function clean(): void {
    if (is_dir(static::getStageDirectory())) {
      $this->cleaner->clean(static::getStageDirectory());
    }
  }

  /**
   * @param array $command
   */
  protected function stageCommand(array $command): void {
    $path = apache_getenv('PATH');
    $path .= ":/usr/local/bin";
    apache_setenv('PATH', $path);
    $this->stager->stage($command, static::getStageDirectory());
  }

}