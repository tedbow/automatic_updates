<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates the Composer executable is the correct version.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class ComposerExecutableValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs a ComposerExecutableValidator object.
   *
   * @param \Drupal\package_manager\ComposerInspector $composerInspector
   *   The Composer inspector service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(
    protected ComposerInspector $composerInspector,
    protected PathLocator $pathLocator,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Validates that the Composer executable is the correct version.
   */
  public function validate(PreOperationStageEvent $event): void {
    try {
      $this->composerInspector->validate($this->pathLocator->getProjectRoot());
    }
    catch (\Throwable $e) {
      if ($this->moduleHandler->moduleExists('help')) {
        $url = Url::fromRoute('help.page', ['name' => 'package_manager'])
          ->setOption('fragment', 'package-manager-faq-composer-not-found')
          ->toString();

        $message = $this->t('@message See <a href=":package-manager-help">the help page</a> for information on how to configure the path to Composer.', [
          '@message' => $e->getMessage(),
          ':package-manager-help' => $url,
        ]);
        $event->addError([$message]);
      }
      else {
        $event->addErrorFromThrowable($e);
      }

    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreCreateEvent::class => 'validate',
      PreApplyEvent::class => 'validate',
      StatusCheckEvent::class => 'validate',
    ];
  }

}
