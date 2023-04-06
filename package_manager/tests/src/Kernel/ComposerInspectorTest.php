<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Composer\Json\JsonFile;
use Drupal\Component\Serialization\Json;
use Drupal\fixture_manipulator\ActiveFixtureManipulator;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\Exception\ComposerNotReadyException;
use Drupal\package_manager\InstalledPackage;
use Drupal\package_manager\ProcessOutputCallback;
use Drupal\package_manager\InstalledPackagesList;
use Drupal\Tests\package_manager\Traits\InstalledPackagesListTrait;
use Drupal\package_manager\PathLocator;
use PhpTuf\ComposerStager\Domain\Exception\PreconditionException;
use PhpTuf\ComposerStager\Domain\Exception\RuntimeException;
use PhpTuf\ComposerStager\Domain\Service\Precondition\ComposerIsAvailableInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface;
use PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactory;
use Prophecy\Argument;

/**
 * @coversDefaultClass \Drupal\package_manager\ComposerInspector
 *
 * @group package_manager
 */
class ComposerInspectorTest extends PackageManagerKernelTestBase {

  use InstalledPackagesListTrait;

  /**
   * @covers ::getConfig
   */
  public function testConfig(): void {
    $dir = $this->container->get(PathLocator::class)
      ->getProjectRoot();
    $inspector = $this->container->get(ComposerInspector::class);
    $this->assertTrue((bool) Json::decode($inspector->getConfig('secure-http', $dir)));

    $this->assertSame([
      'boo' => 'boo boo',
      "foo" => ["dev" => "2.x-dev"],
      "foo-bar" => TRUE,
      "boo-far" => [
        "foo" => 1.23,
        "bar" => 134,
        "foo-bar" => NULL,
      ],
      'baz' => NULL,
    ], Json::decode($inspector->getConfig('extra', $dir)));

    try {
      $inspector->getConfig('non-existent-config', $dir);
      $this->fail('Expected an exception when trying to get a non-existent config key, but none was thrown.');
    }
    catch (RuntimeException) {
      // We don't need to do anything here.
    }

    // If composer.json is removed, we should get an exception because
    // getConfig() should validate that $dir is Composer-ready.
    unlink($dir . '/composer.json');
    $this->expectExceptionMessage("composer.json not found.");
    $inspector->getConfig('extra', $dir);
  }

  /**
   * @covers ::getConfig
   */
  public function testConfigUndefinedKey(): void {
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();
    $inspector = $this->container->get(ComposerInspector::class);

    // Overwrite the composer.json file and treat it as a
    $file = new JsonFile($project_root . '/composer.json');
    $this->assertTrue($file->exists());
    $data = $file->read();
    // Ensure that none of the special keys are defined, to test the fallback
    // behavior.
    unset(
      $data['minimum-stability'],
      $data['extra']
    );
    $file->write($data);

    $path = $file->getPath();
    $this->assertSame('stable', $inspector->getConfig('minimum-stability', $path));
    $this->assertSame([], Json::decode($inspector->getConfig('extra', $path)));
  }

  /**
   * @covers ::getInstalledPackagesList
   */
  public function testGetInstalledPackagesList(): void {
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();

    /** @var \Drupal\package_manager\ComposerInspector $inspector */
    $inspector = $this->container->get(ComposerInspector::class);
    $list = $inspector->getInstalledPackagesList($project_root);

    $expected_list = new InstalledPackagesList([
      'drupal/core' => InstalledPackage::createFromArray([
        'name' => 'drupal/core',
        'type' => 'drupal-core',
        'version' => '9.8.0',
        'path' => "$project_root/vendor/drupal/core",
      ]),
      'drupal/core-recommended' => InstalledPackage::createFromArray([
        'name' => 'drupal/core-recommended',
        'type' => 'project',
        'version' => '9.8.0',
        'path' => "$project_root/vendor/drupal/core-recommended",
      ]),
      'drupal/core-dev' => InstalledPackage::createFromArray([
        'name' => 'drupal/core-dev',
        'type' => 'package',
        'version' => '9.8.0',
        'path' => "$project_root/vendor/drupal/core-dev",
      ]),
    ]);

    $this->assertPackageListsEqual($expected_list, $list);

    // Since the lock file hasn't changed, we should get the same package list
    // back if we call getInstalledPackageList() again.
    $this->assertSame($list, $inspector->getInstalledPackagesList($project_root));

    // If we change the lock file, we should get a different package list.
    $lock_file = new JsonFile($project_root . '/composer.lock');
    $lock_data = $lock_file->read();
    $this->assertArrayHasKey('_readme', $lock_data);
    unset($lock_data['_readme']);
    $lock_file->write($lock_data);
    $this->assertNotSame($list, $inspector->getInstalledPackagesList($project_root));

    // If composer.lock is removed, we should get an exception because
    // getInstalledPackagesList() should validate that $project_root is
    // Composer-ready.
    unlink($lock_file->getPath());
    $this->expectExceptionMessage("composer.lock not found in $project_root.");
    $inspector->getInstalledPackagesList($project_root);
  }

