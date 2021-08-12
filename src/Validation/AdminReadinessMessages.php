<?php

namespace Drupal\automatic_updates\Validation;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Routing\RedirectDestinationTrait;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\system\SystemManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class for displaying readiness messages on admin pages.
 *
 * @internal
 *   This class implements logic to output the messages from readiness checkers
 *   on admin pages. It should not be called directly.
 */
final class AdminReadinessMessages implements ContainerInjectionInterface {

  use MessengerTrait;
  use StringTranslationTrait;
  use RedirectDestinationTrait;
  use ReadinessTrait;

  /**
   * The readiness checker manager.
   *
   * @var \Drupal\automatic_updates\Validation\ReadinessValidationManager
   */
  protected $readinessCheckerManager;

  /**
   * The admin context service.
   *
   * @var \Drupal\Core\Routing\AdminContext
   */
  protected $adminContext;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $currentRouteMatch;

  /**
   * Constructs a ReadinessRequirement object.
   *
   * @param \Drupal\automatic_updates\Validation\ReadinessValidationManager $readiness_checker_manager
   *   The readiness checker manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Routing\AdminContext $admin_context
   *   The admin context service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $current_route_match
   *   The current route match.
   */
  public function __construct(ReadinessValidationManager $readiness_checker_manager, MessengerInterface $messenger, AdminContext $admin_context, AccountProxyInterface $current_user, TranslationInterface $translation, CurrentRouteMatch $current_route_match) {
    $this->readinessCheckerManager = $readiness_checker_manager;
    $this->setMessenger($messenger);
    $this->adminContext = $admin_context;
    $this->currentUser = $current_user;
    $this->setStringTranslation($translation);
    $this->currentRouteMatch = $current_route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('automatic_updates.readiness_validation_manager'),
      $container->get('messenger'),
      $container->get('router.admin_context'),
      $container->get('current_user'),
      $container->get('string_translation'),
      $container->get('current_route_match')
    );
  }

  /**
   * Displays the checker results messages on admin pages.
   */
  public function displayAdminPageMessages(): void {
    if (!$this->displayResultsOnCurrentPage()) {
      return;
    }
    if ($this->readinessCheckerManager->getResults() === NULL) {
      $checker_url = Url::fromRoute('automatic_updates.update_readiness')->setOption('query', $this->getDestinationArray());
      if ($checker_url->access()) {
        $this->messenger()->addError($this->t('Your site has not recently run an update readiness check. <a href=":url">Run readiness checks now.</a>', [
          ':url' => $checker_url->toString(),
        ]));
      }
    }
    else {
      // Display errors, if there are any. If there aren't, then display
      // warnings, if there are any.
      if (!$this->displayResultsForSeverity(SystemManager::REQUIREMENT_ERROR)) {
        $this->displayResultsForSeverity(SystemManager::REQUIREMENT_WARNING);
      }
    }
  }

  /**
   * Determines whether the messages should be displayed on the current page.
   *
   * @return bool
   *   Whether the messages should be displayed on the current page.
   */
  protected function displayResultsOnCurrentPage(): bool {
    if ($this->adminContext->isAdminRoute() && $this->currentUser->hasPermission('administer site configuration')) {
      // These routes don't need additional nagging.
      $disabled_routes = [
        'update.theme_update',
        'system.theme_install',
        'update.module_update',
        'update.module_install',
        'update.status',
        'update.report_update',
        'update.report_install',
        'update.settings',
        'system.status',
        'update.confirmation_page',
      ];
      return !in_array($this->currentRouteMatch->getRouteName(), $disabled_routes, TRUE);
    }
    return FALSE;
  }

  /**
   * Displays the results for severity.
   *
   * @param int $severity
   *   The severity for the results to display. Should be one of the
   *   SystemManager::REQUIREMENT_* constants.
   *
   * @return bool
   *   Whether any results were displayed.
   */
  protected function displayResultsForSeverity(int $severity): bool {
    $results = $this->readinessCheckerManager->getResults($severity);
    if (empty($results)) {
      return FALSE;
    }
    $failure_message = $this->getFailureMessageForSeverity($severity);
    if ($severity === SystemManager::REQUIREMENT_ERROR) {
      $this->messenger()->addError($failure_message);
    }
    else {
      $this->messenger()->addWarning($failure_message);
    }

    foreach ($results as $result) {
      $messages = $result->getMessages();
      $message = count($messages) === 1 ? $messages[0] : $result->getSummary();
      if ($severity === SystemManager::REQUIREMENT_ERROR) {
        $this->messenger()->addError($message);
      }
      else {
        $this->messenger()->addWarning($message);
      }
    }
    return TRUE;
  }

}
