<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessChecker;

use Drupal\automatic_updates\ReadinessChecker\ModifiedFiles;
use Drupal\automatic_updates\Services\ModifiedFilesInterface;
use Drupal\KernelTests\KernelTestBase;
use Prophecy\Argument;

/**
 * Tests of automatic updates.
 *
 * @group automatic_updates
 */
class ModifiedFilesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'test_automatic_updates',
  ];

  /**
   * Tests modified files service.
   */
  public function testModifiedFiles() {
    /** @var \Prophecy\Prophecy\ObjectProphecy|\Drupal\automatic_updates\Services\ModifiedFilesInterface $service */
    $service = $this->prophesize(ModifiedFilesInterface::class);
    $service->getModifiedFiles(Argument::type('array'))->willReturn([]);
    $modules = $this->container->get('extension.list.module');
    $profiles = $this->container->get('extension.list.profile');
    $themes = $this->container->get('extension.list.theme');

    // No modified code.
    $modified_files = new ModifiedFiles(
      $service->reveal(),
      $modules,
      $profiles,
      $themes
    );
    $messages = $modified_files->run();
    $this->assertEmpty($messages);

    // Hash doesn't match i.e. modified code.
    $service->getModifiedFiles(Argument::type('array'))->willReturn(['core/LICENSE.txt']);
    $messages = $modified_files->run();
    $this->assertCount(1, $messages);
  }

}