  /**
   * @covers ::validate
   */
  public function testComposerUnavailable(): void {
    $precondition = $this->prophesize(ComposerIsAvailableInterface::class);
    $mocked_precondition = $precondition->reveal();
    $this->container->set(ComposerIsAvailableInterface::class, $mocked_precondition);

    $precondition->assertIsFulfilled(Argument::cetera())
      ->willThrow(new PreconditionException($mocked_precondition, "Well, that didn't work."))
      // The result of the precondition is statically cached, so it should only
      // be called once even though we call validate() twice.
      ->shouldBeCalledOnce();

    $project_root = $this->container->get(PathLocator::class)->getProjectRoot();
    /** @var \Drupal\package_manager\ComposerInspector $inspector */
    $inspector = $this->container->get(ComposerInspector::class);
    try {
      $inspector->validate($project_root);
      $this->fail('Expected an exception to be thrown, but it was not.');
    }
    catch (ComposerNotReadyException $e) {
      $this->assertNull($e->workingDir);
      $this->assertSame("Well, that didn't work.", $e->getMessage());
    }

    // Call validate() again to ensure the precondition is called once.
    $this->expectException(ComposerNotReadyException::class);
    $this->expectExceptionMessage("Well, that didn't work.");
    $inspector->validate($project_root);
  }

  /**
   * Tests what happens when composer.json or composer.lock are missing.
   *
   * @param string $filename
   *   The filename to delete, which should cause validate() to raise an
   *   error.
   *
   * @covers ::validate
   *
   * @testWith ["composer.json"]
   *   ["composer.lock"]
   */
  public function testComposerFilesDoNotExist(string $filename): void {
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();

    $file_path = $project_root . '/' . $filename;
    unlink($file_path);

    /** @var \Drupal\package_manager\ComposerInspector $inspector */
    $inspector = $this->container->get(ComposerInspector::class);
    try {
      $inspector->validate($project_root);
    }
    catch (ComposerNotReadyException $e) {
      $this->assertSame($project_root, $e->workingDir);
      $this->assertStringContainsString("$filename not found", $e->getMessage());
    }
  }

  /**
   * @param string|null $reported_version
   *   The version of Composer that will be returned by ::getVersion().
   * @param string|null $expected_message
   *   The error message that should be generated for the reported version of
   *   Composer. If not passed, will default to the message format defined in
   *   ::validate().
   *
   * @covers ::validate
   *
   * @testWith ["2.2.12", null]
   *   ["2.2.13", null]
   *   ["2.5.0", null]
   *   ["2.5.11", null]
   *   ["2.2.11", "<default>"]
   *   ["2.2.0-dev", "<default>"]
   *   ["2.3.6", "<default>"]
   *   ["2.4.1", "<default>"]
   *   ["2.3.4", "<default>"]
   *   ["2.1.6", "<default>"]
   *   ["1.10.22", "<default>"]
   *   ["1.7.3", "<default>"]
   *   ["2.0.0-alpha3", "<default>"]
   *   ["2.1.0-RC1", "<default>"]
   *   ["1.0.0-RC", "<default>"]
   *   ["1.0.0-beta1", "<default>"]
   *   ["1.9-dev", "<default>"]
   *   ["@package_version@", "Invalid version string \"@package_version@\""]
   *   [null, "Unable to determine Composer version"]
   */
  public function testVersionCheck(?string $reported_version, ?string $expected_message): void {
    $runner = $this->prophesize(ComposerRunnerInterface::class);

    $pass_version_to_output_callback = function (array $arguments_passed_to_runner) use ($reported_version): void {
      $command_output = Json::encode([
        'application' => [
          'name' => 'Composer',
          'version' => $reported_version,
        ],
      ]);

      /** @var \Drupal\package_manager\ProcessOutputCallback $callback */
      [, $callback] = $arguments_passed_to_runner;
      $callback($callback::OUT, $command_output);
    };

    // We expect the runner to be called with two arguments: an array whose
    // first item is `--format=json`, and an output callback. The result of the
    // version check is statically cached, so the runner should only be called
    // once, even though we call validate() twice in this test.
    $runner->run(
      Argument::withEntry(0, '--format=json'),
      Argument::type(ProcessOutputCallback::class)
    )->will($pass_version_to_output_callback)->shouldBeCalledOnce();
    // The runner should be called with `validate` as the first argument, but
    // it won't affect the outcome of this test.
    $runner->run(Argument::withEntry(0, 'validate'));
    $this->container->set(ComposerRunnerInterface::class, $runner->reveal());

    if ($expected_message === '<default>') {
      $expected_message = "The detected Composer version, $reported_version, does not satisfy <code>" . ComposerInspector::SUPPORTED_VERSION . '</code>.';
    }

    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();
    /** @var \Drupal\package_manager\ComposerInspector $inspector */
    $inspector = $this->container->get(ComposerInspector::class);
    try {
      $inspector->validate($project_root);
      // If we expected the version check to succeed, ensure we did not expect
      // an exception message.
      $this->assertNull($expected_message, 'Expected an exception, but none was thrown.');
    }
    catch (ComposerNotReadyException $e) {
      $this->assertNull($e->workingDir);
      $this->assertSame($expected_message, $e->getMessage());
    }

    if (isset($expected_message)) {
      $this->expectException(ComposerNotReadyException::class);
      $this->expectExceptionMessage($expected_message);
    }
    $inspector->validate($project_root);
  }

