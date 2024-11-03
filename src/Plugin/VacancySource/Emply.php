<?php

namespace Drupal\vacancy_importer\Plugin\VacancySource;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\vacancy_importer\VacancySourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Import vacancies from Emply.com
 *
 * @VacancySource(
 *   id = "emply",
 *   label = @Translation("Emply"),
 *   description = @Translation("Import of vacancies from Emply (https://www.emply.com)")
 * )
 */
class Emply extends VacancySourceBase {

  /**
   * Constructs an Emply object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
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
      '#description' => t('The API domain / URL in the format: https://company.emply.com.'),
      '#default_value' => $config->get('api_domain', ''),
    ];
    $form['insert_jobid_in_facts'] = [
      '#type' => 'checkbox',
      '#title' => t('Insert JobId in facts'),
      '#description' => t('Add JobId into the facts block during import.'),
      '#default_value' => $config->get('insert_jobid_in_facts', FALSE),
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

      // Check that the API works.
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
      ->save();
  }

  /**
   * Plugin callback to get data from the source.
   *
   * @return array
   *   Array with the source data ready for import.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   Thrown if the HTTP request to the Emply API fails.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if there is an error saving the Vacancy nodes.
   * @throws \Exception
   *   Thrown if there is an error creating the Vacancy nodes.
   */
  public function getData() {
    $items = [];

    // Perform the request to fetch vacancies.
    if ($vacancies = $this->doRequest()) {
      foreach ($vacancies as $vacancy) {
        $item = new \stdClass();
        $item->guid = isset($vacancy['jobId']) ? (string) $vacancy['jobId'] : '';
        $item->languageCode = $this->determineLanguageCode($vacancy);
        $item->createTime = isset($vacancy['created']) ? $this->formatEmplyDate($vacancy['created']) : '';

        // Map 'advertisements' -> 'content' to 'advertisementTitle' and 'body'.
        if (isset($vacancy['advertisements'][0])) {
          $advertisement = $vacancy['advertisements'][0];
          $item->advertisementTitle = isset($advertisement['title']['localization'][0]['value']) ? $this->formatPlainText($advertisement['title']['localization'][0]['value']) : '';
          $item->body = isset($advertisement['content']['localization'][0]['value']) ? trim($advertisement['content']['localization'][0]['value']) : '';
        }

        // Map 'title' to 'jobTitle'.
        if (isset($vacancy['title']['localization'][0]['value'])) {
          $item->jobTitle = $this->formatPlainText($vacancy['title']['localization'][0]['value']);
        }

        // Map 'data' to categories and other fields.
        if (isset($vacancy['data']) && is_array($vacancy['data'])) {
          foreach ($vacancy['data'] as $datum) {
            // Extract the localized title
            $localized_title = isset($datum['title']['localization'][0]['value']) ? $datum['title']['localization'][0]['value'] : '';

            switch (strtolower($localized_title)) {
              case 'organisation':
                $item->categoryDepartment = $this->formatPlainText($this->getTermFromLocalization($datum['value']));
                break;

              case 'work area':
                $item->categoryWorkArea = $this->getTermFromLocalization($datum['value']);
                break;

              case 'employment conditions':
                $item->categoryEmploymentType = $this->getTermFromLocalization($datum['value']);
                break;

            }
          }
        }

        // Map 'applyUrl' and 'adUrl' to 'advertisementUrl' and 'applicationUrl'.
        $item->advertisementUrl = $vacancy['applyUrl']['localization'][0]['value'] ?? '';
        $item->applicationUrl = $vacancy['adUrl']['localization'][0]['value'] ?? '';

        // Map 'deadline' and 'deadlineText'.
        $item->dueDate = isset($vacancy['deadline']) ? $this->formatEmplyDate($vacancy['deadline']) : '';
        $item->dueDateTxt = $vacancy['deadlineText']['localization'][0]['value'] ?? '';

        // Map 'location'.
        if (isset($vacancy['location'])) {
          $item->workPlace = $this->formatPlainText($vacancy['location']['address']);
        }

        // Map 'facts' (if applicable).
        $item->facts = isset($vacancy['data']) ? $this->formatEmplyFacts($vacancy['data'], $item->guid) : '';

        $items[] = $item;
      }
    }

    return $items;
  }

