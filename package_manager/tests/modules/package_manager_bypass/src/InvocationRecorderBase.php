<?php

namespace Drupal\package_manager_bypass;

/**
 * Records information about method invocations.
 *
 * This can be used by functional tests to ensure that the bypassed Composer
 * Stager services were called as expected. Kernel and unit tests should use
 * regular mocks instead.
 */
abstract class InvocationRecorderBase {

  /**
   * Returns the arguments from every invocation of the main class method.
   *
   * @return array[]
   *   The arguments from every invocation of the main class method.
   */
  public function getInvocationArguments(): array {
    return \Drupal::state()->get(static::class, []);
  }

  /**
   * Records the arguments from an invocation of the main class method.
   *
   * @param mixed ...$arguments
   *   The arguments that the main class method was called with.
   */
  protected function saveInvocationArguments(...$arguments): void {
    $invocations = $this->getInvocationArguments();
    $invocations[] = $arguments;
    \Drupal::state()->set(static::class, $invocations);
  }

}
