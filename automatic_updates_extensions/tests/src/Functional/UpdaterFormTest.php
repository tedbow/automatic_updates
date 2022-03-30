<?php

namespace Drupal\Tests\automatic_updates_extensions\Functional;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Functional\AutomaticUpdatesFunctionalTestBase;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;

/**
 * Tests updating using the form.
 *
 * @group automatic_updates_extensions
 */
class UpdaterFormTest extends AutomaticUpdatesFunctionalTestBase {

  use ValidationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates_test',
    'automatic_updates_extensions',
    'semver_test',
  ];

  /**
   * Project to test updates.
   *
   * @var string
   */
  protected $updateProject = 'semver_test';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setReleaseMetadata(__DIR__ . '/../../../../tests/fixtures/release-history/semver_test.1.1.xml');
  }

  /**
   * Sets installed project version.
   *
   * @todo This is copied from core. We need to file a core issue so we do not
   *    have to copy this.
   */
  protected function setProjectInstalledVersion($version) {
    $this->config('update.settings')
      ->set('fetch.url', $this->baseUrl . '/automatic-update-test')
      ->save();
    $system_info = [
      $this->updateProject => [
        'project' => $this->updateProject,
        'version' => $version,
        'hidden' => FALSE,
      ],
      // Ensure Drupal core on the same version for all test runs.
      'drupal' => [
        'project' => 'drupal',
        'version' => '8.0.0',
        'hidden' => FALSE,
      ],
    ];
    $this->config('update_test.settings')
      ->set('system_info', $system_info)
      ->save();
  }

  /**
   * Asserts the table shows the updates.
   */
  private function assertTableShowsUpdates() {
    $assert = $this->assertSession();
    $assert->elementTextContains('css', '.update-recommended td:nth-of-type(2)', 'Semver Test');
    $assert->elementTextContains('css', '.update-recommended td:nth-of-type(3)', '8.1.0');
    $assert->elementTextContains('css', '.update-recommended td:nth-of-type(4)', '8.1.1');
    $assert->elementsCount('css', '.update-recommended tbody tr', 1);
  }

  /**
   * Tests the form when a module requires an update.
   */
  public function testHasUpdate(): void {
    $assert = $this->assertSession();
    $user = $this->createUser(['administer site configuration']);
    $this->drupalLogin($user);
    $this->setProjectInstalledVersion('8.1.0');
    $this->checkForUpdates();
    $this->drupalGet('/admin/automatic-update-extensions');
    $assert->pageTextContains('Access Denied');
    $assert->pageTextNotContains('Automatic Updates Form');
    $user = $this->createUser(['administer software updates']);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/automatic-update-extensions');
    $this->assertTableShowsUpdates();
    $assert->pageTextContains('Automatic Updates Form');
    $assert->buttonExists('Update');
  }

  /**
   * Tests the form when there are no available updates.
   */
  public function testNoUpdate(): void {
    $assert = $this->assertSession();
    $user = $this->createUser([
      'administer site configuration',
      'administer software updates',
    ]);
    $this->drupalLogin($user);
    $this->setProjectInstalledVersion('8.1.1');
    $this->checkForUpdates();
    $this->drupalGet('/admin/automatic-update-extensions');
    $assert->pageTextContains('There are no available updates.');
    $assert->buttonNotExists('Update');
  }

  /**
   * Test the form for errors.
   */
  public function testErrors(): void {
    $assert = $this->assertSession();
    $user = $this->createUser([
      'administer site configuration',
      'administer software updates',
    ]);
    $this->drupalLogin($user);
    $this->setProjectInstalledVersion('8.1.0');
    $this->checkForUpdates();
    $this->drupalGet('/admin/automatic-update-extensions');
    $this->assertTableShowsUpdates();
    $message = t("You've not experienced Shakespeare until you have read him in the original Klingon.");
    $error = ValidationResult::createError([$message]);
    TestSubscriber1::setTestResult([$error], ReadinessCheckEvent::class);
    $this->getSession()->reload();
    $assert->pageTextContains($message);
    $assert->pageTextContains(static::$errorsExplanation);
    $assert->pageTextNotContains(static::$warningsExplanation);
    $assert->buttonNotExists('Update');
  }

  /**
   * Test the form for warning messages.
   */
  public function testWarnings(): void {
    $assert = $this->assertSession();
    $user = $this->createUser([
      'administer site configuration',
      'administer software updates',
    ]);
    $this->drupalLogin($user);
    $this->setProjectInstalledVersion('8.1.0');
    $this->checkForUpdates();
    $message = t("Warning! Updating this module may cause an error.");
    $warning = ValidationResult::createWarning([$message]);
    TestSubscriber1::setTestResult([$warning], ReadinessCheckEvent::class);
    $this->drupalGet('/admin/automatic-update-extensions');
    $this->assertTableShowsUpdates();
    $assert->pageTextContains(static::$warningsExplanation);
    $assert->pageTextNotContains(static::$errorsExplanation);
    $assert->buttonExists('Update');
  }

}
