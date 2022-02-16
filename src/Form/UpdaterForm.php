<?php

namespace Drupal\automatic_updates\Form;

use Drupal\automatic_updates\BatchProcessor;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\UpdateRecommender;
use Drupal\automatic_updates\Validation\ReadinessTrait;
use Drupal\automatic_updates\Validation\ReadinessValidationManager;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\package_manager\Exception\StageOwnershipException;
use Drupal\system\SystemManager;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Defines a form to update Drupal core.
 *
 * @internal
 *   Form classes are internal.
 */
class UpdaterForm extends FormBase {

  use ReadinessTrait;

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
   * The readiness validation manager service.
   *
   * @var \Drupal\automatic_updates\Validation\ReadinessValidationManager
   */
  protected $readinessValidationManager;

  /**
   * The event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The current session.
   *
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface
   */
  protected $session;

  /**
   * Constructs a new UpdaterForm object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   * @param \Drupal\automatic_updates\Validation\ReadinessValidationManager $readiness_validation_manager
   *   The readiness validation manager service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service.
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The current session.
   */
  public function __construct(StateInterface $state, Updater $updater, ReadinessValidationManager $readiness_validation_manager, EventDispatcherInterface $event_dispatcher, SessionInterface $session) {
    $this->updater = $updater;
    $this->state = $state;
    $this->readinessValidationManager = $readiness_validation_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->session = $session;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'automatic_updates_updater_form';
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('state'),
      $container->get('automatic_updates.updater'),
      $container->get('automatic_updates.readiness_validation_manager'),
      $container->get('event_dispatcher'),
      $container->get('session')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->messenger()->addWarning($this->t('This is an experimental Automatic Updater using Composer. Use at your own risk.'));

    if ($this->updater->isAvailable()) {
      $stage_exists = FALSE;
    }
    else {
      $stage_exists = TRUE;

      // If there's a stage ID stored in the session, try to claim the stage
      // with it. If we succeed, then an update is already in progress, and the
      // current session started it, so redirect them to the confirmation form.
      $stage_id = $this->session->get(BatchProcessor::STAGE_ID_SESSION_KEY);
      if ($stage_id) {
        try {
          $this->updater->claim($stage_id);
          return $this->redirect('automatic_updates.confirmation_page', [
            'stage_id' => $stage_id,
          ]);
        }
        catch (StageOwnershipException $e) {
          // We already know a stage exists, even if it's not ours, so we don't
          // have to do anything else here.
        }
      }
    }

    $form['last_check'] = [
      '#theme' => 'update_last_check',
      '#last' => $this->state->get('update.last_check', 0),
    ];

    $recommender = new UpdateRecommender();
    try {
      $recommended_release = $recommender->getRecommendedRelease(TRUE);
    }
    catch (\RuntimeException $e) {
      $form['message'] = [
        '#markup' => $e->getMessage(),
      ];
      return $form;
    }

    // @todo Should we be using the Update module's library here, or our own?
    $form['#attached']['library'][] = 'update/drupal.update.admin';

    // If we're already up-to-date, there's nothing else we need to do.
    if ($recommended_release === NULL) {
      $this->messenger()->addMessage('No update available');
      return $form;
    }

    $form['update_version'] = [
      '#type' => 'value',
      '#value' => [
        'drupal' => $recommended_release->getVersion(),
      ],
    ];

    $project = $recommender->getProjectInfo();
    if (empty($project['title']) || empty($project['link'])) {
      throw new \UnexpectedValueException('Expected project data to have a title and link.');
    }
    $title = Link::fromTextAndUrl($project['title'], Url::fromUri($project['link']))->toRenderable();

    switch ($project['status']) {
      case UpdateManagerInterface::NOT_SECURE:
      case UpdateManagerInterface::REVOKED:
        $title['#suffix'] = ' ' . $this->t('(Security update)');
        $type = 'update-security';
        break;

      case UpdateManagerInterface::NOT_SUPPORTED:
        $title['#suffix'] = ' ' . $this->t('(Unsupported)');
        $type = 'unsupported';
        break;

      default:
        $type = 'recommended';
        break;
    }

    // Create an entry for this project.
    $entry = [
      'title' => [
        'data' => $title,
      ],
      'installed_version' => $project['existing_version'],
      'recommended_version' => [
        'data' => [
          // @todo Is an inline template the right tool here? Is there an Update
          // module template we should use instead?
          '#type' => 'inline_template',
          '#template' => '{{ release_version }} (<a href="{{ release_link }}" title="{{ project_title }}">{{ release_notes }}</a>)',
          '#context' => [
            'release_version' => $recommended_release->getVersion(),
            'release_link' => $recommended_release->getReleaseUrl(),
            'project_title' => $this->t('Release notes for @project_title', ['@project_title' => $project['title']]),
            'release_notes' => $this->t('Release notes'),
          ],
        ],
      ],
    ];

    $form['projects'] = [
      '#type' => 'table',
      '#header' => [
        'title' => [
          'data' => $this->t('Name'),
          'class' => ['update-project-name'],
        ],
        'installed_version' => $this->t('Installed version'),
        'recommended_version' => [
          'data' => $this->t('Recommended version'),
        ],
      ],
      '#rows' => [
        'drupal' => [
          'class' => "update-$type",
          'data' => $entry,
        ],
      ],
    ];

    if ($form_state->getUserInput()) {
      $results = [];
    }
    else {
      $event = new ReadinessCheckEvent($this->updater, [
        'drupal' => $recommended_release->getVersion(),
      ]);
      $this->eventDispatcher->dispatch($event);
      $results = $event->getResults();
    }
    $this->displayResults($results, $this->messenger());

    if ($stage_exists) {
      // If the form has been submitted, do not display this error message
      // because ::deleteExistingUpdate() may run on submit. The message will
      // still be displayed on form build if needed.
      if (!$form_state->getUserInput()) {
        $this->messenger()->addError($this->t('Cannot begin an update because another Composer operation is currently in progress.'));
      }
      $form['actions']['delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Delete existing update'),
        '#submit' => ['::deleteExistingUpdate'],
      ];
    }
    // If there were no errors, allow the user to proceed with the update.
    elseif ($this->getOverallSeverity($results) !== SystemManager::REQUIREMENT_ERROR) {
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Update'),
      ];
    }
    $form['actions']['#type'] = 'actions';

    return $form;
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
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Downloading updates'))
      ->setInitMessage($this->t('Preparing to download updates'))
      ->addOperation(
        [BatchProcessor::class, 'begin'],
        [$form_state->getValue('update_version')]
      )
      ->addOperation([BatchProcessor::class, 'stage'])
      ->setFinishCallback([BatchProcessor::class, 'finishStage'])
      ->toArray();

    batch_set($batch);
  }

}
