<?php

namespace Drupal\Tests\automatic_updates_extensions\Functional;

use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\package_manager_test_validation\StagedDatabaseUpdateValidator;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\package_manager_bypass\Committer;
use Drupal\package_manager_bypass\Stager;
use Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber;
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
    'automatic_updates_extensions_test',
  ];

  /**
   * Data provider for testSuccessfulUpdate().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerSuccessfulUpdate(): array {
    return [
      'maintenance mode on, semver module' => [
        TRUE, 'semver_test', 'Semver Test', '8.1.0', '8.1.1',
      ],
      'maintenance mode off, legacy module' => [
        FALSE, 'aaa_update_test', 'AAA Update test', '8.x-2.0', '8.x-2.1',
      ],
      'maintenance mode off, legacy theme' => [
        FALSE, 'automatic_updates_extensions_test_theme', 'Automatic Updates Extensions Test Theme', '8.x-2.0', '8.x-2.1',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $user = $this->createUser([
      'administer site configuration',
      'administer software updates',
      'access site in maintenance mode',
    ]);
    // We need this fixture as only projects installed via composer will show up
    // on the form.
    $this->useFixtureDirectoryAsActive(__DIR__ . '/../../fixtures/two_projects');
    $this->drupalLogin($user);
    $this->drupalPlaceBlock('local_tasks_block', ['primary' => TRUE]);
  }

  /**
   * Sets installed project version.
   *
   * @todo This is copied from core. We need to file a core issue so we do not
   *    have to copy this.
   */
  protected function setProjectInstalledVersion($project_versions): void {
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
   * @param int $row
   *   The row number.
   */
  private function assertTableShowsUpdates(string $expected_project_title, string $expected_installed_version, string $expected_target_version, int $row = 1): void {
    $this->assertUpdateTableRow($this->assertSession(), $expected_project_title, $expected_installed_version, $expected_target_version, $row);
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
   * @param string $project_title
   *   The project title.
   * @param string $installed_version
   *   The installed version.
   * @param string $target_version
   *   The target version.
   *
   * @dataProvider providerSuccessfulUpdate
   */
  public function testSuccessfulUpdate(bool $maintenance_mode_on, string $project_name, string $project_title, string $installed_version, string $target_version): void {
    $this->container->get('theme_installer')->install(['automatic_updates_theme_with_updates']);
    // By default, the Update module only checks for updates of installed
    // modules and themes. The two modules we're testing here (semver_test and
    // aaa_update_test) are already installed by static::$modules.
    $this->container->get('theme_installer')->install(['automatic_updates_extensions_test_theme']);
    $this->useFixtureDirectoryAsStaged(__DIR__ . '/../../fixtures/stage_composer/' . $project_name);
    $this->setReleaseMetadata(__DIR__ . '/../../../../package_manager/tests/fixtures/release-history/drupal.9.8.2.xml');
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/' . $project_name . '.1.1.xml');
    $this->setProjectInstalledVersion([$project_name => $installed_version]);
    $this->checkForUpdates();
    $state = $this->container->get('state');
    $state->set('system.maintenance_mode', $maintenance_mode_on);
    StagedDatabaseUpdateValidator::setExtensionsWithUpdates(['system', 'automatic_updates_theme_with_updates']);

    $page = $this->getSession()->getPage();
    // Navigate to the automatic updates form.
    $this->drupalGet('/admin/reports/updates');
    $this->clickLink('Update Extensions');
    $this->assertTableShowsUpdates(
      $project_title,
      $installed_version,
      $target_version
    );
    $assert_session = $this->assertSession();
    $this->assertUpdatesCount(1);

    // Submit without selecting a project.
    $page->pressButton('Update');
    $assert_session->pageTextContains('Please select one or more projects.');

    // Submit with a project selected.
    $page->checkField('projects[' . $project_name . ']');
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    // Confirm that the site was put into maintenance mode if needed.
    $this->assertSame($state->get('system.maintenance_mode'), $maintenance_mode_on);

    $assert_session->pageTextNotContains('The following dependencies will also be updated:');
    // Ensure that a list of pending database updates is visible, along with a
    // short explanation, in the warning messages.
    $warning_messages = $assert_session->elementExists('xpath', '//div[@data-drupal-messages]//div[@aria-label="Warning message"]');
    $this->assertStringContainsString('Possible database updates have been detected in the following extensions.<ul><li>System</li><li>Automatic Updates Theme With Updates</li></ul>', $warning_messages->getHtml());

    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    $assert_session->addressEquals('/admin/reports/updates');
    // Confirm that the site was in maintenance before the update was applied.
    // @see \Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber::handleEvent()
    $this->assertTrue($state->get(PreApplyEvent::class . '.system.maintenance_mode'));
    $assert_session->pageTextContainsOnce('Update complete!');
    // Confirm the site was returned to the original maintenance mode state.
    $this->assertSame($state->get('system.maintenance_mode'), $maintenance_mode_on);
    // Confirm that the apply and post-apply operations happened in
    // separate requests.
    // @see \Drupal\automatic_updates_test\EventSubscriber\RequestTimeRecorder
    $pre_apply_time = $state->get('Drupal\package_manager\Event\PreApplyEvent time');
    $post_apply_time = $state->get('Drupal\package_manager\Event\PostApplyEvent time');
    $this->assertNotEmpty($pre_apply_time);
    $this->assertNotEmpty($post_apply_time);
    $this->assertNotSame($pre_apply_time, $post_apply_time);
  }

  /**
   * Tests that an exception is thrown if a previous apply failed.
   */
  public function testMarkerFileFailure(): void {
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/semver_test.1.1.xml');
    $this->setProjectInstalledVersion(['semver_test' => '8.1.0']);
    $this->checkForUpdates();
    $page = $this->getSession()->getPage();
    // Navigate to the automatic updates form.
    $this->drupalGet('/admin/modules/automatic-update-extensions');
    $assert_session = $this->assertSession();
    $assert_session->pageTextNotContains(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);

    $this->assertTableShowsUpdates('Semver Test', '8.1.0', '8.1.1');
    $this->assertUpdatesCount(1);
    $page->checkField('projects[semver_test]');
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    $assert_session->pageTextNotContains('The following dependencies will also be updated:');
    Committer::setException(new \Exception('failed at committer'));
    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $assert_session->pageTextContains('The update operation failed to apply. The update may have been partially applied. It is recommended that the site be restored from a code backup.');
    $page->clickLink('the error page');

    $failure_message = 'Automatic updates failed to apply, and the site is in an indeterminate state. Consider restoring the code and database from a backup.';
    // We should be on the form (i.e., 200 response code), but unable to
    // continue the update.
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains($failure_message);
    $assert_session->buttonNotExists('Continue');
    // The same thing should be true if we try to start from the beginning.
    $this->drupalGet('/admin/modules/automatic-update-extensions');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains($failure_message);
    $assert_session->buttonNotExists('Update');
  }

  /**
   * Data provider for testDisplayUpdates().
   *
   * @return array[]
   *   The test cases.
   */
  public function providerDisplayUpdates(): array {
    return [
      'with unrequested updates' => [TRUE],
      'without unrequested updates' => [FALSE],
    ];
  }

  /**
   * Tests the form displays the correct projects which will be updated.
   *
   * @param bool $unrequested_updates
   *   Whether unrequested updates are present during update.
   *
   * @dataProvider providerDisplayUpdates
   */
  public function testDisplayUpdates(bool $unrequested_updates): void {
    $this->container->get('theme_installer')->install(['automatic_updates_theme_with_updates']);
    $this->setReleaseMetadata(__DIR__ . '/../../../../package_manager/tests/fixtures/release-history/drupal.9.8.2.xml');
    $this->setReleaseMetadata(__DIR__ . "/../../fixtures/release-history/semver_test.1.1.xml");
    $this->setReleaseMetadata(__DIR__ . "/../../fixtures/release-history/aaa_update_test.1.1.xml");
    Stager::setFixturePath(__DIR__ . '/../../fixtures/stage_composer/two_projects');
    $this->setProjectInstalledVersion([
      'semver_test' => '8.1.0',
      'aaa_update_test' => '8.x-2.0',
    ]);
    $this->checkForUpdates();
    $state = $this->container->get('state');
    $page = $this->getSession()->getPage();

    // Navigate to the automatic updates form.
    $this->drupalGet('/admin/reports/updates');
    $this->clickLink('Update Extensions');
    $this->assertTableShowsUpdates(
      'AAA Update test',
      '8.x-2.0',
      '8.x-2.1',
    );
    $this->assertTableShowsUpdates(
      'Semver Test',
      '8.1.0',
      '8.1.1',
      2
    );
    // User will choose both the projects to update and there will be no
    // unrequested updates.
    if ($unrequested_updates === FALSE) {
      $page->checkField('projects[aaa_update_test]');
    }
    $page->checkField('projects[semver_test]');
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    $assert_session = $this->assertSession();
    // Both projects will be shown as requested updates if there are no
    // unrequested updates, otherwise one project which user chose will be shown
    // as requested update and other one will be shown as unrequested update.
    if ($unrequested_updates === FALSE) {
      $assert_session->pageTextNotContains('The following dependencies will also be updated:');
    }
    else {
      $assert_session->pageTextContains('The following dependencies will also be updated:');
    }
    $assert_session->pageTextContains('The following projects will be updated:');
    $assert_session->pageTextContains('Semver Test from 8.1.0 to 8.1.1');
    $assert_session->pageTextContains('AAA Update test from 2.0.0 to 2.1.0');
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
        'aaa_update_test' => '8.x-2.0',
        'semver_test' => '8.1.0',
      ]
    );

    // One module not installed through composer.
    $this->useFixtureDirectoryAsActive(__DIR__ . '/../../fixtures/one_project');
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
    $this->assertUpdatesCount(1);

    // Both of the modules not installed through composer.
    $this->useFixtureDirectoryAsActive(__DIR__ . '/../../fixtures/no_project');
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
    $this->assertUpdatesCount(1);
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
    $this->assertUpdatesCount(1);
    $message = t("You've not experienced Shakespeare until you have read him in the original Klingon.");
    $error = ValidationResult::createError([$message]);
    TestSubscriber1::setTestResult([$error], StatusCheckEvent::class);
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
    TestSubscriber1::setTestResult([$warning], StatusCheckEvent::class);
    // Navigate to the automatic updates form.
    $this->drupalGet('/admin/reports/updates');
    $this->clickLink('Update Extensions');
    $this->assertTableShowsUpdates('Semver Test', '8.1.0', '8.1.1');
    $this->assertUpdatesCount(1);
    $this->checkForMetaRefresh();
    $assert->pageTextNotContains(static::$errorsExplanation);
    $assert->elementExists('css', '#edit-projects-semver-test')->check();
    $assert->checkboxChecked('edit-projects-semver-test');
    $assert->pageTextContains(static::$warningsExplanation);
    $assert->buttonExists('Update');

    // Add warnings from StatusCheckEvent.
    $summary_status_check_event = t('Some summary');
    $messages_status_check_event = [
      "The only thing we're allowed to do is to",
      "believe that we won't regret the choice",
      "we made.",
    ];
    $warning_status_check_event = ValidationResult::createWarning($messages_status_check_event, $summary_status_check_event);
    TestSubscriber::setTestResult([$warning_status_check_event], StatusCheckEvent::class);
    $this->getSession()->getPage()->pressButton('Update');
    $this->checkForMetaRefresh();
    $assert->buttonExists('Continue');
    $assert->pageTextContains($summary_status_check_event);
    foreach ($messages_status_check_event as $message) {
      $assert->pageTextContains($message);
    }
  }

  /**
   * Tests that messages from StatusCheckEvent are shown on the confirmation form.
   */
  public function testStatusErrorMessages(): void {
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/semver_test.1.1.xml');
    $assert = $this->assertSession();
    $this->setProjectInstalledVersion(['semver_test' => '8.1.0']);
    $this->checkForUpdates();
    $this->drupalGet('admin/reports/updates/automatic-update-extensions');
    $this->assertTableShowsUpdates('Semver Test', '8.1.0', '8.1.1');
    $this->assertUpdatesCount(1);
    $this->getSession()->reload();
    $assert->elementExists('css', '#edit-projects-semver-test')->check();
    $assert->checkboxChecked('edit-projects-semver-test');
    $assert->buttonExists('Update');
    $messages = [
      "The only thing we're allowed to do is to",
      "believe that we won't regret the choice",
      "we made.",
    ];
    $summary = t('Some summary');
    $error = ValidationResult::createError($messages, $summary);
    TestSubscriber::setTestResult([$error], StatusCheckEvent::class);
    $this->getSession()->getPage()->pressButton('Update');
    $this->checkForMetaRefresh();
    $assert->pageTextContains(static::$errorsExplanation);
    $assert->pageTextNotContains(static::$warningsExplanation);
    $assert->pageTextContains($summary);
    foreach ($messages as $message) {
      $assert->pageTextContains($message);
    }
    $assert->buttonNotExists('Continue');
  }

  /**
   * Tests the form when an uninstallable module requires an update.
   */
  public function testUninstallableRelease(): void {
    $this->container->get('state')->set('testUninstallableRelease', TRUE);
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/semver_test.1.1.xml');
    $assert = $this->assertSession();
    $this->setProjectInstalledVersion(['semver_test' => '8.1.0']);
    $user = $this->createUser(['administer software updates', 'administer site configuration']);
    $this->drupalLogin($user);
    $this->drupalGet('admin/reports/updates/automatic-update-extensions');
    $this->checkForUpdates();
    $this->assertNoUpdates();
  }

}
