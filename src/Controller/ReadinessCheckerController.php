<?php

namespace Drupal\automatic_updates\Controller;

use Drupal\automatic_updates\Validation\ReadinessValidationManager;
use Drupal\automatic_updates\Validation\ReadinessTrait;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\system\SystemManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * A controller for running readiness checkers.
 *
 * @internal
 *   Controller classes are internal.
 */
final class ReadinessCheckerController extends ControllerBase {

  use ReadinessTrait;

  /**
   * The readiness checker manager.
   *
   * @var \Drupal\automatic_updates\Validation\ReadinessValidationManager
   */
  protected $readinessCheckerManager;

  /**
   * Constructs a ReadinessCheckerController object.
   *
   * @param \Drupal\automatic_updates\Validation\ReadinessValidationManager $checker_manager
   *   The readiness checker manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(ReadinessValidationManager $checker_manager, TranslationInterface $string_translation) {
    $this->readinessCheckerManager = $checker_manager;
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('automatic_updates.readiness_validation_manager'),
      $container->get('string_translation'),
    );
  }

  /**
   * Run the readiness checkers.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the status report page.
   */
  public function run(): RedirectResponse {
    $results = $this->readinessCheckerManager->run()->getResults();
    if (!$results) {
      // @todo Link "automatic updates" to documentation in
      //   https://www.drupal.org/node/3168405.
      // If there are no messages from the readiness checkers display a message
      // that the site is ready. If there are messages, the status report will
      // display them.
      $this->messenger()->addStatus($this->t('No issues found. Your site is ready for automatic updates'));
    }
    else {
      // Determine if any of the results are errors.
      $error_results = $this->readinessCheckerManager->getResults(SystemManager::REQUIREMENT_ERROR);
      // If there are any errors, display a failure message as an error.
      // Otherwise, display it as a warning.
      $severity = $error_results ? SystemManager::REQUIREMENT_ERROR : SystemManager::REQUIREMENT_WARNING;
      $failure_message = $this->getFailureMessageForSeverity($severity);
      if ($severity === SystemManager::REQUIREMENT_ERROR) {
        $this->messenger()->addError($failure_message);
      }
      else {
        $this->messenger()->addWarning($failure_message);
      }
    }
    // Set a redirect to the status report page. Any other page that provides a
    // link to this controller should include 'destination' in the query string
    // to ensure this redirect is overridden.
    // @see \Drupal\Core\EventSubscriber\RedirectResponseSubscriber::checkRedirectUrl()
    return $this->redirect('system.status');
  }

}
