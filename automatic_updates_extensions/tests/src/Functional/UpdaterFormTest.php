<?php

namespace Drupal\Tests\automatic_updates_extensions\Functional;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Functional\AutomaticUpdatesFunctionalTestBase;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use Drupal\Tests\automatic_updates_extensions\Traits\FormTestTrait;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;

/**
 * Tests updating using the form.
 *
 * @group automatic_updates_extensions
 */
class UpdaterFormTest extends AutomaticUpdatesFunctionalTestBase {

  use ValidationTestTrait;
  use FormTestTrait;
  use PackageManagerBypassTestTrait;

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
    'block',
    'semver_test',
    'aaa_update_test',
  ];

  /**
   * Project to test updates.
   *
   * @var string
   */
  protected $updateProject = 'semver_test';

  /**
   * Data provider for testSuccessfulUpdate().
   *
   * @return array[]
   *   The test cases.
   */
  public function providerSuccessfulUpdate() {
    return [
      'maintiance_mode_on, semver' => [TRUE, 'semver_test', '8.1.0', '8.1.1'],
      'maintiance_mode_off, legacy' => [FALSE, 'aaa_update_test', '8.x-2.0', '8.x-2.1'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/semver_test.1.1.xml');
    $this->drupalLogin($this->rootUser);
    $this->drupalPlaceBlock('local_tasks_block', ['primary' => TRUE]);
  }

  /**
   * Sets installed project version.
   *
   * @todo This is copied from core. We need to file a core issue so we do not
   *    have to copy this.
   */
  protected function setProjectInstalledVersion($version) {
    $this->config('update.settings')
      ->set('fetch.url', $this->baseUrl . '/test-release-history')
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
   *
   * @param string $expected_project_title
   *   The expected project title.
   * @param string $expected_installed_version
   *   The expected installed version.
   * @param string $expected_update_version
   *   The expected update version.
   */
  private function assertTableShowsUpdates(string $expected_project_title, string $expected_installed_version, string $expected_update_version): void {
    $this->assertUpdateTableRow($this->assertSession(), $expected_project_title, $expected_installed_version, $expected_update_version);
  }

  /**
   * Tests an update that has no errors or special conditions.
   *
   * @param bool $maintenance_mode_on
   *   Whether maintenance should be on at the beginning of the update.
   * @param string $project_name
   *   The project name.
   * @param string $installed_version
   *   The installed version.
   * @param string $update_version
   *   The update version.
   *
   * @dataProvider providerSuccessfulUpdate
   */
  public function testSuccessfulUpdate(bool $maintenance_mode_on, string $project_name, string $installed_version, string $update_version): void {
    $this->updateProject = $project_name;
    $this->setReleaseMetadata(__DIR__ . "/../../fixtures/release-history/$project_name.1.1.xml");
    $this->setProjectInstalledVersion($installed_version);
    $this->checkForUpdates();
    $state = $this->container->get('state');
    $state->set('system.maintenance_mode', $maintenance_mode_on);

    $page = $this->getSession()->getPage();
    // Navigate to the automatic updates form.
    $this->drupalGet('/admin/reports/updates');
    $this->clickLink('Update Extensions');
    $this->assertTableShowsUpdates(
      $project_name === 'semver_test' ? 'Semver Test' : 'AAA Update test',
      $installed_version,
      $update_version
    );
    $page->checkField('projects[' . $this->updateProject . ']');
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    // Confirm that the site was put into maintenance mode if needed.
    $this->assertSame($state->get('system.maintenance_mode'), $maintenance_mode_on);
    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    $assert_session = $this->assertSession();
    $assert_session->addressEquals('/admin/reports/updates');
    // Confirm that the site was in maintenance before the update was applied.
    // @see \Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber::handleEvent()
    $this->assertTrue($state->get(PreApplyEvent::class . '.system.maintenance_mode'));
    $assert_session->pageTextContainsOnce('Update complete!');
    // Confirm the site was returned to the original maintenance mode state.
    $this->assertSame($state->get('system.maintenance_mode'), $maintenance_mode_on);
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
    $this->drupalGet('admin/reports/updates/automatic-update-extensions');
    $assert->pageTextContains('Access Denied');
    $assert->pageTextNotContains('Automatic Updates Form');
    $user = $this->createUser(['administer software updates']);
    $this->drupalLogin($user);
    $this->drupalGet('admin/reports/updates/automatic-update-extensions');
    $this->assertTableShowsUpdates('Semver Test', '8.1.0', '8.1.1');
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
    $this->drupalGet('admin/reports/updates/automatic-update-extensions');
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
    $this->drupalGet('admin/reports/updates/automatic-update-extensions');
    $this->assertTableShowsUpdates('Semver Test', '8.1.0', '8.1.1');
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
    $this->setProjectInstalledVersion('8.1.0');
    $this->checkForUpdates();
    $message = t("Warning! Updating this module may cause an error.");
    $warning = ValidationResult::createWarning([$message]);
    TestSubscriber1::setTestResult([$warning], ReadinessCheckEvent::class);
    // Navigate to the automatic updates form.
    $this->drupalGet('/admin/reports/updates');
    $this->clickLink('Update Extensions');
    $this->assertTableShowsUpdates('Semver Test', '8.1.0', '8.1.1');
    $assert->pageTextContains(static::$warningsExplanation);
    $assert->pageTextNotContains(static::$errorsExplanation);
    $assert->buttonExists('Update');
  }

}
