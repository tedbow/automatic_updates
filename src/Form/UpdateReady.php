<?php

namespace Drupal\automatic_updates\Form;

use Drupal\automatic_updates\BatchProcessor;
use Drupal\automatic_updates\Updater;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\package_manager\StageException;
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
   * Constructs a new UpdateReady object.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(Updater $updater, MessengerInterface $messenger, StateInterface $state) {
    $this->updater = $updater;
    $this->setMessenger($messenger);
    $this->state = $state;
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
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $stage_id = NULL) {
    try {
      $this->updater->claim($stage_id);
    }
    catch (StageException $e) {
      $this->messenger()->addError($this->t('Cannot continue the update because another Composer operation is currently in progress.'));
      return $form;
    }

    $form['stage_id'] = [
      '#type' => 'value',
      '#value' => $stage_id,
    ];

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

    $form['actions'] = ['#type' => 'actions'];
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
    $session = $this->getRequest()->getSession();
    // Store maintenance_mode setting so we can restore it when done.
    $session->set('maintenance_mode', $this->state->get('system.maintenance_mode'));
    if ($form_state->getValue('maintenance_mode') === TRUE) {
      $this->state->set('system.maintenance_mode', TRUE);
      // @todo unset after updater. After db update?
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

}