  /**
   * Helper function to check if a string is valid JSON.
   *
   * @param string $string
   *   The string to check.
   *
   * @return bool
   *   TRUE if the string is valid JSON, FALSE otherwise.
   */
  private function isJson(string $string) {
    json_decode($string);
    return (json_last_error() === JSON_ERROR_NONE);
  }

  /**
   * Performs an HTTP request to the Emply API.
   *
   * @param array $config
   *   An optional configuration array with service URL, media ID, and API key.
   *
   * @return mixed
   *   An array with the result if successful, or FALSE if the request fails.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   If the request fails.
   */
  private function doRequest(array $config = []) {
    // If configuration is not provided, use the default API configuration.
    $config = !empty($config) ? $config : $this->getEmplyApiConfig();

    // Initialize Guzzle HTTP client.
    $client = new Client(['base_uri' => $config['url']]);
    try {
      // Make the GET request.
      $response = $client->get('/v1/norden/postings/' . $config['media_id'], [
        'query' => ['apiKey' => $config['api_key']],
        'headers' => $config['headers'],
      ]);

      // Retrieve the response body and decode it.
      $responseBody = $response->getBody()->getContents();

      // Check if the response is JSON formatted.
      if ($this->isJson($responseBody)) {
        return json_decode($responseBody, TRUE);
      }
      else {
        \Drupal::logger('emply')->error('API response is not valid JSON: ' . $responseBody);
        return FALSE;
      }
    }
    catch (RequestException $e) {
      \Drupal::logger('emply')->error('API request exception: ' . $e->getMessage());
      if ($e->hasResponse()) {
        $errorBody = $e->getResponse()->getBody()->getContents();
        \Drupal::logger('emply')->error('Error response from Emply API: ' . $errorBody);
      }

      return FALSE;
    }
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
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   If the request fails.
   */
  public function checkApi(array $config = []) {
    // Use the default API configuration if none is provided.
    $config = !empty($config) ? $config : $this->getEmplyApiConfig();

    // Perform a test request using the provided configuration.
    $response = $this->doRequest($config);

    // Check if the response is an array or object based on Emply API response format.
    if (is_array($response) || is_object($response)) {
      return TRUE;
    }

    \Drupal::logger('emply')->error('Emply API check failed. Response format was unexpected or inaccessible.');
    return FALSE;
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
   * @return array|bool
   *   The configuration array containing the API URL, media ID, API key, and headers,
   *   or FALSE if configuration is incomplete.
   */
  private function getEmplyApiConfig($domain = '', $media_id = '', $api_key = '') {
    $config = $this->configFactory->getEditable('vacancy_importer.settings.source.emply');
    $domain = $domain ? $domain : $config->get('api_domain');
    $media_id = $media_id ? $media_id : $config->get('media_id');
    $api_key = $api_key ? $api_key : $config->get('api_key');

    if (!empty($domain) && !empty($media_id) && !empty($api_key)) {
      return [
        'url' => rtrim($domain, '/'),
        'media_id' => $media_id,
        'api_key' => $api_key,
        'headers' => [
          'Accept' => 'application/json',
        ],
      ];
    }
    \Drupal::logger('emply')->error('Incomplete Emply API configuration.');
    return FALSE;
  }

  /**
   * Change the format of a date from Emply to the standard format.
   *
   * @param string $date
   *   ISO 8601 date string from Emply.
   *
   * @return string
   *   A formatted string with facts.
   */
  private function formatEmplyDate($date) {
    try {
      $dateTime = new \DateTime($date);
      return $dateTime->format('Y-m-d H:i:s');
    }
    catch (\Exception $e) {
      \Drupal::logger('emply')->error('Date formatting error: @message', ['@message' => $e->getMessage()]);
      return '';
    }
  }

  /**
   * Format a plain text string in the format we want in Drupal.
   * E.g. removing single and double quotes.
   *
   * @param string $text
   *   The source text string.
   *
   * @return string
   *   The formatted string ready for import.
   */
  private function formatPlainText($text) {
    $text = trim($text);
    $text = str_replace(['"', "'"], '', $text);

    return $text;
  }

  /**
   * Extracting a specific term value from the facts section.
   *
   * @param array $data
   *   Array with data entries.
   * @param string $type
   *   The term type we are extracting the value for.
   *
   * @return string
   *   The found term name.
   */
  private function getEmplyTermFromFacts(array $data, string $type) {
    $config = $this->configFactory->getEditable('vacancy_importer.settings.source.emply');

    if ($id = $config->get("fact_id__{$type}")) {
      foreach ($data as $datum) {
        if (isset($datum['id']) && $datum['id'] === $id) {
          return isset($datum['value']['localization'][0]['value']) ? $this->formatPlainText($datum['value']['localization'][0]['value']) : '';
        }
      }
    }

    return '';
  }

  /**
   * Extract language code from the vacancy and return it in ISO 639-1 format.
   *
   * @param array $vacancy
   *   The vacancy data array.
   *
   * @return string
   *   The ISO 639-1 language code or 'und' if undefined.
   */
  private function determineLanguageCode(array $vacancy) {
    // Prioritize languages based on available localization entries.
    if (isset($vacancy['title']['localization'][0]['locale'])) {
      return substr($vacancy['title']['localization'][0]['locale'], 0, 2);
    }

    // Default to undefined if no localization found.
    return 'und';
  }

  /**
   * Extracts the term value from a localization array.
   *
   * @param array $localization
   *   Array of localization entries.
   *
   * @return string
   *   The extracted term value.
   */
  private function getLocalizationValue(array $localization) {
    if (isset($localization[0]['value'])) {
      return $this->formatPlainText($localization[0]['value']);
    }
    return '';
  }

  /**
   * Formats the data array into a structured HTML string.
   *
   * @param array $data
   *   Array of data entries.
   * @param string $vacancyId
   *   The vacancy ID.
   *
   * @return string
   *   A formatted HTML string containing the facts.
   */
  private function formatEmplyFacts(array $data, string $vacancyId) {
    $formatted = [];

    // Iterate over the data entries and format them.
    foreach ($data as $datum) {
      $title = isset($datum['title']['localization'][0]['value']) ? $this->getLocalizationValue([$datum['title']['localization'][0]]) : '';
      $value = '';
      if ($datum['valueType'] == 0 && isset($datum['value']['localization'][0]['value'])) {
        $value = $this->getLocalizationValue([$datum['value']['localization'][0]]);
      }
      elseif ($datum['valueType'] == 1 && is_array($datum['value'])) {
        $values = array_map(function ($item) {
          return isset($item['title']['localization'][0]['value']) ? $this->getLocalizationValue([$item['title']['localization'][0]]) : '';
        }, $datum['value']);
        $value = implode(', ', array_filter($values));
      }

      // Add to formatted if both title and value exist.
      if ($title && $value) {
        $formatted[] = "<h3>" . Html::escape($title) . "</h3><p>" . Html::escape($value) . "</p>";
      }
    }

    // Optionally add the vacancy ID if configured.
    $config = $this->configFactory->getEditable('vacancy_importer.settings.source.emply');
    if ($config->get('insert_jobid_in_facts')) {
      $job_id_label = $this->t('Job ID');
      $formatted[] = "<h3>" . Html::escape($job_id_label) . "</h3><p>" . Html::escape($vacancyId) . "</p>";
    }

    return implode('', $formatted);
  }

  /**
   * Extracts the term value from a localization array.
   *
   * @param mixed $value
   *   The value field from the JSON, which could be a string or an array.
   *
   * @return string
   *   The extracted term value.
   */
  private function getTermFromLocalization($value) {
    if (is_array($value)) {
      // Handle multiple localized values if necessary.
      return implode(', ', array_column($value, 'value'));
    }
    return is_string($value) ? $this->formatPlainText($value) : '';
  }

}
