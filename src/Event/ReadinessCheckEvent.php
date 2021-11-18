<?php

namespace Drupal\automatic_updates\Event;

use Drupal\automatic_updates\Updater;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\WarningEventInterface;
use Drupal\package_manager\ValidationResult;

/**
 * Event fired when checking if the site could perform an update.
 *
 * An update is not actually being started when this event is being fired. It
 * should be used to notify site admins if the site is in a state which will
 * not allow automatic updates to succeed.
 *
 * This event should only be dispatched from ReadinessValidationManager to
 * allow caching of the results.
 *
 * @see \Drupal\automatic_updates\Validation\ReadinessValidationManager
 */
class ReadinessCheckEvent extends PreOperationStageEvent implements WarningEventInterface {

  /**
   * The desired package versions to update to, keyed by package name.
   *
   * @var string[]
   */
  protected $packageVersions;

  /**
   * Constructs a ReadinessCheckEvent object.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   * @param string[] $package_versions
   *   (optional) The desired package versions to update to, keyed by package
   *   name.
   */
  public function __construct(Updater $updater, array $package_versions = []) {
    parent::__construct($updater);
    $this->packageVersions = $package_versions;
  }

  /**
   * Returns the desired package versions to update to.
   *
   * @return string[]
   *   The desired package versions to update to, keyed by package name.
   */
  public function getPackageVersions(): array {
    return $this->packageVersions;
  }

  /**
   * {@inheritdoc}
   */
  public function addWarning(array $messages, ?TranslatableMarkup $summary = NULL) {
    $this->results[] = ValidationResult::createWarning($messages, $summary);
  }

}
