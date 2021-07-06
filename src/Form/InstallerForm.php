<?php


namespace Drupal\automatic_updates\Form;


use Drupal\automatic_updates\BatchProcessor;
use Drupal\automatic_updates\Updater;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class InstallerForm extends FormBase {

  /**
   * @var \Drupal\automatic_updates\Updater
   */
  protected $updater;


  /**
   * InstallerForm constructor.
   */
  public function __construct(Updater $updater, MessengerInterface $messenger) {
    $this->updater = $updater;
    $this->setMessenger($messenger);
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('automatic_updates.updater'),
      $container->get('messenger')
    );
  }


  public function getFormId() {
    return 'automatic_updates_installer';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['package'] = [
      '#title' => 'Project name or composer requirement or project URL',
      '#type' => 'textfield',
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Install'),
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $package = $form_state->getValue('package');
    if (strpos($package, 'https') === 0) {
      $parts = explode('/', $package);
      $package = array_pop($parts);
    }
    $batch_builder = (new BatchBuilder())
      ->setTitle($this->t('Downloading projects'))
      ->setInitMessage($this->t('Preparing to download selected projects'))
      ->setFinishCallback([BatchProcessor::class, 'finish']);
    $project_versions = [];
    $batch_builder->addOperation([BatchProcessor::class, 'begin']);
    // @todo Use Update XML to get recommended secure version;
    $batch_builder->addOperation([BatchProcessor::class, 'stageProjectVersions'], [[$package => '*']]);
    batch_set($batch_builder->toArray());

  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    // @todo Validate only drupal.org projects url should work
    // @todo Validate the project is not already present
  }


}