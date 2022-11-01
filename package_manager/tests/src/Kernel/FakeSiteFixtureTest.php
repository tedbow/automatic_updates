<?php

namespace Drupal\Tests\package_manager\Kernel;

/**
 * Test that the 'fake-site' fixture is a valid starting point.
 *
 * @group package_manager
 */
class FakeSiteFixtureTest extends PackageManagerKernelTestBase {

  /**
   * Tests the complete stage life cycle using the 'fake-site' fixture.
   */
  public function testLifeCycle(): void {
    $this->assertStatusCheckResults([]);
    $this->assertResults([]);
    // Ensure there are no validation errors after the stage lifecycle has been
    // completed.
    $this->assertStatusCheckResults([]);
  }

}
