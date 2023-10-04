<?php

namespace Drupal\package_manager;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Drupal\Core\Utility\Error as CoreError;

/**
 * Temporary class until 10.0.x is no longer supported.
 *
 * // @todo Remove this class in https://drupal.org/i/3377458.
 */
class Error {

  /**
   * Log a formatted exception message to the provided logger.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Throwable $exception
   *   The exception.
   * @param string $message
   *   (optional) The message.
   * @param array $additional_variables
   *   (optional) Any additional variables.
   * @param string $level
   *   The PSR log level. Must be valid constant in \Psr\Log\LogLevel.
   */
  public static function logException(LoggerInterface $logger, \Throwable $exception, string $message = CoreError::DEFAULT_ERROR_MESSAGE, array $additional_variables = [], string $level = LogLevel::ERROR): void {
    $logger->log($level, $message, CoreError::decodeException($exception) + $additional_variables);
  }

}
