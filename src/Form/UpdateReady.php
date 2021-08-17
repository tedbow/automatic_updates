<?php

namespace Drupal\automatic_updates\Form;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Updater;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to commit staged updates.
 *
 * @internal
 *   Form classes are internal.
 */
class UpdateReady extends UpdateFormBase {

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
    parent::__construct($updater);
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
  public function buildForm(array $form, FormStateInterface $form_state) {
    if (!$this->validateUpdate(AutomaticUpdatesEvents::PRE_COMMIT)) {
      return $form;
    }

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
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $this->validateUpdate(AutomaticUpdatesEvents::PRE_COMMIT, $form, $form_state);
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

    // @todo Should these operations be done in batch.
    $this->updater->commit();
    // Clean could be done in another page load or on cron to reduce page time.
    $this->updater->clean();
    $this->messenger->addMessage("Update complete!");

    // @todo redirect to update.php?
    $form_state->setRedirect('automatic_updates.update_form');
  }

}
