<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use PhpTuf\ComposerStager\API\Core\BeginnerInterface;
use PhpTuf\ComposerStager\API\Core\CommitterInterface;
use PhpTuf\ComposerStager\API\Core\StagerInterface;
use PhpTuf\ComposerStager\API\Path\Factory\PathFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

/**
 * Prevents any module from being uninstalled if update is in process.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class PackageManagerUninstallValidator implements ModuleUninstallValidatorInterface, ContainerAwareInterface {

  use ContainerAwareTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function validate($module) {
    $stage = new class(
      $this->container->get(PathLocator::class),
      $this->container->get(BeginnerInterface::class),
      $this->container->get(StagerInterface::class),
      $this->container->get(CommitterInterface::class),
      $this->container->get('file_system'),
      $this->container->get('event_dispatcher'),
      $this->container->get('tempstore.shared'),
      $this->container->get('datetime.time'),
      $this->container->get(PathFactoryInterface::class),
      $this->container->get(FailureMarker::class)) extends StageBase {};
    if ($stage->isAvailable() || !$stage->isApplying()) {
      return [];
    }
    if ($stage->isApplying()) {
      $reasons[] = $this->t('Modules cannot be uninstalled while Package Manager is applying staged changes to the active code base.');
    }
    return $reasons;
  }

}
