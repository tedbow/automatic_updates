<?php

namespace Drupal\updated_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * This block is removed from version 1.1.0 of this module.
 *
 * @Block(
 *   id = "updated_module_deleted_block",
 *   admin_label = @Translation("Deleted block"),
 * )
 */
class DeletedBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#markup' => $this->t('Goodbye!'),
    ];
  }

}
