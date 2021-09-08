<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\automatic_updates\Validation\ValidationResult;
use PhpTuf\ComposerStager\Exception\IOException;
use PhpTuf\ComposerStager\Infrastructure\Process\ExecutableFinderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that the Composer executable can be found.
 */
class ComposerExecutableValidator implements EventSubscriberInterface {

  /**
   * The executable finder service.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Process\ExecutableFinderInterface
   */
  protected $executableFinder;

  /**
   * Constructs a ComposerExecutableValidator object.
   *
   * @param \PhpTuf\ComposerStager\Infrastructure\Process\ExecutableFinderInterface $executable_finder
   *   The executable finder service.
   */
  public function __construct(ExecutableFinderInterface $executable_finder) {
    $this->executableFinder = $executable_finder;
  }

  /**
   * Validates that the Composer executable can be found.
   *
   * @param \Drupal\automatic_updates\Event\UpdateEvent $event
   *   The event object.
   */
  public function checkForComposerExecutable(UpdateEvent $event): void {
    try {
      $this->executableFinder->find('composer');
    }
    catch (IOException $e) {
      $error = ValidationResult::createError([
        $e->getMessage(),
      ]);
      $event->addValidationResult($error);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      AutomaticUpdatesEvents::READINESS_CHECK => 'checkForComposerExecutable',
    ];
  }

}
