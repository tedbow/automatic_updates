<?php

namespace Drupal\Tests\package_manager\Functional;

use Drupal\package_manager\Stage;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests that Package Manager's requirements check for the failure marker.
 *
 * @group package_manager
 */
class FailureMarkerRequirementTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'package_manager',
    'package_manager_bypass',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that error is shown if failure marker already exists.
   */
  public function testFailureMarkerExists() {
    $account = $this->drupalCreateUser([
      'administer site configuration',
    ]);
    $this->drupalLogin($account);

    $this->container->get('package_manager.path_locator')
      ->setPaths($this->publicFilesDirectory, NULL, NULL, NULL);

    $failure_marker = $this->container->get('package_manager.failure_marker');
    $message = 'Package Manager is here to wreck your day.';
    $failure_marker->write($this->createMock(Stage::class), $message);
    $path = $failure_marker->getPath();
    $this->assertFileExists($path);
    $this->assertStringStartsWith($this->publicFilesDirectory, $path);

    $this->drupalGet('/admin/reports/status');
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('Failed update detected');
    $assert_session->pageTextContains($message);
  }

}
