<?php


namespace Drupal\automatic_updates;


use Composer\Autoload\ClassLoader;
use PhpTuf\ComposerStager\Domain\Beginner;
use PhpTuf\ComposerStager\Domain\BeginnerInterface;
use PhpTuf\ComposerStager\Infrastructure\Process\ExecutableFinder;
use PhpTuf\ComposerStager\Infrastructure\Process\FileCopier;
use PhpTuf\ComposerStager\Infrastructure\Process\Runner\RsyncRunner;

class Updater {

  /**
   * @var \PhpTuf\ComposerStager\Domain\BeginnerInterface
   */
  protected $beginner;


  /**
   * Updater constructor.
   */
  public function __construct(BeginnerInterface $beginner) {
    $this->beginner = $beginner;
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

  public function stage(): void {

  }

  public function commit(): void {

  }

  public function clean(): void {

  }

}