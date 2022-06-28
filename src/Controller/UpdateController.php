<?php

namespace Drupal\automatic_updates\Controller;

use Drupal\automatic_updates\BatchProcessor;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\package_manager\Validator\PendingUpdatesValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Defines a controller to handle various stages of an automatic update.
 *
 * @internal
 *   Controller classes are internal.
 */
class UpdateController extends ControllerBase {

  /**
   * The pending updates validator.
   *
   * @var \Drupal\package_manager\Validator\PendingUpdatesValidator
   */
  protected $pendingUpdatesValidator;

  /**
   * Constructs an UpdateController object.
   *
   * @param \Drupal\package_manager\Validator\PendingUpdatesValidator $pending_updates_validator
   *   The pending updates validator.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(PendingUpdatesValidator $pending_updates_validator, StateInterface $state) {
    $this->pendingUpdatesValidator = $pending_updates_validator;
    $this->stateService = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('package_manager.validator.pending_updates'),
      $container->get('state')
    );
  }

  /**
   * Redirects after staged changes are applied to the active directory.
   *
   * If there are any pending update hooks or post-updates, the user is sent to
   * update.php to run those. Otherwise, they are redirected to the status
   * report.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the appropriate destination.
   */
  public function onFinish(Request $request): RedirectResponse {
    if ($this->pendingUpdatesValidator->updatesExist()) {
      $message = $this->t('Please apply database updates to complete the update process.');
      $url = Url::fromRoute('system.db_update');
    }
    else {
      $message = $this->t('Update complete!');
      $url = Url::fromRoute('update.status');
      // Now that the update is done, we can put the site back online if it was
      // previously not in maintenance mode.
      if (!$request->getSession()->remove(BatchProcessor::MAINTENANCE_MODE_SESSION_KEY)) {
        $this->state()->set('system.maintenance_mode', FALSE);
        // @todo Remove once the core bug that shows the maintenance mode
        //   message after the site is out of maintenance mode is fixed in
        //   https://www.drupal.org/i/3279246.
        $messages = $this->messenger()->messagesByType(MessengerInterface::TYPE_STATUS);
        $messages = array_filter($messages, function (string $message) {
          return !str_starts_with($message, (string) $this->t('Operating in maintenance mode.'));
        });
        $this->messenger()->deleteByType(MessengerInterface::TYPE_STATUS);
        foreach ($messages as $message) {
          $this->messenger()->addStatus($message);
        }
      }
    }
    $this->messenger()->addStatus($message);
    return new RedirectResponse($url->setAbsolute()->toString());
  }

}
