<?php

namespace Drupal\automatic_updates\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\package_manager\Validator\PendingUpdatesValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
   */
  public function __construct(PendingUpdatesValidator $pending_updates_validator) {
    $this->pendingUpdatesValidator = $pending_updates_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('package_manager.validator.pending_updates')
    );
  }

  /**
   * Redirects after staged changes are applied to the active directory.
   *
   * If there are any pending update hooks or post-updates, the user is sent to
   * update.php to run those. Otherwise, they are redirected to the status
   * report.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the appropriate destination.
   */
  public function onFinish(): RedirectResponse {
    if ($this->pendingUpdatesValidator->updatesExist()) {
      $message = $this->t('Please apply database updates to complete the update process.');
      $url = Url::fromRoute('system.db_update');
    }
    else {
      $message = $this->t('Update complete!');
      $url = Url::fromRoute('update.status');
    }
    $this->messenger()->addStatus($message);
    return new RedirectResponse($url->setAbsolute()->toString());
  }

}
