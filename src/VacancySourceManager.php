<?php

/**
 * @file
 * Contains \Drupal\vacancy_importer\VacancySourceManager.
 */

namespace Drupal\vacancy_importer;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Config;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Utility\SafeMarkup;

/**
 * Manages Vacancy Importer source plugins.
 *
 * A vacancy source plugin is a third part service where vacancies are imported from.
 */
class VacancySourceManager extends DefaultPluginManager {

  /**
   * Creates the discovery object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   *
   * @see \Drupal\vacancy_importer\Annotation\VacancySource
   * @see \Drupal\vacancy_importer\VacancySourceInterface
   * @see \Drupal\vacancy_importer\VacancySourceManager
   * @see plugin_api
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    $subdir = 'Plugin/VacancySource';
    $plugin_interface = 'Drupal\vacancy_importer\VacancySourceInterface';
    $plugin_definition_annotation_name = 'Drupal\vacancy_importer\Annotation\VacancySource';
    parent::__construct($subdir, $namespaces, $module_handler, $plugin_interface, $plugin_definition_annotation_name);

    $this->alterInfo('vacancy_source_info');
    $this->setCacheBackend($cache_backend, 'vacancy_source_info');
  }

  /**
   * Gets a list of available sources.
   *
   * @return array
   *   An array with the sources names as keys and the descriptions as values.
   */
  public function getAvailableSources() {
    // Use plugin system to get list of available vacancy sources.
    $sources = $this->getDefinitions();

    $output = array();
    foreach ($sources as $id => $definition) {
      $output[$id] = $definition;
    }

    return $output;
  }

  /**
   * Gets the source plugin that is configured in the settings and validate that it exists.
   *
   * @return string
   *   The id of the active source plugin.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getActiveSource() {
    $config = \Drupal::config('vacancy_importer.settings');
    $source = $config->get('source');

    try {
      $test = $this->getDefinition($source);
    } catch (PluginNotFoundException $e) {
      $message = SafeMarkup::format("The configured vacancy source plugin '@source' was not found.", ['@source' => $source]);
      throw new PluginNotFoundException($source, $message);
    }

    return $source;
  }

  /**
   * Get the vacancy data from the configured source plugin.
   *
   * @return array
   *   Array with data from the source plugin.
   *
   * @throws PluginNotFoundException
   */
  public function getSourceData() {
    $plugin_id = $this->getActiveSource();
    $instance = $this->createInstance($plugin_id, []);
    return $instance->getData();
  }

}
