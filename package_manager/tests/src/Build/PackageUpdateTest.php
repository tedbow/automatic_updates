<?php

namespace Drupal\Tests\package_manager\Build;

use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PostCreateEvent;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreDestroyEvent;
use Drupal\package_manager\Event\PreRequireEvent;

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

    $this->addRepository('alpha', $this->copyFixtureToTempDirectory(__DIR__ . '/../../fixtures/alpha/1.0.0'));
    $this->addRepository('updated_module', $this->copyFixtureToTempDirectory(__DIR__ . '/../../fixtures/updated_module/1.0.0'));
    $this->setReleaseMetadata([
      'updated_module' => __DIR__ . '/../../fixtures/release-history/updated_module.1.1.0.xml',
    ]);
    $this->runComposer('COMPOSER_MIRROR_PATH_REPOS=1 composer require drupal/alpha drupal/updated_module --update-with-all-dependencies', 'project');

    // The updated_module provides actual Drupal-facing functionality that we're
    // testing as well, so we need to install it.
    $this->installModules(['updated_module']);

    // Change both modules' upstream version.
    $this->addRepository('alpha', $this->copyFixtureToTempDirectory(__DIR__ . '/../../fixtures/alpha/1.1.0'));
    $this->addRepository('updated_module', $this->copyFixtureToTempDirectory(__DIR__ . '/../../fixtures/updated_module/1.1.0'));

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

    $assert_session = $mink->assertSession();
    // There should be information for every event triggered.
    $this->visit('/admin/reports/dblog');
    file_put_contents("/Users/kunal.sachdev/www/test_sample.html", $mink->getSession()->getPage()->getContent());
    $events = [
      PreCreateEvent::class,
      PostCreateEvent::class,
      PreRequireEvent::class,
      PostRequireEvent::class,
      PreApplyEvent::class,
      PostApplyEvent::class,
      PreDestroyEvent::class,
      PostDestroyEvent::class,
    ];
    foreach ($events as $event) {
      $assert_session->pageTextContains('Event: ' . $event);
    }
  }

}
