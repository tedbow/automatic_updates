<?php

namespace Drupal\automatic_updates\Form;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\BatchProcessor;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates_9_3_shim\ProjectRelease;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to update Drupal core.
 *
 * @internal
 *   Form classes are internal.
 */
class UpdaterForm extends UpdateFormBase {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a new UpdateManagerUpdate object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   */
  public function __construct(ModuleHandlerInterface $module_handler, StateInterface $state, Updater $updater) {
    parent::__construct($updater);
    $this->moduleHandler = $module_handler;
    $this->state = $state;
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
      $container->get('module_handler'),
      $container->get('state'),
      $container->get('automatic_updates.updater')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->messenger()->addWarning($this->t('This is an experimental updater using Composer. Use at your own risk 💀'));
    $this->moduleHandler->loadInclude('update', 'inc', 'update.manager');

    $form['last_check'] = [
      '#theme' => 'update_last_check',
      '#last' => $this->state->get('update.last_check', 0),
    ];

    $available = update_get_available(TRUE);
    if (empty($available)) {
      $form['message'] = [
        '#markup' => $this->t('There was a problem getting update information. Try again later.'),
      ];
      return $form;
    }

    // @todo Should we be using the Update module's library here, or our own?
    $form['#attached']['library'][] = 'update/drupal.update.admin';

    $this->moduleHandler->loadInclude('update', 'inc', 'update.compare');
    $project_data = update_calculate_project_data($available);
    $project = $project_data['drupal'];

    // If we're already up-to-date, there's nothing else we need to do.
    if ($project['status'] === UpdateManagerInterface::CURRENT) {
      $this->messenger()->addMessage('No update available');
      return $form;
    }
    // If we don't know what to recommend they upgrade to, time to freak out.
    elseif (empty($project['recommended'])) {
      // @todo Can we fail more gracefully here? Maybe link to the status report
      // page, or do anything other than throw a nasty exception?
      throw new \LogicException("Should always have an update at this point");
    }

    $recommended_release = ProjectRelease::createFromArray($project['releases'][$project['recommended']]);

    $form['update_version'] = [
      '#type' => 'value',
      '#value' => [
        'drupal' => $recommended_release->getVersion(),
      ],
    ];

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

    $form['actions'] = $this->actions();
    return $form;
  }

  /**
   * Builds the form actions.
   *
   * @return mixed[][]
   *   The form's actions elements.
   */
  protected function actions(): array {
    $actions = ['#type' => 'actions'];

    if ($this->updater->hasActiveUpdate()) {
      $this->messenger()->addError($this->t('Another Composer update process is currently active'));
      $actions['delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Delete existing update'),
        '#submit' => ['::deleteExistingUpdate'],
      ];
    }
    else {
      $actions['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Download these updates'),
      ];
    }
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $this->validateUpdate(AutomaticUpdatesEvents::PRE_START, $form, $form_state);
  }

  /**
   * Submit function to delete an existing in-progress update.
   */
  public function deleteExistingUpdate(): void {
    $this->updater->clean();
    $this->messenger()->addMessage($this->t("Staged update deleted"));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Downloading updates'))
      ->setInitMessage($this->t('Preparing to download updates'))
      ->addOperation([BatchProcessor::class, 'begin'])
      ->addOperation([BatchProcessor::class, 'stageProjectVersions'], [
        $form_state->getValue('update_version'),
      ])
      ->setFinishCallback([BatchProcessor::class, 'finish'])
      ->toArray();

    batch_set($batch);
  }

}
