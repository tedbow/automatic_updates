<?php

namespace Drupal\automatic_updates;

/**
 * Interface for database_update_handler plugins.
 */
interface DatabaseUpdateHandlerInterface {

  /**
   * Returns the translated plugin label.
   *
   * @return string
   *   The translated title.
   */
  public function label();

  /**
   * Handle database updates.
   *
   * @return bool
   *   TRUE if database update was handled successfully, FALSE otherwise.
   */
  public function execute();

}
