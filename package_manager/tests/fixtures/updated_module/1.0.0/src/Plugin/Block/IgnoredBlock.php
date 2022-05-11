<?php

namespace Drupal\updated_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Defines a block plugin to test plugin reloading during an update.
 *
 * This block should NOT be loaded before updating to version 1.1.0 of this
 * module.
 *
 * @Block(
 *   id = "updated_module_ignored_block",
 *   admin_label = @Translation("1.0.0")
 * )
 */
class IgnoredBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#markup' => $this->t("I should not be instantiated before the update."),
    ];
  }

}
