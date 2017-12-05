<?php

/**
 * @file
 * Drush command for the Vacancy Importer module.
 */

namespace Drupal\vacancy_importer\Command;

use Drupal\vacancy_importer\VacancyImporter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Drush\Commands\DrushCommands;
use Drush\Log\LogLevel;

/**
 * Drush Command for importing vacancies from the configured source.
 *
 * @package Drupal\vacancy_importer\Command
 */
class VacancyImportDrushCommands extends DrushCommands {

  /**
   * @var \Drupal\vacancy_importer\VacancyImporter
   */
  protected $importer;

  /**
   * Constructs the Drush command.
   *
   * @param \Drupal\vacancy_importer\VacancyImporter $importer
   */
  public function __construct(VacancyImporter $importer) {
    $this->importer = $importer;
  }

  /**
   * Execute vacancy import from configured source plugin.
   *
   * @command vacancy-importer:import
   *
   * @aliases vi:import, vii, vi-i
   * @validate-module-enabled vacancy_importer
   */
  public function import() {
    $this->logger()->info(dt('Starting vacancy import....'));

    // Execute importer
    $result = $this->importer->execute();

    // Output status
    $this->logger()->success(dt('@count vacancies imported/updated from "@plugin_id".',
      ['@count' => $result['count'], '@plugin_id' => $result['plugin_id']]));
  }

}
