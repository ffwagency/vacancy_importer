<?php

/**
 * @file
 * Drush integration for the Vacancy Importer module.
 */

use Psr\Log\Loglevel;

/**
 * Implements hook_drush_command().
 */
function vacancy_importer_drush_command() {
  $items['vacancy-importer-import'] = [
    'description' => dt('Import vacancies from the configured source.'),
    'aliases' => ['vi:import', 'vii', 'vacancy-importer:import'],
  ];
  return $items;
}

/**
 * Command callback to import vacancies from the configured source.
 */
function drush_vacancy_importer_import() {
  $importer = \Drupal::service('vacancy.importer');

  // Output info about plugin
  \Drupal::logger(dt('Starting vacancy import.......'), LogLevel::INFO);

  // Execute importer
  $result = $importer->execute();

  // Output status
  \Drupal::logger(dt('@count vacancies imported/updated from "@plugin".', ['@count' => $result['count'], '@plugin' => $result['plugin_id']]), LogLevel::SUCCESS);
}
