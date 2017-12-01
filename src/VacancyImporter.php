<?php

/**
 * @file
 * Contains \Drupal\vacancy_importer\VacancyImport.
 */

namespace Drupal\vacancy_importer;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\node\Entity\Node;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;

define(__NAMESPACE__ . '\VOCAB_DEPARTMENT', 'vacancy_importer_department');
define(__NAMESPACE__ . '\VOCAB_EMPLOYMENT_TYPE', 'vacancy_importer_employment_type');
define(__NAMESPACE__ . '\VOCAB_WORK_AREA', 'vacancy_importer_work_area');
define(__NAMESPACE__ . '\VOCAB_WORK_TIME', 'vacancy_importer_work_time');
define(__NAMESPACE__ . '\LANG_CODE', 'da');
define(__NAMESPACE__ . '\AUTHOR_UID', 1);
define(__NAMESPACE__ . '\TEXT_FORMAT_HTML', 'vacancy_importer_html');
define(__NAMESPACE__ . '\TEXT_FORMAT_PLAIN', 'plain_text');

/**
 * Vacancy Importer
 *
 * Fetching data from the active source plugin and handle the node and term creation.
 *
 * @package Drupal\vacancy_importer
 */
class VacancyImporter {

  /**
   * The rest resource plugin manager.
   *
   * @var \Drupal\vacancy_importer\VacancySourceManager
   */
  protected $vacancySourceManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The database connection used to check the IP against.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs the default content manager.
   *
   * @param \Drupal\vacancy_importer\VacancySourceManager $vacancySourceManager
   *   The vacancy source manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection which will be used to store / get the nid / source guid mapping.
   */
  public function __construct(VacancySourceManager $vacancy_source_manager, EntityTypeManagerInterface $entity_manager,
                              EntityRepositoryInterface $entity_repository, ModuleHandlerInterface $module_handler,
                              Connection $connection) {
    $this->vacancySourceManager = $vacancy_source_manager;
    $this->entityManager = $entity_manager;
    $this->entityRepository = $entity_repository;
    $this->moduleHandler = $module_handler;
    $this->connection = $connection;
  }

  /**
   * Execute the import from the configured source plugin and the node create / update.
   *
   * @return array
   *   Array with info about the result.
   */
  public function execute() {
    $plugin_id = $this->vacancySourceManager->getActiveSource();
    $items = $this->vacancySourceManager->getSourceData();

    $i = 0;
    if (is_array($items)) {
      foreach ($items as $item) {
        $nid = (int)$this->getNidFromGuid($plugin_id, $item->guid);
        $nid = $this->createVacancyNode($item, $nid);
        if ($nid) {
          $this->setNodeSourceRelation($plugin_id, $item->guid, $nid);
          $i++;
        }
      }
    }

    return ['count' => $i, 'plugin_id' => $plugin_id];
  }

  /**
   * Create / update a vacancy node.
   *
   * @param object $data
   *   An object with the node data.
   * @param int $nid
   *   Node id if we are updating an existing node.
   *
   * @return int
   *   The found node id.
   */
  private function createVacancyNode($data, $nid = NULL) {
    if ($nid) {
      $node = Node::load($nid);
    }
    else {
      $node = Node::create([
        'type' => 'vacancy_importer',
        'langcode' => LANG_CODE,
      ]);
      $node->setCreatedTime(!empty($data->createTime) ? strtotime($data->createTime) : REQUEST_TIME);
      $node->setOwnerId(AUTHOR_UID);
      $node->setPublished(NODE_PUBLISHED);
    }

    $node->setChangedTime(REQUEST_TIME);
    $node->setTitle(!empty($data->advertisementTitle) ?
      strip_tags($data->advertisementTitle) : strip_tags($data->jobTitle));

    // Node body
    $node->body->summary = !empty($data->summary) ? strip_tags($data->summary) : '';
    $node->body->format = TEXT_FORMAT_HTML;
    $node->body->value = Html::decodeEntities($data->body);

    // Node facts
    if (!empty($data->facts)) {
      $node->field_vacancy_facts->summary = '';
      $node->field_vacancy_facts->format = TEXT_FORMAT_HTML;
      $node->field_vacancy_facts->value = Html::decodeEntities($data->facts);
    }

    // Job title
    if (!empty($data->jobTitle)) {
      $node->field_vacancy_job_title->value = strip_tags($data->jobTitle);
    }

    // Set the Work Area term if the source included this category.
    if (!empty($data->categoryWorkArea)) {
      $tid = $this->getTermId(VOCAB_WORK_AREA, $data->categoryWorkArea);
      $node->field_vacancy_work_area->target_id = $tid;
    }

    // Set the Work Time term if the source included this category.
    if (!empty($data->categoryWorkTime)) {
      $tid = $this->getTermId(VOCAB_WORK_TIME, $data->categoryWorkTime);
      $node->field_vacancy_work_time->target_id = $tid;
    }

    // Set the Department term if the source included this category.
    if (!empty($data->categoryDepartment)) {
      $tid = $this->getTermId(VOCAB_DEPARTMENT, $data->categoryDepartment);
      $node->field_vacancy_department->target_id = $tid;
    }

    // Set the Employment Type term if the source included this category.
    if (!empty($data->categoryEmploymentType)) {
      $tid = $this->getTermId(VOCAB_EMPLOYMENT_TYPE, $data->categoryEmploymentType);
      $node->field_vacancy_employment_type->target_id = $tid;
    }

    // Advertisement Url
    if (!empty($data->advertisementUrl)) {
      $node->field_vacancy_advertisement_url->uri = $data->advertisementUrl;
      $node->field_vacancy_advertisement_url->options = array();
    }

    // Application Url
    if (!empty($data->applicationUrl)) {
      $node->field_vacancy_application_url->uri = $data->applicationUrl;
      $node->field_vacancy_application_url->options = array();
    }

    // Due date
    if (!empty($data->dueDate)) {
      $node->field_vacancy_application_due = $this->formatDueDate($data->dueDate);
    }

    // Due date text
    if (!empty($data->dueDateTxt)) {
      $node->field_vacancy_application_due_t->value = strip_tags($data->dueDateTxt);
    }

    // Work place
    if (!empty($data->workPlace)) {
      $node->field_vacancy_work_place->value = strip_tags($data->workPlace);
    }
    
    $node->save();

    return $node->id();
  }

