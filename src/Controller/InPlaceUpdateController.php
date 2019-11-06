<?php

namespace Drupal\automatic_updates\Controller;

use Drupal\automatic_updates\Services\UpdateInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Automatic Updates routes.
 */
class InPlaceUpdateController extends ControllerBase {

  /**
   * Updater service.
   *
   * @var \Drupal\automatic_updates\Services\UpdateInterface
   */
  protected $updater;

  /**
   * InPlaceUpdateController constructor.
   *
   * @param \Drupal\automatic_updates\Services\UpdateInterface $updater
   *   The updater service.
   */
  public function __construct(UpdateInterface $updater) {
    $this->updater = $updater;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('automatic_updates.update')
    );
  }

  /**
   * Builds the response.
   */
  public function update($project, $type, $from, $to) {
    $updated = $this->updater->update($project, $type, $from, $to);
    $message_type = MessengerInterface::TYPE_STATUS;
    $message = $this->t('Update successful');
    if (!$updated) {
      $message_type = MessengerInterface::TYPE_ERROR;
      $message = $this->t('Update failed. Please review logs to determine the cause.');
    }
    $this->messenger()->addMessage($message, $message_type);
    return $this->redirect('automatic_updates.settings');
  }

}
