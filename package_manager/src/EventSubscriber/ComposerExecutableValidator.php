<?php

namespace Drupal\package_manager\EventSubscriber;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use PhpTuf\ComposerStager\Domain\Output\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Exception\ExceptionInterface;
use PhpTuf\ComposerStager\Infrastructure\Process\Runner\ComposerRunnerInterface;

/**
 * Validates that the Composer executable can be found in the correct version.
 */
class ComposerExecutableValidator implements PreOperationStageValidatorInterface, ProcessOutputCallbackInterface {

  use StringTranslationTrait;

  /**
   * The Composer runner.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Process\Runner\ComposerRunnerInterface
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
   * @param \PhpTuf\ComposerStager\Infrastructure\Process\Runner\ComposerRunnerInterface $composer
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
      $major_version = ExtensionVersion::createFromVersionString($this->version)
        ->getMajorVersion();

      if ($major_version < 2) {
        $event->addError([
          $this->t('Composer 2 or later is required, but version @version was detected.', [
            '@version' => $this->version,
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
