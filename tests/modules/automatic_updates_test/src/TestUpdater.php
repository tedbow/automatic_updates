<?php

namespace Drupal\automatic_updates_test;

use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\automatic_updates\Updater;

/**
 * A test-only updater which can throw errors during the update process.
 */
class TestUpdater extends Updater {

  /**
   * Sets the errors to be thrown during the begin() method.
   *
   * @param \Drupal\automatic_updates\Validation\ValidationResult[] $errors
   *   The validation errors that should be thrown.
   */
  public static function setBeginErrors(array $errors): void {
    \Drupal::state()->set('automatic_updates_test.updater_errors', [
      'begin' => $errors,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function begin(): string {
    $errors = $this->state->get('automatic_updates_test.updater_errors', []);
    if (isset($errors['begin'])) {
      throw new UpdateException($errors['begin'], reset($errors['begin'])->getSummary());
    }
    return parent::begin();
  }

}
