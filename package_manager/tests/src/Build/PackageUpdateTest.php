<?php

namespace Drupal\Tests\package_manager\Build;

/**
 * Tests updating packages in a staging area.
 *
 * @group package_manager
 */
class PackageUpdateTest extends TemplateProjectTestBase {

  /**
   * Tests updating packages in a staging area.
   */
  public function testPackageUpdate(): void {
    $this->createTestProject('RecommendedProject');

    $this->addRepository('alpha', __DIR__ . '/../../fixtures/alpha/1.0.0');
    $this->addRepository('updated_module', __DIR__ . '/../../fixtures/updated_module/1.0.0');
    $this->runComposer('COMPOSER_MIRROR_PATH_REPOS=1 composer require drupal/alpha drupal/updated_module --update-with-all-dependencies', 'project');

    $this->installQuickStart('minimal');
    $this->formLogin($this->adminUsername, $this->adminPassword);
    // The updated_module provides actual Drupal-facing functionality that we're
    // testing as well, so we need to install it.
    $this->installModules(['package_manager_test_api', 'updated_module']);

    // Change both modules' upstream version.
    $this->addRepository('alpha', __DIR__ . '/../../fixtures/alpha/1.1.0');
    $this->addRepository('updated_module', __DIR__ . '/../../fixtures/updated_module/1.1.0');

    // Use the API endpoint to create a stage and update updated_module to
    // 1.1.0. Even though both modules have version 1.1.0 available, only
    // updated_module should be updated. We ask the API to return the contents
    // of both modules' composer.json files, so we can assert that they were
    // updated to the versions we expect.
    // @see \Drupal\package_manager_test_api\ApiController::run()
    $query = http_build_query([
      'runtime' => [
        'drupal/updated_module:1.1.0',
      ],
      'files_to_return' => [
        'web/modules/contrib/alpha/composer.json',
        'web/modules/contrib/updated_module/composer.json',
        'bravo.txt',
        "system_changes.json",
      ],
    ]);
    $this->visit("/package-manager-test-api?$query");
    $mink = $this->getMink();
    $mink->assertSession()->statusCodeEquals(200);

    $file_contents = $mink->getSession()->getPage()->getContent();
    $file_contents = json_decode($file_contents, TRUE);

    $expected_versions = [
      'alpha' => '1.0.0',
      'updated_module' => '1.1.0',
    ];
    foreach ($expected_versions as $module_name => $expected_version) {
      $path = "web/modules/contrib/$module_name/composer.json";
      $module_composer_json = json_decode($file_contents[$path]);
      $this->assertSame($expected_version, $module_composer_json->version);
    }
    // The post-apply event subscriber in updated_module 1.1.0 should have
    // created this file.
    // @see \Drupal\updated_module\PostApplySubscriber::postApply()
    $this->assertSame('Bravo!', $file_contents['bravo.txt']);

    $results = json_decode($file_contents['system_changes.json'], TRUE);
    $expected_pre_apply_results = [
      'return value of existing global function' => 'pre-update-value',
      'new global function exists' => 'not exists',
      'path of changed route' => '/updated-module/changed/pre',
      'deleted route exists' => 'exists',
      'new route exists' => 'not exists',
    ];
    $this->assertSame($expected_pre_apply_results, $results['pre']);

    $expected_post_apply_results = [
      // Existing functions will still use the pre-update version.
      'return value of existing global function' => 'pre-update-value',
      // New functions that were added in .module files will not be available.
      'new global function exists' => 'not exists',
      // Definitions for existing routes should be updated.
      'path of changed route' => '/updated-module/changed/post',
      // Routes deleted from the updated module should not be available.
      'deleted route exists' => 'not exists',
      // Routes added to the updated module should be available.
      'new route exists' => 'exists',
    ];
    $this->assertSame($expected_post_apply_results, $results['post']);
  }

  /**
   * Adds a path repository to the test site.
   *
   * @param string $name
   *   An arbitrary name for the repository.
   * @param string $path
   *   The path of the repository. Must exist in the file system.
   */
  private function addRepository(string $name, string $path): void {
    $this->assertDirectoryExists($path);

    $repository = json_encode([
      'type' => 'path',
      'url' => $path,
      'options' => [
        'symlink' => FALSE,
      ],
    ]);
    $this->runComposer("composer config repo.$name '$repository'", 'project');
  }

}
