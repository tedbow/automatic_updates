<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Composer\Json\JsonFile;
use Drupal\Component\Serialization\Json;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\Exception\ComposerNotReadyException;
use Drupal\package_manager\InstalledPackage;
use Drupal\package_manager\JsonProcessOutputCallback;
use PhpTuf\ComposerStager\Domain\Exception\PreconditionException;
use PhpTuf\ComposerStager\Domain\Exception\RuntimeException;
use PhpTuf\ComposerStager\Domain\Service\Precondition\ComposerIsAvailableInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface;
use Prophecy\Argument;

/**
 * @coversDefaultClass \Drupal\package_manager\ComposerInspector
 *
 * @group package_manager
 */
class ComposerInspectorTest extends PackageManagerKernelTestBase {

  /**
   * @covers ::getConfig
   */
  public function testConfig(): void {
    $dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
    $inspector = $this->container->get('package_manager.composer_inspector');
    $this->assertSame(1, Json::decode($inspector->getConfig('secure-http', $dir)));

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
    $project_root = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
    $inspector = $this->container->get('package_manager.composer_inspector');

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
    $project_root = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    /** @var \Drupal\package_manager\ComposerInspector $inspector */
    $inspector = $this->container->get('package_manager.composer_inspector');
    $list = $inspector->getInstalledPackagesList($project_root);

    $this->assertInstanceOf(InstalledPackage::class, $list['drupal/core']);
    $this->assertSame('drupal/core', $list['drupal/core']->name);
    $this->assertSame('drupal-core', $list['drupal/core']->type);
    $this->assertSame('9.8.0', $list['drupal/core']->version);
    $this->assertSame("$project_root/vendor/drupal/core", $list['drupal/core']->path);

    $this->assertInstanceOf(InstalledPackage::class, $list['drupal/core-recommended']);
    $this->assertSame('drupal/core-recommended', $list['drupal/core-recommended']->name);
    $this->assertSame('project', $list['drupal/core-recommended']->type);
    $this->assertSame('9.8.0', $list['drupal/core']->version);
    $this->assertSame("$project_root/vendor/drupal/core-recommended", $list['drupal/core-recommended']->path);

    $this->assertInstanceOf(InstalledPackage::class, $list['drupal/core-dev']);
    $this->assertSame('drupal/core-dev', $list['drupal/core-dev']->name);
    $this->assertSame('package', $list['drupal/core-dev']->type);
    $this->assertSame('9.8.0', $list['drupal/core']->version);
    $this->assertSame("$project_root/vendor/drupal/core-dev", $list['drupal/core-dev']->path);

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

    $project_root = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
    /** @var \Drupal\package_manager\ComposerInspector $inspector */
    $inspector = $this->container->get('package_manager.composer_inspector');
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
    $project_root = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    $file_path = $project_root . '/' . $filename;
    unlink($file_path);

    /** @var \Drupal\package_manager\ComposerInspector $inspector */
    $inspector = $this->container->get('package_manager.composer_inspector');
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

      /** @var \Drupal\package_manager\JsonProcessOutputCallback $callback */
      [, $callback] = $arguments_passed_to_runner;
      $callback(JsonProcessOutputCallback::OUT, $command_output);
    };

    // We expect the runner to be called with two arguments: an array whose
    // first item is `--format=json`, and an output callback. The result of the
    // version check is statically cached, so the runner should only be called
    // once, even though we call validate() twice in this test.
    $runner->run(
      Argument::withEntry(0, '--format=json'),
      Argument::type(JsonProcessOutputCallback::class)
    )->will($pass_version_to_output_callback)->shouldBeCalledOnce();
    // The runner should be called with `validate` as the first argument, but
    // it won't affect the outcome of this test.
    $runner->run(Argument::withEntry(0, 'validate'));
    $this->container->set(ComposerRunnerInterface::class, $runner->reveal());

    if ($expected_message === '<default>') {
      $expected_message = "The detected Composer version, $reported_version, does not satisfy <code>" . ComposerInspector::SUPPORTED_VERSION . '</code>.';
    }

    $project_root = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
    /** @var \Drupal\package_manager\ComposerInspector $inspector */
    $inspector = $this->container->get('package_manager.composer_inspector');
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
    $project_root = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    // Put an invalid value into composer.json and ensure it gets surfaced as
    // an exception.
    $file = new JsonFile($project_root . '/composer.json');
    $this->assertTrue($file->exists());
    $data = $file->read();
    $data['prefer-stable'] = 'truthy';
    $file->write($data);

    try {
      $this->container->get('package_manager.composer_inspector')
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
    $project_root = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    $info = $this->container->get('package_manager.composer_inspector')
      ->getRootPackageInfo($project_root);
    $this->assertSame('fake/site', $info['name']);
  }

}
