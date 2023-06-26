<?php

namespace Drupal\package_manager;

class Debugger {

  public static function debugOutput($value, $label = NULL, $flags = FILE_APPEND) {
    file_put_contents("/Users/ted.bowman/sites/debug2.txt", "\n***", $flags);
    if (is_bool($value)) {
      $value = $value ? 'TRUE' : 'FALSE';
    }
    elseif ($value instanceof \Throwable) {
      $value = 'Msg: ' . $value->getMessage() . '- trace ' . $value->getTraceAsString();
    }
    else {
      $value = print_r($value, TRUE);
    }
    if ($label) {
      $value = "$label: $value";
    }
    //file_put_contents("/Users/ted.bowman/sites/debug2.txt", "\n$value", $flags);
  }

}
