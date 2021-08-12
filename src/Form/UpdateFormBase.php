<?php

namespace Drupal\automatic_updates\Form;

use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines a base class for forms which are part of the attended update process.
 */
abstract class UpdateFormBase extends FormBase {

  /**
   * The updater service.
   *
   * @var \Drupal\automatic_updates\Updater
   */
  protected $updater;

  /**
   * Constructs an UpdateFormBase object.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   */
  public function __construct(Updater $updater) {
    $this->updater = $updater;
  }

  /**
   * Fires an update validation event and handles any detected errors.
   *
   * If $form and $form_state are passed, errors will be flagged against the
   * form_id element, since it's guaranteed to exist in all forms. Otherwise,
   * the errors will be displayed in the messages area.
   *
   * @param string $event
   *   The name of the event to fire. Should be one of the constants from
   *   \Drupal\automatic_updates\AutomaticUpdatesEvents.
   * @param array|null $form
   *   (optional) The complete form array.
   * @param \Drupal\Core\Form\FormStateInterface|null $form_state
   *   (optional) The current form state.
   *
   * @return bool
   *   TRUE if no errors were found, FALSE otherwise.
   */
  protected function validateUpdate(string $event, array &$form = NULL, FormStateInterface $form_state = NULL): bool {
    $errors = FALSE;
    foreach ($this->getValidationErrors($event) as $error) {
      if ($form && $form_state) {
        $form_state->setError($form['form_id'], $error);
      }
      else {
        $this->messenger()->addError($error);
      }
      $errors = TRUE;
    }
    return !$errors;
  }

  /**
   * Fires an update validation event and returns all resulting errors.
   *
   * @param string $event
   *   The name of the event to fire. Should be one of the constants from
   *   \Drupal\automatic_updates\AutomaticUpdatesEvents.
   *
   * @return \Drupal\Component\Render\MarkupInterface[]
   *   The validation errors, if any.
   */
  protected function getValidationErrors(string $event): array {
    $errors = [];
    try {
      $this->updater->dispatchUpdateEvent($event);
    }
    catch (UpdateException $e) {
      foreach ($e->getValidationResults() as $result) {
        $errors = array_merge($errors, $this->getMessagesFromValidationResult($result));
      }
    }
    return $errors;
  }

  /**
   * Extracts all relevant messages from an update validation result.
   *
   * @param \Drupal\automatic_updates\Validation\ValidationResult $result
   *   The validation result.
   *
   * @return \Drupal\Component\Render\MarkupInterface[]
   *   The messages to display from the validation result.
   */
  protected function getMessagesFromValidationResult(ValidationResult $result): array {
    $messages = $result->getMessages();
    if (count($messages) > 1) {
      array_unshift($messages, $result->getSummary());
    }
    return $messages;
  }

}
