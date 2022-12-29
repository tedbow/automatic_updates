<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Url;
use Drupal\package_manager\Event\CollectIgnoredPathsEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;
use PhpTuf\ComposerStager\Domain\Exception\PreconditionException;
use PhpTuf\ComposerStager\Domain\Service\Precondition\CodebaseContainsNoSymlinksInterface;
use PhpTuf\ComposerStager\Domain\Value\Path\PathInterface;
use PhpTuf\ComposerStager\Domain\Value\PathList\PathListInterface;
use PHPUnit\Framework\Assert;
use Prophecy\Argument;

/**
 * @covers \Drupal\package_manager\Validator\SymlinkValidator
 * @group package_manager
 * @internal
 */
class SymlinkValidatorTest extends PackageManagerKernelTestBase {

  /**
   * The mocked precondition that checks for symlinks.
   *
   * @var \PhpTuf\ComposerStager\Domain\Service\Precondition\CodebaseContainsNoSymlinksInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  private $precondition;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->precondition = $this->prophesize(CodebaseContainsNoSymlinksInterface::class);
    parent::setUp();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    $container->getDefinition('package_manager.validator.symlink')
      ->setArgument('$precondition', $this->precondition->reveal());
  }

  /**
   * Data provider for ::testSymlink().
   *
   * @return array[]
   *   The test cases.
   */
  public function providerSymlink(): array {
    return [
      'no symlinks' => [
        FALSE,
        [],
      ],
      'symlinks' => [
        TRUE,
        [
          ValidationResult::createError(['Symlinks were found.']),
        ],
      ],
    ];
  }

  /**
   * Tests that the validator invokes Composer Stager's symlink precondition.
   *
   * @param bool $symlinks_exist
   *   Whether or not the precondition will detect symlinks.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerSymlink
   */
  public function testSymlink(bool $symlinks_exist, array $expected_results): void {
    $add_ignored_path = function (CollectIgnoredPathsEvent $event): void {
      $event->add(['ignore/me']);
    };
    $this->addEventTestListener($add_ignored_path, CollectIgnoredPathsEvent::class);

    $arguments = [
      Argument::type(PathInterface::class),
      Argument::type(PathInterface::class),
      Argument::type(PathListInterface::class),
    ];
    $this->precondition->assertIsFulfilled(...$arguments)
      ->will(function (array $arguments) use ($symlinks_exist): void {
        Assert::assertContains('ignore/me', $arguments[2]->getAll());

        if ($symlinks_exist) {
          throw new PreconditionException($this->reveal(), 'Symlinks were found.');
        }
      })
      ->shouldBeCalled();

    $this->assertStatusCheckResults($expected_results);
    $this->assertResults($expected_results, PreCreateEvent::class);

    $this->enableModules(['help']);
    // Enabling Help rebuilt the container, so we need to re-add our event
    // listener.
    $this->addEventTestListener($add_ignored_path, CollectIgnoredPathsEvent::class);

    $url = Url::fromRoute('help.page', ['name' => 'package_manager'])
      ->setOption('fragment', 'package-manager-faq-symlinks-found')
      ->toString();

    // Reformat the provided results so that they all have the link to the
    // online documentation appended to them.
    $map = function (string $message) use ($url): string {
      return $message . ' See <a href="' . $url . '">the help page</a> for information on how to resolve the problem.';
    };
    foreach ($expected_results as $index => $result) {
      $messages = array_map($map, $result->getMessages());
      $expected_results[$index] = ValidationResult::createError($messages);
    }
    $this->assertStatusCheckResults($expected_results);
    $this->assertResults($expected_results, PreCreateEvent::class);
  }

}
