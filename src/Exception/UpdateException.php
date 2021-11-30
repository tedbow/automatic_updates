<?php

namespace Drupal\automatic_updates\Exception;

use Drupal\package_manager\Exception\StageValidationException;

/**
 * Defines a custom exception for a failure during an update.
 */
class UpdateException extends StageValidationException {
}
