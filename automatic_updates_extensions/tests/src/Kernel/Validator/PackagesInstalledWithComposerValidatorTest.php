<?php

namespace Drupal\Tests\automatic_updates_extensions\Kernel\Validator;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates_extensions\Kernel\AutomaticUpdatesExtensionsKernelTestBase;

/**
 * Validates the installed packages via composer after an update.
 *
 * @coversDefaultClass \Drupal\automatic_updates_extensions\Validator\PackagesInstalledWithComposerValidator
 *
 * @group automatic_updates_extensions
 */
class PackagesInstalledWithComposerValidatorTest extends AutomaticUpdatesExtensionsKernelTestBase {

  /**
   * The active directory in the virtual file system.
   *
   * @var string
   */
  private $activeDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // In this test, we don't focus on validating that the updated projects are
    // secure and supported. Therefore, we need to disable the update release
    // validator that validates updated projects are secure and supported.
    $this->disableValidators[] = 'automatic_updates_extensions.validator.target_release';
    // In this test, we don't focus on validating that the updated projects are
    // only themes or modules. Therefore, we need to disable the update packages
    // type validator.
    $this->disableValidators[] = 'automatic_updates_extensions.validator.packages_type';
    parent::setUp();
    $this->activeDir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
  }

  /**
   * Data provider for testPreCreateException().
   *
   * @return array
   *   Test cases for testPreCreateException().
   */
  public function providerPreCreateException(): array {
    return [
      'module not installed via composer' => [
        [
          'new_module' => '9.8.0',
        ],
        [ValidationResult::createError(['new_module'], t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:'))],
      ],
      'theme not installed via composer' => [
        [
          'new_theme' => '9.8.0',
        ],
        [ValidationResult::createError(['new_theme'], t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:'))],
      ],
      'profile not installed via composer' => [
        [
          'new_profile' => '9.8.0',
        ],
        [ValidationResult::createError(['new_profile'], t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:'))],
      ],
      'module_theme_profile_dependency_not_installed_via_composer' => [
        [
          'new_module' => '9.8.0',
          'new_theme' => '9.8.0',
          'new_profile' => '9.8.0',
          'new_dependency' => '9.8.0',
        ],
        [
          ValidationResult::createError(
            ['new_module', 'new_theme', 'new_profile', 'new_dependency'],
            t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:')),
        ],
      ],
      'module_theme_profile_installed_via_composer' => [
        [
          'existing_module' => '9.8.1',
          'existing_theme' => '9.8.1',
          'existing_profile' => '9.8.1',
        ],
        [],
      ],
      'existing module installed and new module not installed via composer' => [
        [
          'existing_module' => '9.8.1',
          'new_module' => '9.8.0',
        ],
        [ValidationResult::createError(['new_module'], t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:'))],
      ],
    ];
  }

  /**
   * Tests the packages installed with composer during pre-create.
   *
   * @param array $projects
   *   The projects to install.
   * @param array $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerPreCreateException
   */
  public function testPreCreateException(array $projects, array $expected_results): void {
    // Path of `active.installed.json` file. It will be used as the virtual
    // project's active `vendor/composer/installed.json` file.
    $active_installed = __DIR__ . '/../../../fixtures/packages_installed_with_composer_validator/active.installed.json';
    $this->assertFileIsReadable($active_installed);
    copy($active_installed, "$this->activeDir/vendor/composer/installed.json");
    $this->assertUpdateResults($projects, $expected_results, PreCreateEvent::class);
  }

  /**
   * Data provider for testPreApplyException().
   *
   * @return array
   *   Test cases for testPreApplyException().
   */
  public function providerPreApplyException(): array {
    $fixtures_folder = __DIR__ . '/../../../fixtures/packages_installed_with_composer_validator';
    return [
      'module not installed via composer' => [
        "$fixtures_folder/module_not_installed.staged.installed.json",
        [ValidationResult::createError(['new_module'], t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:'))],
      ],
      'theme not installed via composer' => [
        "$fixtures_folder/theme_not_installed.staged.installed.json",
        [ValidationResult::createError(['new_theme'], t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:'))],
      ],
      'profile not installed via composer' => [
        "$fixtures_folder/profile_not_installed.staged.installed.json",
        [ValidationResult::createError(['new_profile'], t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:'))],
      ],
      // Dependency drupal/new_dependency of type 'drupal-library' will not show
      // up in the error because it is not one of the covered types
      // ('drupal-module', 'drupal-theme' or 'drupal-profile'). Module
      // new_module1 will also not show up as it's name doesn't start with
      // 'drupal/'.
      // @see \Drupal\automatic_updates_extensions\Validator\PackagesInstalledWithComposerValidator
      'module_theme_profile_dependency_not_installed_via_composer' => [
        "$fixtures_folder/module_theme_profile_dependency_not_installed.staged.installed.json",
        [
          ValidationResult::createError(
            ['new_module', 'new_theme', 'new_profile'],
            t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:')),
        ],
      ],
    ];
  }

  /**
   * Tests the packages installed with composer during pre-apply.
   *
   * @param string $staged_installed
   *   Path of `staged.installed.json` file. It will be used as the virtual
   *   project's staged `vendor/composer/installed.json` file.
   * @param array $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerPreApplyException
   */
  public function testPreApplyException(string $staged_installed, array $expected_results): void {
    // Path of `active.installed.json` file. It will be used as the virtual
    // project's active `vendor/composer/installed.json` file.
    $active_installed = __DIR__ . '/../../../fixtures/packages_installed_with_composer_validator/active.installed.json';
    $this->assertFileIsReadable($active_installed);
    $this->assertFileIsReadable($staged_installed);
    copy($active_installed, "$this->activeDir/vendor/composer/installed.json");
    $listener = function (PreApplyEvent $event) use ($staged_installed): void {
      $stage_dir = $event->getStage()->getStageDirectory();
      copy($staged_installed, $stage_dir . "/vendor/composer/installed.json");
    };
    $this->container->get('event_dispatcher')->addListener(PreApplyEvent::class, $listener, 1000);
    $this->assertUpdateResults([], $expected_results, PreApplyEvent::class);
  }

}
