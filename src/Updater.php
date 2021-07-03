<?php


namespace Drupal\automatic_updates;


use Composer\Autoload\ClassLoader;
use PhpTuf\ComposerStager\Domain\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Cleaner;
use PhpTuf\ComposerStager\Domain\CleanerInterface;
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
   * Updater constructor.
   */
  public function __construct(BeginnerInterface $beginner, StagerInterface $stager, CleanerInterface $cleaner) {
    $this->beginner = $beginner;
    $this->stager = $stager;
    $this->cleaner = $cleaner;
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

  public function stage(string $version): void {
    $command = [
      'require',
      "drupal/core-recommended:$version",
      '--update-with-all-dependencies',
      ];
    //$username = posix_getpwuid(posix_geteuid())['name'];
    $path = apache_getenv('PATH');
    $path .= ":/usr/local/bin";
    apache_setenv('PATH', $path);
    //putenv("PATH=$path");
    /*$home = static::getStageDirectory() . "/vendor/bin/composer";
    $home_dir = '/Users/ted.bowman/sites/wdev/d8_stager/composer_home';
    apache_setenv('COMPOSER_HOME', $home_dir);*/

    putenv("COMPOSER_HOME=$home_dir");
    $this->stager->stage($command, static::getStageDirectory());
  }

  public function commit(): void {

  }

  public function clean(): void {
    if (is_dir(static::getStageDirectory())) {
      $this->cleaner->clean(static::getStageDirectory());
    }
  }

}