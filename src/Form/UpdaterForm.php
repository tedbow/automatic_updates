<?php

namespace Drupal\automatic_updates\Form;

use Drupal\automatic_updates\BatchProcessor;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\ReleaseChooser;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\Validation\ReadinessTrait;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\package_manager\Exception\StageException;
use Drupal\package_manager\Exception\StageOwnershipException;
use Drupal\system\SystemManager;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

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
   * The event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The release chooser service.
   *
   * @var \Drupal\automatic_updates\ReleaseChooser
   */
  protected $releaseChooser;

  /**
   * Constructs a new UpdaterForm object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service.
   * @param \Drupal\automatic_updates\ReleaseChooser $release_chooser
   *   The release chooser service.
   */
  public function __construct(StateInterface $state, Updater $updater, EventDispatcherInterface $event_dispatcher, ReleaseChooser $release_chooser) {
    $this->updater = $updater;
    $this->state = $state;
    $this->eventDispatcher = $event_dispatcher;
    $this->releaseChooser = $release_chooser;
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
      $container->get('event_dispatcher'),
      $container->get('automatic_updates.release_chooser')
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
      $stage_id = $this->getRequest()->getSession()->get(BatchProcessor::STAGE_ID_SESSION_KEY);
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
    $project_info = new ProjectInfo();

    try {
      // @todo Until https://www.drupal.org/i/3264849 is fixed, we can only show
      //   one release on the form. First, try to show the latest release in the
      //   currently installed minor. Failing that, try to show the latest
      //   release in the next minor. If neither of those are available, just
      //   show the first available release.
      $recommended_release = $this->releaseChooser->refresh()->getLatestInInstalledMinor();
      if (!$recommended_release) {
        $recommended_release = $this->releaseChooser->getLatestInNextMinor();
        if (!$recommended_release) {
          // @todo Do not list an update that can't be validated in
          //   https://www.drupal.org/i/3271235.
          $updates = $project_info->getInstallableReleases();
          $recommended_release = array_pop($updates);
        }
      }
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
      // @todo Link to the Available Updates report if there are other updates
      //   that are not supported by this module in
      //   https://www.drupal.org/i/3271235.
      $this->messenger()->addMessage('No update available');
      return $form;
    }

    $form['update_version'] = [
      '#type' => 'value',
      '#value' => [
        'drupal' => $recommended_release->getVersion(),
      ],
    ];

    $project = $project_info->getProjectInfo();
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
      'installed_version' => $project_info->getInstalledVersion(),
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
    try {
      $this->updater->destroy(TRUE);
      $this->messenger()->addMessage($this->t("Staged update deleted"));
    }
    catch (StageException $e) {
      $this->messenger()->addError($e->getMessage());
    }
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
