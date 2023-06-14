<?php

namespace Drupal\package_manager;

trait DebuggerTrait {

  protected function debugOut($string, $flags = FILE_APPEND) {
    file_put_contents("/Users/ted.bowman/sites/drush.txt", "\n" . $string, $flags);
  }

}
