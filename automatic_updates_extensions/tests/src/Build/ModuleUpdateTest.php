<?php

namespace Drupal\Tests\automatic_updates_extensions\Build;

use Drupal\Tests\automatic_updates\Build\UpdateTestBase;

/**
 * Tests updating modules in a staging area.
 *
 * @group automatic_updates_extensions
 */
class ModuleUpdateTest extends UpdateTestBase {

  /**
   * Tests updating a module in a staging area.
   */
  public function testApi(): void {
    $this->createTestProject('RecommendedProject');
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . '/../../../../tests/fixtures/release-history/drupal.9.8.1-security.xml',
      'alpha'  => __DIR__ . '/../../fixtures/release-history/alpha.1.1.0.xml',
    ]);

    // Set 'version' and 'project' for the 'alpha' module to enable the Update
    // to determine the update status.
    $system_info = ['alpha' => ['version' => '1.0.0', 'project' => 'alpha']];
    $system_info = var_export($system_info, TRUE);
    $code = <<<END
\$config['update_test.settings']['system_info'] = $system_info;
END;
    $this->writeSettings($code);

    $this->addRepository('alpha', __DIR__ . '/../../../../package_manager/tests/fixtures/alpha/1.0.0');
    $this->runComposer('COMPOSER_MIRROR_PATH_REPOS=1 composer require drupal/alpha --update-with-all-dependencies', 'project');

    $this->installModules(['automatic_updates_extensions_test_api', 'alpha']);

    // Change both modules' upstream version.
    $this->addRepository('alpha', __DIR__ . '/../../../../package_manager/tests/fixtures/alpha/1.1.0');

    // Use the API endpoint to create a stage and update the 'alpha' module to
    // 1.1.0. We ask the API to return the contents of the module's
    // composer.json file, so we can assert that they were updated to the
    // version we expect.
    // @see \Drupal\automatic_updates_extensions_test_api\ApiController::run()
    $query = http_build_query([
      'projects' => [
        'alpha' => '1.1.0',
      ],
      'files_to_return' => [
        'web/modules/contrib/alpha/composer.json',
      ],
    ]);
    $this->visit("/automatic-updates-extensions-test-api?$query");
    $mink = $this->getMink();
    $mink->assertSession()->statusCodeEquals(200);

    $file_contents = $mink->getSession()->getPage()->getContent();
    $file_contents = json_decode($file_contents, TRUE);

    $module_composer_json = json_decode($file_contents['web/modules/contrib/alpha/composer.json']);
    $this->assertSame('1.1.0', $module_composer_json->version);
  }

}
