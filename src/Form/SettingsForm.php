<?php

namespace Drupal\automatic_updates\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Settings form for automatic updates.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'automatic_updates.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'automatic_updates_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('automatic_updates.settings');
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Public service announcements are compared against the entire code for the site, not just installed extensions.') . '</p>',
    ];
    $form['enable_psa'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show public service announcements on administrative pages.'),
      '#default_value' => $config->get('enable_psa'),
    ];
    $form['notify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send email notifications for public service announcements.'),
      '#default_value' => $config->get('notify'),
      '#description' => $this->t('The email addresses listed in <a href="@update_manager">update manager settings</a> will be notified.', ['@update_manager' => Url::fromRoute('update.settings')->toString()]),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $form_state->cleanValues();
    $config = $this->config('automatic_updates.settings');
    foreach ($form_state->getValues() as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

}
