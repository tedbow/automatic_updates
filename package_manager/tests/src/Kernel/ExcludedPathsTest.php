<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\EventSubscriber\ExcludedPathsSubscriber;
use Drupal\package_manager\PathLocator;
use org\bovigo\vfs\vfsStream;

/**
 * @covers \Drupal\package_manager\EventSubscriber\ExcludedPathsSubscriber
 *
 * @group package_manager
 */
class ExcludedPathsTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure that any staging directories created by TestStage are created
    // in the virtual file system.
    TestStage::$stagingRoot = $this->vfsRoot->url();

    // We need to rebuild the container after setting a private file path, since
    // the private stream wrapper is only registered if this setting is set.
    // @see \Drupal\Core\CoreServiceProvider::register()
    $this->setSetting('file_private_path', 'private');
    $kernel = $this->container->get('kernel');
    $kernel->rebuildContainer();
    $this->container = $kernel->getContainer();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    // Normally, package_manager_bypass will disable all the actual staging
    // operations. In this case, we want to perform them so that we can be sure
    // that files are staged as expected.
    $this->setSetting('package_manager_bypass_stager', FALSE);

    $container->getDefinition('package_manager.excluded_paths_subscriber')
      ->setClass(TestExcludedPathsSubscriber::class);

    parent::register($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function disableValidators(ContainerBuilder $container): void {
    parent::disableValidators($container);

    // Disable the disk space validator, since it tries to inspect the file
    // system in ways that vfsStream doesn't support, like calling stat() and
    // disk_free_space().
    $container->removeDefinition('package_manager.validator.disk_space');

    // Disable the lock file and Composer settings validators, since in this
    // test we have an imaginary file system without any Composer files.
    $container->removeDefinition('package_manager.validator.lock_file');
  }

  /**
   * Tests that certain paths are excluded from staging operations.
   */
  public function testExcludedPaths(): void {
    $site = [
      'composer.json' => '{}',
      'private' => [
        'ignore.txt' => 'This file should never be staged.',
      ],
      'sites' => [
        'default' => [
          'services.yml' => <<<END
# This file should never be staged.
must_not_be: 'empty'
END,
          'settings.local.php' => <<<END
<?php

/**
 * @file
 * This file should never be staged.
 */
END,
          'settings.php' => <<<END
<?php

/**
 * @file
 * This file should never be staged.
 */
END,
          'stage.txt' => 'This file should be staged.',
        ],
        'example.com' => [
          'files' => [
            'ignore.txt' => 'This file should never be staged.',
          ],
          'db.sqlite' => 'This file should never be staged.',
          'db.sqlite-shm' => 'This file should never be staged.',
          'db.sqlite-wal' => 'This file should never be staged.',
          'services.yml' => <<<END
# This file should never be staged.
key: "value"
END,
          'settings.local.php' => <<<END
<?php

/**
 * @file
 * This file should never be staged.
 */
END,
          'settings.php' => <<<END
<?php

/**
 * @file
 * This file should never be staged.
 */
END,
        ],
        'simpletest' => [
          'ignore.txt' => 'This file should never be staged.',
        ],
      ],
      'vendor' => [
        '.htaccess' => '# This file should never be staged.',
        'web.config' => 'This file should never be staged.',
      ],
    ];
    vfsStream::create(['active' => $site], $this->vfsRoot);

    $active_dir = $this->vfsRoot->getChild('active')->url();

    $path_locator = $this->prophesize(PathLocator::class);
    $path_locator->getActiveDirectory()->willReturn($active_dir);
    $path_locator->getProjectRoot()->willReturn($active_dir);
    $path_locator->getWebRoot()->willReturn('');
    $path_locator->getVendorDirectory()->willReturn("$active_dir/vendor");
    $this->container->set('package_manager.path_locator', $path_locator->reveal());

    $site_path = 'sites/example.com';
    // Ensure that we are using directories within the fake site fixture for
    // public and private files.
    $this->setSetting('file_public_path', "$site_path/files");

    /** @var \Drupal\Tests\package_manager\Kernel\TestExcludedPathsSubscriber $subscriber */
    $subscriber = $this->container->get('package_manager.excluded_paths_subscriber');
    $subscriber->sitePath = $site_path;

    // Mock a SQLite database connection to a file in the active directory. The
    // file should not be staged.
    $database = $this->prophesize(Connection::class);
    $database->driver()->willReturn('sqlite');
    $database->getConnectionOptions()->willReturn([
      'database' => $site_path . '/db.sqlite',
    ]);
    $subscriber->database = $database->reveal();

    $stage = $this->createStage();
    $stage->create();
    $stage_dir = $stage->getStageDirectory();

    $ignore = [
      'sites/simpletest',
      'vendor/.htaccess',
      'vendor/web.config',
      "$site_path/files/ignore.txt",
      'private/ignore.txt',
      "$site_path/settings.php",
      "$site_path/settings.local.php",
      "$site_path/services.yml",
      // SQLite databases and their support files should always be ignored.
      "$site_path/db.sqlite",
      "$site_path/db.sqlite-shm",
      "$site_path/db.sqlite-wal",
      // Default site-specific settings files should be ignored.
      'sites/default/settings.php',
      'sites/default/settings.local.php',
      'sites/default/services.yml',
    ];
    foreach ($ignore as $path) {
      $this->assertFileExists("$active_dir/$path");
      $this->assertFileDoesNotExist("$stage_dir/$path");
    }
    // A non-excluded file in the default site directory should be staged.
    $this->assertFileExists("$stage_dir/sites/default/stage.txt");

    // A new file added to the staging area in an excluded directory, should not
    // be copied to the active directory.
    $file = "$stage_dir/sites/default/no-copy.txt";
    touch($file);
    $this->assertFileExists($file);
    $stage->apply();
    $this->assertFileDoesNotExist("$active_dir/sites/default/no-copy.txt");

    // The ignored files should still be in the active directory.
    foreach ($ignore as $path) {
      $this->assertFileExists("$active_dir/$path");
    }
  }

}

/**
 * A test-only implementation of the excluded path event subscriber.
 */
class TestExcludedPathsSubscriber extends ExcludedPathsSubscriber {

  /**
   * {@inheritdoc}
   */
  public $sitePath;

  /**
   * {@inheritdoc}
   */
  public $database;

}
