<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Build;

/**
 * Tests installing packages in a stage directory.
 *
 * @group package_manager
 * @internal
 */
class PackageInstallTest extends TemplateProjectTestBase {

  /**
   * Tests installing packages in a stage directory.
   */
  public function testPackageInstall(): void {
    $this->createTestProject('RecommendedProject');

    $this->setReleaseMetadata([
      'alpha' => __DIR__ . '/../../fixtures/release-history/alpha.1.1.0.xml',
    ]);
    $this->addRepository('alpha', $this->copyFixtureToTempDirectory(__DIR__ . '/../../fixtures/build_test_projects/alpha/1.0.0'));

    // Use the API endpoint to create a stage and install alpha 1.0.0. We ask
    // the API to return the contents of composer.json file of installed module,
    // so we can assert that the module was installed with the expected version.
    // @see \Drupal\package_manager_test_api\ApiController::run()
    $query = http_build_query([
      'runtime' => [
        'drupal/alpha:1.0.0',
      ],
      'files_to_return' => [
        'web/modules/contrib/alpha/composer.json',
      ],
    ]);
    $this->visit("/package-manager-test-api?$query");
    $mink = $this->getMink();
    $mink->assertSession()->statusCodeEquals(200);

    $file_contents = $mink->getSession()->getPage()->getContent();
    $file_contents = json_decode($file_contents, TRUE);

    $this->assertArrayHasKey('web/modules/contrib/alpha/composer.json', $file_contents);
  }

}
