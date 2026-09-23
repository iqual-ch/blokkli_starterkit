<?php

declare(strict_types=1);

namespace Drupal\blokkli_starterkit\Plugin\views\field;

use Drupal\blokkli_starterkit\LatestModerationStateLookup;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a view field with the moderation state of the latest revision.
 *
 * Unlike content_moderation's own "moderation_state" field, which reports the
 * state of the default (live) revision, this reports the state an editor sees
 * on the entity form. Entities whose bundle is not moderated fall back to
 * "published" or "unpublished", so the field is never empty. Live content
 * with unpublished changes in the blökkli editor is reported as "draft", as
 * those changes are pending on top of the live version like a draft revision.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("latest_moderation_state_field")
 */
class LatestModerationStateViewsField extends FieldPluginBase {

  /**
   * The latest moderation state lookup.
   *
   * @var \Drupal\blokkli_starterkit\LatestModerationStateLookup
   */
  protected $lookup;

  /**
   * The states of the current result set, keyed by entity ID and langcode.
   *
   * @var array
   */
  protected $states = [];

  /**
   * The UUIDs of entities with pending blökkli changes, keyed by UUID.
   *
   * @var array
   */
  protected $pendingBlokkliChanges = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->setLookup($container->get('blokkli_starterkit.latest_moderation_state'));
    return $instance;
  }

  /**
   * Sets the latest moderation state lookup.
   *
   * @param \Drupal\blokkli_starterkit\LatestModerationStateLookup $lookup
   *   The lookup service.
   */
  public function setLookup(LatestModerationStateLookup $lookup): void {
    $this->lookup = $lookup;
  }

  /**
   * {@inheritdoc}
   */
  public function usesGroupBy() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // No value is selected for this field, but the language of the row is
    // needed to pick the right translation of the moderation state.
    $this->ensureMyTable();
    $this->aliases['langcode'] = $this->query->addField($this->tableAlias, 'langcode');
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$values) {
    $ids = [];
    $uuids = [];
    foreach ($values as $row) {
      $entity = $this->getEntity($row);
      if ($entity) {
        $ids[$entity->id()] = $entity->id();
        $uuids[$entity->uuid()] = $entity->uuid();
      }
    }

    $this->states = $this->lookup->getStates($this->getEntityType(), $ids);
    $this->pendingBlokkliChanges = $this->lookup->getPendingBlokkliChanges($this->getEntityType(), $uuids);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $this->getEntity($values);
    if (!$entity) {
      return '';
    }

    $alias = $this->aliases['langcode'] ?? NULL;
    $langcode = ($alias !== NULL && isset($values->{$alias}))
      ? $values->{$alias}
    : $entity->language()->getId();

    $state = $this->states[(int) $entity->id()][$langcode] ?? NULL;

    if ($state === NULL) {
      // The bundle is not moderated, so the publication status of the default
      // revision is the only state there is.
      if ($entity->hasTranslation($langcode)) {
        $entity = $entity->getTranslation($langcode);
      }
      $published = !$entity instanceof EntityPublishedInterface || $entity->isPublished();
      $state = $published ? 'published' : 'unpublished';
    }

    // Changes in the blökkli editor are only a draft on top of a live version,
    // like a pending revision.
    if ($state === 'published' && isset($this->pendingBlokkliChanges[$entity->uuid()])) {
      $state = 'draft';
    }

    return $this->sanitizeValue($state);
  }

}
