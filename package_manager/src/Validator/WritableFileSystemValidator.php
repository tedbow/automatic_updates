<?php

namespace Drupal\package_manager\Validator;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\PathLocator;

/**
 * Checks that the file system is writable.
 */
class WritableFileSystemValidator implements PreOperationStageValidatorInterface {

  use StringTranslationTrait;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * Constructs a WritableFileSystemValidator object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(PathLocator $path_locator, TranslationInterface $translation) {
    $this->pathLocator = $path_locator;
    $this->setStringTranslation($translation);
  }

  /**
   * {@inheritdoc}
   *
   * @todo It might make sense to use a more sophisticated method of testing
   *   writability than is_writable(), since it's not clear if that can return
   *   false negatives/positives due to things like SELinux, exotic file
   *   systems, and so forth.
   */
  public function validateStagePreOperation(PreOperationStageEvent $event): void {
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

    if ($messages) {
      $event->addError($messages, $this->t('The file system is not writable.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'validateStagePreOperation',
    ];
  }

}
