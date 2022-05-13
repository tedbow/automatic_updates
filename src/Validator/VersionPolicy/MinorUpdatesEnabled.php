<?php

namespace Drupal\automatic_updates\Validator\VersionPolicy;

use Drupal\automatic_updates\Updater;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ExtensionVersion;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A policy rule that allows minor updates if enabled in configuration.
 *
 * @internal
 *   This is an internal part of Automatic Updates' version policy for
 *   Drupal core. It may be changed or removed at any time without warning.
 *   External code should not interact with this class.
 */
class MinorUpdatesEnabled extends RuleBase implements ContainerInjectionInterface {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $configFactory;

  /**
   * Constructs a MinorUpdatesEnabled object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    $installed_minor = ExtensionVersion::createFromVersionString($installed_version)
      ->getMinorVersion();
    $target_minor = ExtensionVersion::createFromVersionString($target_version)
      ->getMinorVersion();

    $minor_updates_allowed = $this->configFactory->get('automatic_updates.settings')
      ->get('allow_core_minor_updates');

    if ($installed_minor === $target_minor || $minor_updates_allowed) {
      return [];
    }
    return [
      $this->t('Drupal cannot be automatically updated from its current version, @installed_version, to the recommended version, @target_version, because automatic updates from one minor version to another are not supported.'),
    ];
  }

}
