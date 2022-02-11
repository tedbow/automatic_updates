<?php

namespace Drupal\automatic_updates_test\Validator;

use Drupal\package_manager\Validator\PendingUpdatesValidator;

/**
 * Defines a test-only implementation of the pending updates validator.
 */
class TestPendingUpdatesValidator extends PendingUpdatesValidator {

  /**
   * {@inheritdoc}
   */
  public function updatesExist(): bool {
    $pending_updates = \Drupal::state()
      ->get('automatic_updates_test.staged_database_updates', []);

    // If the System module should expose a pending update, create one that will
    // be detected by the update hook registry. We only do this for System so
    // that there is NO way we could possibly evaluate any user input (i.e.,
    // if malicious code were somehow injected into state).
    if (array_key_exists('system', $pending_updates)) {
      // @codingStandardsIgnoreLine
      eval('function system_update_4294967294() {}');
    }
    return parent::updatesExist();
  }

}
