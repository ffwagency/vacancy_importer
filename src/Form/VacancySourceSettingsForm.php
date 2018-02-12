<?php

namespace Drupal\vacancy_importer\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\vacancy_importer\VacancySourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures vacancy sources settings for this site.
 */
class VacancySourceSettingsForm extends ConfigFormBase {

  /**
   * An array containing currently available sources.
   *
   * @var \Drupal\vacancy_importer\VacancySourceInterface[]
   */
  protected $availableSources = [];

  /**
   * Constructs a VacancySourceForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\vacancy_importer\VacancySourceManager $manager
   *   The vacancy source plugin manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, VacancySourceManager $manager) {
    parent::__construct($config_factory);

    foreach ($manager->getAvailableSources() as $id => $definition) {
      $this->availableSources[$id] = $manager->createInstance($id);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('plugin.manager.vacancy.source')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'vacancy_importer_source_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['vacancy_importer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['vacancy_source'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select a vacancy source'),
      '#default_value' => $this->config('vacancy_importer.settings')->get('source'),
      '#options' => [],
    ];

    // If we have more than one vacancy source, allow the user to select the one
    // to use, and load each of the vacancy source' settings form.
    foreach ($this->availableSources as $id => $source) {
      $definition = $source->getPluginDefinition();
      $form['vacancy_source']['#options'][$id] = $definition['label'];
      $form['vacancy_source_settings'][$id] = [
        '#type' => 'details',
        '#title' => $this->t('@source settings', ['@source' => $definition['label']]),
        '#open' => TRUE,
        '#tree' => TRUE,
        '#states' => [
          'visible' => [
            ':radio[name="vacancy_source"]' => ['value' => $id],
          ],
        ],
      ];
      $form['vacancy_source_settings'][$id] += $source->buildConfigurationForm([], $form_state);
    }

    // Import cron settings
    $cron = $this->config('vacancy_importer.settings')->get('cron');
    $form['cron'] = [
      '#title' => t('Import cron settings'),
      '#type' => 'details',
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['cron']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => t('Enable import by cron'),
      '#description' => t('Check to enable the import cron job.'),
      '#default_value' => $cron['enabled'] ? $cron['enabled'] : FALSE,
    ];
    $options = [900, 1800, 3600, 10800, 21600, 43200, 86400, 604800];
    $form['cron']['interval'] = [
      '#type' => 'select',
      '#title' => t('Import vacancies every'),
      '#description' => t('How often should Drupal check for updated vacancies?'),
      '#default_value' => $cron['interval'] ? $cron['interval'] : 1800,
      '#options' => array_map([\Drupal::service('date.formatter'), 'formatInterval'], array_combine($options, $options)),
      '#states' => [
        'visible' => [
          ':input[name="cron[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Archive cron settings
    $archive = $this->config('vacancy_importer.settings')->get('archive');
    $form['archive'] = [
      '#title' => t('Archive cron settings'),
      '#type' => 'details',
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['archive']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => t('Enable automatic archiving'),
      '#description' => t('Check to enable automatic archiving of vacancies with past due date.'),
      '#default_value' => $archive['enabled'] ? $archive['enabled'] : FALSE,
    ];
    $form['archive']['minutes'] = [
      '#type' => 'textfield',
      '#title' => t('Expire minutes'),
      '#description' => t('How many minutes after due date should the vacancy be archived?'),
      '#default_value' => $archive['minutes'] ? $archive['minutes'] : 15,
      '#states' => [
        'visible' => [
          ':input[name="archive[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $options = [900, 1800, 3600, 10800, 21600, 43200, 86400, 604800];
    $form['archive']['interval'] = [
      '#type' => 'select',
      '#title' => t('Archive vacancies every'),
      '#description' => t('How often should Drupal archive vacancies with expired due date?'),
      '#default_value' => $archive['interval'] ? $archive['interval'] : 900,
      '#options' => array_map([\Drupal::service('date.formatter'), 'formatInterval'], array_combine($options, $options)),
      '#states' => [
        'visible' => [
          ':input[name="archive[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $values = $form_state->getValues();

    // Call the form validation handler for each of the sources.
    foreach ($this->availableSources as $source) {
      $source->validateConfigurationForm($form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->config('vacancy_importer.settings')
      ->set('source', $values['vacancy_source'])
      ->save();

    // Call the form submit handler for each of the sources.
    foreach ($this->availableSources as $source) {
      $source->submitConfigurationForm($form, $form_state);
    }

    $this->config('vacancy_importer.settings')
      ->set('cron.enabled', $values['cron']['enabled'])
      ->save();

    $this->config('vacancy_importer.settings')
      ->set('cron.interval', $values['cron']['enabled'])
      ->save();

    $this->config('vacancy_importer.settings')
      ->set('archive.enabled', $values['archive']['enabled'])
      ->save();

    $this->config('vacancy_importer.settings')
      ->set('archive.interval', $values['archive']['interval'])
      ->save();

    $this->config('vacancy_importer.settings')
      ->set('archive.minutes', $values['archive']['minutes'])
      ->save();

    parent::submitForm($form, $form_state);
  }

}
