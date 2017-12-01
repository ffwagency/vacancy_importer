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


}