  /**
   * @covers ::validate
   */
  public function testComposerValidateIsCalled(): void {
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();

    // Put an invalid value into composer.json and ensure it gets surfaced as
    // an exception.
    $file = new JsonFile($project_root . '/composer.json');
    $this->assertTrue($file->exists());
    $data = $file->read();
    $data['prefer-stable'] = 'truthy';
    $file->write($data);

    try {
      $this->container->get(ComposerInspector::class)
        ->validate($project_root);
      $this->fail('Expected an exception to be thrown, but it was not.');
    }
    catch (ComposerNotReadyException $e) {
      $this->assertSame($project_root, $e->workingDir);
      $this->assertStringContainsString('composer.json" does not match the expected JSON schema', $e->getMessage());
      $this->assertStringContainsString('prefer-stable : String value found, but a boolean is required', $e->getPrevious()?->getMessage());
    }
  }

  /**
   * @covers ::getRootPackageInfo
   */
  public function testRootPackageInfo(): void {
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();

    $info = $this->container->get(ComposerInspector::class)
      ->getRootPackageInfo($project_root);
    $this->assertSame('fake/site', $info['name']);
  }

  /**
   * Tests that the installed path of metapackages is always NULL.
   *
   * @param bool $is_metapackage
   *   Whether or not the test package will be a metapackage.
   * @param string|null $install_path
   *   The package install path that Composer should report. If NULL, the
   *   reported path will be unchanged. The token <PROJECT_ROOT> will be
   *   replaced with the project root.
   * @param string|null $exception_message
   *   The expected exception message, or NULL if no exception should be thrown.
   *   The token <PROJECT_ROOT> will be replaced with the project root.
   *
   * @covers ::getInstalledPackagesList
   *
   * @testWith [true, "<PROJECT_ROOT>", null]
   *   [true, "<PROJECT_ROOT>/another/directory", "Metapackage 'test/package' is installed at unexpected path: '<PROJECT_ROOT>/another/directory', expected '<PROJECT_ROOT>'"]
   *   [false, null, null]
   *   [false, "<PROJECT_ROOT>", "Package 'test/package' cannot be installed at path: '<PROJECT_ROOT>'"]
   */
  public function testMetapackagePath(bool $is_metapackage, ?string $install_path, ?string $exception_message): void {
    $inspector = new class (
      $this->container->get(ComposerRunnerInterface::class),
      $this->container->get(ComposerIsAvailableInterface::class),
      new PathFactory(),
    ) extends ComposerInspector {

      /**
       * The install path that Composer should report for `test/package`.
       *
       * If not set, the reported install path will not be changed.
       *
       * @var string
       */
      public $packagePath;

      /**
       * {@inheritdoc}
       */
      protected function show(string $working_dir): array {
        $data = parent::show($working_dir);
        if ($this->packagePath) {
          $data['test/package']['path'] = $this->packagePath;
        }
        return $data;
      }

    };
    $project_root = $this->container->get(PathLocator::class)
      ->getProjectRoot();

    if ($install_path) {
      $install_path = str_replace('<PROJECT_ROOT>', $project_root, $install_path);

      // The install path must actually exist.
      if (!is_dir($install_path)) {
        $this->assertTrue(mkdir($install_path, 0777, TRUE));
      }
      $inspector->packagePath = $install_path;
    }

    (new ActiveFixtureManipulator())
      ->addPackage([
        'name' => 'test/package',
        'type' => $is_metapackage ? 'metapackage' : 'library',
      ])
      ->commitChanges();

    if ($exception_message) {
      $this->expectException(\UnexpectedValueException::class);
      $exception_message = str_replace('<PROJECT_ROOT>', $project_root, $exception_message);
      $this->expectExceptionMessage($exception_message);
    }
    $list = $inspector->getInstalledPackagesList($project_root);
    $this->assertArrayHasKey('test/package', $list);
    // If the package is a metapackage, its path should be NULL.
    $this->assertSame($is_metapackage, is_null($list['test/package']->path));
  }

}
