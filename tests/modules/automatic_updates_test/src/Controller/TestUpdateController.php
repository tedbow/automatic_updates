<?php

namespace Drupal\automatic_updates_test\Controller;

use Drupal\automatic_updates\Controller\UpdateController;

/**
 * A test-only version of the update controller.
 */
class TestUpdateController extends UpdateController {

  /**
   * {@inheritdoc}
   */
  protected function pendingUpdatesExist(): bool {
    $pending_updates = $this->state()
      ->get('automatic_updates_test.staged_database_updates', []);

    // If the System module should expose a pending update, create one that will
    // be detected by the update hook registry. We only do this for System so
    // that there is NO way we could possibly evaluate any user input (i.e.,
    // if malicious code were somehow injected into state).
    if (array_key_exists('system', $pending_updates)) {
      // @codingStandardsIgnoreLine
      eval('function system_update_4294967294() {}');
    }
    return parent::pendingUpdatesExist();
  }

}
