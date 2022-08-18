<?php

namespace Drupal\automatic_updates_extensions\Validator;

use Drupal\automatic_updates_extensions\ExtensionUpdater;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Utility\ProjectInfo;
use Drupal\package_manager\Event\PreCreateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates the type of updated packages.
 */
class UpdatePackagesTypeValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The module list service.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleList;

  /**
   * The theme list service.
   *
   * @var \Drupal\Core\Extension\ThemeExtensionList
   */
  protected $themeList;

  /**
   * Constructs a UpdatePackagesTypeValidator object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_list
   *   The module list service.
   * @param \Drupal\Core\Extension\ThemeExtensionList $theme_list
   *   The theme list service.
   */
  public function __construct(TranslationInterface $translation, ModuleExtensionList $module_list, ThemeExtensionList $theme_list) {
    $this->setStringTranslation($translation);
    $this->moduleList = $module_list;
    $this->themeList = $theme_list;
  }

  /**
   * Validates that updated packages are only modules or themes.
   *
   * @param \Drupal\package_manager\Event\PreCreateEvent $event
   *   The event object.
   */
  public function checkPackagesAreOnlyThemesOrModules(PreCreateEvent $event): void {
    $stage = $event->getStage();
    if (!$stage instanceof ExtensionUpdater) {
      return;
    }

    $invalid_projects = [];
    $all_projects = $this->getInstalledProjectNames();

    foreach ($stage->getPackageVersions() as $group) {
      foreach (array_keys($group) as $package) {
        // @todo Use
        //   \Drupal\package_manager\ComposerUtility::getProjectForPackage() to
        //   determine the project name in https://www.drupal.org/i/3304142.
        $update_project = str_replace('drupal/', '', $package);
        if ($update_project === 'drupal' || !in_array($update_project, $all_projects, TRUE)) {
          $invalid_projects[] = $update_project;
        }
      }
    }
    if ($invalid_projects) {
      $event->addError($invalid_projects, $this->t('The following projects cannot be updated because they are not Drupal modules or themes:'));
    }
  }

  /**
   * Returns a list of all available modules and themes' project names.
   *
   * @return string[]
   *   The project names of all available modules and themes.
   */
  private function getInstalledProjectNames(): array {
    $extension_list = array_merge($this->themeList->getList(), $this->moduleList->getList());
    $map = \Closure::fromCallable([new ProjectInfo(), 'getProjectName']);
    return array_map($map, $extension_list);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'checkPackagesAreOnlyThemesOrModules',
    ];
  }

}
