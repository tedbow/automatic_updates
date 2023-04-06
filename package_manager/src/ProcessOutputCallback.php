<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use PhpTuf\ComposerStager\Domain\Service\ProcessOutputCallback\ProcessOutputCallbackInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * A process callback for capturing output.
 *
 * @see \Symfony\Component\Process\Process
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class ProcessOutputCallback implements ProcessOutputCallbackInterface, LoggerAwareInterface {

  use LoggerAwareTrait;

  /**
   * The output buffer.
   *
   * @var string
   */
  private string $outBuffer = '';

  /**
   * The error buffer.
   *
   * @var string
   */
  private string $errorBuffer = '';

  /**
   * Constructs a ProcessOutputCallback object.
   */
  public function __construct() {
    $this->setLogger(new NullLogger());
  }

  /**
   * {@inheritdoc}
   */
  public function __invoke(string $type, string $buffer): void {
    if ($type === self::OUT) {
      $this->outBuffer .= $buffer;
    }
    elseif ($type === self::ERR) {
      $this->errorBuffer .= $buffer;
    }
    else {
      throw new \InvalidArgumentException("Unsupported output type: '$type'");
    }
  }

  /**
   * Gets the output.
   *
   * If there is anything in the error buffer, it will be logged as a warning.
   *
   * @return string|null
   *   The output or NULL if there is none.
   */
  public function getOutput(): ?string {
    $error_output = $this->getErrorOutput();
    if ($error_output) {
      $this->logger->warning($error_output);
    }
    return trim($this->outBuffer) !== '' ? $this->outBuffer : NULL;
  }

  /**
   * Gets the parsed JSON output.
   *
   * @return mixed
   *   The decoded JSON output or NULL if there isn't any.
   */
  public function parseJsonOutput(): mixed {
    $output = $this->getOutput();
    if ($output !== NULL) {
      return json_decode($output, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    return NULL;
  }

  /**
   * Gets the error output.
   *
   * @return string|null
   *   The error output or NULL if there isn't any.
   */
  public function getErrorOutput(): ?string {
    return trim($this->errorBuffer) !== '' ? $this->errorBuffer : NULL;
  }

  /**
   * Resets buffers.
   *
   * @return self
   */
  public function reset(): self {
    $this->errorBuffer = '';
    $this->outBuffer = '';
    return $this;
  }

}
