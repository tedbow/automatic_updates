<?php

namespace Drupal\package_manager\Validator;

use Composer\Semver\Comparator;
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
  public const MINIMUM_COMPOSER_VERSION = '2.2.4';

  /**
   * The Composer runner.
   *
   * @var \PhpTuf\ComposerStager\Domain\Process\Runner\ComposerRunnerInterface
   */
  protected $composer;

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
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(ComposerRunnerInterface $composer, TranslationInterface $translation) {
    $this->composer = $composer;
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
      $event->addError([
        $e->getMessage(),
      ]);
      return;
    }

    if ($this->version) {
      if (Comparator::lessThan($this->version, static::MINIMUM_COMPOSER_VERSION)) {
        $event->addError([
          $this->t('Composer @minimum_version or later is required, but version @detected_version was detected.', [
            '@minimum_version' => static::MINIMUM_COMPOSER_VERSION,
            '@detected_version' => $this->version,
          ]),
        ]);
      }
    }
    else {
      $event->addError([
        $this->t('The Composer version could not be detected.'),
      ]);
    }
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
