<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\package_manager\Validator\RsyncValidator;
use PhpTuf\ComposerStager\Domain\Exception\LogicException;
use PhpTuf\ComposerStager\Infrastructure\Service\Finder\ExecutableFinderInterface;

/**
 * @covers \Drupal\package_manager\Validator\RsyncValidator
 * @group package_manager
 * @internal
 */
class RsyncValidatorTest extends PackageManagerKernelTestBase {

  /**
   * The mocked executable finder.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Service\Finder\ExecutableFinderInterface
   */
  private $executableFinder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Set up a mocked executable finder which will always be re-injected into
    // the validator when the container is rebuilt.
    $this->executableFinder = $this->prophesize(ExecutableFinderInterface::class);
    $this->executableFinder->find('rsync')->willReturn('/path/to/rsync');

    parent::setUp();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    $container->getDefinition(RsyncValidator::class)
      ->setArgument('$executableFinder', $this->executableFinder->reveal());
  }

  /**
   * Data provider for ::testConfiguredFileSyncer().
   *
   * @return array[]
   *   The test cases.
   */
  public function providerConfiguredFileSyncer(): array {
    return [
      'using rsync' => [
        'rsync',
        [],
        [],
      ],
      'not using rsync, help not installed' => [
        'php',
        [
          ValidationResult::createWarning([
            t('You are currently using the PHP file syncer, which has known problems and is not stable. It is strongly recommended to switch back to the default <em>rsync</em> file syncer instead.'),
          ]),
        ],
        [],
      ],
      'not using rsync, help installed' => [
        'php',
        [
          ValidationResult::createWarning([
            t('You are currently using the PHP file syncer, which has known problems and is not stable. It is strongly recommended to switch back to the default <em>rsync</em> file syncer instead. See the <a href="/admin/help/package_manager#package-manager-faq-rsync">Package Manager help</a> for more information on how to resolve this.'),
          ]),
        ],
        ['help'],
      ],
    ];
  }

  /**
   * Tests that the file_syncer config option is validated.
   *
   * @param string $configured_syncer
   *   The file_syncer value in package_manager.settings config.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected status check results.
   * @param string[] $additional_modules
   *   Any additional modules to enable.
   *
   * @dataProvider providerConfiguredFileSyncer
   */
  public function testConfiguredFileSyncer(string $configured_syncer, array $expected_results, array $additional_modules): void {
    if ($additional_modules) {
      $this->enableModules($additional_modules);
    }

    $this->config('package_manager.settings')
      ->set('file_syncer', $configured_syncer)
      ->save();

    $this->assertStatusCheckResults($expected_results);
  }

  /**
   * Tests that the stage is created even if the PHP file syncer is selected.
   */
  public function testPreCreateAllowsPhpSyncer(): void {
    $this->config('package_manager.settings')
      ->set('file_syncer', 'php')
      ->save();

    $this->assertResults([]);
  }

  /**
   * Tests that the stage cannot be created if rsync is selected, but not found.
   */
  public function testPreCreateFailsIfRsyncNotFound(): void {
    $this->executableFinder->find('rsync')->willThrow(new LogicException('Nope!'));

    $result = ValidationResult::createError([
      t('<code>rsync</code> is not available.'),
    ]);
    $this->assertResults([$result], PreCreateEvent::class);

    $this->enableModules(['help']);

    $result = ValidationResult::createError([
      t('<code>rsync</code> is not available. See the <a href="/admin/help/package_manager#package-manager-faq-rsync">Package Manager help</a> for more information on how to resolve this.'),
    ]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

}
