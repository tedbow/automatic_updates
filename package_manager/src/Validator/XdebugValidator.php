<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\StatusCheckEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Performs validation if Xdebug is enabled.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class XdebugValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Adds warning if Xdebug is enabled.
   *
   * @param \Drupal\package_manager\Event\StatusCheckEvent $event
   *   The event object.
   */
  public function validateXdebugOff(StatusCheckEvent $event): void {
    $warning = $this->checkForXdebug();
    if ($warning) {
      $event->addWarning($warning);
    }
  }

  /**
   * Checks if Xdebug is enabled and returns a warning if it is.
   *
   * @return array|null
   *   Returns an array of warnings or null if Xdebug isn't detected.
   */
  protected function checkForXdebug(): ?array {
    if (function_exists('xdebug_break')) {
      return [
        $this->t('Xdebug is enabled, which may have a negative performance impact on Package Manager and any modules that use it.'),
      ];
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      StatusCheckEvent::class => 'validateXdebugOff',
    ];
  }

}
