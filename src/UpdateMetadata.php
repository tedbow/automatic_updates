<?php

namespace Drupal\automatic_updates;

/**
 * Transfer object to encapsulate the details for an update.
 */
final class UpdateMetadata {

  /**
   * The project name.
   *
   * @var string
   */
  protected $projectName;

  /**
   * The project type.
   *
   * @var string
   */
  protected $projectType;

  /**
   * The current project version.
   *
   * @var string
   */
  protected $fromVersion;

  /**
   * The desired next project version.
   *
   * @var string
   */
  protected $toVersion;

  /**
   * UpdateMetadata constructor.
   *
   * @param string $project_name
   *   The project name.
   * @param string $project_type
   *   The project type.
   * @param string $from_version
   *   The current project version.
   * @param string $to_version
   *   The desired next project version.
   */
  public function __construct($project_name, $project_type, $from_version, $to_version) {
    $this->projectName = $project_name;
    $this->projectType = $project_type;
    $this->fromVersion = $from_version;
    $this->toVersion = $to_version;
  }

  /**
   * Get project name.
   *
   * @return string
   *   The project nam.
   */
  public function getProjectName() {
    return $this->projectName;
  }

  /**
   * Set the project name.
   *
   * @param string $projectName
   *   The project name.
   *
   * @return \Drupal\automatic_updates\UpdateMetadata
   *   The update metadata.
   */
  public function setProjectName($projectName) {
    $this->projectName = $projectName;
    return $this;
  }

  /**
   * Get the project type.
   *
   * @return string
   *   The project type.
   */
  public function getProjectType() {
    return $this->projectType;
  }

  /**
   * Set the project type.
   *
   * @param string $projectType
   *   The project type.
   *
   * @return \Drupal\automatic_updates\UpdateMetadata
   *   The update metadata.
   */
  public function setProjectType($projectType) {
    $this->projectType = $projectType;
    return $this;
  }

  /**
   * Get the current project version.
   *
   * @return string
   *   The current project version.
   */
  public function getFromVersion() {
    return $this->fromVersion;
  }

  /**
   * Set the current project version.
   *
   * @param string $fromVersion
   *   The current project version.
   *
   * @return \Drupal\automatic_updates\UpdateMetadata
   *   The update metadata.
   */
  public function setFromVersion($fromVersion) {
    $this->fromVersion = $fromVersion;
    return $this;
  }

  /**
   * Get the desired next project version.
   *
   * @return string
   *   The desired next project version.
   */
  public function getToVersion() {
    return $this->toVersion;
  }

  /**
   * Set the desired next project version.
   *
   * @param string $toVersion
   *   The desired next project version.
   *
   * @return \Drupal\automatic_updates\UpdateMetadata
   *   The update metadata.
   */
  public function setToVersion($toVersion) {
    $this->toVersion = $toVersion;
    return $this;
  }

}
