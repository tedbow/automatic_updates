<?php

namespace Drupal\automatic_updates\ReadinessChecker;

use Composer\Semver\VersionParser;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Minimum PHP version checker.
 */
class MinimumPhpVersion extends SupportedPhpVersion {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  protected function getUnsupportedVersionConstraint() {
    $parser = new VersionParser();
    // Constant was introduced in 8.7.0-beta1, back fill for full 8.7 support.
    defined('DRUPAL_MINIMUM_SUPPORTED_PHP') or define('DRUPAL_MINIMUM_SUPPORTED_PHP', '7.0.8');
    return $parser->parseConstraints('<' . DRUPAL_MINIMUM_SUPPORTED_PHP);
  }

  /**
   * {@inheritdoc}
   */
  protected function getMessage() {
    return $this->t('This site is running on an unsupported version of PHP. It cannot be updated. Please update to at least PHP @version.', ['@version' => DRUPAL_MINIMUM_SUPPORTED_PHP]);
  }

}
