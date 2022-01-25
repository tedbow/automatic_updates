<?php

namespace Drupal\automatic_updates_test\Form;

use Drupal\automatic_updates\Form\UpdateReady;

/**
 * A test-only version of the form displayed before applying an update.
 */
class TestUpdateReady extends UpdateReady {

  /**
   * {@inheritdoc}
   */
  protected function getModulesWithStagedDatabaseUpdates(): array {
    return $this->state->get('automatic_updates_test.staged_database_updates', parent::getModulesWithStagedDatabaseUpdates());
  }

}
