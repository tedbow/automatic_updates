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
    return $parser->parseConstraints('<' . DRUPAL_MINIMUM_SUPPORTED_PHP);
  }

  /**
   * {@inheritdoc}
   */
  protected function getMessage() {
    return $this->t('This site is running on an unsupported version of PHP. It cannot be updated. Please update to at least PHP @version.', ['@version' => DRUPAL_MINIMUM_SUPPORTED_PHP]);
  }

}
