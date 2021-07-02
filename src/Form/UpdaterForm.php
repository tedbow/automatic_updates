<?php


namespace Drupal\automatic_updates\Form;

use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\UpdateRecommender;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
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

  public function __construct(Updater $updater, UpdateRecommender $update_recommender, MessengerInterface $messenger) {
    $this->updater = $updater;
    $this->updateRecommender = $update_recommender;
    $this->setMessenger($messenger);
  }


  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('automatic_updates.updater'),
      $container->get('automatic_updates.recommender'),
      $container->get('messenger')
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
    $update_stage = $form_state->getTemporaryValue('update_stage');
    if (!$update_stage) {
      if ($this->updater->hasActiveUpdate()) {
        $this->messenger->addError("Unknown active update");
        return $form;
      }
      if ($update_version = $this->updateRecommender->getRecommendedUpdateVersion('drupal')) {
        $this->messenger->addMessage($this->t('No active update. Recommend update @version', ['@version' => $update_version]));
        $form['begin'] = [
          '#type' => 'submit',
          '#value' => $this->t('Begin Update'),
          '#name' => 'begin'
        ];
        $form['update_version'] = ['#value' => $update_version];
      }
    }
    return $form;
  }

  /**
   * @inheritDoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $submitted_button = $form_state->getTriggeringElement()['#name'];
    if ($submitted_button === 'begin') {
      $this->updater->begin();
      $this->messenger->addMessage('Copied active directory');
      $form_state->setTemporaryValue('update_stage', 'begin');
    }
  }

}