<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates certain Composer settings.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class ComposerSettingsValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs a ComposerSettingsValidator object.
   *
   * @param \Drupal\package_manager\ComposerInspector $inspector
   *   The Composer inspector service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   */
  public function __construct(protected ComposerInspector $inspector, protected PathLocator $pathLocator) {
  }

  /**
   * Validates Composer settings.
   */
  public function validate(PreOperationStageEvent $event): void {
    $dir = $this->pathLocator->getProjectRoot();

    try {
      $setting = (int) $this->inspector->getConfig('secure-http', $dir);
    }
    catch (\Throwable $throwable) {
      $event->addErrorFromThrowable($throwable, $this->t('Unable to determine Composer <code>secure-http</code> setting.'));
      return;
    }
    if ($setting !== 1) {
      $event->addError([
        $this->t('HTTPS must be enabled for Composer downloads. See <a href=":url">the Composer documentation</a> for more information.', [
          ':url' => 'https://getcomposer.org/doc/06-config.md#secure-http',
        ]),
      ]);
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
