<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that the installed core version is on a supported branch.
 *
 * @internal
 *   This class is an internal part of the module's update handling and
 *   should not be used by external code.
 */
class SupportedBranchValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a SupportedBranchValidator object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(TranslationInterface $translation, ConfigFactoryInterface $config_factory) {
    $this->setStringTranslation($translation);
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ReadinessCheckEvent::class => 'checkBranchSupported',
    ];
  }

  /**
   * Validates the installed core version is in a supported branch.
   *
   * @param \Drupal\automatic_updates\Event\ReadinessCheckEvent $event
   *   The readiness check event.
   */
  public function checkBranchSupported(ReadinessCheckEvent $event): void {
    $stage = $event->getStage();
    if (!($stage instanceof CronUpdater) || $stage->getMode() !== CronUpdater::DISABLED) {
      return;
    }
    $project_info = new ProjectInfo('drupal');
    $project_data = $project_info->getProjectInfo();
    $installed_version_string = $project_info->getInstalledVersion();
    $installed_version = ExtensionVersion::createFromVersionString($installed_version_string);
    $supported_major = FALSE;
    foreach (explode(',', $project_data['supported_branches']) as $supported_branch) {
      $branch_version = ExtensionVersion::createFromSupportBranch($supported_branch);
      if ($branch_version->getMajorVersion() === $installed_version->getMajorVersion()) {
        if ($branch_version->getMinorVersion() === $installed_version->getMinorVersion()) {
          return;
        }
        $supported_major = TRUE;
      }
    }
    $messages[] = $this->t(
      'The currently installed version of Drupal core, @version, is not in a supported minor version. Your site will not be automatically updated during cron until it is updated to a supported minor version.',
      ['@version' => $installed_version_string]
    );
    if ($supported_major && $this->configFactory->get('automatic_updates.settings')->get('allow_core_minor_updates')) {
      $messages[] = $this->t(
        'Use the <a href=":update_url">update form</a> to update to a supported version.',
        [':update_url' => Url::fromRoute('automatic_updates.module_update')->toString()]
      );
    }
    else {
      $messages[] = $this->t(
        'See the <a href=":available_updates">available updates</a> page for available updates.',
        [':available_updates' => Url::fromRoute('update.status')->toString()]
      );
    }
    $event->addError(
      [$messages],
      $this->t('Install core version unsupported')
    );
  }

}
