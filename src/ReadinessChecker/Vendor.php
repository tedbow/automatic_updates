<?php

namespace Drupal\automatic_updates\ReadinessChecker;

/**
 * Error if site is managed via composer instead of via tarballs.
 */
class Vendor extends Filesystem {

  /**
   * {@inheritdoc}
   */
  protected function doCheck() {
    if (!$this->exists($this->getVendorPath() . DIRECTORY_SEPARATOR . 'autoload.php')) {
      return [$this->t('The vendor folder could not be located.')];
    }
    return [];
  }

}
