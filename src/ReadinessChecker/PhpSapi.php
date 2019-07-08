<?php

namespace Drupal\automatic_updates\ReadinessChecker;

use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Warn if PHP SAPI changes between checker executions.
 */
class PhpSapi implements ReadinessCheckerInterface {
  use StringTranslationTrait;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * PhpSapi constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    $messages = [];
    $php_sapi = $this->state->get('automatic_updates.php_sapi', PHP_SAPI);
    if ($php_sapi !== PHP_SAPI) {
      $messages[] = $this->t('PHP changed from running as "@previous" to "@current". This can lead to inconsistent and misleading results.', ['@previous' => $php_sapi, '@current' => PHP_SAPI]);
    }
    $this->state->set('automatic_updates.php_sapi', PHP_SAPI);
    return $messages;
  }

}
