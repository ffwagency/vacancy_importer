<?php

namespace Drupal\vacancy_importer\Plugin\VacancySource;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\vacancy_importer\VacancySourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use SimpleXMLElement;

/**
 * Import vacancies from Emply.net
 *
 * @VacancySource(
 *   id = "emply",
 *   label = @Translation("Emply"),
 *   description = @Translation("Import of vacancies from Emply (http://www.emply.net)")
 * )
 */
class Emply extends VacancySourceBase {

  /**
   * Constructs a Emply object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('vacancy_importer.settings.source.emply');
    $form['media_id'] = [
      '#type' => 'textfield',
      '#title' => t('Media Id'),
      '#description' => t('Media Id received from Emply.'),
      '#default_value' => $config->get('media_id', ''),
    ];
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => t('API Key'),
      '#description' => t('API key received from Emply.'),
      '#default_value' => $config->get('api_key', ''),
    ];
    $form['api_domain'] = [
      '#type' => 'textfield',
      '#title' => t('API Domain'),
      '#description' => t('The API domain / URL in the format: https://company.emply.net.'),
      '#default_value' => $config->get('api_domain', ''),
    ];
    $form['insert_jobid_in_facts'] = [
      '#type' => 'checkbox',
      '#title' => t('Insert JobId in facts'),
      '#description' => t('Insert JobId into the facts block during import.'),
      '#default_value' => $config->get('insert_jobid_in_facts', ''),
    ];
    $form['fact_id__work_area'] = [
      '#type' => 'textfield',
      '#title' => t('Fact ID - Work Area'),
      '#description' => t('The Fact ID used to extract the Work Area category.'),
      '#default_value' => $config->get('fact_id__work_area', ''),
    ];
    $form['fact_id__work_time'] = [
      '#type' => 'textfield',
      '#title' => t('Fact ID - Work Time'),
      '#description' => t('The Fact ID used to extract the Work Time category.'),
      '#default_value' => $config->get('fact_id__work_time', ''),
    ];
    $form['fact_id__employment_type'] = [
      '#type' => 'textfield',
      '#title' => t('Fact ID - Employment Type'),
      '#description' => t('The Fact ID used to extract the Employment Type category.'),
      '#default_value' => $config->get('fact_id__employment_type', ''),
    ];
    $form['fact_id__work_place'] = [
      '#type' => 'textfield',
      '#title' => t('Fact ID - Work Place'),
      '#description' => t('The Fact ID used to extract the Work Place text'),
      '#default_value' => $config->get('fact_id__work_place', ''),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    if ($values['vacancy_source'] == 'emply') {
      $settings = $values['emply'];
      if (empty($settings['media_id'])) {
        $form_state->setErrorByName('emply][media_id', t('Media Id is required. Please fill in the missing value!'));
      }

      if (empty($settings['api_key'])) {
        $form_state->setErrorByName('emply][api_key', t('API Key is required. Please fill in the missing value!'));
      }

      if (empty($settings['api_domain'])) {
        $form_state->setErrorByName('emply][api_domain', t('API Domain is required. Please fill in the missing value!'));
      }

      $url_parts = parse_url($settings['api_domain']);

      if ($url_parts === FALSE) {
        $form_state->setErrorByName('emply][api_domain', t('The "API Domain" is not a valid domain.'));
      }

      if (empty($url_parts['scheme']) || $url_parts['scheme'] != 'https') {
        $form_state->setErrorByName('emply][api_domain', t('The API Domain should include the protocol https!'));
      }

      if (!empty($url_parts['path'])) {
        $form_state->setErrorByName('emply][api_domain', t('The API Domain should not include a path or leading slash!'));
      }

      // Check that the API works
      $service_config = $this->getEmplyApiConfig($settings['api_domain'], $settings['media_id'], $settings['api_key']);
      if (!is_array($service_config) || !$this->checkApi($service_config)) {
        $form_state->setErrorByName('', t('The Emply API is not accessible. Please, check the configuration again.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('vacancy_importer.settings.source.emply')
      ->set('media_id', $form_state->getValue(['emply', 'media_id']))
      ->set('api_key', $form_state->getValue(['emply', 'api_key']))
      ->set('api_domain', $form_state->getValue(['emply', 'api_domain']))
      ->set('insert_jobid_in_facts', $form_state->getValue(['emply', 'insert_jobid_in_facts']))
      ->set('fact_id__work_area', $form_state->getValue(['emply', 'fact_id__work_area']))
      ->set('fact_id__work_time', $form_state->getValue(['emply', 'fact_id__work_time']))
      ->set('fact_id__employment_type', $form_state->getValue(['emply', 'fact_id__employment_type']))
      ->set('fact_id__work_place', $form_state->getValue(['emply', 'fact_id__work_place']))
      ->save();
  }

  /**
   * Plugin callback to get data from the source.
   *
   * @return array
   *   Array with the source data ready for import.
   */
  public function getData() {
    $items = [];

    if ($vacancies = $this->doRequest()) {
      foreach ($vacancies as $vacancy) {
        $item = new \stdClass();
        $item->guid = $vacancy->adid->__toString();
        $item->languageCode = $this->getLanguageCode($vacancy);
        $item->createTime = $this->formatEmplyDate($vacancy->published->__toString());
        $item->advertisementTitle = $this->formatPlainText($vacancy->adheader->__toString());
        $item->jobTitle = $this->formatPlainText($vacancy->jobtitle->__toString());
        $item->categoryWorkArea = $this->getEmplyTermFromFacts($vacancy->facts, 'work_area');
        $item->categoryWorkTime = $this->getEmplyTermFromFacts($vacancy->facts, 'work_time');
        $item->categoryEmployementType = $this->getEmplyTermFromFacts($vacancy->facts, 'employement_type');
        $item->categoryDepartment = $this->formatPlainText($vacancy->department->__toString());
        $item->body = trim($vacancy->ad->__toString());
        $item->summary = trim($vacancy->adshorttext->__toString());
        $item->facts = $this->formatEmplyFacts($vacancy->facts, $vacancy->publishingid);
        $item->advertisementUrl = $vacancy['apply_link']->__toString();
        $item->applicationUrl = $vacancy['direct_apply_link']->__toString();
        $item->dueDate = $this->formatEmplyDate($vacancy->deadline->__toString());
        $item->dueDateTxt = $vacancy->deadlinetext->__toString();
        $item->workPlace = $this->getEmplyTermFromFacts($vacancy->facts, 'work_place');
        $items[] = $item;
      }
    }

    return $items;
  }

