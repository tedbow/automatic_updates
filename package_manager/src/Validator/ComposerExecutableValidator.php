<?php

namespace Drupal\package_manager\Validator;

use Composer\Semver\Semver;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\Event\StatusCheckEvent;
use PhpTuf\ComposerStager\Domain\Exception\ExceptionInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessOutputCallback\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates the Composer executable is the correct version.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class ComposerExecutableValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The minimum required version of Composer.
   *
   * @var string
   */
  public const MINIMUM_COMPOSER_VERSION_CONSTRAINT = '~2.2.12 || ^2.3.5';

  /**
   * The Composer runner.
   *
   * @var \PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface
   */
  protected $composer;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a ComposerExecutableValidator object.
   *
   * @param \PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface $composer
   *   The Composer runner.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(ComposerRunnerInterface $composer, ModuleHandlerInterface $module_handler, TranslationInterface $translation) {
    $this->composer = $composer;
    $this->moduleHandler = $module_handler;
    $this->setStringTranslation($translation);
  }

  /**
   * {@inheritdoc}
   */
  public function validateStagePreOperation(PreOperationStageEvent $event): void {
    try {
      $output = $this->runCommand();
    }
    catch (ExceptionInterface $e) {
      $this->setError($e->getMessage(), $event);
      return;
    }

    $matched = [];
    // Search for a semantic version number and optional stability flag.
    if (preg_match('/([0-9]+\.?){3}-?((alpha|beta|rc)[0-9]*)?/i', $output, $matched)) {
      $version = $matched[0];
    }

    if (isset($version)) {
      if (!Semver::satisfies($version, static::MINIMUM_COMPOSER_VERSION_CONSTRAINT)) {
        $message = $this->t('A Composer version which satisfies <code>@minimum_version</code> is required, but version @detected_version was detected.', [
          '@minimum_version' => static::MINIMUM_COMPOSER_VERSION_CONSTRAINT,
          '@detected_version' => $version,
        ]);
        $this->setError($message, $event);
      }
    }
    else {
      $this->setError($this->t('The Composer version could not be detected.'), $event);
    }
  }

  /**
   * Flags a validation error.
   *
   * @param string $message
   *   The error message. If the Help module is enabled, a link to Package
   *   Manager's online documentation will be appended.
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event object.
   *
   * @see package_manager_help()
   */
  protected function setError(string $message, PreOperationStageEvent $event): void {
    if ($this->moduleHandler->moduleExists('help')) {
      $url = Url::fromRoute('help.page', ['name' => 'package_manager'])
        ->setOption('fragment', 'package-manager-faq-composer-not-found')
        ->toString();

      $message = $this->t('@message See <a href=":package-manager-help">the help page</a> for information on how to configure the path to Composer.', [
        '@message' => $message,
        ':package-manager-help' => $url,
      ]);
    }
    $event->addError([$message]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreCreateEvent::class => 'validateStagePreOperation',
      StatusCheckEvent::class => 'validateStagePreOperation',
    ];
  }

  /**
   * Runs `composer --version` and returns its output.
   *
   * @return string
   *   The output of `composer --version`.
   */
  protected function runCommand(): string {
    // For whatever reason, PHPCS thinks that $output is not used, even though
    // it very clearly *is*. So, shut PHPCS up for the duration of this method.
    // phpcs:disable
    $callback = new class () implements ProcessOutputCallbackInterface {

      /**
       * The command output.
       *
       * @var string
       */
      public string $output = '';

      /**
       * {@inheritdoc}
       */
      public function __invoke(string $type, string $buffer): void {
        $this->output .= $buffer;
      }

    };
    $this->composer->run(['--version'], $callback);
    return $callback->output;
    // phpcs:enable
  }

}
