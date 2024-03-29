<?php

namespace Drupal\automatic_updates\Validation;

use Drupal\automatic_updates\CronUpdater;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Render\RendererInterface;
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
 * Class for displaying status check results on admin pages.
 *
 * @internal
 *   This class implements logic to output the messages from status checkers
 *   on admin pages. It should not be called directly.
 */
final class AdminStatusCheckMessages implements ContainerInjectionInterface {

  use MessengerTrait;
  use StringTranslationTrait;
  use RedirectDestinationTrait;
  use ValidationResultDisplayTrait;

  /**
   * The status checker service.
   *
   * @var \Drupal\automatic_updates\Validation\StatusChecker
   */
  protected $statusChecker;

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
   * The cron updater service.
   *
   * @var \Drupal\automatic_updates\CronUpdater
   */
  protected $cronUpdater;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs an AdminStatusCheckMessages object.
   *
   * @param \Drupal\automatic_updates\Validation\StatusChecker $status_checker
   *   The status checker service.
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
   * @param \Drupal\automatic_updates\CronUpdater $cron_updater
   *   The cron updater service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(StatusChecker $status_checker, MessengerInterface $messenger, AdminContext $admin_context, AccountProxyInterface $current_user, TranslationInterface $translation, CurrentRouteMatch $current_route_match, CronUpdater $cron_updater, RendererInterface $renderer) {
    $this->statusChecker = $status_checker;
    $this->setMessenger($messenger);
    $this->adminContext = $admin_context;
    $this->currentUser = $current_user;
    $this->setStringTranslation($translation);
    $this->currentRouteMatch = $current_route_match;
    $this->cronUpdater = $cron_updater;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('automatic_updates.status_checker'),
      $container->get('messenger'),
      $container->get('router.admin_context'),
      $container->get('current_user'),
      $container->get('string_translation'),
      $container->get('current_route_match'),
      $container->get('automatic_updates.cron_updater'),
      $container->get('renderer')
    );
  }

  /**
   * Displays the checker results messages on admin pages.
   */
  public function displayAdminPageMessages(): void {
    if (!$this->displayResultsOnCurrentPage()) {
      return;
    }
    if ($this->statusChecker->getResults() === NULL) {
      $checker_url = Url::fromRoute('automatic_updates.status_check')->setOption('query', $this->getDestinationArray());
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
    // If updates will not run during cron then we don't need to show the
    // status checks on admin pages.
    if ($this->cronUpdater->getMode() === CronUpdater::DISABLED) {
      return FALSE;
    }

    if ($this->adminContext->isAdminRoute() && $this->currentUser->hasPermission('administer site configuration')) {
      $route = $this->currentRouteMatch->getRouteObject();
      if ($route) {
        if ($route->hasOption('_automatic_updates_readiness_messages')) {
          @trigger_error('The _automatic_updates_readiness_messages route option is deprecated in automatic_updates:8.x-2.5 and will be removed in automatic_updates:3.0.0. Use _automatic_updates_status_messages route option instead. See https://www.drupal.org/node/3316086.', E_USER_DEPRECATED);
          $route->setOption('_automatic_updates_status_messages', $route->getOption('_automatic_updates_readiness_messages'));
        }
        return $route->getOption('_automatic_updates_status_messages') !== 'skip';
      }
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
    $results = $this->statusChecker->getResults($severity);
    if (empty($results)) {
      return FALSE;
    }
    $this->displayResults($results, $this->messenger(), $this->renderer);
    return TRUE;
  }

}
