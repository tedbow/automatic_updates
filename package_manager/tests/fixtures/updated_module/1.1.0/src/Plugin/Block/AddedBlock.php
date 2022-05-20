<?php

namespace Drupal\updated_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * This block doesn't exist in version 1.0.0 of this module.
 *
 * @Block(
 *   id = "updated_module_added_block",
 *   admin_label = @Translation("Added block"),
 * )
 */
class AddedBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#markup' => $this->t('Hello!'),
    ];
  }

}
