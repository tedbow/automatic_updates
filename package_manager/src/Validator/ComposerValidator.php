<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates the project can be used by the Composer Inspector.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class ComposerValidator implements EventSubscriberInterface {

  use BaseRequirementValidatorTrait;
  use StringTranslationTrait;

  /**
   * Constructs a ComposerExecutableValidator object.
   *
   * @param \Drupal\package_manager\ComposerInspector $composerInspector
   *   The Composer inspector service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(
    private readonly ComposerInspector $composerInspector,
    private readonly PathLocator $pathLocator,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Validates that the Composer executable is the correct version.
   */
  public function validate(PreOperationStageEvent $event): void {
    $messages = [];
    $dir = $event instanceof PreApplyEvent
      ? $event->stage->getStageDirectory()
      : $this->pathLocator->getProjectRoot();
    try {
      $this->composerInspector->validate($dir);
    }
    catch (\Throwable $e) {
      if ($this->moduleHandler->moduleExists('help')) {
        $url = Url::fromRoute('help.page', ['name' => 'package_manager'])
          ->setOption('fragment', 'package-manager-faq-composer-not-found')
          ->toString();

        $message = $this->t('@message See <a href=":package-manager-help">the help page</a> for information on how to configure the path to Composer.', [
          '@message' => $e->getMessage(),
          ':package-manager-help' => $url,
        ]);
        $event->addError([$message]);
      }
      else {
        $event->addErrorFromThrowable($e);
      }
      return;
    }

    $settings = [];
    foreach (['disable-tls', 'secure-http'] as $key) {
      try {
        $settings[$key] = ComposerInspector::toBoolean($this->composerInspector->getConfig($key, $dir) ?: '0');
      }
      catch (\Throwable $e) {
        $event->addErrorFromThrowable($e, $this->t('Unable to determine Composer <code>@key</code> setting.', [
          '@key' => $key,
        ]));
        return;
      }
    }

    // If disable-tls is enabled, it overrides secure-http and sets its value to
    // FALSE, even if secure-http is set to TRUE explicitly.
    if ($settings['disable-tls'] === TRUE) {
      $messages[] = $this->t('TLS must be enabled for HTTPS Composer downloads. See <a href=":url">the Composer documentation</a> for more information.', [
        ':url' => 'https://getcomposer.org/doc/06-config.md#disable-tls',
      ]);
      $messages[] = $this->t('You should also check the value of <code>secure-http</code> and make sure that it is set to <code>true</code> or not set at all.');
    }
    elseif ($settings['secure-http'] !== TRUE) {
      $messages[] = $this->t('HTTPS must be enabled for Composer downloads. See <a href=":url">the Composer documentation</a> for more information.', [
        ':url' => 'https://getcomposer.org/doc/06-config.md#secure-http',
      ]);
    }

    if ($messages) {
      $event->addError($messages, $this->t("Composer settings don't satisfy Package Manager's requirements."));
    }
  }

}
