<?php


namespace Drupal\automatic_updates\Form;

use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\UpdateRecommender;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

class UpdaterForm extends FormBase {

  /**
   * @var \Drupal\automatic_updates\Updater
   */
  protected $updater;

  /**
   * @var \Drupal\automatic_updates\UpdateRecommender
   */
  protected $updateRecommender;

  /**
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  public function __construct(Updater $updater, UpdateRecommender $update_recommender, MessengerInterface $messenger, PrivateTempStoreFactory $temp_store_factory) {
    $this->updater = $updater;
    $this->updateRecommender = $update_recommender;
    $this->setMessenger($messenger);
    $this->tempStore = $temp_store_factory->get('automatic_updates');
  }


  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('automatic_updates.updater'),
      $container->get('automatic_updates.recommender'),
      $container->get('messenger'),
      $container->get('tempstore.private')
    );
  }


  /**
   * @inheritDoc
   */
  public function getFormId() {
    return 'automatic_updates_updater';
  }

  /**
   * @inheritDoc
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $update_stage = $this->tempStore->get('update_stage');
    $update_version = $this->updateRecommender->getRecommendedUpdateVersion('drupal');
    $form['update_version'] = [
      '#type' => 'value',
      '#value' => $update_version,
    ];
    if (!$update_stage) {
      if ($this->updater->hasActiveUpdate()) {
        $this->messenger->addError("Unknown active update");
        return $form;
      }
      if ($update_version) {
        $this->messenger->addMessage($this->t('No active update. Recommend update @version', ['@version' => $update_version]));
        $form['begin'] = [
          '#type' => 'submit',
          '#value' => $this->t('Begin Update'),
          '#name' => 'begin'
        ];

      }
    }
    else {
      switch ($update_stage) {
        case 'begin':
          $this->messenger->addMessage($this->t('Update process begun. Stage update to @version', ['@version' => $update_version]));
          $form['stage'] = [
            '#type' => 'submit',
            '#value' => $this->t('Stage Update'),
            '#name' => 'stage'
          ];
          break;
      }
    }
    return $form;
  }

  /**
   * @inheritDoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $submitted_button = $form_state->getTriggeringElement()['#name'];
    switch ($submitted_button) {
      case 'begin':
        $this->updater->begin();
        $this->messenger->addMessage('Copied active directory');
        $this->tempStore->set('update_stage', 'begin');
        break;
      case 'stage':
        $this->updater->stage($form_state->getValue('update_version'));
        $this->tempStore->set('update_stage', 'stage');
        break;

    }

  }

}