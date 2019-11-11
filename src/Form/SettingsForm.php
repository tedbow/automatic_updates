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
   * The update manager service.
   *
   * @var \Drupal\update\UpdateManagerInterface
   */
  protected $updateManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->checker = $container->get('automatic_updates.readiness_checker');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->drupalRoot = (string) $container->get('app.root');
    $instance->updateManager = $container->get('update.manager');
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
      '#description' => $this->t('Paths relative to %drupal_root. One path per line. Automatic Updates is intentionally limited to Drupal core. It is recommended to ignore paths to contrib extensions.', ['%drupal_root' => $this->drupalRoot]),
      '#default_value' => $config->get('ignored_paths'),
      '#states' => [
        'visible' => [
          ':input[name="enable_readiness_checks"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $this->updateManager->refreshUpdateData();
    $available = update_get_available(TRUE);
    $data = update_calculate_project_data($available);
    $not_recommended = $data['drupal']['existing_version'] !== $data['drupal']['recommended'];
    $security_update = isset($data['drupal']['security updates']);
    $no_dev_core = strpos(\Drupal::VERSION, '-dev') === FALSE;
    $form['experimental'] = [
      '#type' => 'details',
      '#title' => $this->t('Experimental'),
      '#states' => [
        'visible' => [
          ':input[name="enable_readiness_checks"]' => ['checked' => TRUE],
        ],
      ],
    ];
    if ($not_recommended && $security_update && $no_dev_core) {
      $form['experimental']['security'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('A security update is available for your version of Drupal.'),
      ];
    }

    $update_text = $this->t('Your site is running %version of Drupal core. No recommended update is available at this time.</i>', ['%version' => \Drupal::VERSION]);
    if ($not_recommended && $no_dev_core) {
      $update_text = $this->t('Even with all that caution, if you want to try it out, <a href="@link">manually update now</a>.', [
        '@link' => Url::fromRoute('automatic_updates.inplace-update', [
          'project' => 'drupal',
          'type' => 'core',
          'from' => \Drupal::VERSION,
          'to' => $data['drupal']['latest_version'],
        ])->toString(),
      ]);
    }

    $form['experimental']['update'] = [
      '#prefix' => 'Database updates are <strong>not</strong> run after an update. This module does not have a stable release and it is recommended to not use these features on a live website. Use at your own risk.',
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $update_text,
    ];

    $form['experimental']['enable_cron_updates'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic updates of Drupal core via cron.'),
      '#default_value' => $config->get('enable_cron_updates'),
      '#description' => $this->t('When a recommended update for Drupal core is available, a manual method to update is available. As an alternative to the full control of manually executing an update, enable automated updates via cron.'),
    ];
    $form['experimental']['enable_cron_security_updates'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable security only updates'),
      '#default_value' => $config->get('enable_cron_security_updates'),
      '#description' => $this->t('Enable automated updates via cron for security-only releases of Drupal core.'),
      '#states' => [
        'visible' => [
          ':input[name="enable_cron_updates"]' => ['checked' => TRUE],
        ],
      ],
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
      // Disable cron automatic updates if readiness checks are disabled.
      if (in_array($key, ['enable_cron_updates', 'enable_cron_security_updates'], TRUE) && !$form_state->getValue('enable_readiness_checks')) {
        $config->set($key, FALSE);
      }
    }
    $config->save();
  }

}
