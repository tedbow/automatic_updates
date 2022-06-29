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
    'automatic_updates_extensions_test',
    'block',
    'semver_test',
    'aaa_update_test',
  ];

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
    // In this test class, some modules are added and this validator will
    // complain because these are not installed via composer. This validator
    // already has test coverage.
    // @see \Drupal\Tests\automatic_updates_extensions\Build\ModuleUpdateTest
    $this->disableValidators[] = 'automatic_updates_extensions.validator.packages_installed_with_composer';
    parent::setUp();
    $user = $this->createUser([
      'administer site configuration',
      'administer software updates',
      'access site in maintenance mode',
    ]);
    // We need this fixture as only projects installed via composer will show up
    // on the form.
    $this->container->get('state')->set('automatic_updates_extensions_test.active_path', __DIR__ . '/../../fixtures/active_composer/two_projects');
    $this->drupalLogin($user);
    $this->drupalPlaceBlock('local_tasks_block', ['primary' => TRUE]);
  }

  /**
   * Sets installed project version.
   *
   * @todo This is copied from core. We need to file a core issue so we do not
   *    have to copy this.
   */
  protected function setProjectInstalledVersion($project_versions) {
    $this->config('update.settings')
      ->set('fetch.url', $this->baseUrl . '/test-release-history')
      ->save();
    $system_info = [];
    foreach ($project_versions as $project_name => $version) {
      $system_info[$project_name] = [
        'project' => $project_name,
        'version' => $version,
        'hidden' => FALSE,
      ];
    }
    $system_info['drupal'] = [
      'project' => 'drupal',
      'version' => '8.0.0',
      'hidden' => FALSE,
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
   * @param string $expected_target_version
   *   The expected target version.
   */
  private function assertTableShowsUpdates(string $expected_project_title, string $expected_installed_version, string $expected_target_version): void {
    $this->assertUpdateTableRow($this->assertSession(), $expected_project_title, $expected_installed_version, $expected_target_version);
  }

  /**
   * Asserts the form shows no updates.
   */
  private function assertNoUpdates(): void {
    $assert = $this->assertSession();
    $assert->buttonNotExists('Update');
    $assert->pageTextContains('There are no available updates.');
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
   * @param string $target_version
   *   The target version.
   *
   * @dataProvider providerSuccessfulUpdate
   */
  public function testSuccessfulUpdate(bool $maintenance_mode_on, string $project_name, string $installed_version, string $target_version): void {
    // Disable the scaffold file permissions validator because it will try to
    // read composer.json from the staging area, which won't exist because
    // Package Manager is bypassed.
    $this->disableValidators(['automatic_updates.validator.scaffold_file_permissions']);

    $this->container->get('theme_installer')->install(['automatic_updates_theme_with_updates']);
    $this->updateProject = $project_name;
    $this->setReleaseMetadata(__DIR__ . '/../../../../tests/fixtures/release-history/drupal.9.8.2.xml');
    $this->setReleaseMetadata(__DIR__ . "/../../fixtures/release-history/$project_name.1.1.xml");
    $this->setProjectInstalledVersion([$project_name => $installed_version]);
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
      $target_version
    );
    $page->checkField('projects[' . $this->updateProject . ']');
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    // Confirm that the site was put into maintenance mode if needed.
    $this->assertSame($state->get('system.maintenance_mode'), $maintenance_mode_on);
    $assert_session = $this->assertSession();
    $possible_update_message = 'Possible database updates were detected in the following extensions; you may be redirected to the database update page in order to complete the update process.';
    $assert_session->pageTextContains($possible_update_message);
    $assert_session->pageTextContainsOnce('System');
    $assert_session->pageTextContainsOnce('Automatic Updates Theme With Updates');

    $page->pressButton('Continue');
    $this->checkForMetaRefresh();

    $assert_session->addressEquals('/admin/reports/updates');
    // Confirm that the site was in maintenance before the update was applied.
    // @see \Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber::handleEvent()
    $this->assertTrue($state->get(PreApplyEvent::class . '.system.maintenance_mode'));
    $assert_session->pageTextContainsOnce('Update complete!');
    // Confirm the site was returned to the original maintenance mode state.
    $this->assertSame($state->get('system.maintenance_mode'), $maintenance_mode_on);
  }

  /**
   * Tests the form when modules requiring an update not installed via composer.
   */
  public function testNonComposerProjects(): void {
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/aaa_update_test.1.1.xml');
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/semver_test.1.1.xml');
    $this->config('update.settings')
      ->set('fetch.url', $this->baseUrl . '/test-release-history')
      ->save();
    $this->setProjectInstalledVersion(
      [
        'aaa_update_test' => '8.x-2.x',
        'semver_test' => '8.1.0',
      ]
    );

    // One module not installed through composer.
    $this->container->get('state')->set('automatic_updates_extensions_test.active_path', __DIR__ . '/../../fixtures/active_composer/one_project');
    $assert = $this->assertSession();
    $user = $this->createUser(
      [
        'administer site configuration',
        'administer software updates',
      ]
    );
    $this->drupalLogin($user);
    $this->checkForUpdates();
    $this->drupalGet('admin/reports/updates/automatic-update-extensions');
    $assert->pageTextContains('Other updates were found, but they must be performed manually. See the list of available updates for more information.');
    $this->assertTableShowsUpdates('Semver Test', '8.1.0', '8.1.1');

    // Both of the modules not installed through composer.
    $this->container->get('state')->set('automatic_updates_extensions_test.active_path', __DIR__ . '/../../fixtures/active_composer/no_project');
    $this->getSession()->reload();
    $assert->pageTextContains('Updates were found, but they must be performed manually. See the list of available updates for more information.');
    $this->assertNoUpdates();
  }

  /**
   * Tests the form when a module requires an update.
   */
  public function testHasUpdate(): void {
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/semver_test.1.1.xml');
    $assert = $this->assertSession();
    $user = $this->createUser(['administer site configuration']);
    $this->drupalLogin($user);
    $this->setProjectInstalledVersion(['semver_test' => '8.1.0']);
    $this->drupalGet('admin/reports/updates/automatic-update-extensions');
    $assert->pageTextContains('Access Denied');
    $assert->pageTextNotContains('Automatic Updates Form');
    $user = $this->createUser(['administer software updates', 'administer site configuration']);
    $this->drupalLogin($user);
    $this->checkForUpdates();
    $this->assertTableShowsUpdates('Semver Test', '8.1.0', '8.1.1');
    $assert->pageTextContains('Automatic Updates Form');
    $assert->buttonExists('Update');
  }

  /**
   * Tests the form when there are no available updates.
   */
  public function testNoUpdate(): void {
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/semver_test.1.1.xml');
    $user = $this->createUser([
      'administer site configuration',
      'administer software updates',
    ]);
    $this->drupalLogin($user);
    $this->setProjectInstalledVersion(['semver_test' => '8.1.1']);
    $this->checkForUpdates();
    $this->drupalGet('admin/reports/updates/automatic-update-extensions');
    $this->assertNoUpdates();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkForUpdates(): void {
    $this->drupalGet('/admin/modules/automatic-update-extensions');
    $this->clickLink('Check manually');
    $this->checkForMetaRefresh();
  }

  /**
   * Test the form for errors.
   */
  public function testErrors(): void {
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/semver_test.1.1.xml');
    $assert = $this->assertSession();
    $user = $this->createUser([
      'administer site configuration',
      'administer software updates',
    ]);
    $this->drupalLogin($user);
    $this->setProjectInstalledVersion(['semver_test' => '8.1.0']);
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
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/semver_test.1.1.xml');
    $assert = $this->assertSession();
    $this->setProjectInstalledVersion(['semver_test' => '8.1.0']);
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
