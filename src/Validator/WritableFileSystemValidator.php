<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\automatic_updates\PathLocator;
use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Checks that the file system is writable.
 */
class WritableFileSystemValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The path locator service.
   *
   * @var \Drupal\automatic_updates\PathLocator
   */
  protected $pathLocator;

  /**
   * The Drupal root.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * Constructs a WritableFileSystemValidator object.
   *
   * @param \Drupal\automatic_updates\PathLocator $path_locator
   *   The path locator service.
   * @param string $app_root
   *   The Drupal root.
   */
  public function __construct(PathLocator $path_locator, string $app_root) {
    $this->pathLocator = $path_locator;
    $this->appRoot = $app_root;
  }

  /**
   * Checks that the file system is writable.
   *
   * @param \Drupal\automatic_updates\Event\UpdateEvent $event
   *   The event object.
   *
   * @todo It might make sense to use a more sophisticated method of testing
   *   writability than is_writable(), since it's not clear if that can return
   *   false negatives/positives due to things like SELinux, exotic file
   *   systems, and so forth.
   */
  public function checkPermissions(UpdateEvent $event): void {
    $messages = [];

    if (!is_writable($this->appRoot)) {
      $messages[] = $this->t('The Drupal directory "@dir" is not writable.', [
        '@dir' => $this->appRoot,
      ]);
    }

    $dir = $this->pathLocator->getVendorDirectory();
    if (!is_writable($dir)) {
      $messages[] = $this->t('The vendor directory "@dir" is not writable.', ['@dir' => $dir]);
    }

    if ($messages) {
      $result = ValidationResult::createError($messages, $this->t('The file system is not writable.'));
      $event->addValidationResult($result);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      AutomaticUpdatesEvents::READINESS_CHECK => 'checkPermissions',
      AutomaticUpdatesEvents::PRE_START => 'checkPermissions',
    ];
  }

}
