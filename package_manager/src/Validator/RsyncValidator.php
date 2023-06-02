<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use PhpTuf\ComposerStager\Domain\Exception\LogicException;
use PhpTuf\ComposerStager\Infrastructure\Service\Finder\ExecutableFinderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Checks that rsync is available, if it is the configured file syncer.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class RsyncValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs an RsyncValidator object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \PhpTuf\ComposerStager\Infrastructure\Service\Finder\ExecutableFinderInterface $executableFinder
   *   The executable finder service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ExecutableFinderInterface $executableFinder,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Checks that rsync is being used, if it's available.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event being handled.
   */
  public function validate(PreOperationStageEvent $event): void {
    try {
      $this->executableFinder->find('rsync');
      $rsync_found = TRUE;
    }
    catch (LogicException) {
      $rsync_found = FALSE;
    }

    if ($this->moduleHandler->moduleExists('help')) {
      $help_url = Url::fromRoute('help.page')
        ->setRouteParameter('name', 'package_manager')
        ->setOption('fragment', 'package-manager-faq-rsync')
        ->toString();
    }

    $configured_syncer = $this->configFactory->get('package_manager.settings')
      ->get('file_syncer');

    // If the PHP file syncer is selected, warn that we don't recommend it.
    if ($configured_syncer === 'php') {
      // Only status checks support warnings.
      if ($event instanceof StatusCheckEvent) {
        $message = $this->t('You are currently using the PHP file syncer, which has known problems and is not stable. It is strongly recommended to switch back to the default <em>rsync</em> file syncer instead.');

        if (!$rsync_found) {
          $message = $this->t('@message <code>rsync</code> was not found on your system.', ['@message' => $message]);
        }
        if (isset($help_url)) {
          $message = $this->t('@message See the <a href=":url">Package Manager help</a> for more information on how to resolve this.', [
            ':url' => $help_url,
            '@message' => $message,
          ]);
        }
        $event->addWarning([$message]);
      }
      // If we're not doing a status check, well, I guess we'll be using the
      // PHP file syncer. There's nothing else to do.
      return;
    }

    if ($rsync_found === FALSE) {
      $message = $this->t('<code>rsync</code> is not available.');

      if (isset($help_url)) {
        $message = $this->t('@message See the <a href=":url">Package Manager help</a> for more information on how to resolve this.', [
          '@message' => $message,
          ':url' => $help_url,
        ]);
      }
      $event->addError([$message]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      StatusCheckEvent::class => 'validate',
      PreCreateEvent::class => 'validate',
    ];
  }

}
