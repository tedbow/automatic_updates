<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates the configuration of the cweagans/composer-patches plugin.
 */
class ComposerPatchesValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function validateStagePreOperation(PreOperationStageEvent $event): void {
    $stage = $event->getStage();
    $composer = $stage->getActiveComposer();

    if (array_key_exists('cweagans/composer-patches', $composer->getInstalledPackages())) {
      $composer = $composer->getComposer();

      $extra = $composer->getPackage()->getExtra();
      if (empty($extra['composer-exit-on-patch-failure'])) {
        $event->addError([
          $this->t('The <code>cweagans/composer-patches</code> plugin is installed, but the <code>composer-exit-on-patch-failure</code> key is not set to <code>true</code> in the <code>extra</code> section of @file.', [
            // If composer.json is in a virtual file system, Composer will not
            // be able to resolve a real path for it.
            '@file' => $composer->getConfig()->getConfigSource()->getName() ?: 'composer.json',
          ]),
        ]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreCreateEvent::class => 'validateStagePreOperation',
      PreApplyEvent::class => 'validateStagePreOperation',
      StatusCheckEvent::class => 'validateStagePreOperation',
    ];
  }

}
