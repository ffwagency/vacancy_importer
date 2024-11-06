<?php

namespace Drupal\vacancy_importer\Plugin\VacancySource;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\vacancy_importer\VacancySourceBase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Import vacancies from Emply.com.
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
      ->set(
        'insert_jobid_in_facts',
        $form_state->getValue(['emply', 'insert_jobid_in_facts'])
      )
      ->set(
        'fact_id__work_area',
        $form_state->getValue(['emply', 'fact_id__work_area'])
      )
      ->set(
        'fact_id__work_time',
        $form_state->getValue(['emply', 'fact_id__work_time'])
      )
      ->set(
        'fact_id__employment_type',
        $form_state->getValue(['emply', 'fact_id__employment_type'])
      )
      ->set(
        'fact_id__work_place',
        $form_state->getValue(['emply', 'fact_id__work_place'])
      )
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
        $item->languageCode = $this->getLanguageCode($vacancy);
        $item->createTime = isset($vacancy['created']) ? $this->formatEmplyDate($vacancy['created']) : '';

        // Map 'advertisements' -> 'content' to 'advertisementTitle' and 'body'.
        if (isset($vacancy['advertisements'][0])) {
          $advertisement = $vacancy['advertisements'][0];
          $item->advertisementTitle = isset($advertisement['title']['localization'][0]['value'])
            ? $this->formatPlainText($advertisement['title']['localization'][0]['value'])
            : '';
          $item->body = isset($advertisement['content']['localization'][0]['value'])
            ? trim($advertisement['content']['localization'][0]['value'])
            : '';
        }

        // Map 'title' to 'jobTitle'.
        if (isset($vacancy['title']['localization'][0]['value'])) {
          $item->jobTitle = $this->formatPlainText($vacancy['title']['localization'][0]['value']);
        }

        // Map 'department' to 'categoryDepartment'.
        if (isset($vacancy['department']['title']['localization'])) {
          $item->categoryDepartment = $this->formatPlainText($vacancy['department']['title']['localization'][0]['value']);
        }

        // Map 'data' to categories based on machine names.
        if (isset($vacancy['data'])) {
          $item->categoryWorkArea = $this->getEmplyTermFromFacts($vacancy['data'], 'work_area', $item->languageCode);
          $item->categoryWorkTime = $this->getEmplyTermFromFacts($vacancy['data'], 'work_time', $item->languageCode);
          $item->categoryEmploymentType = $this->getEmplyTermFromFacts($vacancy['data'], 'employment_type', $item->languageCode);
          $item->workPlace = $this->getEmplyTermFromFacts($vacancy['data'], 'work_place', $item->languageCode);
        }

        // Map 'applyUrl' and 'adUrl' to
        // 'advertisementUrl' and 'applicationUrl'.
        $item->advertisementUrl = $vacancy['applyUrl']['localization'][0]['value'] ?? '';
        $item->applicationUrl = $vacancy['adUrl']['localization'][0]['value'] ?? '';

        // Map 'deadline' and 'deadlineText'.
        $item->dueDate = isset($vacancy['deadline']) ? $this->formatEmplyDate($vacancy['deadline']) : '';
        $item->dueDateTxt = $vacancy['deadlineText']['localization'][0]['value'] ?? '';

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

    if (empty($config['url']) || empty($config['media_id']) || empty($config['api_key'])) {
      \Drupal::logger('emply')->error('Missing configuration for API request.');

      return FALSE;
    }

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

    // Check if the response is an array or object based on
    // Emply API response format.
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
   *   The Emply API key used in API request.
   *
   * @return array|bool
   *   The configuration array containing the API URL, media ID, API key,
   *   and headers, or FALSE if configuration is incomplete.
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
   * @param string $languageCode
   *   The language code for the term.
   *
   * @return string
   *   The found term name.
   */
  private function getEmplyTermFromFacts(array $data, string $type, string $languageCode) {
    $config = $this->configFactory->get('vacancy_importer.settings.source.emply');
    $jobDetailsId = $config->get("fact_id__{$type}");
    $jobDetailsId = trim($jobDetailsId);

    foreach ($data as $datum) {
      if (isset($datum['jobDetailsId'])) {
        $datumJobDetailsId = trim($datum['jobDetailsId']);

        if (strcasecmp($datumJobDetailsId, $jobDetailsId) === 0) {
          // Extract value based on data structure.
          if (isset($datum['value']['localization'])) {
            // Case where value has localization.
            $value = $this->getLocalizationValueByLocale($datum['value']['localization'], $languageCode);
            return $this->formatPlainText($value);
          }
          elseif (is_array($datum['value']) && isset($datum['value'][0]['title']['localization'])) {
            // Case where value is an array of items with title localization.
            $values = array_map(function ($item) use ($languageCode) {
              return $this->getLocalizationValueByLocale($item['title']['localization'], $languageCode);
            }, $datum['value']);
            return implode(', ', array_filter($values));
          }
        }
      }
    }

    return '';
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
   * Retrieves the localized value for a given locale from localizations.
   *
   * @param array $localizations
   *   An array of localization entries.
   * @param string $locale
   *   The locale code to search for (e.g., 'en-GB').
   *
   * @return string
   *   The localized value if found; otherwise, an empty string.
   */
  private function getLocalizationValueByLocale(array $localizations, string $locale) {
    // First, attempt to find the value for the specified locale.
    foreach ($localizations as $localization) {
      if (isset($localization['locale']) && $localization['locale'] === $locale) {
        return $this->formatPlainText($localization['value']);
      }
    }

    // If not found, attempt to find a value for a fallback locale.
    foreach ($localizations as $localization) {
      if (isset($localization['locale']) && $localization['locale'] === 'en-GB') {
        return $this->formatPlainText($localization['value']);
      }
    }

    // If still not found, return the first available localization value.
    if (!empty($localizations[0]['value'])) {
      return $this->formatPlainText($localizations[0]['value']);
    }

    // Return an empty string if no value is found.
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
  private function getLanguageCode(array $vacancy) {
    if (isset($vacancy['ad']['attributes'])) {
      $attributes = $vacancy['ad']['attributes'];
      if (isset($attributes['language'])) {
        return substr($attributes['language'], 0, 2);
      }
    }
    return 'und';
  }

}
