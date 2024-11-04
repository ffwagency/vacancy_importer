<?php

namespace Drupal\vacancy_importer;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

define(__NAMESPACE__ . '\VOCAB_DEPARTMENT', 'vacancy_department');
define(__NAMESPACE__ . '\VOCAB_EMPLOYMENT_TYPE', 'vacancy_employment_type');
define(__NAMESPACE__ . '\VOCAB_WORK_AREA', 'vacancy_work_area');
define(__NAMESPACE__ . '\VOCAB_WORK_TIME', 'vacancy_work_time');
define(__NAMESPACE__ . '\LANG_CODE', 'und');
define(__NAMESPACE__ . '\AUTHOR_UID', 1);
define(__NAMESPACE__ . '\TEXT_FORMAT_HTML', 'vacancy_importer_html');
define(__NAMESPACE__ . '\TEXT_FORMAT_PLAIN', 'plain_text');

/**
 * Vacancy Importer.
 *
 * Fetching data from the active source plugin and handle the node
 * and term creation.
 *
 * @package Drupal\vacancy_importer
 */
class VacancyImporter {

  /**
   * The vacancy source manager.
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
   * Server request UNIX timestamp.
   *
   * @var int
   */
  protected $requestTime;

  /**
   * Constructs the default content manager.
   *
   * @param \Drupal\vacancy_importer\VacancySourceManager $vacancy_source_manager
   *   The vacancy source manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection which will be used to store / get the nid
   *   / source guid mapping.
   */
  public function __construct(
    VacancySourceManager $vacancy_source_manager,
    EntityTypeManagerInterface $entity_manager,
    EntityRepositoryInterface $entity_repository,
    ModuleHandlerInterface $module_handler,
    Connection $connection
  ) {
    $this->vacancySourceManager = $vacancy_source_manager;
    $this->entityManager = $entity_manager;
    $this->entityRepository = $entity_repository;
    $this->moduleHandler = $module_handler;
    $this->connection = $connection;
    $this->requestTime = \Drupal::time()->getRequestTime();
  }

