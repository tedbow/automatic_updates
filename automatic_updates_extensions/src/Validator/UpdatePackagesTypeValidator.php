<?php

namespace Drupal\automatic_updates_extensions\Validator;

use Drupal\automatic_updates_extensions\ExtensionUpdater;
use Drupal\Core\Extension\Extension;
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
    if ($stage instanceof ExtensionUpdater) {
      $package_versions = $stage->getPackageVersions();
      $invalid_projects = [];
      $extension_list = array_merge($this->themeList->getList(), $this->moduleList->getList());
      $project_info = new ProjectInfo();
      $all_projects = array_map(
        function (Extension $extension) use ($project_info): string {
          return $project_info->getProjectName($extension);
        },
        $extension_list
      );
      foreach (['production', 'dev'] as $package_type) {
        foreach ($package_versions[$package_type] as $package => $version) {
          $update_project = str_replace('drupal/', '', $package);
          if ($update_project === 'drupal' || !in_array($update_project, $all_projects)) {
            $invalid_projects[] = $update_project;
          }
        }
      }
      if ($invalid_projects) {
        $event->addError($invalid_projects, $this->t('Only Drupal Modules or Drupal Themes can be updated, therefore the following projects cannot be updated:'));
      }
    }
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
