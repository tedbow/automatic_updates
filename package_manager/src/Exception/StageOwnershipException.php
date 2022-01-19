<?php

namespace Drupal\package_manager\Exception;

/**
 * Exception thrown if a stage encounters an ownership or locking error.
 *
 * Should not be thrown by external code.
 */
class StageOwnershipException extends StageException {
}