  /**
   * Get the term id for the $term_name and if does not exist then create it.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   * @param string $term_name
   *   The name of the term we are getting the term id for.
   *
   * @return int
   *   The found term id.
   */
  private function getTermId($vocabulary, $term_name) {
    $term_name = strip_tags($term_name);

    // Get the term id.
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('vid', $vocabulary);
    $query->condition('name', trim($term_name));
    $tids = $query->execute();

    // Create the term if it does not exist.
    if (empty($tids)) {
      $term = Term::create([
        'vid' => $vocabulary,
        'langcode' => LANG_CODE,
        'name' => trim($term_name),
      ]);
      $term->save();
      $tids = array($term->id());
    }

    return current($tids);
  }

  /**
   * Format the date to the format used by D8 core and convert timezone to GMT.
   *
   * @param string $due_date
   *   The date string that we should reformat to the one required by Drupal.
   *
   * @return string
   *   The formatted date string.
   */
  private function formatDueDate($due_date = NULL) {
    if (!empty($due_date)) {
      $config = \Drupal::config('system.date');
      $timezone = $config->get('timezone');
      $date = new DrupalDateTime($due_date, $timezone['default']);
      return $date->format("Y-m-d\TH:i:s", array('timezone' => 'GMT'));
    }
    else {
      return NULL;
    }
  }

  /**
   * Get the node id (nid) for a given plugin and source guid.
   *
   * @param string $plugin_id
   *   The plugin that imported the node.
   * @param string $guid
   *   The plugins unique identifier of vacancy.
   *
   * @return int nid
   */
  private function getNidFromGuid($plugin_id, $guid) {
    $query = 'SELECT nid FROM {vacancy_importer_item} WHERE plugin_id = :plugin_id AND guid = :guid';
    return $this->connection->query($query, array(':plugin_id' => $plugin_id, ':guid' => $guid))->fetchField();
  }

  /**
   * Save the relation between the node and the plugin and source guid.
   *
   * @param string $plugin_id
   *   The plugin that imported the node.
   * @param string $guid
   *   The plugins unique identifier of vacancy.
   * @param int $nid
   *   The node id.
   *
   * @throws \Exception
   */
  private function setNodeSourceRelation($plugin_id, $guid, $nid) {
    $this->connection->merge('vacancy_importer_item')
      ->key(array('nid' => $nid))
      ->fields(array('nid' => $nid))
      ->fields(array('plugin_id' => $plugin_id))
      ->fields(array('guid' => $guid))
      ->fields(array('imported' => REQUEST_TIME))
      ->execute();
  }

  /**
   * Delete the record holding the relation between the node and the plugin and source guid.
   *
   * @param int $nid
   *   The node id.
   *
   * @throws \Exception
   */
  public function deleteNodeSourceRelation($nid) {
    $this->connection->delete('vacancy_importer_item')
      ->where('nid = :nid', array(':nid' => $nid))
      ->execute();
  }

  /**
   * Delete all unpublished vacancies where due date is older than 60 days.
   */
  public function cleanupOldVacancies() {
    $date = date('Y-m-d\tH:i:s', strtotime('-60 days'));
    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'vacancy_importer')
      ->condition('status', 0)
      ->condition('field_vacancy_application_due', $date, '<')
      ->execute();

    if ($ids) {
      $entities = Node::loadMultiple($ids);
      \Drupal::entityTypeManager()->getStorage('node')->delete($entities);
    }
  }

  /**
   * Archive all published vacancies where due date is older than XX minutes.
   */
  public function archiveDueVacancies() {
    $config = \Drupal::config('vacancy_importer.settings')->get('archive');
    $minutes = $config['minutes'];
    $time_now = date('Y-m-d\TH:i:s', strtotime("- {$minutes} minutes"));

    // Getting Drupal time zone and convert til GMT.
    $config = \Drupal::config('system.date');
    $timezone = $config->get('timezone');
    $date = new DrupalDateTime($time_now, $timezone['default']);
    $time_gmt = $date->format("Y-m-d\TH:i:s", array('timezone' => 'GMT'));

    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'vacancy_importer')
      ->condition('status', 1)
      ->condition('field_vacancy_application_due', $time_gmt, '<')
      ->execute();

    if ($ids) {
      foreach ($ids as $id) {
        $node = Node::load($id);
        $node->setPublished(FALSE);
        $node->save();
      }
    }
  }
}
