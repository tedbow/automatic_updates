<?php

namespace Drupal\automatic_updates\Form;

use Drupal\automatic_updates\BatchProcessor;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\Validator\StagedDatabaseUpdateValidator;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\package_manager\Exception\StageException;
use Drupal\package_manager\Exception\StageOwnershipException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to commit staged updates.
 *
 * @internal
 *   Form classes are internal.
 */
class UpdateReady extends FormBase {

  /**
   * The updater service.
   *
   * @var \Drupal\automatic_updates\Updater
   */
  protected $updater;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The module list service.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleList;

  /**
   * The staged database update validator service.
   *
   * @var \Drupal\automatic_updates\Validator\StagedDatabaseUpdateValidator
   */
  protected $stagedDatabaseUpdateValidator;

  /**
   * Constructs a new UpdateReady object.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_list
   *   The module list service.
   * @param \Drupal\automatic_updates\Validator\StagedDatabaseUpdateValidator $staged_database_update_validator
   *   The staged database update validator service.
   */
  public function __construct(Updater $updater, MessengerInterface $messenger, StateInterface $state, ModuleExtensionList $module_list, StagedDatabaseUpdateValidator $staged_database_update_validator) {
    $this->updater = $updater;
    $this->setMessenger($messenger);
    $this->state = $state;
    $this->moduleList = $module_list;
    $this->stagedDatabaseUpdateValidator = $staged_database_update_validator;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'automatic_updates_update_ready_form';
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('automatic_updates.updater'),
      $container->get('messenger'),
      $container->get('state'),
      $container->get('extension.list.module'),
      $container->get('automatic_updates.validator.staged_database_updates')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $stage_id = NULL) {
    try {
      $this->updater->claim($stage_id);
    }
    catch (StageOwnershipException $e) {
      $this->messenger()->addError($this->t('Cannot continue the update because another Composer operation is currently in progress.'));
      return $form;
    }

    $messages = [];

    // If there are any installed modules with database updates in the staging
    // area, warn the user that they might be sent to update.php once the
    // staged changes have been applied.
    $pending_updates = $this->getModulesWithStagedDatabaseUpdates();
    if ($pending_updates) {
      $messages[MessengerInterface::TYPE_WARNING][] = $this->t('Possible database updates were detected in the following modules; you may be redirected to the database update page in order to complete the update process.');
      foreach ($pending_updates as $info) {
        $messages[MessengerInterface::TYPE_WARNING][] = $info['name'];
      }
    }

    try {
      $staged_core_packages = $this->updater->getStageComposer()
        ->getCorePackages();
    }
    catch (\Throwable $exception) {
      $messages[MessengerInterface::TYPE_ERROR][] = $this->t('There was an error loading the pending update. Press the <em>Cancel update</em> button to start over.');
    }

    // Don't set any messages if the form has been submitted, because we don't
    // want them to be set during form submit.
    if (!$form_state->getUserInput()) {
      foreach ($messages as $type => $messages_of_type) {
        foreach ($messages_of_type as $message) {
          $this->messenger()->addMessage($message, $type);
        }
      }
    }

    $form['actions'] = [
      'cancel' => [
        '#type' => 'submit',
        '#value' => $this->t('Cancel update'),
        '#submit' => ['::cancel'],
      ],
      '#type' => 'actions',
    ];
    $form['stage_id'] = [
      '#type' => 'value',
      '#value' => $stage_id,
    ];

    if (empty($staged_core_packages)) {
      return $form;
    }

    $form['target_version'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Drupal core will be updated to %version', [
        '%version' => reset($staged_core_packages)->getPrettyVersion(),
      ]),
    ];
    $form['backup'] = [
      '#prefix' => '<strong>',
      '#markup' => $this->t('This cannot be undone, so it is strongly recommended to <a href=":url">back up your database and site</a> before continuing, if you haven\'t already.', [':url' => 'https://www.drupal.org/node/22281']),
      '#suffix' => '</strong>',
    ];
    if (!$this->state->get('system.maintenance_mode')) {
      $form['maintenance_mode'] = [
        '#title' => $this->t('Perform updates with site in maintenance mode (strongly recommended)'),
        '#type' => 'checkbox',
        '#default_value' => TRUE,
      ];
    }

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue'),
    ];

    return $form;
  }

  /**
   * Returns info for all installed modules that have staged database updates.
   *
   * @return array[]
   *   The info arrays for the modules which have staged database updates, keyed
   *   by module machine name.
   */
  protected function getModulesWithStagedDatabaseUpdates(): array {
    $filter = function (string $name): bool {
      return $this->stagedDatabaseUpdateValidator->hasStagedUpdates($this->updater, $this->moduleList->get($name));
    };
    return array_filter($this->moduleList->getAllInstalledInfo(), $filter, ARRAY_FILTER_USE_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Store maintenance_mode setting so we can restore it when done.
    $this->getRequest()
      ->getSession()
      ->set(BatchProcessor::MAINTENANCE_MODE_SESSION_KEY, $this->state->get('system.maintenance_mode'));

    if ($form_state->getValue('maintenance_mode')) {
      $this->state->set('system.maintenance_mode', TRUE);
    }

    $stage_id = $form_state->getValue('stage_id');
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Apply updates'))
      ->setInitMessage($this->t('Preparing to apply updates'))
      ->addOperation([BatchProcessor::class, 'commit'], [$stage_id])
      ->addOperation([BatchProcessor::class, 'clean'], [$stage_id])
      ->setFinishCallback([BatchProcessor::class, 'finishCommit'])
      ->toArray();

    batch_set($batch);
  }

  /**
   * Cancels the in-progress update.
   */
  public function cancel(array &$form, FormStateInterface $form_state): void {
    try {
      $this->updater->destroy();
      $this->messenger()->addStatus($this->t('The update was successfully cancelled.'));
      $form_state->setRedirect('automatic_updates.report_update');
    }
    catch (StageException $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
