<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Checks that the file system is writable.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class WritableFileSystemValidator implements EventSubscriberInterface {

  use BaseRequirementValidatorTrait;
  use StringTranslationTrait;

  /**
   * Constructs a WritableFileSystemValidator object.
   *
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   */
  public function __construct(protected PathLocator $pathLocator) {
  }

  /**
   * Checks that the file system is writable.
   *
   * @todo It might make sense to use a more sophisticated method of testing
   *   writability than is_writable(), since it's not clear if that can return
   *   false negatives/positives due to things like SELinux, exotic file
   *   systems, and so forth.
   */
  public function validate(PreOperationStageEvent $event): void {
    $messages = [];

    $drupal_root = $this->pathLocator->getProjectRoot();
    $web_root = $this->pathLocator->getWebRoot();
    if ($web_root) {
      $drupal_root .= DIRECTORY_SEPARATOR . $web_root;
    }
    if (!is_writable($drupal_root)) {
      $messages[] = $this->t('The Drupal directory "@dir" is not writable.', [
        '@dir' => $drupal_root,
      ]);
    }

    $dir = $this->pathLocator->getVendorDirectory();
    if (!is_writable($dir)) {
      $messages[] = $this->t('The vendor directory "@dir" is not writable.', ['@dir' => $dir]);
    }

    // During pre-apply don't check whether the staging root is writable.
    if ($event instanceof PreApplyEvent) {
      if ($messages) {
        $event->addError($messages, $this->t('The file system is not writable.'));
      }
      return;
    }
    // Ensure the staging root is writable. If it doesn't exist, ensure we will
    // be able to create it.
    $dir = $this->pathLocator->getStagingRoot();
    if (!file_exists($dir)) {
      $dir = dirname($dir);
      if (!is_writable($dir)) {
        $messages[] = $this->t('The stage root directory will not able to be created at "@dir".', [
          '@dir' => $dir,
        ]);
      }
    }
    elseif (!is_writable($dir)) {
      $messages[] = $this->t('The stage root directory "@dir" is not writable.', [
        '@dir' => $dir,
      ]);
    }

    if ($messages) {
      $event->addError($messages, $this->t('The file system is not writable.'));
    }
  }

}
