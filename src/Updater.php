<?php


namespace Drupal\automatic_updates;


use Composer\Autoload\ClassLoader;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use PhpTuf\ComposerStager\Domain\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\CleanerInterface;
use PhpTuf\ComposerStager\Domain\CommitterInterface;
use PhpTuf\ComposerStager\Domain\StagerInterface;

class Updater {

  use StringTranslationTrait;
  private const STATE_KEY = 'AUTOMATIC_UPDATES_CURRENT';

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
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;


  /**
   * Updater constructor.
   */
  public function __construct(StateInterface $state, TranslationInterface $translation, BeginnerInterface $beginner, StagerInterface $stager, CleanerInterface $cleaner, CommitterInterface $committer) {
    $this->state = $state;
    $this->beginner = $beginner;
    $this->stager = $stager;
    $this->cleaner = $cleaner;
    $this->committer = $committer;
    $this->setStringTranslation($translation);
  }

  private static function getDrupalPackagesForComposerLock(string $composer_json_file): array {
    $composer_json = file_get_contents($composer_json_file);
    $drupal_packages = [];
    if ($composer_json) {
      $data = json_decode($composer_json, TRUE);
      $packages = $data['packages'];
      foreach ($packages as $package) {
        if (in_array($package['type'], ['drupal-module', 'drupal-theme', 'drupal-core']) || $package['name'] === 'drupal/core-recommended') {
          $drupal_packages[$package['name']] = $package;
        }
      }
    }
    else {
      throw new \RuntimeException("Composer.json files not found.");
    }
    return $drupal_packages;
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

  /**
   * @return string
   *   A key for this stage update process.
   */
  public function begin(): string {
    $stage_key = $this->createActiveStage();
    $this->beginner->begin(static::getActiveDirectory(), static::getStageDirectory());
    return $stage_key;
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
    // Store the expected packages to confirm no other drupal packages were updated.
    $current = $this->state->get(static::STATE_KEY);
    $current['packages'] = $packages;
    $this->state->set(self::STATE_KEY, $current);
  }


  public function commit(): void {
    $this->committer->commit(static::getStageDirectory(), static::getActiveDirectory());
  }

  public function clean(): void {
    if (is_dir(static::getStageDirectory())) {
      $this->cleaner->clean(static::getStageDirectory());
    }
    $this->state->delete(static::STATE_KEY);
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

  private function createActiveStage(): string {
    $value = static::STATE_KEY . microtime();
    $this->state->set(static::STATE_KEY, ['id' => $value]);
    return $value;
  }

  public function getActiveStagerKey(): ?string {
    if ($current = $this->state->get(static::STATE_KEY)) {
      return $current['id'];
    }
    return NULL;
  }

  /**
   * Validates that an update was performed as expected.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Error messages.
   *
   * @todo We will probably need a more complex system for validating updates.
   *   We may want to expand the Readiness Check system that is being worked on
   *   in core to handle different stages. For instance this might be the
   *   "staged" stage. https://www.drupal.org/i/3162655
   *   Other ideas for what might need to be validated
   *   1. Duplicate modules: For installing new modules, if there is module that
   *      was not installed via Composer and it was not in /modules/contrib/
   *      then it would not be overwritten by the newly installed module.
   *      Therefore after the install they may have duplicate .info.yml files.
   *      Duplicates be allow if 1 is under /modules and the other in
   *      /site/-/modules.
   *   2. If exact version are specified in an install or update confirm with
   *      Drupal update XML that staged versions of all drupal projects are
   *      supported and secure.
   */
  public function validateStaged(): array {
    $error_messages = [];
    $current = $this->state->get(static::STATE_KEY);
    $expected_package_changes = $current['packages'];
    $expected_changes = [];
    foreach ($expected_package_changes as $expected_package_change) {
      $parts = explode(':', $expected_package_change);
      $expected_changes[$parts[0]] = $parts[1];
    }
    $active_drupal_packages = static::getDrupalPackagesForComposerLock(static::getActiveDirectory() . "/composer.lock");
    $staged_drupal_packages = static::getDrupalPackagesForComposerLock(static::getStageDirectory() . "/composer.lock");
    foreach ($staged_drupal_packages as $package_name => $staged_drupal_package) {
      if (!isset($active_drupal_packages[$package_name])) {
        $error_messages[] = $this->t("Unexpect new @type package added @name.", ['@type' => $staged_drupal_package['type'], '@name' => $staged_drupal_package['name']]);
        continue;
      }
      $active_drupal_package = $active_drupal_packages[$package_name];
      if ($staged_drupal_package['version'] !== $active_drupal_package['version']) {
        if (array_key_exists($package_name, $expected_changes)) {
          if ($expected_changes[$package_name] !== $staged_drupal_package['version']) {
            $error_messages[] = $this->t(
              '@type package @name updated to version @staged_version instead of the expected version @expected_version.',
              [
                '@type' => $staged_drupal_package['type'],
                '@name' => $staged_drupal_package['name'],
                '@staged_version' => $staged_drupal_package['version'],
                '@expected_version' => $expected_changes[$package_name],
              ]
            );
            continue;
          }
          continue;
        }
        else {
          $error_messages[] = $this->t(
            "Unexpected update @type package @name from @active_version to  @staged_version.",
            [
              '@type' => $staged_drupal_package['type'],
              '@name' => $staged_drupal_package['name'],
              '@staged_version' => $staged_drupal_package['version'],
              '@active_version' => $active_drupal_package['version'],
            ]
          );
          continue;
        }
      }
      else {
        // version did not change.
        if (array_key_exists($package_name, $expected_changes)) {
          $error_messages[] = $this->t(
            "Expected update @type package @name to @expected_version.",
            [
              '@type' => $staged_drupal_package['type'],
              '@name' => $staged_drupal_package['name'],
              '@expected_version' => $expected_changes[$package_name],
            ]
          );
          continue;
        }
      }
    }
    return $error_messages;
  }

}