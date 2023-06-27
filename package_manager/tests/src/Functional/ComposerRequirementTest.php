<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Functional;

use Drupal\package_manager\ComposerInspector;
use Drupal\Tests\BrowserTestBase;
use PhpTuf\ComposerStager\Infrastructure\Service\Finder\ExecutableFinderInterface;

/**
 * Tests that Package Manager shows the Composer version on the status report.
 *
 * @group package_manager
 * @internal
 */
class ComposerRequirementTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['package_manager'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that Composer and file syncer info is listed on the status report.
   */
  public function testComposerInfoShown(): void {
    /** @var \PhpTuf\ComposerStager\Infrastructure\Service\Finder\ExecutableFinderInterface $executable_finder */
    $executable_finder = $this->container->get(ExecutableFinderInterface::class);
    $composer_path = $executable_finder->find('composer');
    $composer_version = $this->container->get(ComposerInspector::class)->getVersion();

    $account = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($account);
    $this->drupalGet('/admin/reports/status');
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('Composer version');
    $assert_session->responseContains("$composer_version (<code>$composer_path</code>)");

    // If the path to Composer is invalid, we should see the error message
    // that gets raised when we try to get its version.
    $this->config('package_manager.settings')
      ->set('executables.composer', '/path/to/composer')
      ->save();
    $this->getSession()->reload();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Composer was not found. The error message was: The command "\'/path/to/composer\' \'--format=json\'" failed.');
  }

}
