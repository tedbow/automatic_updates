<?php

namespace Drupal\Tests\package_manager\Build;

use Drupal\BuildTests\QuickStart\QuickStartTestBase;
use Drupal\Composer\Composer;
use Drupal\Tests\package_manager\Traits\FixtureUtilityTrait;
use Drupal\Tests\RandomGeneratorTrait;

/**
 * Base class for tests which create a test site from a core project template.
 *
 * The test site will be created from one of the core Composer project templates
 * (drupal/recommended-project or drupal/legacy-project) and contain complete
 * copies of Drupal core and all installed dependencies, completely independent
 * of the currently running code base.
 */
abstract class TemplateProjectTestBase extends QuickStartTestBase {

  use FixtureUtilityTrait;
  use RandomGeneratorTrait;

  /**
   * The web root of the test site, relative to the workspace directory.
   *
   * @var string
   */
  private $webRoot;

  /**
   * A secondary server instance, to serve XML metadata about available updates.
   *
   * @var \Symfony\Component\Process\Process
   */
  private $metadataServer;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->metadataServer) {
      $this->metadataServer->stop();
    }
    parent::tearDown();
  }

  /**
   * Data provider for tests which use all of the core project templates.
   *
   * @return string[][]
   *   The test cases.
   */
  public function providerTemplate(): array {
    return [
      'RecommendedProject' => ['RecommendedProject'],
      'LegacyProject' => ['LegacyProject'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCodebaseFinder() {
    // If core's npm dependencies are installed, we don't want them to be
    // included in the upstream version of core that gets installed into the
    // test site.
    return parent::getCodebaseFinder()->notPath('#^core/node_modules#');
  }

  /**
   * Returns the full path to the test site's document root.
   *
   * @return string
   *   The full path of the test site's document root.
   */
  protected function getWebRoot(): string {
    return $this->getWorkspaceDirectory() . '/' . $this->webRoot;
  }

  /**
   * {@inheritdoc}
   */
  protected function instantiateServer($port, $working_dir = NULL) {
    return parent::instantiateServer($port, $working_dir ?: $this->webRoot);
  }

  /**
   * {@inheritdoc}
   */
  public function installQuickStart($profile, $working_dir = NULL) {
    parent::installQuickStart($profile, $working_dir ?: $this->webRoot);

    // Always allow test modules to be installed in the UI and, for easier
    // debugging, always display errors in their dubious glory.
    $php = <<<END
\$settings['extension_discovery_scan_tests'] = TRUE;
\$config['system.logging']['error_level'] = 'verbose';
END;
    $this->writeSettings($php);
  }

  /**
   * {@inheritdoc}
   */
  public function visit($request_uri = '/', $working_dir = NULL) {
    return parent::visit($request_uri, $working_dir ?: $this->webRoot);
  }

  /**
   * {@inheritdoc}
   */
  public function formLogin($username, $password, $working_dir = NULL) {
    parent::formLogin($username, $password, $working_dir ?: $this->webRoot);
  }

  /**
   * Returns the paths of all core Composer packages.
   *
   * @return string[]
   *   The paths of the core Composer packages, keyed by parent directory name.
   */
  protected function getCorePackages(): array {
    $workspace_dir = $this->getWorkspaceDirectory();

    $packages = [
      'core' => "$workspace_dir/core",
    ];
    foreach (['Metapackage', 'Plugin'] as $type) {
      foreach (Composer::composerSubprojectPaths($workspace_dir, $type) as $package) {
        $path = $package->getPath();
        $name = basename($path);
        $packages[$name] = $path;
      }
    }
    return $packages;
  }

  /**
   * Adds a path repository to the test site.
   *
   * @param string $name
   *   An arbitrary name for the repository.
   * @param string $path
   *   The path of the repository. Must exist in the file system.
   * @param string $working_directory
   *   (optional) The Composer working directory. Defaults to 'project'.
   */
  protected function addRepository(string $name, string $path, $working_directory = 'project'): void {
    $this->assertDirectoryExists($path);

    $repository = json_encode([
      'type' => 'path',
      'url' => $path,
      'options' => [
        'symlink' => FALSE,
      ],
    ], JSON_UNESCAPED_SLASHES);
    $this->runComposer("composer config repo.$name '$repository'", $working_directory);
  }

  /**
   * Prepares the test site to serve an XML feed of available release metadata.
   *
   * @param array $xml_map
   *   The update XML map, as used by update_test.settings.
   *
   * @see \Drupal\package_manager_test_release_history\TestController::metadata()
   */
  protected function setReleaseMetadata(array $xml_map): void {
    foreach ($xml_map as $metadata_file) {
      $this->assertFileIsReadable($metadata_file);
    }
    $xml_map = var_export($xml_map, TRUE);
    $this->writeSettings("\$config['update_test.settings']['xml_map'] = $xml_map;");
  }

  /**
   * Creates a test project from a given template and installs Drupal.
   *
   * @param string $template
   *   The template to use. Can be 'RecommendedProject' or 'LegacyProject'.
   */
  protected function createTestProject(string $template): void {
    // Create a copy of core (including its Composer plugins, templates, and
    // metapackages) which we can modify.
    $this->copyCodebase();

    $workspace_dir = $this->getWorkspaceDirectory();
    $template_dir = "composer/Template/$template";

    // Allow pre-release versions of dependencies.
    $this->runComposer('composer config minimum-stability dev', $template_dir);

    // Remove the packages.drupal.org entry (and any other custom repository)
    // from the template's repositories section. We have no reliable way of
    // knowing the repositories' names in advance, so we get that information
    // from `composer config`, and use `composer config --unset` to actually
    // modify the template, to ensure it's done correctly.
    $repositories = $this->runComposer('composer config repo', $template_dir, TRUE);

    foreach (array_keys($repositories) as $name) {
      $this->runComposer("composer config --unset repo.$name", $template_dir);
    }

    // Add all core plugins and metapackages as path repositories. To disable
    // symlinking, we need to pass the JSON representations of the repositories
    // to `composer config`.
    foreach ($this->getCorePackages() as $name => $path) {
      $this->addRepository($name, $path, $template_dir);
    }

    // Add a local Composer repository with all third-party dependencies.
    $vendor = "$workspace_dir/vendor.json";
    file_put_contents($vendor, json_encode($this->createVendorRepository(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $this->runComposer("composer config repo.vendor composer file://$vendor", $template_dir);

    // Disable Packagist entirely so that we don't test the Internet.
    $this->runComposer('composer config repo.packagist.org false', $template_dir);

    // Create the test project, defining its repository as part of the
    // `composer create-project` command.
    $repository = [
      'type' => 'path',
      'url' => $template_dir,
    ];
    $command = sprintf(
      "COMPOSER_MIRROR_PATH_REPOS=1 composer create-project %s project --stability dev --repository '%s'",
      $this->runComposer('composer config name', $template_dir),
      json_encode($repository, JSON_UNESCAPED_SLASHES)
    );
    // Because we set the COMPOSER_MIRROR_PATH_REPOS=1 environment variable when
    // creating the project, none of the dependencies should be symlinked.
    $this->assertStringNotContainsString('Symlinking', $this->runComposer($command));

    // If using the drupal/recommended-project template, we don't expect there
    // to be an .htaccess file at the project root. One would normally be
    // generated by Composer when Package Manager or other code creates a
    // ComposerUtility object in the active directory, except that Package
    // Manager takes specific steps to prevent that. So, here we're just
    // confirming that, in fact, Composer's .htaccess protection was disabled.
    // We don't do this for the drupal/legacy-project template because its
    // project root, which is also the document root, SHOULD contain a .htaccess
    // generated by Drupal core.
    // We do this check because this test uses PHP's built-in web server, which
    // ignores .htaccess files and everything in them, so a Composer-generated
    // .htaccess file won't cause this test to fail.
    if ($template === 'RecommendedProject') {
      $this->assertFileDoesNotExist("$workspace_dir/project/.htaccess");
    }

    // Now that we know the project was created successfully, we can set the
    // web root with confidence.
    $this->webRoot = 'project/' . $this->runComposer('composer config extra.drupal-scaffold.locations.web-root', 'project');
    // BEGIN: DELETE FROM CORE MERGE REQUEST
    // Install Automatic Updates into the test project and ensure it wasn't
    // symlinked.
    $automatic_updates_dir = realpath(__DIR__ . '/../../../..');
    if (str_contains($automatic_updates_dir, 'automatic_updates')) {
      $dir = 'project';
      $this->runComposer("composer config repo.automatic_updates path $automatic_updates_dir", $dir);
      $output = $this->runComposer('COMPOSER_MIRROR_PATH_REPOS=1 composer require --update-with-all-dependencies "drupal/automatic_updates:@dev"', $dir);
      $this->assertStringNotContainsString('Symlinking', $output);
    }
    // END: DELETE FROM CORE MERGE REQUEST

    // Install Drupal.
    $this->installQuickStart('minimal');
    $this->formLogin($this->adminUsername, $this->adminPassword);

    // When checking for updates, we need to be able to make sub-requests, but
    // the built-in PHP server is single-threaded. Therefore, open a second
    // server instance on another port, which will serve the metadata about
    // available updates.
    $port = $this->findAvailablePort();
    $this->metadataServer = $this->instantiateServer($port);
    $code = <<<END
\$config['automatic_updates.settings']['cron_port'] = $port;
\$config['update.settings']['fetch']['url'] = 'http://localhost:$port/test-release-history';
END;
    $this->writeSettings($code);

    // Install helpful modules.
    $this->installModules([
      'package_manager_test_api',
      'package_manager_test_release_history',
    ]);
  }

  /**
   * Creates a Composer repository for all installed third-party dependencies.
   *
   * @return string[][]
   *   The data that should be written to the repository file.
   */
  protected function createVendorRepository(): array {
    $packages = [];
    $drupal_root = $this->getDrupalRoot();

    foreach ($this->getInstalledPackages() as $package) {
      $name = $package['name'];
      $path = "$drupal_root/vendor/$name";

      // We are building a set of path repositories to projects in the vendor
      // directory, so we will skip any project that does not exist in vendor.
      // Also skip the projects that are symlinked in vendor. These are in our
      // metapackage and will be represented as path repositories in the test
      // project's composer.json.
      if (is_dir($path) && !is_link($path)) {
        unset(
          // Force the package to be installed from our 'dist' information.
          $package['source'],
          // Don't notify anybody that we're installing this package.
          $package['notification-url'],
          // Since Drupal 9 requires PHP 7.3 or later, these polyfills won't be
          // installed, so we should make sure that they're not required by
          // anything.
          $package['require']['symfony/polyfill-php72'],
          $package['require']['symfony/polyfill-php73']
        );
        // Disabling symlinks in the transport options doesn't seem to have an
        // effect, so we use the COMPOSER_MIRROR_PATH_REPOS environment variable
        // to force mirroring in ::createTestProject().
        $package['dist'] = [
          'type' => 'path',
          'url' => $path,
        ];
        $version = $package['version'];
        $packages[$name][$version] = $package;
      }
    }
    return ['packages' => $packages];
  }

  /**
   * Returns all package information from the `installed.json` file.
   *
   * @return mixed[][]
   *   All package data from the `installed.json` file.
   */
  private function getInstalledPackages(): array {
    $installed = $this->getDrupalRoot() . '/vendor/composer/installed.json';
    $this->assertFileExists($installed);

    $installed = file_get_contents($installed);
    $installed = json_decode($installed, TRUE, JSON_THROW_ON_ERROR);

    return $installed['packages'];
  }

  /**
   * Runs a Composer command and returns its output.
   *
   * Always asserts that the command was executed successfully.
   *
   * @param string $command
   *   The command to execute, including the `composer` invocation.
   * @param string $working_dir
   *   (optional) A working directory relative to the workspace, within which to
   *   execute the command. Defaults to the workspace directory.
   * @param bool $json
   *   (optional) Whether to parse the command's output as JSON before returning
   *   it. Defaults to FALSE.
   *
   * @return mixed|string|null
   *   The command's output, optionally parsed as JSON.
   */
  protected function runComposer(string $command, string $working_dir = NULL, bool $json = FALSE) {
    $output = $this->executeCommand($command, $working_dir)->getOutput();
    $this->assertCommandSuccessful();

    $output = trim($output);
    if ($json) {
      $output = json_decode($output, TRUE, JSON_THROW_ON_ERROR);
    }
    return $output;
  }

  /**
   * Appends PHP code to the test site's settings.php.
   *
   * @param string $php
   *   The PHP code to append to the test site's settings.php.
   */
  protected function writeSettings(string $php): void {
    // Ensure settings are writable, since this is the only way we can set
    // configuration values that aren't accessible in the UI.
    $file = $this->getWebRoot() . '/sites/default/settings.php';
    $this->assertFileExists($file);
    chmod(dirname($file), 0744);
    chmod($file, 0744);
    $this->assertFileIsWritable($file);

    $stream = fopen($file, 'a');
    $this->assertIsResource($stream);
    $this->assertIsInt(fwrite($stream, $php));
    $this->assertTrue(fclose($stream));
  }

  /**
   * Installs modules in the UI.
   *
   * Assumes that a user with the appropriate permissions is logged in.
   *
   * @param string[] $modules
   *   The machine names of the modules to install.
   */
  protected function installModules(array $modules): void {
    $mink = $this->getMink();
    $page = $mink->getSession()->getPage();
    $assert_session = $mink->assertSession();

    $this->visit('/admin/modules');
    foreach ($modules as $module) {
      $page->checkField("modules[$module][enable]");
    }
    $page->pressButton('Install');

    // If there is a confirmation form warning about additional dependencies
    // or non-stable modules, submit it.
    $form_id = $assert_session->elementExists('css', 'input[type="hidden"][name="form_id"]')
      ->getValue();
    if (preg_match('/^system_modules_(experimental_|non_stable_)?confirm_form$/', $form_id)) {
      $page->pressButton('Continue');
      $assert_session->statusCodeEquals(200);
    }
  }

  /**
   * Copies a fixture directory to a temporary directory and returns its path.
   *
   * @param string $fixture_directory
   *   The fixture directory.
   *
   * @return string
   *   The temporary directory.
   */
  protected function copyFixtureToTempDirectory(string $fixture_directory): string {
    $temp_directory = $this->getWorkspaceDirectory() . '/fixtures_temp_' . $this->randomMachineName(20);
    static::copyFixtureFilesTo($fixture_directory, $temp_directory);
    return $temp_directory;
  }

  // BEGIN: DELETE FROM CORE MERGE REQUEST.

  /**
   * {@inheritdoc}
   */
  public function copyCodebase(\Iterator $iterator = NULL, $working_dir = NULL) {
    parent::copyCodebase($iterator, $working_dir);

    // In certain situations, like Drupal CI, automatic_updates might be
    // required into the code base by Composer. This may cause it to be added to
    // the drupal/core-recommended metapackage, which can prevent the test site
    // from being built correctly, among other deleterious effects. To prevent
    // such shenanigans, always remove drupal/automatic_updates from
    // drupal/core-recommended.
    $file = $this->getWorkspaceDirectory() . '/composer/Metapackage/CoreRecommended/composer.json';
    $this->assertFileIsWritable($file);
    $data = file_get_contents($file);
    $data = json_decode($data, TRUE);
    unset($data['require']['drupal/automatic_updates']);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
  }

  // END: DELETE FROM CORE MERGE REQUEST.
}
