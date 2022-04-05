<?php

namespace Drupal\Tests\automatic_updates_extensions\Build;

use Drupal\Tests\package_manager\Build\TemplateProjectTestBase;

/**
 * Tests updating modules in a staging area.
 *
 * @group automatic_updates_extensions
 */
class ModuleUpdateTest extends TemplateProjectTestBase {

  /**
   * Tests updating a module in a staging area.
   */
  public function testApi(): void {
    $this->createTestProject('RecommendedProject');

    $this->addRepository('alpha', __DIR__ . '/../../../../package_manager/tests/fixtures/alpha/1.0.0');
    $this->runComposer('COMPOSER_MIRROR_PATH_REPOS=1 composer require drupal/alpha --update-with-all-dependencies', 'project');

    $this->installQuickStart('minimal');
    $this->formLogin($this->adminUsername, $this->adminPassword);
    $this->installModules(['automatic_updates_extensions_test_api']);

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