  /**
   * Execute the import from the configured source plugin and the node
   * create/update.
   *
   * @return array
   *   Array with info about the result.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException|\Drupal\Core\Entity\EntityStorageException
   *   If the source plugin is not found.
   */
  public function execute() {
    $plugin_id = $this->vacancySourceManager->getActiveSource();
    $items = $this->vacancySourceManager->getSourceData();

    $i = 0;
    if (is_array($items)) {
      foreach ($items as $item) {
        $nid = (int) $this->getNidFromGuid($plugin_id, $item->guid);
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
   * Create or update a vacancy node.
   *
   * @param object $data
   *   An object with the node data.
   * @param int|null $nid
   *   Node ID if we are updating an existing node.
   *
   * @return int
   *   The node ID.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If the node could not be saved.
   */
  private function createVacancyNode(object $data, int $nid = NULL) {
    if ($nid) {
      $node = Node::load($nid);
    }
    else {
      // Validate the language code.
      $language_manager = \Drupal::languageManager();
      $available_languages = $language_manager->getLanguages();
      $langcode = isset($available_languages[$data->languageCode]) ? $data->languageCode : LANG_CODE;

      $node = Node::create([
        'type' => 'vacancy',
        'langcode' => $langcode,
      ]);

      // Handle creation time.
      if (!empty($data->createTime)) {
        $created_time = strtotime($data->createTime);
        if ($created_time === FALSE) {
          $created_time = $this->requestTime;
        }
      }
      else {
        $created_time = $this->requestTime;
      }
      $node->setCreatedTime($created_time);
      $node->setOwnerId(AUTHOR_UID);
      $node->setPublished(Node::PUBLISHED);
    }

    $node->setChangedTime($this->requestTime);

    $node->setTitle(!empty($data->advertisementTitle) ?
      strip_tags($data->advertisementTitle) : strip_tags($data->jobTitle));

    // Node body.
    $node->body->summary = !empty($data->summary) ? strip_tags($data->summary) : '';
    $node->body->format = TEXT_FORMAT_HTML;
    $node->body->value = $this->sanitizeContent($data->body);

    // Node facts.
    if (!empty($data->facts)) {
      $node->field_vacancy_facts->summary = '';
      $node->field_vacancy_facts->format = TEXT_FORMAT_HTML;
      $node->field_vacancy_facts->value = $this->sanitizeContent($data->facts);
    }

    // Job title.
    if ($node->hasField('field_vacancy_job_title') && !empty($data->jobTitle)) {
      $node->field_vacancy_job_title->value = strip_tags($data->jobTitle);
    }

    // Set the Work Area term if the source included this category.
    if (!empty($data->categoryWorkArea)) {
      $tid = $this->getTermId(VOCAB_WORK_AREA, $data->categoryWorkArea, $data->languageCode);
      $node->field_vacancy_work_area->target_id = $tid;
    }

    // Set the Work Time term if the source included this category.
    if (!empty($data->categoryWorkTime)) {
      $tid = $this->getTermId(VOCAB_WORK_TIME, $data->categoryWorkTime, $data->languageCode);
      $node->field_vacancy_work_time->target_id = $tid;
    }

    // Set the Department term if the source included this category.
    if (!empty($data->categoryDepartment)) {
      $tid = $this->getTermId(VOCAB_DEPARTMENT, $data->categoryDepartment, $data->languageCode);
      $node->field_vacancy_department->target_id = $tid;
    }

    // Set the Employment Type term if the source included this category.
    if (!empty($data->categoryEmploymentType)) {
      $tid = $this->getTermId(VOCAB_EMPLOYMENT_TYPE, $data->categoryEmploymentType, $data->languageCode);
      $node->field_vacancy_employment_type->target_id = $tid;
    }

    // Advertisement URL.
    if (!empty($data->advertisementUrl)) {
      $node->field_vacancy_advertisement_url->uri = $data->advertisementUrl;
      $node->field_vacancy_advertisement_url->options = [];
    }

    // Application URL.
    if (!empty($data->applicationUrl)) {
      $node->field_vacancy_application_url->uri = $data->applicationUrl;
      $node->field_vacancy_application_url->options = [];
    }

    // Due date.
    if (!empty($data->dueDate)) {
      $node->field_vacancy_application_due = $this->formatDueDate($data->dueDate);
    }

    // Due date text.
    if (!empty($data->dueDateTxt)) {
      $node->field_vacancy_application_due_t->value = strip_tags($data->dueDateTxt);
    }

    // Work place.
    if (!empty($data->workPlace)) {
      $node->field_vacancy_work_place->value = strip_tags($data->workPlace);
    }

    $node->save();

    return $node->id();
  }

  /**
   * Get the term ID for the term name, and create it if it doesn't exist.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   * @param string $term_name
   *   The name of the term.
   * @param string $langcode
   *   The language code for the term.
   *
   * @return int
   *   The term ID.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If the term could not be saved.
   */
  private function getTermId(string $vocabulary, string $term_name, string $langcode = 'und') {
    $term_name = strip_tags($term_name);

    // Get the term ID.
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('vid', $vocabulary);
    $query->condition('name', trim($term_name));
    $query->condition('langcode', $langcode);
    $tids = $query->accessCheck(TRUE)->execute();

    // Create the term if it doesn't exist.
    if (empty($tids)) {
      $term = Term::create([
        'vid' => $vocabulary,
        'langcode' => $langcode,
        'name' => trim($term_name),
      ]);
      $term->save();
      $tids = [$term->id()];
    }

    return current($tids);
  }

  /**
   * Format the due date to the format used by Drupal core.
   *
   * @param string|null $due_date
   *   The date string that we should reformat.
   *
   * @return string|null
   *   The formatted date string or NULL on failure.
   */
  private function formatDueDate(string $due_date = NULL) {
    if (!empty($due_date)) {
      // Assume the date is in 'Y-m-d H:i:s' format.
      $date = \DateTime::createFromFormat('Y-m-d H:i:s', $due_date);
      if ($date) {
        return $date->format("Y-m-d\TH:i:s");
      }
    }
    return NULL;
  }

  /**
   * Sanitize content to prevent XSS and other issues.
   *
   * @param string $content
   *   The content to sanitize.
   *
   * @return string
   *   The sanitized content.
   */
  private function sanitizeContent(string $content) {
    $content = Html::decodeEntities($content);

    return Xss::filterAdmin($content);
  }

  /**
   * Get the node ID (nid) for a given plugin and source GUID.
   *
   * @param string $plugin_id
   *   The plugin that imported the node.
   * @param string $guid
   *   The plugin's unique identifier of the vacancy.
   *
   * @return int
   *   The node ID.
   */
  private function getNidFromGuid($plugin_id, $guid) {
    $query = 'SELECT nid FROM {vacancy_importer_item} WHERE plugin_id = :plugin_id AND guid = :guid';
    return $this->connection
      ->query($query, [':plugin_id' => $plugin_id, ':guid' => $guid])
      ->fetchField();
  }

  /**
   * Save the relation between the node and the plugin and source GUID.
   *
   * @param string $plugin_id
   *   The plugin that imported the node.
   * @param string $guid
   *   The plugin's unique identifier of the vacancy.
   * @param int $nid
   *   The node ID.
   *
   * @throws \Exception
   */
  private function setNodeSourceRelation(string $plugin_id, string $guid, int $nid) {
    $this->connection->merge('vacancy_importer_item')
      ->key(['nid' => $nid])
      ->fields(['nid' => $nid])
      ->fields(['plugin_id' => $plugin_id])
      ->fields(['guid' => $guid])
      ->fields(['imported' => $this->requestTime])
      ->execute();
  }

  /**
   * Delete the record holding the relation between the node and the plugin and
   * source GUID.
   *
   * @param int $nid
   *   The node ID.
   *
   * @throws \Exception
   */
  public function deleteNodeSourceRelation(int $nid) {
    $this->connection->delete('vacancy_importer_item')
      ->where('nid = :nid', [':nid' => $nid])
      ->execute();
  }

  /**
   * Delete all unpublished vacancies where due date is older than 60 days.
   */
  public function cleanupOldVacancies() {
    $date = date('Y-m-d\tH:i:s', strtotime('-60 days'));
    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'vacancy')
      ->condition('status', 0)
      ->condition('field_vacancy_application_due', $date, '<')
      ->accessCheck(TRUE)
      ->execute();

    if ($ids) {
      $entities = Node::loadMultiple($ids);
      \Drupal::entityTypeManager()->getStorage('node')->delete($entities);
    }
  }

  /**
   * Archive all published vacancies where due date is older than specified
   * minutes.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If the node could not be saved.
   */
  public function archiveDueVacancies() {
    $config = \Drupal::config('vacancy_importer.settings')->get('archive');
    $minutes = $config['minutes'];
    $time_now = date('Y-m-d\TH:i:s', strtotime("- {$minutes} minutes"));

    // Getting Drupal time zone and convert to GMT.
    $config = \Drupal::config('system.date');
    $timezone = $config->get('timezone');
    $date = new DrupalDateTime($time_now, $timezone['default']);
    $time_gmt = $date->format("Y-m-d\TH:i:s", ['timezone' => 'GMT']);

    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'vacancy')
      ->condition('status', 1)
      ->condition('field_vacancy_application_due', $time_gmt, '<')
      ->accessCheck(TRUE)
      ->execute();

    if ($ids) {
      foreach ($ids as $id) {
        $node = Node::load($id);
        $node->setUnpublished();
        $node->save();
      }
    }
  }

}
