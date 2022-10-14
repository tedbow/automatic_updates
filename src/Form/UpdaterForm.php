<?php

namespace Drupal\automatic_updates\Form;

use Drupal\automatic_updates\BatchProcessor;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\package_manager\FailureMarker;
use Drupal\package_manager\ProjectInfo;
use Drupal\automatic_updates\ReleaseChooser;
use Drupal\automatic_updates\Updater;
use Drupal\package_manager\Exception\ApplyFailedException;
use Drupal\update\ProjectRelease;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\package_manager\Exception\StageException;
use Drupal\package_manager\Exception\StageOwnershipException;
use Drupal\package_manager\ValidationResult;
use Drupal\system\SystemManager;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Defines a form to update Drupal core.
 *
 * @internal
 *   Form classes are internal and the form structure may change at any time.
 */
final class UpdaterForm extends UpdateFormBase {

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
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Failure marker service.
   *
   * @var \Drupal\package_manager\FailureMarker
   */
  protected $failureMarker;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

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
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\package_manager\FailureMarker $failure_marker
   *   The failure marker service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(StateInterface $state, Updater $updater, EventDispatcherInterface $event_dispatcher, ReleaseChooser $release_chooser, RendererInterface $renderer, FailureMarker $failure_marker, ModuleHandlerInterface $module_handler) {
    $this->updater = $updater;
    $this->state = $state;
    $this->eventDispatcher = $event_dispatcher;
    $this->releaseChooser = $release_chooser;
    $this->renderer = $renderer;
    $this->failureMarker = $failure_marker;
    $this->moduleHandler = $module_handler;
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
      $container->get('automatic_updates.release_chooser'),
      $container->get('renderer'),
      $container->get('package_manager.failure_marker'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    try {
      $this->failureMarker->assertNotExists();
    }
    catch (ApplyFailedException $e) {
      $this->messenger()->addError($e->getMessage());
      return $form;
    }
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
    $project_info = new ProjectInfo('drupal');

    try {
      // @todo Until https://www.drupal.org/i/3264849 is fixed, we can only show
      //   one release on the form. First, try to show the latest release in the
      //   currently installed minor. Failing that, try to show the latest
      //   release in the next minor.
      $installed_minor_release = $this->releaseChooser->getLatestInInstalledMinor($this->updater);
      $next_minor_release = $this->releaseChooser->getLatestInNextMinor($this->updater);
    }
    catch (\RuntimeException $e) {
      $form['message'] = [
        '#markup' => $e->getMessage(),
      ];
      return $form;
    }

    if ($form_state->getUserInput()) {
      $results = [];
    }
    else {
      $event = new ReadinessCheckEvent($this->updater);
      $this->eventDispatcher->dispatch($event);
      $results = $event->getResults();
    }
    $this->displayResults($results, $this->renderer);
    $project = $project_info->getProjectInfo();
    if ($installed_minor_release === NULL && $next_minor_release === NULL) {
      if ($project['status'] === UpdateManagerInterface::CURRENT) {
        $this->messenger()->addMessage($this->t('No update available'));
      }
      else {
        $message = $this->t('Updates were found, but they must be performed manually. See <a href=":url">the list of available updates</a> for more information.', [
          ':url' => Url::fromRoute('update.status')->toString(),
        ]);
        // If the current release is old, but otherwise secure and supported,
        // this should be a regular status message. In any other case, urgent
        // action is needed so flag it as an error.
        $this->messenger()->addMessage($message, $project['status'] === UpdateManagerInterface::NOT_CURRENT ? MessengerInterface::TYPE_STATUS : MessengerInterface::TYPE_ERROR);
      }
      return $form;
    }

    if (empty($project['title']) || empty($project['link'])) {
      throw new \UnexpectedValueException('Expected project data to have a title and link.');
    }

    $form['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t(
        'Update <a href=":url">Drupal core</a>',
        [':url' => $project['link']],
      ),
    ];
    $form['current'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t(
        'Currently installed: @version (@status)',
        [
          '@version' => $project_info->getInstalledVersion(),
          '@status' => $this->getUpdateStatus($project['status']),
        ]
      ),
    ];

    switch ($project['status']) {
      case UpdateManagerInterface::NOT_SECURE:
      case UpdateManagerInterface::REVOKED:
        $release_status = $this->t('Security update');
        $type = 'update-security';
        break;

      default:
        $release_status = $this->t('Available update');
        $type = 'update-recommended';
    }
    $create_update_buttons = !$stage_exists && ValidationResult::getOverallSeverity($results) !== SystemManager::REQUIREMENT_ERROR;
    if ($installed_minor_release) {
      $installed_version = ExtensionVersion::createFromVersionString($project_info->getInstalledVersion());
      $form['installed_minor'] = $this->createReleaseTable(
        $installed_minor_release,
        $release_status,
        $this->t('Latest version of Drupal @major.@minor (currently installed):', [
          '@major' => $installed_version->getMajorVersion(),
          '@minor' => $installed_version->getMinorVersion(),
        ]),
        $type,
        $create_update_buttons,
        // Any update in the current minor should be the primary update.
        TRUE,
      );
    }
    if ($next_minor_release) {
      // If there is no update in the current minor make the button for the next
      // minor primary unless the project status is 'CURRENT' or 'NOT_CURRENT'.
      // 'NOT_CURRENT' does not denote that installed version is not a valid
      // only that there is newer version available.
      $is_primary = !$installed_minor_release && !($project['status'] === UpdateManagerInterface::CURRENT || $project['status'] === UpdateManagerInterface::NOT_CURRENT);
      $next_minor_version = ExtensionVersion::createFromVersionString($next_minor_release->getVersion());

      // Since updating to another minor version of Drupal is more disruptive
      // than updating within the currently installed minor version, ensure we
      // display a link to the release notes for the first (x.y.0) release of
      // the next minor version, which will inform site owners of any potential
      // pitfalls or major changes. We should always be able to get release info
      // for it; if we can't, that's an error condition.
      $first_release_version = $next_minor_version->getMajorVersion() . '.' . $next_minor_version->getMinorVersion() . '.0';
      $available_updates = update_get_available(TRUE);
      if (isset($available_updates['drupal']['releases'][$first_release_version])) {
        $next_minor_first_release = ProjectRelease::createFromArray($available_updates['drupal']['releases'][$first_release_version]);
      }
      else {
        throw new \LogicException("Release information for Drupal $first_release_version is not available.");
      }

      if ($this->moduleHandler->moduleExists('help')) {
        $url = Url::fromRoute('help.page')
          ->setRouteParameter('name', 'automatic_updates')
          ->setOption('fragment', 'minor-update');

        // @todo Updating this wording in https://www.drupal.org/i/3280403 to
        //   reflect that multiple minor branches may be visible.
        $form['minor_update_help'] = [
          '#markup' => $this->t('The following updates are in the next minor version of Drupal. <a href=":url">Learn more about updating to another minor version.</a>', [
            ':url' => $url->toString(),
          ]),
          '#prefix' => '<p>',
          '#suffix' => '</p>',
        ];
      }

      $form['next_minor'] = $this->createReleaseTable(
        $next_minor_release,
        $installed_minor_release ? $this->t('Minor update') : $release_status,
        $this->t('Latest version of Drupal @major.@minor (next minor) (<a href=":url">Release notes</a>):', [
          '@major' => $next_minor_version->getMajorVersion(),
          '@minor' => $next_minor_version->getMinorVersion(),
          ':url' => $next_minor_first_release->getReleaseUrl(),
        ]),
        $installed_minor_release ? 'update-optional' : $type,
        $create_update_buttons,
        $is_primary
      );
    }

    $form['backup'] = [
      '#markup' => $this->t('It\'s a good idea to <a href=":url">back up your database and site code</a> before you begin.', [':url' => 'https://www.drupal.org/node/22281']),
    ];

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
    $button = $form_state->getTriggeringElement();
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Downloading updates'))
      ->setInitMessage($this->t('Preparing to download updates'))
      ->addOperation(
        [BatchProcessor::class, 'begin'],
        [['drupal' => $button['#target_version']]]
      )
      ->addOperation([BatchProcessor::class, 'stage'])
      ->setFinishCallback([BatchProcessor::class, 'finishStage'])
      ->toArray();

    batch_set($batch);
  }

