<?php

namespace Drupal\automatic_updates\ReadinessChecker;

use Composer\Semver\VersionParser;

/**
 * Supported PHP version checker.
 */
abstract class SupportedPhpVersion implements ReadinessCheckerInterface {

  /**
   * {@inheritdoc}
   */
  public function run() {
    $messages = [];
    $parser = new VersionParser();
    $unsupported_constraint = $this->getUnsupportedVersionConstraint();
    if ($unsupported_constraint->matches($parser->parseConstraints($this->getPhpVersion()))) {
      $messages[] = $this->getMessage();
    }
    return $messages;
  }

  /**
   * Get the PHP version.
   *
   * @return string
   *   The current php version.
   */
  protected function getPhpVersion() {
    return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
  }

  /**
   * Get the unsupported PHP version constraint.
   *
   * @return \Composer\Semver\Constraint\ConstraintInterface
   *   The version constraint.
   */
  abstract protected function getUnsupportedVersionConstraint();

  /**
   * Get the message to return if the current PHP version is unsupported.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The message to return if the current PHP version is unsupported.
   */
  abstract protected function getMessage();

}
