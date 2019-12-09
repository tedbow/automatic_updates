<?php

namespace Drupal\test_automatic_updates\Controller;

use Drupal\automatic_updates\Services\UpdateInterface;
use Drupal\automatic_updates\UpdateMetadata;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Test Automatic Updates routes.
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
    $metadata = new UpdateMetadata($project, $type, $from, $to);
    $updated = $this->updater->update($metadata);
    return [
      '#markup' => $updated ? $this->t('Update successful') : $this->t('Update Failed'),
    ];
  }

}