  /**
   * Gets the update table for a specific release.
   *
   * @param \Drupal\update\ProjectRelease $release
   *   The project release.
   * @param string $release_description
   *   The release description.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $caption
   *   The table caption, if any.
   * @param string $update_type
   *   The update type.
   * @param bool $create_update_button
   *   Whether the update button should be created.
   * @param bool $is_primary
   *   Whether update button should be a primary button.
   *
   * @return string[][]
   *   The table render array.
   */
  private function createReleaseTable(ProjectRelease $release, string $release_description, ?TranslatableMarkup $caption, string $update_type, bool $create_update_button, bool $is_primary): array {
    $release_section = ['#type' => 'container'];
    $release_section['table'] = [
      '#type' => 'table',
      '#description' => $this->t('more'),
      '#header' => [
        'title' => [
          'data' => $this->t('Update type'),
          'class' => ['update-project-name'],
        ],
        'target_version' => [
          'data' => $this->t('Version'),
        ],
      ],
    ];
    if ($caption) {
      $release_section['table']['#caption'] = $caption;
    }
    $release_section['table'][$release->getVersion()] = [
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $release_description,
      ],
      'target_version' => [
        'data' => [
          // @todo Is an inline template the right tool here? Is there an Update
          // module template we should use instead?
          '#type' => 'inline_template',
          '#template' => '{{ release_version }} (<a href="{{ release_link }}" title="{{ project_title }}">{{ release_notes }}</a>)',
          '#context' => [
            'release_version' => $release->getVersion(),
            'release_link' => $release->getReleaseUrl(),
            'project_title' => $this->t(
              'Release notes for @project_title @version',
              [
                '@project_title' => 'Drupal core',
                '@version' => $release->getVersion(),
              ]
            ),
            'release_notes' => $this->t('Release notes'),
          ],
        ],
      ],
      '#attributes' => ['class' => ['update-' . $update_type]],
    ];
    if ($create_update_button) {
      $release_section['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Update to @version', ['@version' => $release->getVersion()]),
        '#target_version' => $release->getVersion(),
      ];
      if ($is_primary) {
        $release_section['submit']['#button_type'] = 'primary';
      }
    }
    $release_section['#suffix'] = '<br />';
    return $release_section;

  }

  /**
   * Gets the human-readable project status.
   *
   * @param int $status
   *   The project status, one of \Drupal\update\UpdateManagerInterface
   *   constants.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The human-readable status.
   */
  private function getUpdateStatus(int $status): TranslatableMarkup {
    switch ($status) {
      case UpdateManagerInterface::NOT_SECURE:
        return $this->t('Security update required!');

      case UpdateManagerInterface::REVOKED:
        return $this->t('Revoked!');

      case UpdateManagerInterface::NOT_SUPPORTED:
        return $this->t('Not supported!');

      case UpdateManagerInterface::NOT_CURRENT:
        return $this->t('Update available');

      case UpdateManagerInterface::CURRENT:
        return $this->t('Up to date');

      default:
        return $this->t('Unknown status');
    }
  }

}
