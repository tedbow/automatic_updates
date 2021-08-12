<?php

namespace Drupal\Tests\automatic_updates\Traits;

use Drupal\Component\Serialization\Json;
use PHPUnit\Framework\Assert;

/**
 * Provides assertive methods to read and write JSON data in files.
 */
trait JsonTrait {

  /**
   * Reads JSON data from a file and returns it as an array.
   *
   * @param string $path
   *   The path of the file to read.
   *
   * @return array
   *   The parsed data in the file.
   */
  protected function readJson(string $path): array {
    Assert::assertIsReadable($path);
    $data = file_get_contents($path);
    return Json::decode($data);
  }

  /**
   * Writes an array of data to a file as JSON.
   *
   * @param string $path
   *   The path of the file to write.
   * @param array $data
   *   The data to be written.
   */
  protected function writeJson(string $path, array $data): void {
    Assert::assertIsWritable(file_exists($path) ? $path : dirname($path));
    file_put_contents($path, Json::encode($data));
  }

}
