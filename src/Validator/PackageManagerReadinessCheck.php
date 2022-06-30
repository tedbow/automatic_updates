<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\package_manager\Validator\PreOperationStageValidatorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * An adapter to run another stage validator during readiness checking.
 *
 * This class exists to facilitate re-use of Package Manager's stage validators
 * during update readiness checks, in addition to whatever events they normally
 * subscribe to.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class PackageManagerReadinessCheck implements EventSubscriberInterface {

  /**
   * The validator to run.
   *
   * @var \Drupal\package_manager\Validator\PreOperationStageValidatorInterface
   */
  protected $validator;

  /**
   * Constructs a PackageManagerReadinessCheck object.
   *
   * @param \Drupal\package_manager\Validator\PreOperationStageValidatorInterface $validator
   *   The Package Manager validator to run during readiness checking.
   */
  public function __construct(PreOperationStageValidatorInterface $validator) {
    $this->validator = $validator;
  }

  /**
   * Performs a readiness check by proxying to a Package Manager validator.
   *
   * @param \Drupal\automatic_updates\Event\ReadinessCheckEvent $event
   *   The event object.
   */
  public function validate(ReadinessCheckEvent $event): void {
    $this->validator->validateStagePreOperation($event);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ReadinessCheckEvent::class => 'validate',
    ];
  }

}
