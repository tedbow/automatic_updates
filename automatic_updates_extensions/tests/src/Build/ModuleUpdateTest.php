<?php

namespace Drupal\Tests\automatic_updates_extensions\Build;

use Drupal\Tests\automatic_updates\Build\UpdateTestBase;
use Drupal\Tests\automatic_updates_extensions\Traits\FormTestTrait;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Tests updating modules in a staging area.
 *
 * @group automatic_updates_extensions
 */
class ModuleUpdateTest extends UpdateTestBase {

  use FormTestTrait;

  /**
   * {@inheritdoc}
   */
  protected function createTestProject(string $template): void {
    parent::createTestProject($template);
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . '/../../../../package_manager/tests/fixtures/release-history/drupal.9.8.1-security.xml',
      'alpha'  => __DIR__ . '/../../fixtures/release-history/alpha.1.1.0.xml',
      'new_module' => __DIR__ . '/../../fixtures/release-history/new_module.1.1.0.xml',
    ]);

    // Set 'version' and 'project' for the 'alpha' and 'new_module' module to
    // enable the Update to determine the update status.
    $system_info = [
      'alpha' => ['version' => '1.0.0', 'project' => 'alpha'],
      'new_module' => ['version' => '1.0.0', 'project' => 'new_module'],
    ];
    $system_info = var_export($system_info, TRUE);
    $code = <<<END
\$config['update_test.settings']['system_info'] = $system_info;
END;
    $this->writeSettings($code);
    $this->addRepository('alpha', $this->copyFixtureToTempDirectory(__DIR__ . '/../../../../package_manager/tests/fixtures/alpha/1.0.0'));
    $this->runComposer('COMPOSER_MIRROR_PATH_REPOS=1 composer require drupal/alpha --update-with-all-dependencies', 'project');
    $this->assertModuleVersion('alpha', '1.0.0');
    $fs = new SymfonyFilesystem();
    $fs->mirror($this->copyFixtureToTempDirectory(__DIR__ . '/../../fixtures/new_module'), $this->getWorkspaceDirectory() . '/project/web/modules');
    $this->installModules([
      'automatic_updates_extensions_test_api',
      'alpha',
      'new_module',
    ]);

    // Change both modules' upstream version.
    $this->addRepository('alpha', $this->copyFixtureToTempDirectory(__DIR__ . '/../../../../package_manager/tests/fixtures/alpha/1.1.0'));
  }

  /**
   * Tests updating a module in a staging area via the API.
   */
  public function testApi(): void {
    $this->createTestProject('RecommendedProject');
    // Use the API endpoint to create a stage and update the 'new_module' module
    // to 1.1.0.
    // @see \Drupal\automatic_updates_extensions_test_api\ApiController::run()
    $query = http_build_query([
      'projects' => [
        'new_module' => '1.1.0',
      ],
    ]);
    $this->visit("/automatic-updates-extensions-test-api?$query");
    $mink = $this->getMink();
    $mink->assertSession()->statusCodeEquals(500);
    $page_text = $mink->getSession()->getPage()->getText();
    $this->assertStringContainsString('The project new_module is not a Drupal project known to Composer and cannot be updated.', $page_text);
    $this->assertStringContainsString('new_module', $page_text);
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

  /**
   * Tests updating a module in a staging area via the UI.
   */
  public function testUi(): void {
    $this->createTestProject('RecommendedProject');

    $mink = $this->getMink();
    $session = $mink->getSession();
    $page = $session->getPage();
    $assert_session = $mink->assertSession();

    $this->visit('/admin/reports/updates');
    // Confirm that 'New Module' project, which is not installed via Composer,
    // has a 1.1.0 update release on the 'Available Updates' page.
    $this->assertReportProjectUpdateVersion('New Module', '1.1.0');
    $page->clickLink('Update Extensions');
    $this->assertUpdateTableRow($assert_session, 'Alpha', '1.0.0', '1.1.0', 1);
    // Confirm that a 'New Module' project does not appear on the form.
    $assert_session->pageTextContains('Other updates were found, but they must be performed manually.');
    $assert_session->fieldNotExists('projects[new_module]');
    $page->checkField('projects[alpha]');
    $page->pressButton('Update');
    $this->waitForBatchJob();
    $assert_session->pageTextContains('Ready to update');
    $page->pressButton('Continue');
    $this->waitForBatchJob();
    $assert_session->pageTextContains('Update complete!');
    $this->assertModuleVersion('alpha', '1.1.0');
  }

  /**
   * Assert a project version is on the Available Updates page.
   *
   * @param string $project_title
   *   The project title.
   * @param string $expected_version
   *   The expected version.
   */
  protected function assertReportProjectUpdateVersion(string $project_title, string $expected_version): void {
    $mink = $this->getMink();
    $session = $mink->getSession();
    $title_element = $session->getPage()
      ->find('css', ".project-update__title:contains(\"$project_title\")");
    $this->assertNotNull($title_element, "Title element found for $project_title");
    $version_element = $title_element->getParent()->find('css', '.project-update__version-details');
    $this->assertStringContainsString($expected_version, $version_element->getText());
  }

  /**
   * Asserts a module is a specified version.
   *
   * @param string $module_name
   *   The module name.
   * @param string $version
   *   The expected version.
   */
  private function assertModuleVersion(string $module_name, string $version): void {
    $web_root = $this->getWebRoot();
    $composer_json = file_get_contents("$web_root/modules/contrib/$module_name/composer.json");
    $data = json_decode($composer_json, TRUE);
    $this->assertSame($version, $data['version']);
  }

}