  /**
   * Do the request to Emply API.
   *
   * @param $config
   *   Array with service url, path and query parameters.
   *
   * @return mixed
   *   SimpleXML object with the result and FALSE if it the request fails.
   *
   */
  private function doRequest($config = []) {
    $config = !empty($config) ? $config : $this->getEmplyApiConfig();

    if (!empty($config)) {
      $client = new Client(['base_uri' => $config['url']]);
      try {
        $res = $client->get($config['path'], ['debug' => FALSE, 'query' => $config['query']]);
        $xml = preg_replace('/&#x([a-f0-9]+);/mi', '', $res->getBody()->__toString());
        $data = new SimpleXMLElement($xml);

        if (isset($data->vacancy)) {
          return $data->vacancy;
        }
        else {
          return FALSE;
        }

      }
      catch (RequestException $e) {
        return $this->t('Error');
      }
    }

    return FALSE;
  }

  /**
   * Check that the Emply API works.
   *
   * @param array $config
   *   Array with service url, path and query parameters.
   *
   * @return bool
   *   Returns TRUE of API works and FALSE if it fails.
   *
   */
  public function checkApi(array $config) {
    $check = $this->doRequest($config);
    return is_object($check) ? TRUE : FALSE;
  }

  /**
   * Create and return the Emply API url, path and query parameters.
   *
   * @param string $domain
   *   The Emply API service domain.
   * @param string $media_id
   *   The Emply media id used in API request.
   * @param string $api_key
   *   he Emply API key used in API request.
   *
   * @return array
   *   The Emply url, path and query parameters.
   */
  private function getEmplyApiConfig($domain = '', $media_id = '', $api_key = '') {
    $config = $this->configFactory->getEditable('vacancy_importer.settings.source.emply');
    $domain = $domain ? $domain : $config->get('api_domain');
    $media_id = $media_id ? $media_id : $config->get('media_id');
    $api_key = $api_key ? $api_key : $config->get('api_key');

    if (!empty($domain) && !empty($media_id) && !empty($api_key)) {
      return [
        'url' => $domain,
        'path' => '/api.asmx/GetVacancies',
        'query' => [
          'apiKey' => $api_key,
          'mediaid' => $media_id
        ]
      ];
    }

    return FALSE;
  }

