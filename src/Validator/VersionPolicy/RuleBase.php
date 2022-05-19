<?php

namespace Drupal\automatic_updates\Validator\VersionPolicy;

use Drupal\automatic_updates\Updater;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a base class for checking version numbers against policy rules.
 *
 * The job of a policy rule is to take an updater, along with the currently
 * installed and (if known) target versions of Drupal core, and determine if
 * there is any reason why the installed version cannot be updated to the given
 * target version.
 *
 * @internal
 *   This is an internal part of Automatic Updates' version policy for
 *   Drupal core. It may be changed or removed at any time without warning.
 *   External code should not interact with this class.
 */
abstract class RuleBase {

  use StringTranslationTrait;

  /**
   * Validates the installed and target versions of Drupal core.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater which will perform the update.
   * @param string $installed_version
   *   The currently installed version of Drupal core.
   * @param string|null $target_version
   *   The version of Drupal core to which we will update, if known.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Error messages explaining why the installed version cannot be updated to
   *   the target version.
   */
  public function validate(Updater $updater, string $installed_version, ?string $target_version): array {
    $variables = [
      '@installed_version' => $installed_version,
      '@target_version' => $target_version,
    ];
    $map = function (TranslatableMarkup $message) use ($variables): TranslatableMarkup {
      // @codingStandardsIgnoreLine
      return new TranslatableMarkup($message->getUntranslatedString(), $message->getArguments() + $variables, $message->getOptions(), $this->getStringTranslation());
    };
    return array_map($map, $this->doValidation($updater, $installed_version, $target_version));
  }

  /**
   * Validates the installed and target versions of Drupal core.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater which will perform the update.
   * @param string $installed_version
   *   The currently installed version of Drupal core.
   * @param string|null $target_version
   *   The version of Drupal core to which we will update, if known.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Error messages explaining why the installed version cannot be updated to
   *   the target version. The placeholders `@installed_version` and
   *   `@target_version` will be automatically replaced with the installed and
   *   target versions of Drupal core, respectively.
   */
  abstract protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array;

}