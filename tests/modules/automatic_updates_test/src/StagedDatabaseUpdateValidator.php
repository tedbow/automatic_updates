<?php

namespace Drupal\automatic_updates_test;

use Drupal\package_manager\Validator\StagedDBUpdateValidator as BaseValidator;
use Drupal\Core\Extension\Extension;
use Drupal\Core\State\StateInterface;
use Drupal\package_manager\Stage;

/**
 * Allows tests to dictate which extensions have staged database updates.
 */
class StagedDatabaseUpdateValidator extends BaseValidator {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  private $state;

  /**
   * Sets the state service dependency.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function setState(StateInterface $state): void {
    $this->state = $state;
  }

  /**
   * Sets the names of the extensions which should have staged database updates.
   *
   * @param string[]|null $extensions
   *   The machine names of the extensions which should say they have staged
   *   database updates, or NULL to defer to the parent class.
   */
  public static function setExtensionsWithUpdates(?array $extensions): void {
    \Drupal::state()->set(static::class, $extensions);
  }

  /**
   * {@inheritdoc}
   */
  public function hasStagedUpdates(Stage $stage, Extension $extension): bool {
    $extensions = $this->state->get(static::class);
    if (isset($extensions)) {
      return in_array($extension->getName(), $extensions, TRUE);
    }
    return parent::hasStagedUpdates($stage, $extension);
  }

}
