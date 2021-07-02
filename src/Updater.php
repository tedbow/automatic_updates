<?php


namespace Drupal\automatic_updates;


use Composer\Autoload\ClassLoader;
use PhpTuf\ComposerStager\Domain\BeginnerInterface;
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
   * Updater constructor.
   */
  public function __construct(BeginnerInterface $beginner, StagerInterface $stager) {
    $this->beginner = $beginner;
    $this->stager = $stager;
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
      "drupal/core-recommended:\"$version\"",
      '--update-with-all-dependencies',
      ];
    putenv('COMPOSER_HOME=/Users/ted.bowman/.composer');
    $this->stager->stage($command, static::getStageDirectory());
  }

  public function commit(): void {

  }

  public function clean(): void {

  }

}