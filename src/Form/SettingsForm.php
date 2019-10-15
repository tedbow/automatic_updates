<?php

namespace Drupal\automatic_updates\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for automatic updates.
 */
class SettingsForm extends ConfigFormBase {
  /**
   * The readiness checker.
   *
   * @var \Drupal\automatic_updates\ReadinessChecker\ReadinessCheckerManagerInterface
   */
  protected $checker;

  /**
   * The data formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Drupal root path.
   *
   * @var string
   */
  protected $drupalRoot;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->checker = $container->get('automatic_updates.readiness_checker');
    $instance->dateFormatter = $container->get('date.formatter');
    $drupal_finder = $container->get('automatic_updates.drupal_finder');
    $drupal_finder->locateRoot(getcwd());
    $instance->drupalRoot = $drupal_finder->getDrupalRoot();
    return $instance;
  }

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
    return 'automatic_updates_settings_form';
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
      '#title' => $this->t('Show Public service announcements on administrative pages.'),
      '#default_value' => $config->get('enable_psa'),
    ];
    $form['notify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send email notifications for Public service announcements.'),
      '#default_value' => $config->get('notify'),
      '#description' => $this->t('The email addresses listed in <a href="@update_manager">update manager settings</a> will be notified.', ['@update_manager' => Url::fromRoute('update.settings')->toString()]),
    ];
    $last_check_timestamp = $this->checker->timestamp();
    $form['enable_readiness_checks'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check the readiness of automatically updating the site.'),
      '#default_value' => $config->get('enable_readiness_checks'),
    ];
    if ($this->checker->isEnabled()) {
      $form['enable_readiness_checks']['#description'] = $this->t('Readiness checks were last run @time ago. Manually <a href="@link">run the readiness checks</a>.', [
        '@time' => $this->dateFormatter->formatTimeDiffSince($last_check_timestamp),
        '@link' => Url::fromRoute('automatic_updates.update_readiness')->toString(),
      ]);
    }
    $form['ignored_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Paths to ignore for readiness checks'),
      '#description' => $this->t('Paths relative to %drupal_root. One path per line.', ['%drupal_root' => $this->drupalRoot]),
      '#default_value' => $config->get('ignored_paths'),
      '#states' => [
        'visible' => [
          ':input[name="enable_readiness_checks"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['experimental'] = [
      '#type' => 'details',
      '#title' => t('Experimental'),
    ];
    $form['experimental']['update'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('No update for Drupal is available for version %version.', ['%version' => \Drupal::VERSION]),
    ];
    if (strpos(\Drupal::VERSION, '-dev') === FALSE) {
      \Drupal::service('update.manager')->refreshUpdateData();
      $available = update_get_available(TRUE);
      $data = update_calculate_project_data($available);
      // If we aren't on the recommended version for our version of Drupal, then
      // enable this experimental feature.
      if ($data['drupal']['existing_version'] !== $data['drupal']['recommended']) {
        if (isset($data['drupal']['security updates'])) {
          $form['experimental']['security'] = [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('A security update is available for your version of Drupal.'),
            '#weight' => -1,
          ];
        }
        $form['experimental']['update'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Even with all that caution, if you want to try it out, <a href="@link">update now</a>.', [
            '@link' => Url::fromRoute('automatic_updates.inplace-update', [
              'project' => 'drupal',
              'type' => 'core',
              'from' => \Drupal::VERSION,
              'to' => $data['drupal']['latest_version'],
            ])->toString(),
          ]),
          '#prefix' => 'Note: Might break the site. No readiness checks or anything in place. Just update the files of Drupal core. Database updates are not run.',
        ];
      }
    }

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
