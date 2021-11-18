<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\package_manager\EventSubscriber\PreOperationStageValidatorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * An adapter to run another stage validator during readiness checking.
 *
 * This class exists to facilitate re-use of Package Manager's stage validators
 * during update readiness checks, in addition to whatever events they normally
 * subscribe to.
 */
class PackageManagerReadinessCheck implements EventSubscriberInterface {

  /**
   * The validator to run.
   *
   * @var \Drupal\package_manager\EventSubscriber\PreOperationStageValidatorInterface
   */
  protected $validator;

  /**
   * Constructs a PackageManagerReadinessCheck object.
   *
   * @param \Drupal\package_manager\EventSubscriber\PreOperationStageValidatorInterface $validator
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
