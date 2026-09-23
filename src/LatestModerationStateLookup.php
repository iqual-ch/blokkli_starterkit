<?php

declare(strict_types=1);

namespace Drupal\blokkli_starterkit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Resolves the moderation state of the latest revision of content entities.
 *
 * Views lists default (live) revisions, so content_moderation's own views field
 * renders the state of the default revision. Editors instead see the state of
 * the latest revision on the entity form, which means a node with a published
 * default revision and a pending draft on top of it reads as "published" in a
 * view while its edit form says "draft". This service resolves the latest
 * revision state for a whole result set in a single query.
 *
 * A draft only stands for pending changes on top of a live version. Content
 * whose default revision is not published, because it has never been published
 * or has been taken offline, is reported as "unpublished" whatever its latest
 * revision says.
 *
 * Changes made in the blökkli editor are not saved as revisions until they are
 * published from the editor, but kept in a paragraphs_blokkli_edit_state
 * entity. They are pending changes on top of the live version just the same,
 * so this service also reports which entities have them.
 */
class LatestModerationStateLookup {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a new LatestModerationStateLookup.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Gets the latest revision moderation states for the given entities.
   *
   * @param string $entity_type_id
   *   The moderated entity type ID, for example "node".
   * @param array $ids
   *   The entity IDs to look up.
   *
   * @return array
   *   Moderation state IDs, keyed by entity ID and then by langcode, with
   *   "unpublished" for every translation whose default revision is not
   *   published. Entities whose bundle is not moderated have no entry at all.
   */
  public function getStates(string $entity_type_id, array $ids): array {
    if (empty($ids) || !$this->moduleHandler->moduleExists('content_moderation')) {
      return [];
    }

    $table = $this->entityTypeManager
      ->getDefinition('content_moderation_state')
      ->getRevisionDataTable();

    // The highest revision ID per entity, which is the latest revision.
    $latest = $this->database->select($table, 'l');
    $latest->addField('l', 'content_entity_id');
    $latest->addExpression('MAX([l].[content_entity_revision_id])', 'latest_revision_id');
    $latest->condition('l.content_entity_type_id', $entity_type_id);
    $latest->condition('l.content_entity_id', $ids, 'IN');
    $latest->groupBy('l.content_entity_id');

    $query = $this->database->select($table, 'cms');
    $query->innerJoin($latest, 'latest', '[latest].[content_entity_id] = [cms].[content_entity_id] AND [latest].[latest_revision_id] = [cms].[content_entity_revision_id]');
    $query->fields('cms', ['content_entity_id', 'langcode', 'moderation_state']);
    $query->condition('cms.content_entity_type_id', $entity_type_id);

    $states = [];
    foreach ($query->execute() as $record) {
      $states[(int) $record->content_entity_id][$record->langcode] = $record->moderation_state;
    }

    return $this->applyPublicationStatus($entity_type_id, $states);
  }

  /**
   * Replaces the state of unpublished translations with "unpublished".
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param array $states
   *   Moderation state IDs, keyed by entity ID and then by langcode.
   *
   * @return array
   *   The states, with "unpublished" wherever the default revision of the
   *   translation is not published.
   */
  protected function applyPublicationStatus(string $entity_type_id, array $states): array {
    if (empty($states)) {
      return $states;
    }

    $entities = $this->entityTypeManager
      ->getStorage($entity_type_id)
      ->loadMultiple(array_keys($states));

    foreach ($states as $id => $langcodes) {
      $entity = $entities[$id] ?? NULL;
      if (!$entity instanceof EntityPublishedInterface) {
        continue;
      }
      foreach (array_keys($langcodes) as $langcode) {
        // A translation that only exists in a pending revision has no live
        // version either.
        if (!$entity->hasTranslation($langcode) || !$entity->getTranslation($langcode)->isPublished()) {
          $states[$id][$langcode] = 'unpublished';
        }
      }
    }

    return $states;
  }

  /**
   * Gets the entities with unpublished changes in the blökkli editor.
   *
   * An edit state is kept per host entity, not per translation. This follows
   * the status indicator of the blökkli editor itself, which shows published
   * content as having pending changes as soon as its edit state holds any
   * mutation, so the content overview and the editor always agree.
   *
   * @param string $entity_type_id
   *   The host entity type ID, for example "node".
   * @param array $uuids
   *   The UUIDs of the host entities to look up.
   *
   * @return array
   *   The UUIDs of the entities with pending changes, keyed by UUID.
   */
  public function getPendingBlokkliChanges(string $entity_type_id, array $uuids): array {
    if (empty($uuids)) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('paragraphs_blokkli_edit_state');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('host_entity_type', $entity_type_id)
      ->condition('host_entity_uuid', array_values($uuids), 'IN')
      ->exists('mutations.plugin_id')
      ->execute();

    $pending = [];
    /** @var \Drupal\paragraphs_blokkli\ParagraphsBlokkliEditStateInterface $state */
    foreach ($storage->loadMultiple($ids) as $state) {
      $uuid = $state->getHostEntityUuid();
      $pending[$uuid] = $uuid;
    }

    return $pending;
  }

}
