<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates_extensions\Form;

use Drupal\automatic_updates\Form\UpdateFormBase;
use Drupal\automatic_updates_extensions\BatchProcessor;
use Drupal\automatic_updates_extensions\ExtensionUpdater;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\Exception\StageFailureMarkerException;
use Drupal\package_manager\FailureMarker;
use Drupal\package_manager\PathLocator;
use Drupal\package_manager\ProjectInfo;
use Drupal\package_manager\ValidationResult;
use Drupal\system\SystemManager;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A form for selecting extension updates.
 *
 * @internal
 *   Form classes are internal.
 */
final class UpdaterForm extends UpdateFormBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('automatic_updates_extensions.updater'),
      $container->get('event_dispatcher'),
      $container->get('renderer'),
      $container->get('state'),
      $container->get('package_manager.failure_marker'),
      $container->get('package_manager.composer_inspector'),
      $container->get('package_manager.path_locator')
    );
  }

  /**
   * Constructs a new UpdaterForm object.
   *
   * @param \Drupal\automatic_updates_extensions\ExtensionUpdater $extensionUpdater
   *   The extension updater service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The extension event dispatcher service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\package_manager\FailureMarker $failureMarker
   *   The failure marker service.
   * @param \Drupal\package_manager\ComposerInspector $composerInspector
   *   The Composer inspector service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   */
  public function __construct(
    private ExtensionUpdater $extensionUpdater,
    private EventDispatcherInterface $eventDispatcher,
    private RendererInterface $renderer,
    private StateInterface $state,
    private FailureMarker $failureMarker,
    private ComposerInspector $composerInspector,
    private PathLocator $pathLocator,
  ) {}

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
    try {
      $this->failureMarker->assertNotExists();
    }
    catch (StageFailureMarkerException $e) {
      $this->messenger()->addError($e->getMessage());
      return $form;
    }
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
      '#required' => TRUE,
      '#required_error' => t('Please select one or more projects.'),
    ];

    if ($form_state->getUserInput()) {
      $results = [];
    }
    else {
      $results = $this->runStatusCheck($this->extensionUpdater, $this->eventDispatcher);
    }
    $this->displayResults($results, $this->renderer);
    $security_level = ValidationResult::getOverallSeverity($results);

    if ($update_projects && $security_level !== SystemManager::REQUIREMENT_ERROR) {
      $form['actions'] = $this->actions($form_state);
    }

    $form['last_check'] = [
      '#theme' => 'update_last_check',
      '#last' => $this->state->get('update.last_check', 0),
    ];
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
    if (!$this->extensionUpdater->isAvailable()) {
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
    $this->extensionUpdater->destroy(TRUE);
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
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Downloading updates'))
      ->setInitMessage($this->t('Preparing to download updates'))
      ->addOperation(
        [BatchProcessor::class, 'begin'],
        [$selected_versions]
      )
      ->addOperation([BatchProcessor::class, 'stage'])
      ->setFinishCallback([BatchProcessor::class, 'finishStage'])
      ->toArray();

    batch_set($batch);
  }

  /**
   * Gets the modules that require updates.
   *
   * @return mixed[]
   *   Modules that require updates.
   */
  private function getRecommendedModuleUpdates(): array {
    $supported_project_types = [
      "module", "module-disabled", "theme", "theme-disabled",
    ];
    $available_updates = update_get_available(TRUE);
    if (empty($available_updates)) {
      $this->messenger()->addError('There was a problem getting update information. Try again later.');
      return [];
    }

    $all_projects_data = update_calculate_project_data($available_updates);
    $outdated_modules = [];
    $installed_packages = $this->composerInspector->getInstalledPackagesList($this->pathLocator->getProjectRoot())
      ->getArrayCopy();
    $non_supported_update_statuses = [];
    foreach ($all_projects_data as $project_name => $project_data) {
      if (in_array($project_data['project_type'], $supported_project_types, TRUE)) {
        if ($project_data['status'] !== UpdateManagerInterface::CURRENT) {
          if (!array_key_exists("drupal/$project_name", $installed_packages)) {
            $non_supported_update_statuses[] = $project_data['status'];
            continue;
          }
          $project_information = new ProjectInfo($project_name);
          $installable_versions = array_keys($project_information->getInstallableReleases());
          if (!empty($project_data['recommended']) && in_array($project_data['recommended'], $installable_versions, TRUE)) {
            $outdated_modules[$project_name] = $project_data;
          }
          else {
            $non_supported_update_statuses[] = $project_data['status'];
          }
        }
      }
    }
    if ($non_supported_update_statuses) {
      $message_status = array_intersect([UpdateManagerInterface::NOT_SECURE, UpdateManagerInterface::NOT_SUPPORTED, UpdateManagerInterface::REVOKED], $non_supported_update_statuses) ?
        MessengerInterface::TYPE_ERROR :
        MessengerInterface::TYPE_STATUS;
      if ($outdated_modules) {
        $this->messenger()->addMessage(
          $this->t(
            'Other updates were found, but they must be performed manually. See <a href=":url">the list of available updates</a> for more information.',
            [
              ':url' => Url::fromRoute('update.status')->toString(),
            ]
          ),
          $message_status
        );
      }
      else {
        $this->messenger()->addMessage(
          $this->t(
            'Updates were found, but they must be performed manually. See <a href=":url">the list of available updates</a> for more information.',
            [
              ':url' => Url::fromRoute('update.status')->toString(),
            ]
          ),
          $message_status
        );
      }
    }
    return $outdated_modules;
  }

}
