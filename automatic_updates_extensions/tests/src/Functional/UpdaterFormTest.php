<?php

namespace Drupal\Tests\automatic_updates_extensions\Functional;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\automatic_updates_test\StagedDatabaseUpdateValidator;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\package_manager_bypass\Beginner;
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
        FALSE, 'test_theme', 'Test theme', '8.x-2.0', '8.x-2.1',
      ],
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
    $fixture_dir = __DIR__ . '/../../fixtures/two_projects';
    Beginner::setFixturePath($fixture_dir);
    $this->container->get('package_manager.path_locator')
      ->setPaths($fixture_dir, $fixture_dir . '/vendor', '', NULL);
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
    $this->updateProject = $project_name;
    // By default, the Update module only checks for updates of installed modules
    // and themes. The two modules we're testing here (semver_test and aaa_update_test)
    // are already installed by static::$modules.
    $this->container->get('theme_installer')->install(['test_theme']);
    $this->setReleaseMetadata(__DIR__ . '/../../../../tests/fixtures/release-history/drupal.9.8.2.xml');
    $this->setReleaseMetadata(__DIR__ . "/../../fixtures/release-history/$project_name.1.1.xml");
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
    $page->checkField('projects[' . $this->updateProject . ']');
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    // Confirm that the site was put into maintenance mode if needed.
    $this->assertSame($state->get('system.maintenance_mode'), $maintenance_mode_on);

    // Ensure that a list of pending database updates is visible, along with a
    // short explanation, in the warning messages.
    $warning_messages = $assert_session->elementExists('xpath', '//div[@data-drupal-messages]//div[@aria-label="Warning message"]');
    $this->assertStringContainsString('Possible database updates were detected in the following extensions; you may be redirected to the database update page in order to complete the update process.', $warning_messages->getText());
    $pending_updates = $warning_messages->findAll('css', 'ul.item-list__automatic-updates-extensions__pending-database-updates li');
    $this->assertCount(2, $pending_updates);
    $this->assertSame('Automatic Updates Theme With Updates', $pending_updates[0]->getText());
    $this->assertSame('System', $pending_updates[1]->getText());

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
        'aaa_update_test' => '8.x-2.0',
        'semver_test' => '8.1.0',
      ]
    );

    // One module not installed through composer.
    $fixture_dir = __DIR__ . '/../../fixtures/one_project';
    Beginner::setFixturePath($fixture_dir);
    $this->container->get('package_manager.path_locator')
      ->setPaths($fixture_dir, $fixture_dir . '/vendor', '', NULL);
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
    $fixture_dir = __DIR__ . '/../../fixtures/no_project';
    Beginner::setFixturePath($fixture_dir);
    $this->container->get('package_manager.path_locator')
      ->setPaths($fixture_dir, $fixture_dir . '/vendor', '', NULL);
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
    $this->assertUpdatesCount(1);
    $assert->pageTextContains(static::$warningsExplanation);
    $assert->pageTextNotContains(static::$errorsExplanation);
    $assert->buttonExists('Update');
  }

}
