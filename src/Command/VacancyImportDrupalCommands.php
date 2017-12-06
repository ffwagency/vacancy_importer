<?php

/**
 * @file
 * Drupal Console command for the Vacancy Importer module.
 */

namespace Drupal\vacancy_importer\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\Command;
use Drupal\Console\Core\Command\Shared\ContainerAwareCommandTrait;
use Drupal\Console\Core\Style\DrupalStyle;

/**
 * Drupal Console command for importing vacancies from the configured source.
 *
 * @package Drupal\vacancy_importer\Command
 */
class VacancyImportDrupalCommands extends Command {

  use ContainerAwareCommandTrait;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('vacancy:importer:import')
      ->setDescription($this->trans('commands.vacancy.importer.import.description'))
      ->setAliases(['vii', 'vi:import']);
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $importer = \Drupal::service('vacancy.importer');

    // Output info about plugin
    $io = new DrupalStyle($input, $output);
    $io->info($this->trans('commands.vacancy.importer.import.messages.start'));

    // Execute importer
    $result = $importer->execute();

    // Output status
    $out[] = $result['count'];
    $out[] = $this->trans('commands.vacancy.importer.import.messages.success');
    $out[] = $result['plugin_id'];
    $io->info(implode(' ', $out));
  }

}
