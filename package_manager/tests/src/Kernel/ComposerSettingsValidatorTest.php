<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\PathLocator;
use Drupal\package_manager\ValidationResult;
use org\bovigo\vfs\vfsStream;

/**
 * @covers \Drupal\package_manager\EventSubscriber\ComposerSettingsValidator
 *
 * @group package_manager
 */
class ComposerSettingsValidatorTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function disableValidators(ContainerBuilder $container): void {
    parent::disableValidators($container);

    // Disable the disk space validator, since it tries to inspect the file
    // system in ways that vfsStream doesn't support, like calling stat() and
    // disk_free_space().
    $container->removeDefinition('package_manager.validator.disk_space');

    // Disable the lock file validator, since the mock file system we create in
    // this test doesn't have any lock files to validate.
    $container->removeDefinition('package_manager.validator.lock_file');
  }

  /**
   * Data provider for ::testSecureHttpValidation().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerSecureHttpValidation(): array {
    $error = ValidationResult::createError([
      'HTTPS must be enabled for Composer downloads. See <a href="https://getcomposer.org/doc/06-config.md#secure-http">the Composer documentation</a> for more information.',
    ]);

    return [
      'disabled' => [
        Json::encode([
          'config' => [
            'secure-http' => FALSE,
          ],
        ]),
        [$error],
      ],
      'explicitly enabled' => [
        Json::encode([
          'config' => [
            'secure-http' => TRUE,
          ],
        ]),
        [],
      ],
      'implicitly enabled' => [
        '{}',
        [],
      ],
    ];
  }

  /**
   * Tests that Composer's secure-http setting is validated.
   *
   * @param string $contents
   *   The contents of the composer.json file.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results, if any.
   *
   * @dataProvider providerSecureHttpValidation
   */
  public function testSecureHttpValidation(string $contents, array $expected_results): void {
    $file = vfsStream::newFile('composer.json')->setContent($contents);
    $this->vfsRoot->addChild($file);

    $active_dir = $this->vfsRoot->url();
    $locator = $this->prophesize(PathLocator::class);
    $locator->getActiveDirectory()->willReturn($active_dir);
    $locator->getProjectRoot()->willReturn($active_dir);
    $locator->getWebRoot()->willReturn('');
    $locator->getVendorDirectory()->willReturn($active_dir);
    $this->container->set('package_manager.path_locator', $locator->reveal());

    try {
      $this->createStage()->create();
      $this->assertSame([], $expected_results);
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

}