  /**
   * Change the format of a date from Emply to the standard format.
   *
   * @param string $date
   *   Date string from Emply.
   *
   * @return string $date
   *   The standard formatted date.
   */
  private function formatEmplyDate($date) {
    preg_match('/(\d{2})-(\d{2})-(\d{4}), (\d{2}):(\d{2})/', $date, $matches);
    return "{$matches[3]}-{$matches[2]}-{$matches[1]} {$matches[4]}:{$matches[5]}:00";
  }

  /**
   * Format the facts array to a string.
   *
   * @param array $facts
   *   Array with facts.
   *
   * @param integer $job_id
   *   The job id from Emply.
   *
   * @return string
   *   A formatted string with facts.
   */
  private function formatEmplyFacts($facts, $job_id) {
    $config = $this->configFactory->getEditable('vacancy_importer.settings.source.emply');
    $formatted = [];

    foreach ($facts->fact as $fact) {
      $visibility = ($fact['visible']->__toString() == 'True') ? TRUE : FALSE;

      if ($visibility) {
        $formatted[] = "<h3>{$fact['title']->__toString()}</h3><p>{$fact->__toString()}</p>";
      }
    }

    // Add job_id to the facts box
    if ($config->get('insert_jobid_in_facts')) {
      $job_id_label = $this->t('Job ID');
      $formatted[] = "<h3>{$job_id_label}</h3><p>{$job_id}</p>";
    }

    return implode('', $formatted);
  }

  /**
   * Format a plain text string in the format we want in Drupal.
   * E.g. removing single and double quotes.
   *
   * @param string $text
   *   The source text string
   * @return string
   *   The formatted string ready for import.
   */
  private function formatPlainText($text) {
    $text = trim($text);
    $text = str_replace('"', '', $text);
    $text = str_replace("'", "" , $text);
    return $text;
  }

  /**
   * Extracting a specific term value from the facts section.
   *
   * @param array $facts
   *   Array with facts.
   * @param string $type
   *   The term type we are extracting the value for.
   *
   * @return string
   *   The found term name.
   */
  private function getEmplyTermFromFacts($facts, $type) {
    $config = $this->configFactory->getEditable('vacancy_importer.settings.source.emply');

    if ($id = $config->get("fact_id__{$type}")) {
      foreach ($facts->fact as $fact) {
        if ($fact['id']->__toString() == $id) {
          return $this->formatPlainText($fact->__toString());
        }
      }
    }

    return '';
  }

  /**
   * Extract language code from the vacancy and return it in ISO 639-1 format.
   *
   * @param $vacancy
   */
  private function getLanguageCode($vacancy) {
    $attributes = $vacancy->ad->attributes();
    if (isset($attributes['language'])) {
      return substr($attributes['language'], 0, 2);
    }
    else {
      return 'und';
    }
  }

}
