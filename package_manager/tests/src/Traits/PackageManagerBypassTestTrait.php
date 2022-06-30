<?php

namespace Drupal\Tests\package_manager\Traits;

/**
 * Common functions for testing using the package_manager_bypass module.
 */
trait PackageManagerBypassTestTrait {

  /**
   * Asserts the number of times an update was staged.
   *
   * @param int $attempted_times
   *   The expected number of times an update was staged.
   */
  private function assertUpdateStagedTimes(int $attempted_times): void {
    /** @var \Drupal\package_manager_bypass\BypassedStagerServiceBase $beginner */
    $beginner = $this->container->get('package_manager.beginner');
    $this->assertCount($attempted_times, $beginner->getInvocationArguments());

    /** @var \Drupal\package_manager_bypass\BypassedStagerServiceBase $stager */
    $stager = $this->container->get('package_manager.stager');
    // If an update was attempted, then there will be two calls to the stager:
    // one to change the constraints in composer.json, and another to actually
    // update the installed dependencies.
    $this->assertCount($attempted_times * 2, $stager->getInvocationArguments());

    /** @var \Drupal\package_manager_bypass\BypassedStagerServiceBase $committer */
    $committer = $this->container->get('package_manager.committer');
    $this->assertEmpty($committer->getInvocationArguments());
  }

}
