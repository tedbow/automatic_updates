<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Url;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;
use PhpTuf\ComposerStager\Domain\Exception\PreconditionException;
use PhpTuf\ComposerStager\Domain\Service\Precondition\CodebaseContainsNoSymlinksInterface;
use Prophecy\Argument;

/**
 * @covers \Drupal\package_manager\Validator\SymlinkValidator
 *
 * @group package_manager
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
      'no symlinks' => [FALSE],
      'symlinks' => [TRUE],
    ];
  }

  /**
   * Tests that the validator invokes Composer Stager's symlink precondition.
   *
   * @param bool $symlinks_exist
   *   Whether or not the precondition will detect symlinks.
   *
   * @dataProvider providerSymlink
   */
  public function testSymlink(bool $symlinks_exist): void {
    $arguments = Argument::cetera();
    // The precondition should always be invoked.
    $this->precondition->assertIsFulfilled($arguments)->shouldBeCalled();

    if ($symlinks_exist) {
      $exception = new PreconditionException($this->precondition->reveal(), 'Symlinks were found.');
      $this->precondition->assertIsFulfilled($arguments)->willThrow($exception);

      $expected_results = [
        ValidationResult::createError([
          $exception->getMessage(),
        ]),
      ];
    }
    else {
      $expected_results = [];
    }

    $this->assertStatusCheckResults($expected_results);
    $this->assertResults($expected_results, PreCreateEvent::class);

    $this->enableModules(['help']);

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
