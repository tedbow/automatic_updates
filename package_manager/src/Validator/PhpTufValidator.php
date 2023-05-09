<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that PHP-TUF is installed and correctly configured.
 *
 * In both the active and stage directories, this checks for the following
 * conditions:
 * - The PHP-TUF plugin is installed.
 * - The plugin is not explicitly blocked by Composer's `allow-plugins`
 *   configuration.
 * - Composer is aware of at least one repository hosted at
 *   packages.drupal.org (since that's currently the only server that supports
 *   TUF), and that those repositories have TUF support explicitly enabled.
 *
 * Note that this validator is currently not active, because the service
 * definition is not tagged as an event subscriber. This will be changed in
 * https://drupal.org/i/3358504, once TUF support is rolled out on
 * packages.drupal.org.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class PhpTufValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The name of the PHP-TUF Composer integration plugin.
   *
   * @var string
   */
  public const PLUGIN_NAME = 'php-tuf/composer-integration';

  /**
   * Constructs a PhpTufValidator object.
   *
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   * @param \Drupal\package_manager\ComposerInspector $composerInspector
   *   The Composer inspector service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(
    private readonly PathLocator $pathLocator,
    private readonly ComposerInspector $composerInspector,
    private readonly ModuleHandlerInterface $moduleHandler
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      StatusCheckEvent::class => 'validate',
      PreCreateEvent::class => 'validate',
      PreRequireEvent::class => 'validate',
      PreApplyEvent::class => 'validate',
    ];
  }

  /**
   * Reacts to a stage event by validating PHP-TUF configuration as needed.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event object.
   */
  public function validate(PreOperationStageEvent $event): void {
    $messages = $this->validateTuf($this->pathLocator->getProjectRoot());
    if ($messages) {
      $event->addError($messages, $this->t('The active directory is not protected by PHP-TUF, which is required to use Package Manager securely.'));
    }

    $stage = $event->stage;
    if ($stage->stageDirectoryExists()) {
      $messages = $this->validateTuf($stage->getStageDirectory());
      if ($messages) {
        $event->addError($messages, $this->t('The stage directory is not protected by PHP-TUF, which is required to use Package Manager securely.'));
      }
    }
  }

  /**
   * Flags messages if PHP-TUF is not installed and configured properly.
   *
   * @param string $dir
   *   The directory to examine.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages, if any.
   */
  private function validateTuf(string $dir): array {
    $messages = [];

    if ($this->moduleHandler->moduleExists('help')) {
      $help_url = Url::fromRoute('help.page', ['name' => 'package_manager'])
        ->setOption('fragment', 'package-manager-tuf-info')
        ->toString();
    }

    // The Composer plugin must be installed.
    $installed_packages = $this->composerInspector->getInstalledPackagesList($dir);
    if (!isset($installed_packages[static::PLUGIN_NAME])) {
      $message = $this->t('The <code>@plugin</code> plugin is not installed.', [
        '@plugin' => static::PLUGIN_NAME,
      ]);
      if (isset($help_url)) {
        $message = $this->t('@message See <a href=":url">the help page</a> for more information on how to install the plugin.', [
          '@message' => $message,
          ':url' => $help_url,
        ]);
      }
      $messages[] = $message;
    }

    // And it has to be explicitly enabled.
    $allowed_plugins = $this->composerInspector->getAllowPluginsConfig($dir);
    if ($allowed_plugins !== TRUE && empty($allowed_plugins[static::PLUGIN_NAME])) {
      $message = $this->t('The <code>@plugin</code> plugin is not listed as an allowed plugin.', [
        '@plugin' => static::PLUGIN_NAME,
      ]);
      if (isset($help_url)) {
        $message = $this->t('@message See <a href=":url">the help page</a> for more information on how to configure the plugin.', [
          '@message' => $message,
          ':url' => $help_url,
        ]);
      }
      $messages[] = $message;
    }

    // Get the defined repositories that use packages.drupal.org.
    $repositories = array_filter(
      Json::decode($this->composerInspector->getConfig('repositories', $dir)),
      fn (array $r): bool => str_starts_with($r['url'], 'https://packages.drupal.org')
    );

    // All packages.drupal.org repositories must have TUF protection.
    foreach ($repositories as $repository) {
      if (empty($repository['tuf'])) {
        $messages[] = $this->t('TUF is not enabled for the @url repository.', [
          '@url' => $repository['url'],
        ]);
      }
    }

    // There must be at least one repository using packages.drupal.org, since
    // that's the only repository which supports TUF right now.
    if (empty($repositories)) {
      $message = $this->t('The <code>https://packages.drupal.org</code> Composer repository must be defined in <code>composer.json</code>.');
      if (isset($help_url)) {
        $message = $this->t('@message See <a href=":url">the help page</a> for more information on how to set up this repository.', [
          '@message' => $message,
          ':url' => $help_url,
        ]);
      }
      $messages[] = $message;
    }
    return $messages;
  }

}
