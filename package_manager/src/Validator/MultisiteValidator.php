<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Checks that the current site is not part of a multisite.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class MultisiteValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs a new MultisiteValidator.
   *
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   */
  public function __construct(protected PathLocator $pathLocator) {
  }

  /**
   * {@inheritdoc}
   */
  public function validateStagePreOperation(PreOperationStageEvent $event): void {
    if ($this->isMultisite()) {
      $event->addError([
        $this->t('Drupal multisite is not supported by Package Manager.'),
      ]);
    }
  }

  /**
   * Detects if the current site is part of a multisite.
   *
   * @return bool
   *   TRUE if the current site is part of a multisite, otherwise FALSE.
   *
   * @todo Make this smarter in https://www.drupal.org/node/3267646.
   */
  protected function isMultisite(): bool {
    $web_root = $this->pathLocator->getWebRoot();
    if ($web_root) {
      $web_root .= '/';
    }
    return file_exists($this->pathLocator->getProjectRoot() . '/' . $web_root . 'sites/sites.php');
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
