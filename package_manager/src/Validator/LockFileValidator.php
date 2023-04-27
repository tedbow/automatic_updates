<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Checks that the active lock file is unchanged during stage operations.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class LockFileValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The state key under which to store the hash of the active lock file.
   *
   * @var string
   */
  protected const STATE_KEY = 'package_manager.lock_hash';

  /**
   * Constructs a LockFileValidator object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly PathLocator $pathLocator
  ) {}

  /**
   * Returns the current hash of the given directory's lock file.
   *
   * @param string $directory
   *   Path of a directory containing a composer.lock file.
   *
   * @return string|false
   *   The hash of the given directory's lock file, or FALSE if the lock file
   *   does not exist.
   */
  protected function getLockFileHash(string $directory) {
    $file = $directory . DIRECTORY_SEPARATOR . 'composer.lock';
    // We want to directly hash the lock file itself, rather than look at its
    // content-hash value, which is actually a hash of the relevant parts of
    // composer.json. We're trying to verify that the actual installed packages
    // have not changed; we don't care about the constraints in composer.json.
    try {
      return hash_file('sha256', $file);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Stores the current lock file hash.
   */
  public function storeHash(PreCreateEvent $event): void {
    $hash = $this->getLockFileHash($this->pathLocator->getProjectRoot());
    if ($hash) {
      $this->state->set(static::STATE_KEY, $hash);
    }
    else {
      $event->addError([
        // @todo Reword in https://www.drupal.org/project/automatic_updates/issues/3352846
        $this->t('The active lock file does not exist.'),
      ]);
    }
  }

  /**
   * Checks that the active lock file is unchanged during stage operations.
   */
  public function validate(PreOperationStageEvent $event): void {
    // Early return if the stage is not already created.
    if ($event instanceof StatusCheckEvent && $event->stage->isAvailable()) {
      return;
    }

    $messages = [];
    // Ensure we can get a current hash of the lock file.
    $active_hash = $this->getLockFileHash($this->pathLocator->getProjectRoot());
    if (empty($active_hash)) {
      // @todo Reword in https://www.drupal.org/project/automatic_updates/issues/3352846
      $messages[] = $this->t('The active lock file does not exist.');
    }

    // Ensure we also have a stored hash of the lock file.
    $stored_hash = $this->state->get(static::STATE_KEY);
    if (empty($stored_hash)) {
      throw new \LogicException('Stored hash key deleted.');
    }

    // If we have both hashes, ensure they match.
    if ($active_hash && $stored_hash && !hash_equals($stored_hash, $active_hash)) {
      $messages[] = $this->t('Unexpected changes were detected in composer.lock, which indicates that other Composer operations were performed since this Package Manager operation started. This can put the code base into an unreliable state and therefore is not allowed.');
    }

    // Don't allow staged changes to be applied if the staged lock file has no
    // apparent changes.
    if (empty($messages) && $event instanceof PreApplyEvent) {
      $stage_hash = $this->getLockFileHash($event->stage->getStageDirectory());
      if ($stage_hash && hash_equals($active_hash, $stage_hash)) {
        $messages[] = $this->t('There are no pending Composer operations.');
      }
    }

    if (!empty($messages)) {
      $summary = $this->formatPlural(
        count($messages),
        'Problem detected in lock file during stage operations.',
        'Problems detected in lock file during stage operations.',
      );
      $event->addError($messages, $summary);
    }
  }

  /**
   * Deletes the stored lock file hash.
   */
  public function deleteHash(): void {
    $this->state->delete(static::STATE_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreCreateEvent::class => 'storeHash',
      PreRequireEvent::class => 'validate',
      PreApplyEvent::class => 'validate',
      StatusCheckEvent::class => 'validate',
      PostDestroyEvent::class => 'deleteHash',
    ];
  }

}
