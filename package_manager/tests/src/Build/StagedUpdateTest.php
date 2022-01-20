<?php

namespace Drupal\Tests\package_manager\Build;

/**
 * Tests updating packages in a staging area.
 *
 * @group package_manager
 */
class StagedUpdateTest extends TemplateProjectTestBase {

  /**
   * Tests that a stage only updates packages with changed constraints.
   */
  public function testStagedUpdate(): void {
    $this->createTestProject('RecommendedProject');

    $this->createModule('alpha');
    $this->createModule('bravo');
    $this->runComposer('COMPOSER_MIRROR_PATH_REPOS=1 composer require drupal/alpha drupal/bravo --update-with-all-dependencies', 'project');

    $this->installQuickStart('minimal');
    $this->formLogin($this->adminUsername, $this->adminPassword);
    $this->installModules(['package_manager_test_api']);

    // Change both modules' upstream version.
    $this->runComposer('composer config version 1.1.0', 'alpha');
    $this->runComposer('composer config version 1.1.0', 'bravo');

    // Use the API endpoint to create a stage and update bravo to 1.1.0. Even
    // though both modules are at version 1.1.0, only bravo should be updated.
    // We ask the API to return the contents of both modules' staged
    // composer.json files, so we can assert that the staged versions are what
    // we expect.
    // @see \Drupal\package_manager_test_api\ApiController::require()
    $query = http_build_query([
      'runtime' => [
        'drupal/bravo:1.1.0',
      ],
      'files_to_return' => [
        'web/modules/contrib/alpha/composer.json',
        'web/modules/contrib/bravo/composer.json',
      ],
    ]);
    $this->visit("/package-manager-test-api/require?$query");
    $mink = $this->getMink();
    $mink->assertSession()->statusCodeEquals(200);

    $staged_file_contents = $mink->getSession()->getPage()->getContent();
    $staged_file_contents = json_decode($staged_file_contents, TRUE);

    $expected_versions = [
      'alpha' => '1.0.0',
      'bravo' => '1.1.0',
    ];
    foreach ($expected_versions as $module_name => $expected_version) {
      $path = "web/modules/contrib/$module_name/composer.json";
      $staged_composer_json = json_decode($staged_file_contents[$path]);
      $this->assertSame($expected_version, $staged_composer_json->version);
    }
  }

  /**
   * Creates an empty module for testing purposes.
   *
   * @param string $name
   *   The machine name of the module, which can be added to the test site as
   *   'drupal/$name'.
   */
  private function createModule(string $name): void {
    $dir = $this->getWorkspaceDirectory() . '/' . $name;
    mkdir($dir);
    $this->assertDirectoryExists($dir);
    $this->runComposer("composer init --name drupal/$name --type drupal-module", $name);
    $this->runComposer('composer config version 1.0.0', $name);

    $repository = json_encode([
      'type' => 'path',
      'url' => $dir,
      'options' => [
        'symlink' => FALSE,
      ],
    ]);
    $this->runComposer("composer config repo.$name '$repository'", 'project');
  }

}
