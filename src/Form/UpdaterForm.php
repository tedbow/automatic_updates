<?php

namespace Drupal\automatic_updates\Form;

use Drupal\automatic_updates\BatchProcessor;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\UpdateRecommender;
use Drupal\automatic_updates\Validation\ReadinessValidationManager;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\system\SystemManager;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to update Drupal core.
 *
 * @internal
 *   Form classes are internal.
 */
class UpdaterForm extends FormBase {

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
   * Constructs a new UpdaterForm object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   * @param \Drupal\automatic_updates\Validation\ReadinessValidationManager $readiness_validation_manager
   *   The readiness validation manager service.
   */
  public function __construct(StateInterface $state, Updater $updater, ReadinessValidationManager $readiness_validation_manager) {
    $this->updater = $updater;
    $this->state = $state;
    $this->readinessValidationManager = $readiness_validation_manager;
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
      $container->get('automatic_updates.readiness_validation_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->messenger()->addWarning($this->t('This is an experimental updater using Composer. Use at your own risk 💀'));

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

    // @todo Add a hasErrors() or getErrors() method to
    // ReadinessValidationManager to make validation more introspectable.
    // Re-running the readiness checks now should mean that when we display
    // cached errors in automatic_updates_page_top(), we'll see errors that
    // were raised during this run, instead of any previously cached results.
    $errors = $this->readinessValidationManager->run()
      ->getResults(SystemManager::REQUIREMENT_ERROR);

    if (empty($errors)) {
      $form['actions'] = $this->actions();
    }
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
