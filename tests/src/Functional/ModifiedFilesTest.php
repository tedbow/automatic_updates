<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\automatic_updates\Services\ModifiedFiles;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests of automatic updates.
 *
 * @group automatic_updates
 */
class ModifiedFilesTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'test_automatic_updates',
  ];

  /**
   * Tests modified files service.
   */
  public function testModifiedFiles() {
    // No modified code.
    $modified_files = new TestModifiedFiles(
      $this->container->get('logger.channel.automatic_updates'),
      $this->container->get('automatic_updates.drupal_finder'),
      $this->container->get('http_client'),
      $this->container->get('config.factory')
    );
    $this->initHashesEndpoint($modified_files, 'core', '8.7.0');
    $files = $modified_files->getModifiedFiles(['system' => []]);
    $this->assertEmpty($files);

    // Hash doesn't match i.e. modified code, including contrib logic.
    $this->initHashesEndpoint($modified_files, 'core', '8.0.0');
    $files = $modified_files->getModifiedFiles(['system' => []]);
    $this->assertCount(1, $files);
    $this->assertStringEndsWith('core/LICENSE.txt', $files[0]);
  }

  /**
   * Set the hashes endpoint.
   *
   * @param TestModifiedFiles $modified_code
   *   The modified code object.
   * @param string $extension
   *   The extension name.
   * @param string $version
   *   The version.
   */
  protected function initHashesEndpoint(TestModifiedFiles $modified_code, $extension, $version) {
    $modified_code->endpoint = $this->buildUrl(Url::fromRoute('test_automatic_updates.hashes_endpoint', [
      'extension' => $extension,
      'version' => $version,
    ]));
  }

}

/**
 * Class TestModifiedCode.
 */
class TestModifiedFiles extends ModifiedFiles {

  /**
   * The endpoint url.
   *
   * @var string
   */
  public $endpoint;

  /**
   * {@inheritdoc}
   */
  protected function buildUrl($extension_name, array $info) {
    return $this->endpoint;
  }

}
