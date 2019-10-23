<?php

namespace Drupal\automatic_updates\ReadinessChecker;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Error if opcode caching is enabled and updates are executed via CLI.
 */
class OpcodeCache implements ReadinessCheckerInterface {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function run() {
    $messages = [];
    if ($this->isCli() && $this->hasOpcodeFileCache()) {
      $messages[] = $this->t('Automatic updates cannot run via CLI  when opcode file cache is enabled.');
    }
    return $messages;
  }

  /**
   * Determine if PHP is running via CLI.
   *
   * @return bool
   *   TRUE if CLI, FALSE otherwise.
   */
  protected function isCli() {
    return PHP_SAPI === 'cli';
  }

  /**
   * Determine if opcode cache is enabled.
   *
   * If opcache.validate_timestamps is disabled or enabled with
   * opcache.revalidate_freq greater then 2, then a site is considered to have
   * opcode caching. The default php.ini setup is
   * opcache.validate_timestamps=TRUE and opcache.revalidate_freq=2.
   *
   * @return bool
   *   TRUE if opcode file cache is enabled, FALSE otherwise.
   */
  protected function hasOpcodeFileCache() {
    if (!ini_get('opcache.validate_timestamps')) {
      return TRUE;
    }
    if (ini_get('opcache.revalidate_freq') > 2) {
      return TRUE;
    }
    return FALSE;
  }

}
