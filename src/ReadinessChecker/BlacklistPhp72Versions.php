<?php

namespace Drupal\automatic_updates\ReadinessChecker;

use Composer\Semver\VersionParser;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Blacklisted PHP 7.2 version checker.
 */
class BlacklistPhp72Versions extends SupportedPhpVersion {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  protected function getUnsupportedVersionConstraint() {
    $parser = new VersionParser();
    // Rather than make things complicated with cli vs non-cli PHP and
    // differences in their support of opcache, libsodium and Sodium_Compat,
    // simply blacklist the entire version range to ensure the best possible
    // and coherent update support.
    return $parser->parseConstraints('>=7.2.0 <=7.2.2');
  }

  /**
   * {@inheritdoc}
   */
  protected function getMessage() {
    return $this->t('PHP 7.2.0, 7.2.1 and 7.2.2 have issues with opcache that breaks signature validation. Please upgrade to a newer version of PHP to ensure assurance and security for package signing.');
  }

}
