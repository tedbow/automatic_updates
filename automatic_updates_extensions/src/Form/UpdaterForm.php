<?php

namespace Drupal\automatic_updates_extensions\Form;

use Drupal\automatic_updates\Updater;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A form for selecting extension updates.
 */
class UpdaterForm extends FormBase {

  /**
   * The updater service.
   *
   * @var \Drupal\automatic_updates\Updater
   */
  private $updater;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('automatic_updates.updater'));
  }

  /**
   * Constructs a new UpdaterForm object.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The extension updater service.
   */
  public function __construct(Updater $updater) {
    $this->updater = $updater;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'automatic_updates_extensions_updater_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $update_projects = $this->getRecommendedModuleUpdates();
    $options = [];
    $recommended_versions = [];
    foreach ($update_projects as $project_name => $update_project) {
      switch ($update_project['status']) {
        case UpdateManagerInterface::NOT_SECURE:
        case UpdateManagerInterface::REVOKED:
          $status_message = $this->t('(Security update)');
          break;

        case UpdateManagerInterface::NOT_SUPPORTED:
          $status_message = $this->t('(Unsupported)');
          break;

        default:
          $status_message = '';
      }
      $options[$project_name] = [
        $update_project['title'] . $status_message,
        $update_project['existing_version'],
        $update_project['recommended'],
      ];
      $recommended_versions[$project_name] = $update_project['recommended'];
    }
    $form['recommended_versions'] = [
      '#type' => 'value',
      '#value' => $recommended_versions,
    ];
    $form['projects'] = [
      '#type' => 'tableselect',
      '#header' => [
        $this->t('Project:'),
        $this->t('Current Version:'),
        $this->t('Update Version:'),
      ],
      '#options' => $options,
      '#empty' => $this->t('There are no available updates.'),
      '#attributes' => ['class' => ['update-recommended']],
    ];
    if ($update_projects) {
      $form['actions'] = $this->actions($form_state);
    }
    return $form;
  }

  /**
   * Builds the form actions.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return mixed[][]
   *   The form's actions elements.
   */
  protected function actions(FormStateInterface $form_state): array {
    $actions = ['#type' => 'actions'];
    if (!$this->updater->isAvailable()) {
      // If the form has been submitted do not display this error message
      // because ::deleteExistingUpdate() may run on submit. The message will
      // still be displayed on form build if needed.
      if (!$form_state->getUserInput()) {
        $this->messenger()->addError($this->t('Cannot begin an update because another Composer operation is currently in progress.'));
      }
      $actions['delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Delete existing update'),
        '#submit' => ['::deleteExistingUpdate'],
      ];
    }
    else {
      $actions['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Update'),
      ];
    }
    return $actions;
  }

  /**
   * Submit function to delete an existing in-progress update.
   */
  public function deleteExistingUpdate(): void {
    $this->updater->destroy(TRUE);
    $this->messenger()->addMessage($this->t("Staged update deleted"));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $projects = $form_state->getValue('projects');
    $selected_projects = array_filter($projects);
    $recommended_versions = $form_state->getValue('recommended_versions');
    $selected_versions = array_intersect_key($recommended_versions, $selected_projects);
    $this->messenger()->addMessage(print_r($selected_versions, TRUE));
  }

  /**
   * Gets the modules that require updates.
   *
   * @return array
   *   Modules that require updates.
   */
  private function getRecommendedModuleUpdates(): array {
    $available_updates = update_get_available(TRUE);
    if (empty($available_updates)) {
      $this->messenger()->addError('There was a problem getting update information. Try again later.');
      return [];
    }

    $project_data = update_calculate_project_data($available_updates);
    $outdated_modules = [];
    foreach ($project_data as $project_name => $project_info) {
      if ($project_info['project_type'] === 'module' || $project_info['project_type'] === 'module-disabled') {
        if ($project_info['status'] !== UpdateManagerInterface::CURRENT) {
          if (!empty($project_info['recommended'])) {
            $outdated_modules[$project_name] = $project_info;
          }
        }
      }
    }
    return $outdated_modules;
  }

}
