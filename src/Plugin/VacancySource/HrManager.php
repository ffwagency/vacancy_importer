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
 * Import vacancies from HR-Manager.net
 *
 * @VacancySource(
 *   id = "hrmanager",
 *   label = @Translation("HR Manager"),
 *   description = @Translation("Import of vacancies from HR Manager (https://www.hr-manager.dk).")
 * )
 */
class HrManager extends VacancySourceBase {

  /**
   * Constructs a Hr Manager object.
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
    $config = $this->configFactory->getEditable('vacancy_importer.settings.source.hrmanager');
    $form['api_name'] = [
      '#type' => 'textfield',
      '#title' => t('API Name'),
      '#description' => t('Write your name that is used as part of the API Url. [YOUR NAME] from
        http://api.hr-manager.net/jobportal.svc/[YOUR NAME]/positionlist/xml/?incads=1.'),
      '#default_value' => $config->get('api_name', ''),
    ];
    $form['query_parameters'] = [
      '#type' => 'textfield',
      '#title' => t('Query Parameters'),
      '#description' => t('Optional: add one or more query parameters to the API call. For example to filter vacancies by location. Use the format param1=value&amp;param2=value.'),
      '#default_value' => $config->get('query_parameters', ''),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    if ($values['vacancy_source'] == 'hrmanager') {
      $settings = $values['hrmanager'];

      if (empty($settings['api_name'])) {
        $form_state->setErrorByName('hrmanager][api_name', t('API Name is required. Please fill in the missing value!'));
      }
      else {
        // Check that the API works
        $service_config = $this->getApiConfig($settings['api_name'], $settings['query_parameters']);
        if (!$this->checkApi($service_config)) {
          $form_state->setErrorByName('', t('The HR Manager API is not accessible. Please, check the configuration again.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('vacancy_importer.settings.source.hrmanager')
      ->set('api_name', $form_state->getValue(['hrmanager', 'api_name']))
      ->set('query_parameters', $form_state->getValue(['hrmanager', 'query_parameters']))
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
        $item->guid = (int) $vacancy->Id;
        $item->languageCode = $this->getLanguageCode($vacancy);
        $item->createTime = $vacancy->LastUpdated->__toString();
        $item->advertisementTitle = trim($vacancy->Name->__toString());
        $item->jobTitle = trim($vacancy->Name->__toString());
        $item->categoryWorkArea = $vacancy->PositionCategory->Name->__toString();
        $item->categoryWorkTime = $vacancy->WorkHours->__toString();
        $item->categoryEmployementType = $vacancy->PositionType->__toString();
        $item->categoryDepartment = $vacancy->Department->Name->__toString();
        $item->body = trim($vacancy->Advertisements->JobPortalAdvertisement->Content->__toString());
        $item->workPlace = $vacancy->WorkPlace->__toString();
        $item->advertisementUrl = $vacancy->AdvertisementUrlSecure->__toString();
        $item->applicationUrl = $vacancy->ApplicationFormUrlSecure->__toString();
        $item->dueDate = $vacancy->ApplicationDue->__toString();
        $item->dueDateTxt = '';
        $items[] = $item;
      }
    }

    return $items;
  }

  /**
   * Do the request to the HR Manager API.
   *
   * @param $config
   *   Array with service url, path and query parameters.
   *
   * @return mixed
   *   SimpleXML object with the result and FALSE if it the request fails.
   *
   */
  private function doRequest($config = []) {
    $config = !empty($config) ? $config : $this->getApiConfig();

    if (!empty($config)) {
      $client = new Client(['base_uri' => $config['url']]);
      try {
        $res = $client->get($config['path'], ['debug' => FALSE, 'query' => $config['query']]);
        $xml = $res->getBody()->__toString();
        $data = new SimpleXMLElement($xml);

        if (isset($data->TransactionStatus->StatusCode) && $data->TransactionStatus->StatusCode == 'Error') {
          return FALSE;
        }
        else if (isset($data->Items->JobPortalPosition) && count($data->Items->JobPortalPosition) > 0) {
          return $data->Items->JobPortalPosition;
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
   * Check that the HR Manager API works.
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
   * Create and return the HR Manager API url, path and query parameters.
   *
   * @param string $api_name
   *   The name that is part of the API url.
   *
   * @param string $query_parameters
   *   A string with query parameters.
   *
   * @return array
   *   The API url, path and query parameters.
   */
  private function getApiConfig($api_name = '', $query_parameters = '') {
    // Load from config if $api_name is empty.
    if (empty($api_name)) {
      $config = $this->configFactory->getEditable('vacancy_importer.settings.source.hrmanager');
      $api_name = $config->get('api_name');
      $query_parameters = $config->get('query_parameters');
    }
    parse_str(trim($query_parameters), $prepared_query);
    $prepared_query = array_merge(['incads' => 1, 'take' => 999], $prepared_query);

    if (!empty($api_name)) {
      return [
        'url' => 'http://api.hr-manager.net',
        'path' => "/jobportal.svc/{$api_name}/positionlist/xml/",
        'query' => $prepared_query
      ];
    }

    return FALSE;
  }

  /**
   * Extract language code from the vacancy and return it in ISO 639-1 format.
   *
   * @param $vacancy
   */
  private function getLanguageCode($vacancy) {
    $langcode = (string) $vacancy->Languages->JobPortalLanguage->Code;
    return !empty($langcode) ? $langcode : 'und';
  }

}
