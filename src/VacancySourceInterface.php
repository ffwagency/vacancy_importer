<?php

/**
 * @file
 * Contains \Drupal\vacancy_importer\VacancySourceInterface.
 */

namespace Drupal\vacancy_importer;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Interface VacancySourceInterface.
 *
 * @see \Drupal\vacancy_importer\Annotation\VacancySource
 * @see \Drupal\vacancy_importer\VacancySourceInterface
 * @see \Drupal\vacancy_importer\VacancySourceManager
 * @see plugin_api
 */
interface VacancySourceInterface extends ContainerFactoryPluginInterface, PluginInspectionInterface, PluginFormInterface {

  /**
   * Check that the HR Manager API works.
   *
   * @param $config
   *   Array with service url, path and query parameters.
   *
   * @return bool
   *   Returns TRUE of API works and FALSE if it fails.
   *
   */
  public function checkApi(array $config);

  /**
   * Plugin callback to get data from the source.
   *
   * @return array
   *   Array with the source data ready for import.
   */
  public function getData();

}
