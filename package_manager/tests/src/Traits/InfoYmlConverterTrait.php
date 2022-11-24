<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Traits;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamFile;
use org\bovigo\vfs\visitor\vfsStreamAbstractVisitor;

/**
 * Common methods to convert info.yml file that will pass core coding standards.
 *
 * @internal
 */
trait InfoYmlConverterTrait {

  /**
   * Renames all files that end with .info.yml.hide.
   */
  protected function renameVfsInfoYmlFiles(): void {
    // Strip the `.hide` suffix from all `.info.yml.hide` files. Drupal's coding
    // standards don't allow info files to have the `project` key, but we need
    // it to be present for testing.
    vfsStream::inspect(new class () extends vfsStreamAbstractVisitor {

      /**
       * {@inheritdoc}
       */
      public function visitFile(vfsStreamFile $file) {
        $name = $file->getName();

        if (str_ends_with($name, '.info.yml.hide')) {
          $new_name = basename($name, '.hide');
          $file->rename($new_name);
        }
      }

      /**
       * {@inheritdoc}
       */
      public function visitDirectory(vfsStreamDirectory $dir) {
        foreach ($dir->getChildren() as $child) {
          $this->visit($child);
        }
      }

    });
  }

}
