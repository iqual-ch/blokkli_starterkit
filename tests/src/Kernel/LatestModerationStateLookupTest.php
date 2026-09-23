<?php

declare(strict_types=1);

namespace Drupal\Tests\blokkli_starterkit\Kernel;

use Drupal\blokkli_starterkit\LatestModerationStateLookup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs_blokkli\Entity\ParagraphsBlokkliEditState;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the latest revision moderation state lookup.
 */
#[Group('blokkli_starterkit')]
class LatestModerationStateLookupTest extends KernelTestBase {

  use ContentModerationTestTrait;
  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_moderation',
    'content_translation',
    'entity_reference_revisions',
    'field',
    'file',
    'filter',
    'language',
    'node',
    'paragraphs',
    'paragraphs_blokkli',
    'system',
    'text',
    'user',
    'workflows',
  ];

  /**
   * The lookup under test.
   *
   * @var \Drupal\blokkli_starterkit\LatestModerationStateLookup
   */
  protected $lookup;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'field', 'filter', 'node']);
    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('paragraphs_blokkli_edit_state');

    ConfigurableLanguage::createFromLangcode('de')->save();

    $this->createContentType(['type' => 'moderated']);
    $this->createContentType(['type' => 'plain']);

    $workflow = $this->createEditorialWorkflow();
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'moderated');
    $workflow->save();

    $this->lookup = new LatestModerationStateLookup(
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('module_handler')
    );
  }

  /**
   * Tests that a pending draft wins over the published default revision.
   */
  public function testPendingDraftOverridesDefaultRevision(): void {
    $node = Node::create([
      'type' => 'moderated',
      'title' => 'Pending draft',
      'moderation_state' => 'published',
    ]);
    $node->save();

    // The live revision is published, so the view would show "published".
    $this->assertTrue($node->isPublished());
    $this->assertSame(
      ['en' => 'published'],
      $this->lookup->getStates('node', [$node->id()])[$node->id()]
    );

    // A pending draft on top of it is what the entity form shows.
    $node->set('moderation_state', 'draft');
    $node->save();

    $this->assertSame(
      ['en' => 'draft'],
      $this->lookup->getStates('node', [$node->id()])[$node->id()]
    );
  }

  /**
   * Tests that content without a live version is unpublished, not a draft.
   */
  public function testDraftWithoutPublishedVersionIsUnpublished(): void {
    $node = Node::create([
      'type' => 'moderated',
      'title' => 'Never published',
      'moderation_state' => 'draft',
    ]);
    $node->save();

    // There is nothing a draft could be pending on top of.
    $this->assertFalse($node->isPublished());
    $this->assertSame(
      ['en' => 'unpublished'],
      $this->lookup->getStates('node', [$node->id()])[$node->id()]
    );

    // A second draft does not change that.
    $node->set('moderation_state', 'draft');
    $node->save();

    $this->assertSame(
      ['en' => 'unpublished'],
      $this->lookup->getStates('node', [$node->id()])[$node->id()]
    );

    // Once published, a further draft is pending changes on a live version.
    $node->set('moderation_state', 'published');
    $node->save();
    $node->set('moderation_state', 'draft');
    $node->save();

    $this->assertSame(
      ['en' => 'draft'],
      $this->lookup->getStates('node', [$node->id()])[$node->id()]
    );
  }

  /**
   * Tests that content taken offline again is unpublished.
   */
  public function testArchivedContentIsUnpublished(): void {
    $node = Node::create([
      'type' => 'moderated',
      'title' => 'Archived',
      'moderation_state' => 'published',
    ]);
    $node->save();

    $node->set('moderation_state', 'archived');
    $node->save();

    $this->assertSame(
      ['en' => 'unpublished'],
      $this->lookup->getStates('node', [$node->id()])[$node->id()]
    );
  }

  /**
   * Tests that the state is resolved per translation.
   */
  public function testStatesAreResolvedPerTranslation(): void {
    $node = Node::create([
      'type' => 'moderated',
      'title' => 'Translated',
      'moderation_state' => 'published',
    ]);
    $node->save();

    $translation = $node->addTranslation('de', ['title' => 'Übersetzt']);
    $translation->set('moderation_state', 'published');
    $translation->save();

    // Only the German translation gets a pending draft.
    $node = Node::load($node->id())->getTranslation('de');
    $node->set('moderation_state', 'draft');
    $node->save();

    $states = $this->lookup->getStates('node', [$node->id()]);
    $this->assertSame('draft', $states[$node->id()]['de']);
    $this->assertSame('published', $states[$node->id()]['en']);
  }

  /**
   * Tests that a translation without a live version is unpublished.
   */
  public function testUnpublishedTranslationIsResolvedOnItsOwn(): void {
    $node = Node::create([
      'type' => 'moderated',
      'title' => 'Published source',
      'moderation_state' => 'published',
    ]);
    $node->save();

    // The German translation only exists as a draft so far.
    $translation = $node->addTranslation('de', ['title' => 'Entwurf']);
    $translation->set('moderation_state', 'draft');
    $translation->save();

    $states = $this->lookup->getStates('node', [$node->id()]);
    $this->assertSame('unpublished', $states[$node->id()]['de']);
    $this->assertSame('published', $states[$node->id()]['en']);
  }

  /**
   * Tests that content without a workflow is not reported.
   */
  public function testUnmoderatedContentHasNoState(): void {
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Not moderated',
      'status' => 0,
    ]);
    $node->save();

    $this->assertSame([], $this->lookup->getStates('node', [$node->id()]));
  }

  /**
   * Tests that no query is run for an empty result set.
   */
  public function testEmptyIdsReturnNoStates(): void {
    $this->assertSame([], $this->lookup->getStates('node', []));
  }

  /**
   * Tests that changes in the blökkli editor are pending.
   */
  public function testBlokkliMutationsArePending(): void {
    $node = $this->createPublishedNode();
    $other = $this->createPublishedNode();

    $this->assertSame([], $this->lookup->getPendingBlokkliChanges('node', [$node->uuid(), $other->uuid()]));

    $this->createEditState($node, 0, [TRUE]);

    $this->assertSame(
      [$node->uuid() => $node->uuid()],
      $this->lookup->getPendingBlokkliChanges('node', [$node->uuid(), $other->uuid()])
    );
  }

  /**
   * Tests that only an edit state without any mutations is not pending.
   */
  public function testBlokkliEditStateWithoutMutationsIsNotPending(): void {
    // Opening the editor creates an edit state without any mutations.
    $opened = $this->createPublishedNode();
    $this->createEditState($opened, -1, []);

    // The blökkli editor still reports undone and disabled mutations as
    // pending changes, so the content overview does too.
    $undone = $this->createPublishedNode();
    $this->createEditState($undone, -1, [TRUE, TRUE]);
    $disabled = $this->createPublishedNode();
    $this->createEditState($disabled, 0, [FALSE]);

    $this->assertSame(
      [$undone->uuid() => $undone->uuid(), $disabled->uuid() => $disabled->uuid()],
      $this->lookup->getPendingBlokkliChanges('node', [
        $opened->uuid(),
        $undone->uuid(),
        $disabled->uuid(),
      ])
    );
  }

  /**
   * Tests that edit states of other entity types are ignored.
   */
  public function testBlokkliEditStateOfOtherEntityTypeIsIgnored(): void {
    $node = $this->createPublishedNode();
    $this->createEditState($node, 0, [TRUE]);

    $this->assertSame([], $this->lookup->getPendingBlokkliChanges('taxonomy_term', [$node->uuid()]));
    $this->assertSame([], $this->lookup->getPendingBlokkliChanges('node', []));
  }

  /**
   * Creates a published moderated node.
   *
   * @return \Drupal\node\NodeInterface
   *   The node.
   */
  protected function createPublishedNode(): NodeInterface {
    $node = Node::create([
      'type' => 'moderated',
      'title' => 'Live',
      'moderation_state' => 'published',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates a blökkli edit state for the given node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The host node.
   * @param int $current_index
   *   The current position in the mutation history.
   * @param bool[] $enabled
   *   Whether each mutation in the history is enabled.
   */
  protected function createEditState(NodeInterface $node, int $current_index, array $enabled): void {
    $mutations = array_map(fn (bool $status) => [
      'plugin_id' => 'add',
      'timestamp' => 0,
      'enabled' => (int) $status,
      'configuration' => [],
    ], $enabled);

    ParagraphsBlokkliEditState::create([
      'host_entity_type' => $node->getEntityTypeId(),
      'host_entity_uuid' => $node->uuid(),
      'current_index' => $current_index,
      'mutations' => $mutations,
    ])->save();
  }

}
