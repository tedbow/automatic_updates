<?php

namespace Drupal\automatic_updates_extensions\Form;

use Drupal\automatic_updates_extensions\BatchProcessor;
use Drupal\automatic_updates\BatchProcessor as AutoUpdatesBatchProcessor;
use Drupal\automatic_updates_extensions\ExtensionUpdater;
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
   * @var \Drupal\automatic_updates_extensions\ExtensionUpdater
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
   * Constructs a new UpdateReady object.
   *
   * @param \Drupal\automatic_updates_extensions\ExtensionUpdater $updater
   *   The updater service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_list
   *   The module list service.
   */
  public function __construct(ExtensionUpdater $updater, MessengerInterface $messenger, StateInterface $state, ModuleExtensionList $module_list) {
    $this->updater = $updater;
    $this->setMessenger($messenger);
    $this->state = $state;
    $this->moduleList = $module_list;
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
      $container->get('automatic_updates_extensions.updater'),
      $container->get('messenger'),
      $container->get('state'),
      $container->get('extension.list.module'),
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

    // @todo Add logic to warn about possible new database updates. Determine if
    //   \Drupal\automatic_updates\Validator\StagedDatabaseUpdateValidator
    //   should be duplicated or changed so that it can work with other stages.
    // @see \Drupal\automatic_updates\Validator\StagedDatabaseUpdateValidator
    // @see \Drupal\automatic_updates\Form\UpdateReady::buildForm()

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

    // @todo Display the project versions that will be update including any
    //   dependencies that are Drupal projects.
    $form['backup'] = [
      '#prefix' => '<strong>',
      '#markup' => $this->t('Back up your database and site before you continue. <a href=":backup_url">Learn how</a>.', [':backup_url' => 'https://www.drupal.org/node/22281']),
      '#suffix' => '</strong>',
    ];
    $form['maintenance_mode'] = [
      '#title' => $this->t('Perform updates with site in maintenance mode (strongly recommended)'),
      '#type' => 'checkbox',
      '#default_value' => TRUE,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Store maintenance_mode setting so we can restore it when done.
    $this->getRequest()
      ->getSession()
      ->set(AutoUpdatesBatchProcessor::MAINTENANCE_MODE_SESSION_KEY, $this->state->get('system.maintenance_mode'));

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
      $form_state->setRedirect('automatic_updates_extensions.report_update');
    }
    catch (StageException $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
