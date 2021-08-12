<?php

namespace Drupal\automatic_updates;

use Drupal\update\UpdateManagerInterface;
use Drupal\update\UpdateProcessorInterface;

/**
 * Determines the recommended version of a package.
 */
class UpdateRecommender {

  /**
   * The update manager service.
   *
   * @var \Drupal\update\UpdateManagerInterface
   */
  protected $updateManager;

  /**
   * The update processor service.
   *
   * @var \Drupal\update\UpdateProcessorInterface
   */
  protected $updateProcessor;

  /**
   * UpdateRecommender constructor.
   *
   * @param \Drupal\update\UpdateManagerInterface $update_manager
   *   The update manager service.
   * @param \Drupal\update\UpdateProcessorInterface $update_processor
   *   The update processor service.
   */
  public function __construct(UpdateManagerInterface $update_manager, UpdateProcessorInterface $update_processor) {
    $this->updateManager = $update_manager;
    $this->updateProcessor = $update_processor;
  }

  /**
   * Returns the recommended update version of a project.
   *
   * @param string $project
   *   The name of the project.
   *
   * @return string
   *   The version that we recommend the site update to.
   */
  public function getRecommendedUpdateVersion(string $project) {
    // Hard code for now
    return '9.2.0';
    // From https://www.drupal.org/project/drupal/issues/3111767
    $this->updateManager->refreshUpdateData();
    $this->updateProcessor->fetchData();
    $available = update_get_available(TRUE);
    $projects = update_calculate_project_data($available);
    $not_recommended_version = $projects[$project]['status'] !== UpdateManagerInterface::CURRENT;
    $security_update = in_array($projects['drupal']['status'], [UpdateManagerInterface::NOT_SECURE, UpdateManagerInterface::REVOKED], TRUE);
    $recommended_release = isset($projects['drupal']['releases'][$projects['drupal']['recommended']]) ? $projects['drupal']['releases'][$projects['drupal']['recommended']] : NULL;
    $existing_minor_version = explode('.', \Drupal::VERSION, -1);
    $recommended_minor_version = explode('.', $recommended_release['version'], -1);
    $major_upgrade = $existing_minor_version !== $recommended_minor_version;
  }

}
