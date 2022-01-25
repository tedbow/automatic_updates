<?php

namespace Drupal\automatic_updates\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Update\UpdateHookRegistry;
use Drupal\Core\Update\UpdateRegistry;
use Drupal\Core\Url;
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
   * The update hook registry service.
   *
   * @var \Drupal\Core\Update\UpdateHookRegistry
   */
  protected $updateHookRegistry;

  /**
   * The post-update registry service.
   *
   * @var \Drupal\Core\Update\UpdateRegistry
   */
  protected $postUpdateRegistry;

  /**
   * Constructs an UpdateController object.
   *
   * @param \Drupal\Core\Update\UpdateHookRegistry $update_hook_registry
   *   The update hook registry service.
   * @param \Drupal\Core\Update\UpdateRegistry $post_update_registry
   *   The post-update registry service.
   */
  public function __construct(UpdateHookRegistry $update_hook_registry, UpdateRegistry $post_update_registry) {
    $this->updateHookRegistry = $update_hook_registry;
    $this->postUpdateRegistry = $post_update_registry;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('update.update_hook_registry'),
      $container->get('update.post_update_registry')
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
    if ($this->pendingUpdatesExist()) {
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

  /**
   * Checks if there are any pending database updates.
   *
   * @return bool
   *   TRUE if there are any pending update hooks or post-updates, otherwise
   *   FALSE.
   */
  protected function pendingUpdatesExist(): bool {
    if ($this->postUpdateRegistry->getPendingUpdateFunctions()) {
      return TRUE;
    }

    $modules = array_keys($this->moduleHandler()->getModuleList());
    foreach ($modules as $module) {
      if ($this->updateHookRegistry->getAvailableUpdates($module)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
