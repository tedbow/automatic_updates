<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Service\Precondition\NoSymlinksPointToADirectoryInterface;

/**
 * Defines dynamic container services for Package Manager.
 *
 * Scans the Composer Stager library and registers its classes in the Drupal
 * service container.
 *
 * @todo Refactor this if/when https://www.drupal.org/i/3111008 is fixed.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class PackageManagerServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    // Use an interface that we know exists to determine the absolute path where
    // Composer Stager is installed.
    $mirror = new \ReflectionClass(BeginnerInterface::class);
    $path = dirname($mirror->getFileName(), 4);

    // Certain subdirectories of Composer Stager shouldn't be scanned for
    // services.
    $ignore_directories = [
      $path . '/Domain/Exception',
      $path . '/Infrastructure/Value',
    ];
    // As we scan for services, compile a list of which classes implement which
    // interfaces so that we can set up aliases for interfaces that are only
    // implemented by one class (to facilitate autowiring).
    $interfaces = [];

    // Find all `.php` files in Composer Stager which aren't in the ignored
    // directories.
    $iterator = new \RecursiveDirectoryIterator($path, \FilesystemIterator::CURRENT_AS_SELF | \FilesystemIterator::SKIP_DOTS);
    $iterator = new \RecursiveCallbackFilterIterator($iterator, static function (\SplFileInfo $current) use ($ignore_directories): bool {
      if ($current->isDir()) {
        return !in_array($current->getPathname(), $ignore_directories, TRUE);
      }
      return $current->getExtension() === 'php';
    });
    $iterator = new \RecursiveIteratorIterator($iterator);

    /** @var \SplFileInfo $file */
    foreach ($iterator as $file) {
      // Convert the file name to a class name.
      $class_name = substr($file->getPathname(), strlen($path) + 1, -4);
      $class_name = 'PhpTuf\\ComposerStager\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $class_name);

      // Don't register interfaces and abstract classes as services.
      $reflector = new \ReflectionClass($class_name);
      if ($reflector->isInterface() || $reflector->isAbstract()) {
        continue;
      }
      foreach ($reflector->getInterfaceNames() as $interface) {
        $interfaces[$interface][] = $class_name;
      }
      // Register the class as an autowired, private service.
      $container->register($class_name)
        ->setClass($class_name)
        ->setAutowired(TRUE)
        ->setPublic(FALSE);
    }

    // Create aliases for interfaces that are only implemented by one class.
    // Ignore interfaces that already have a service alias.
    foreach ($interfaces as $interface_name => $implementations) {
      if (count($implementations) === 1 && !$container->hasAlias($interface_name)) {
        $container->setAlias($interface_name, $implementations[0]);
      }
    }

    // BEGIN: DELETE FROM CORE MERGE REQUEST
    // Remove all of this when Drupal 10.1 is the minimum required version of
    // Drupal core.
    $aliases = [
      'config.factory' => 'Drupal\Core\Config\ConfigFactoryInterface',
      'module_handler' => 'Drupal\Core\Extension\ModuleHandlerInterface',
      'state' => 'Drupal\Core\State\StateInterface',
      'extension.list.module' => 'Drupal\Core\Extension\ModuleExtensionList',
      'extension.list.theme' => 'Drupal\Core\Extension\ThemeExtensionList',
      'stream_wrapper_manager' => 'Drupal\Core\StreamWrapper\StreamWrapperManagerInterface',
      'database' => 'Drupal\Core\Database\Connection',
      'queue' => 'Drupal\Core\Queue\QueueFactory',
      'private_key' => 'Drupal\Core\PrivateKey',
      'datetime.time' => 'Drupal\Component\Datetime\TimeInterface',
      'event_dispatcher' => 'Symfony\Contracts\EventDispatcher\EventDispatcherInterface',
      'plugin.manager.mail' => 'Drupal\Core\Mail\MailManagerInterface',
      'language_manager' => 'Drupal\Core\Language\LanguageManagerInterface',
      'file_system' => 'Drupal\Core\File\FileSystemInterface',
      'tempstore.shared' => 'Drupal\Core\TempStore\SharedTempStoreFactory',
      'class_resolver' => 'Drupal\Core\DependencyInjection\ClassResolverInterface',
      'request_stack' => 'Symfony\Component\HttpFoundation\RequestStack',
      'theme_handler' => 'Drupal\Core\Extension\ThemeHandlerInterface',
      'cron' => 'Drupal\Core\CronInterface',
    ];
    foreach ($aliases as $service_id => $alias) {
      if (!$container->hasAlias($alias)) {
        $container->setAlias($alias, $service_id);
      }
    }
    // END: DELETE FROM CORE MERGE REQUEST
    // Decorate certain Composer Stager preconditions.
    $container->register(NoSymlinksPointToADirectory::class)
      ->setPublic(FALSE)
      ->setAutowired(TRUE)
      ->setDecoratedService(NoSymlinksPointToADirectoryInterface::class);
  }

}
