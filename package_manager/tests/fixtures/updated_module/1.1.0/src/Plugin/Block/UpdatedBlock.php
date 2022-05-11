<?php

namespace Drupal\updated_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Defines a block plugin to test plugin reloading during an update.
 *
 * In version 1.0.0 of this module, this block exists but its plugin definition
 * and implementation are different.
 *
 * @Block(
 *   id = "updated_module_updated_block",
 *   admin_label = @Translation("1.1.0")
 * )
 */
class UpdatedBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#markup' => $this->t('1.1.0'),
    ];
  }

}
