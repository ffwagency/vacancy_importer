<?php

namespace Drupal\vacancy_importer\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a vacancy importer source annotation object.
 *
 * Plugin Namespace: Plugin\VacancySource
 *
 * For a working example, see
 * \Drupal\vacancy_importer\Plugin\VacancySource\Emply
 *
 * @Annotation
 *
 * @see \Drupal\vacancy_importer\Annotation\VacancySource
 * @see \Drupal\vacancy_importer\VacancySourceInterface
 * @see \Drupal\vacancy_importer\VacancySourceManager
 * @see plugin_api
 */
class VacancySource extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The human-readable name of the vacancy source.
   *
   * @ingroup plugin_translatable
   *
   * @var \Drupal\Core\Annotation\Translation
   */
  public $label;

  /**
   * A brief description of the vacancy source.
   *
   * This will be shown when adding or configuring this vacancy source.
   *
   * @ingroup plugin_translatable
   *
   * @var \Drupal\Core\Annotation\Translation (optional)
   */
  public $description = '';

}
