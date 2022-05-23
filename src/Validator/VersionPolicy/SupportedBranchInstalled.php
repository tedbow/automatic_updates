<?php

namespace Drupal\automatic_updates\Validator\VersionPolicy;

use Drupal\automatic_updates\VersionParsingTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A policy rule that requires updating from a supported branch.
 *
 * @internal
 *   This is an internal part of Automatic Updates' version policy for
 *   Drupal core. It may be changed or removed at any time without warning.
 *   External code should not interact with this class.
 */
class SupportedBranchInstalled implements ContainerInjectionInterface {

  use StringTranslationTrait;
  use VersionParsingTrait;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $configFactory;

  /**
   * Constructs a SupportedBranchInstalled object.
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
   * Checks if the installed version of Drupal is in a supported branch.
   *
   * @param string $installed_version
   *   The installed version of Drupal.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages, if any.
   */
  public function validate(string $installed_version): array {
    $available_updates = update_get_available(TRUE);

    $installed_minor = static::getMajorAndMinorVersion($installed_version);
    [$installed_major] = explode('.', $installed_minor);
    $in_supported_major = FALSE;

    $supported_branches = explode(',', $available_updates['drupal']['supported_branches']);
    foreach ($supported_branches as $supported_branch) {
      // If the supported branch is the same as the installed minor version,
      // this rule is fulfilled.
      if (rtrim($supported_branch, '.') === $installed_minor) {
        return [];
      }
      // Otherwise, see if this supported branch is in the same major version as
      // what's installed, since that will influence our messaging.
      elseif ($in_supported_major === FALSE) {
        $supported_major = ExtensionVersion::createFromSupportBranch($supported_branch)
          ->getMajorVersion();
        $in_supported_major = $supported_major === $installed_major;
      }
    }

    // By this point, we know the installed version of Drupal is not in a
    // supported branch, so we'll always show this message.
    $messages = [
      $this->t('The currently installed version of Drupal core, @installed_version, is not in a supported minor version. Your site will not be automatically updated during cron until it is updated to a supported minor version.', [
        '@installed_version' => $installed_version,
      ]),
    ];

    // If the installed version of Drupal is in a supported major branch, an
    // attended update may be possible, depending on configuration.
    $allow_minor_updates = $this->configFactory->get('automatic_updates.settings')
      ->get('allow_core_minor_updates');

    if ($in_supported_major && $allow_minor_updates) {
      $messages[] = $this->t('Use the <a href=":url">update form</a> to update to a supported version.', [
        ':url' => Url::fromRoute('automatic_updates.module_update')->toString(),
      ]);
    }
    else {
      $messages[] = $this->t('See the <a href=":url">available updates page</a> for available updates.', [
        ':url' => Url::fromRoute('update.status')->toString(),
      ]);
    }
    return $messages;
  }

}
