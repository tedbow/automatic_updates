<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use PhpTuf\ComposerStager\Domain\Output\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Exception\ExceptionInterface;
use PhpTuf\ComposerStager\Infrastructure\Process\Runner\ComposerRunnerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that the Composer executable can be found in the correct version.
 */
class ComposerExecutableValidator implements EventSubscriberInterface, ProcessOutputCallbackInterface {

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
   * Validates that the Composer executable can be found.
   *
   * @param \Drupal\automatic_updates\Event\ReadinessCheckEvent $event
   *   The event object.
   */
  public function checkForComposerExecutable(ReadinessCheckEvent $event): void {
    try {
      $this->composer->run(['--version'], $this);
    }
    catch (ExceptionInterface $e) {
      $error = ValidationResult::createError([
        $e->getMessage(),
      ]);
      $event->addValidationResult($error);
      return;
    }

    if ($this->version) {
      $major_version = ExtensionVersion::createFromVersionString($this->version)
        ->getMajorVersion();

      if ($major_version < 2) {
        $error = ValidationResult::createError([
          $this->t('Composer 2 or later is required, but version @version was detected.', [
            '@version' => $this->version,
          ]),
        ]);
        $event->addValidationResult($error);
      }
    }
    else {
      $error = ValidationResult::createError([
        $this->t('The Composer version could not be detected.'),
      ]);
      $event->addValidationResult($error);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ReadinessCheckEvent::class => 'checkForComposerExecutable',
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
