<?php

namespace Drupal\package_manager\Validator;

use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\PathLocator;

/**
 * Checks that the active lock file is unchanged during stage operations.
 */
class LockFileValidator implements PreOperationStageValidatorInterface {

  use StringTranslationTrait;

  /**
   * The state key under which to store the hash of the active lock file.
   *
   * @var string
   */
  protected const STATE_KEY = 'package_manager.lock_hash';

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * Constructs a LockFileValidator object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   */
  public function __construct(StateInterface $state, PathLocator $path_locator, TranslationInterface $translation) {
    $this->state = $state;
    $this->pathLocator = $path_locator;
    $this->setStringTranslation($translation);
  }

  /**
   * Returns the current hash of the active directory's lock file.
   *
   * @return string|false
   *   The hash of the active directory's lock file, or FALSE if the lock file
   *   does not exist.
   */
  protected function getHash() {
    $file = $this->pathLocator->getActiveDirectory() . DIRECTORY_SEPARATOR . 'composer.lock';
    // We want to directly hash the lock file itself, rather than look at its
    // content-hash value, which is actually a hash of the relevant parts of
    // composer.json. We're trying to verify that the actual installed packages
    // have not changed; we don't care about the constraints in composer.json.
    try {
      return hash_file('sha256', $file);
    }
    catch (\Throwable $exception) {
      return FALSE;
    }
  }

  /**
   * Stores the current lock file hash.
   */
  public function storeHash(PreCreateEvent $event): void {
    $hash = $this->getHash();
    if ($hash) {
      $this->state->set(static::STATE_KEY, $hash);
    }
    else {
      $event->addError([
        $this->t('Could not hash the active lock file.'),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateStagePreOperation(PreOperationStageEvent $event): void {
    // Ensure we can get a current hash of the lock file.
    $hash = $this->getHash();
    if (empty($hash)) {
      $error = $this->t('Could not hash the active lock file.');
    }

    // Ensure we also have a stored hash of the lock file.
    $stored_hash = $this->state->get(static::STATE_KEY);
    if (empty($stored_hash)) {
      $error = $this->t('Could not retrieve stored hash of the active lock file.');
    }

    // If we have both hashes, ensure they match.
    if ($hash && $stored_hash && hash_equals($stored_hash, $hash) == FALSE) {
      $error = $this->t('Stored lock file hash does not match the active lock file.');
    }

    // @todo Let the validation result carry all the relevant messages in
    //   https://www.drupal.org/i/3247479.
    if (isset($error)) {
      $event->addError([$error]);
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
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'storeHash',
      PreRequireEvent::class => 'validateStagePreOperation',
      PreApplyEvent::class => 'validateStagePreOperation',
      PostDestroyEvent::class => 'deleteHash',
    ];
  }

}
