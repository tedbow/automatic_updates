<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Updater;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that there are no database updates in a staged update.
 *
 * @internal
 *   This class is an internal part of the module's update handling and
 *   should not be used by external code.
 */
class StagedDatabaseUpdateValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * The module list service.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleList;

  /**
   * Constructs a StagedDatabaseUpdateValidator object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_list
   *   The module list service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   */
  public function __construct(PathLocator $path_locator, ModuleExtensionList $module_list, TranslationInterface $translation) {
    $this->pathLocator = $path_locator;
    $this->moduleList = $module_list;
    $this->setStringTranslation($translation);
  }

  /**
   * Checks that the staged update does not have changes to its install files.
   *
   * @param \Drupal\package_manager\Event\PreApplyEvent $event
   *   The event object.
   */
  public function checkUpdateHooks(PreApplyEvent $event): void {
    $stage = $event->getStage();
    if (!$stage instanceof CronUpdater) {
      return;
    }

    $invalid_modules = [];
    // Although \Drupal\automatic_updates\Validator\StagedProjectsValidator
    // should prevent non-core modules from being added, updated, or removed in
    // the staging area, we check all installed modules so as not to rely on the
    // presence of StagedProjectsValidator.
    foreach ($this->moduleList->getAllInstalledInfo() as $name => $info) {
      if ($this->hasStagedUpdates($stage, $this->moduleList->get($name))) {
        $invalid_modules[] = $info['name'];
      }
    }

    if ($invalid_modules) {
      $event->addError($invalid_modules, $this->t('The update cannot proceed because possible database updates have been detected in the following modules.'));
    }
  }

  /**
   * Determines if a staged extension has changed update functions.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater which is controlling the update process.
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension to check.
   *
   * @return bool
   *   TRUE if the staged copy of the extension has changed update functions
   *   compared to the active copy, FALSE otherwise.
   *
   * @todo Use a more sophisticated method to detect changes in the staged
   *   extension. Right now, we just compare hashes of the .install and
   *   .post_update.php files in both copies of the given extension, but this
   *   will cause false positives for changes to comments, whitespace, or
   *   runtime code like requirements checks. It would be preferable to use a
   *   static analyzer to detect new or changed functions that are actually
   *   executed during an update. No matter what, this method must NEVER cause
   *   false negatives, since that could result in code which is incompatible
   *   with the current database schema being copied to the active directory.
   *
   * @see https://www.drupal.org/project/automatic_updates/issues/3253828
   */
  public function hasStagedUpdates(Updater $updater, Extension $extension): bool {
    $active_dir = $this->pathLocator->getProjectRoot();
    $stage_dir = $updater->getStageDirectory();

    $web_root = $this->pathLocator->getWebRoot();
    if ($web_root) {
      $active_dir .= DIRECTORY_SEPARATOR . $web_root;
      $stage_dir .= DIRECTORY_SEPARATOR . $web_root;
    }

    $active_hashes = $this->getHashes($active_dir, $extension);
    $staged_hashes = $this->getHashes($stage_dir, $extension);

    return $active_hashes !== $staged_hashes;
  }

  /**
   * Returns hashes of the .install and .post-update.php files for a module.
   *
   * @param string $root_dir
   *   The root directory of the Drupal code base.
   * @param \Drupal\Core\Extension\Extension $module
   *   The module to check.
   *
   * @return string[]
   *   The hashes of the module's .install and .post_update.php files, in that
   *   order, if they exist. The array will be keyed by file extension.
   */
  protected function getHashes(string $root_dir, Extension $module): array {
    $path = implode(DIRECTORY_SEPARATOR, [
      $root_dir,
      $module->getPath(),
      $module->getName(),
    ]);
    $hashes = [];

    foreach (['.install', '.post_update.php'] as $suffix) {
      $file = $path . $suffix;

      if (file_exists($file)) {
        $hashes[$suffix] = hash_file('sha256', $file);
      }
    }
    return $hashes;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreApplyEvent::class => 'checkUpdateHooks',
    ];
  }

}
