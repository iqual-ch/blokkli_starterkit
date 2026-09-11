<?php

declare(strict_types=1);

namespace Drupal\Tests\blokkli_starterkit\Kernel;

use Drupal\blokkli_starterkit\LatestModerationStateLookup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
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
    'field',
    'filter',
    'language',
    'node',
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

}
