<?php

namespace Drupal\package_manager\Validator;

use Composer\Semver\Comparator;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use PhpTuf\ComposerStager\Domain\Process\OutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\Process\Runner\ComposerRunnerInterface;
use PhpTuf\ComposerStager\Exception\ExceptionInterface;

/**
 * Validates the Composer executable is the correct version.
 */
class ComposerExecutableValidator implements PreOperationStageValidatorInterface, OutputCallbackInterface {

  use StringTranslationTrait;

  /**
   * The minimum required version of Composer.
   *
   * @var string
   */
  public const MINIMUM_COMPOSER_VERSION = '2.3.5';

  /**
   * The Composer runner.
   *
   * @var \PhpTuf\ComposerStager\Domain\Process\Runner\ComposerRunnerInterface
   */
  protected $composer;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The detected version of Composer.
   *
   * @var string
   */
  protected $version;

  /**
   * Constructs a ComposerExecutableValidator object.
   *
   * @param \PhpTuf\ComposerStager\Domain\Process\Runner\ComposerRunnerInterface $composer
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
      $this->composer->run(['--version'], $this);
    }
    catch (ExceptionInterface $e) {
      $this->setError($e->getMessage(), $event);
      return;
    }

    if ($this->version) {
      if (Comparator::lessThan($this->version, static::MINIMUM_COMPOSER_VERSION)) {
        $message = $this->t('Composer @minimum_version or later is required, but version @detected_version was detected.', [
          '@minimum_version' => static::MINIMUM_COMPOSER_VERSION,
          '@detected_version' => $this->version,
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
        ->setOption('fragment', 'package-manager-requirements')
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
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'validateStagePreOperation',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function __invoke(string $type, string $buffer): void {
    $matched = [];
    // Search for a semantic version number and optional stability flag.
    if (preg_match('/([0-9]+\.?){3}-?((alpha|beta|rc)[0-9]*)?/i', $buffer, $matched)) {
      $this->version = $matched[0];
    }
  }

}
