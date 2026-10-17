<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/** Exercises native sections and contextual blocks, without an alternate reader. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class LayoutBuilderIntegrationTest extends LessonKernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'options', 'filter', 'file', 'image',
    'node', 'taxonomy', 'block', 'contextual', 'layout_discovery', 'layout_builder',
    'actools_lesson', 'actools_lesson_layout_builder', 'actools_lesson_test',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('current_user')->setAccount($this->reader());
  }

  public function testOneContextualReaderUsesRealCoreLayoutAndKeepsCacheDependencies(): void {
    $lesson = $this->lesson('Contextual Layout Builder lesson');
    $this->chapter('Contextual lesson chapter', [$lesson]);
    $this->request($lesson);
    $display = $this->display();
    $typed = $this->container->get('config.typed')->createFromNameAndData(
      $display->getConfigDependencyName(),
      $this->container->get('config.storage')->read($display->getConfigDependencyName()),
    );
    $violations = $typed->validate();
    $this->assertCount(0, $violations, (string) $violations);
    $this->assertSame([], $this->integration()->validationErrors($display));
    $build = $this->integration()->build($lesson, $display);
    $this->assertNotNull($build);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('layout--onecol', $markup);
    $this->assertSame(1, substr_count($markup, 'data-actools-reader='));
    $this->assertStringContainsString('A lesson explanation.', $markup);
    $metadata = CacheableMetadata::createFromRenderArray($build);
    $this->assertContains('node:' . $lesson->id(), $metadata->getCacheTags());
    $this->assertContains('config:' . $display->getConfigDependencyName(), $metadata->getCacheTags());
    $this->assertContains('url.query_args:step', $metadata->getCacheContexts());
    $this->assertContains('url.query_args:view', $metadata->getCacheContexts());
    $this->assertContains('user.permissions', $metadata->getCacheContexts());
  }

  public function testMissingDuplicateAndWrongContextCannotReplaceTheReader(): void {
    $lesson = $this->lesson('Safe fallback lesson');
    $this->chapter('Safe fallback chapter', [$lesson]);
    $this->request($lesson);
    foreach (['missing', 'duplicate', 'wrong_context', 'visible_label'] as $case) {
      $display = $this->display(FALSE);
      $section = $display->getSection(0);
      $components = $section->getComponents();
      $component = reset($components);
      if ($case === 'missing') {
        $section->removeComponent($component->getUuid());
      }
      elseif ($case === 'duplicate') {
        $section->appendComponent($this->readerComponent());
      }
      else {
        $configuration = $component->toArray()['configuration'];
        if ($case === 'wrong_context') {
          $configuration['context_mapping']['node'] = '@node.node_route_context:node';
        }
        else {
          $configuration['label_display'] = 'visible';
        }
        $component->setConfiguration($configuration);
      }
      $this->assertNotEmpty($this->integration()->validationErrors($display), $case);
      $this->assertNull($this->integration()->build($lesson, $display), $case . ' must retain the ordinary complete lesson document.');
    }
  }

  public function testOnlyTheExactManagedNodeCanPopulateTheReaderBlock(): void {
    $lesson = $this->lesson('Correct contextual lesson');
    $this->chapter('Correct contextual chapter', [$lesson]);
    $legacy = Node::create(['type' => 'actools_resource', 'title' => 'Ordinary Resource', 'status' => 1]);
    $legacy->save();
    $component = $this->readerComponent();
    $this->request($lesson);
    $build = $component->toRenderArray(['layout_builder.entity' => EntityContext::fromEntity($lesson)]);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('Correct contextual lesson', $markup);
    $this->assertStringNotContainsString('Ordinary Resource', $markup);
    $build = $component->toRenderArray(['layout_builder.entity' => EntityContext::fromEntity($legacy)]);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringNotContainsString('data-actools-reader=', $markup);
    $this->assertStringNotContainsString('A lesson explanation.', $markup);
    $this->assertNull($this->integration()->build($legacy, $this->display()));
  }

  public function testSharedLayoutIgnoresAndPreservesExistingNodeOverrides(): void {
    $display = $this->display(TRUE, 'default');
    $display->setOverridable(TRUE)->save();
    $lesson = $this->lesson('Shared layout lesson');
    $this->chapter('Shared layout chapter', [$lesson]);
    // A preexisting override must neither render nor be deleted by opt-in.
    $lesson = Node::load($lesson->id());
    $lesson->get('layout_builder__layout')->appendSection(new Section('layout_twocol_section', ['column_widths' => '50-50']));
    $lesson->save();
    $stored = $lesson->get('layout_builder__layout')->getValue();
    $revision = $lesson->getRevisionId();
    $this->request($lesson);
    $build = $this->integration()->build($lesson, $display);
    $this->assertNotNull($build);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('layout--onecol', $markup);
    $this->assertStringNotContainsString('layout--twocol-section', $markup);
    $this->assertStringContainsString('A lesson explanation.', $markup);
    $reloaded = Node::load($lesson->id());
    $this->assertEquals($stored, $reloaded->get('layout_builder__layout')->getValue());
    $this->assertSame($revision, $reloaded->getRevisionId());
    $this->assertTrue(LayoutBuilderEntityViewDisplay::load($display->id())->isOverridable());
  }

  public function testNativeFullFallbackSuppressesCorruptOverridesBeforeTheyExecute(): void {
    $display = $this->display(TRUE, 'default');
    $display->setOverridable(TRUE)->save();
    $lesson = $this->lesson('Native full fallback lesson');
    $this->chapter('Native full fallback chapter', [$lesson]);
    $lesson = Node::load($lesson->id());
    $lesson->get('layout_builder__layout')->appendSection(new Section('layout_onecol', [], [
      new SectionComponent($this->container->get('uuid')->generate(), 'content', ['id' => 'block_plugin_that_must_never_execute']),
    ]));
    $lesson->save();
    $stored = $lesson->get('layout_builder__layout')->getValue();
    $this->request($lesson);
    $selected = EntityViewDisplay::collectRenderDisplay($lesson, 'full');
    $this->assertSame('default', $selected->getMode());
    $this->assertSame('full', $selected->getOriginalMode());
    $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($lesson, 'full');
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('actools-lesson-shared-layout', $markup);
    $this->assertSame(1, substr_count($markup, 'data-actools-reader='));
    $this->assertStringContainsString('A lesson explanation.', $markup);
    $this->assertEquals($stored, Node::load($lesson->id())->get('layout_builder__layout')->getValue());
  }

  public function testInvalidOptedInDisplaySaveDoesNotDestroyTheSavedLayout(): void {
    $display = $this->display();
    $name = $display->getConfigDependencyName();
    $stored = $this->container->get('config.storage')->read($name);
    $components = $display->getSection(0)->getComponents();
    $display->getSection(0)->removeComponent(reset($components)->getUuid());
    $rejected = FALSE;
    try {
      $display->save();
    }
    catch (\Exception) {
      $rejected = TRUE;
    }
    $this->assertTrue($rejected, 'Programmatic saves must enforce the required reader too.');
    $this->assertSame($stored, $this->container->get('config.storage')->read($name));
  }

  public function testUnsavedDisplayAndNodeChangesCannotReplacePublishedSharedContent(): void {
    $lesson = $this->lesson('Saved published contextual title');
    $this->chapter('Saved contextual chapter', [$lesson]);
    $this->request($lesson);
    $display = $this->display();
    $unsaved = clone $display;
    $unsaved->removeAllSections();
    $reader = $this->readerComponent();
    $reader->setRegion('first');
    $unsaved->appendSection(new Section('layout_twocol_section', ['column_widths' => '50-50'], [$reader]));
    $lesson->setTitle('UNSAVED PRIVATE CONTEXT TITLE');
    $steps = $lesson->get('field_actools_lesson_steps')->getValue();
    $components = json_decode($steps[0]['components'], TRUE, 512, JSON_THROW_ON_ERROR);
    $components[0]['body']['value'] = 'UNSAVED PRIVATE CONTEXT BODY';
    $steps[0]['components'] = json_encode($components, JSON_THROW_ON_ERROR);
    $lesson->set('field_actools_lesson_steps', $steps);
    $build = $this->integration()->build($lesson, $unsaved);
    $this->assertNotNull($build);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('layout--onecol', $markup);
    $this->assertStringNotContainsString('layout--twocol-section', $markup);
    $this->assertStringContainsString('Saved published contextual title', $markup);
    $this->assertStringContainsString('A lesson explanation.', $markup);
    $this->assertStringNotContainsString('UNSAVED PRIVATE CONTEXT', $markup);
  }

  public function testNativeFieldAndTitleBlocksRemainStoredWithoutDuplicatingManagedContent(): void {
    $lesson = $this->lesson('One managed heading');
    $lesson->set('field_actools_body', ['value' => 'One shared introduction.', 'format' => 'plain_text']);
    $this->chapter('One heading chapter', [$lesson]);
    $this->request($lesson);
    $display = $this->display();
    $display->getSection(0)->appendComponent(new SectionComponent($this->container->get('uuid')->generate(), 'content', [
      'id' => 'field_block:node:actools_resource:field_actools_body', 'provider' => 'layout_builder',
      'label_display' => '0', 'context_mapping' => ['entity' => 'layout_builder.entity'],
      'formatter' => ['type' => 'text_default', 'label' => 'hidden', 'settings' => [], 'third_party_settings' => []],
    ]));
    $display->getSection(0)->appendComponent(new SectionComponent($this->container->get('uuid')->generate(), 'content', [
      'id' => 'page_title_block', 'provider' => 'core', 'label_display' => '0',
    ]));
    $display->save();
    $stored = $this->container->get('config.storage')->read($display->getConfigDependencyName());
    $build = $this->integration()->build($lesson, $display);
    $this->assertNotNull($build);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertSame(1, substr_count($markup, '<h1'));
    $this->assertSame(1, substr_count($markup, 'One shared introduction.'));
    $this->assertSame($stored, $this->container->get('config.storage')->read($display->getConfigDependencyName()));
  }

  public function testOptOutAndNonFullDisplaysKeepTheOriginalPresentation(): void {
    $lesson = $this->lesson('Opt-out lesson');
    $this->chapter('Opt-out chapter', [$lesson]);
    $this->request($lesson);
    $display = $this->display();
    $sections = array_map(static fn (Section $section): array => $section->toArray(), $display->getSections());
    $display->setThirdPartySetting('actools_lesson_layout_builder', 'enabled', FALSE)->save();
    $this->assertNull($this->integration()->build($lesson, $display));
    $this->assertSame($sections, array_map(static fn (Section $section): array => $section->toArray(), $display->getSections()));
    $teaser = EntityViewDisplay::create([
      'targetEntityType' => 'node', 'bundle' => 'actools_resource', 'mode' => 'teaser', 'status' => TRUE,
    ]);
    $teaser->enableLayoutBuilder();
    $teaser->appendSection(new Section('layout_onecol', [], [$this->readerComponent()]));
    $teaser->setThirdPartySetting('actools_lesson_layout_builder', 'enabled', TRUE);
    $this->assertNull($this->integration()->build($lesson, $teaser));
  }

  public function testDeniedTeachingFieldsCannotLeakThroughContextualBlocks(): void {
    $lesson = $this->lesson('Access-sensitive layout lesson');
    $this->chapter('Access-sensitive chapter', [$lesson]);
    $this->request($lesson);
    $display = $this->display();
    $this->container->get('state')->set('actools_lesson_test.hidden_fields', ['field_actools_lesson_steps']);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['actools_lesson_test:hidden_fields']);
    $build = $this->integration()->build($lesson, $display);
    $this->assertNotNull($build);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringNotContainsString('A lesson explanation.', $markup);
    $metadata = CacheableMetadata::createFromRenderArray($build);
    $this->assertContains('actools_lesson_test:hidden_fields', $metadata->getCacheTags());
  }

  public function testTheSameContextualBlockRendersAChapterWithoutLessonBodyDuplication(): void {
    $lesson = $this->lesson('A chapter-linked lesson');
    $chapter = $this->chapter('A shared chapter overview', [$lesson]);
    $this->request($chapter);
    $display = $this->display(TRUE, 'full', 'actools_topic');
    $build = $this->integration()->build($chapter, $display);
    $this->assertNotNull($build);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertSame(1, substr_count($markup, '<h1'));
    $this->assertStringContainsString('A shared chapter overview', $markup);
    $this->assertStringContainsString('A chapter-linked lesson', $markup);
    $this->assertStringNotContainsString('A lesson explanation.', $markup);
    $this->assertStringNotContainsString('data-actools-reader=', $markup);
    $this->assertContains('node:' . $chapter->id(), CacheableMetadata::createFromRenderArray($build)->getCacheTags());
  }

  private function integration(): object {
    return $this->container->get('actools_lesson_layout_builder.integration');
  }

  private function display(bool $save = TRUE, string $mode = 'full', string $bundle = 'actools_resource'): LayoutBuilderEntityViewDisplay {
    $display = EntityViewDisplay::load('node.' . $bundle . '.' . $mode) ?? EntityViewDisplay::create([
      'targetEntityType' => 'node', 'bundle' => $bundle, 'mode' => $mode, 'status' => TRUE,
    ]);
    $this->assertInstanceOf(LayoutBuilderEntityViewDisplay::class, $display);
    $display->enableLayoutBuilder();
    $display->removeAllSections();
    $display->appendSection(new Section('layout_onecol', [], [$this->readerComponent()]));
    if ($save) {
      // Match the supported opt-in sequence: core persists/derives its field
      // blocks first, then the adapter validates the saved display choices.
      $display->unsetThirdPartySetting('actools_lesson_layout_builder', 'enabled');
      $display->save();
      $display->setThirdPartySetting('actools_lesson_layout_builder', 'enabled', TRUE);
      $display->save();
    }
    else {
      $display->setThirdPartySetting('actools_lesson_layout_builder', 'enabled', TRUE);
    }
    return $display;
  }

  private function readerComponent(): SectionComponent {
    return new SectionComponent($this->container->get('uuid')->generate(), 'content', [
      'id' => 'actools_lesson_reader', 'provider' => 'actools_lesson_layout_builder',
      'label' => 'Lesson reader', 'label_display' => '0',
      'context_mapping' => ['node' => 'layout_builder.entity'],
    ]);
  }

  private function request(NodeInterface $node, array $query = []): void {
    $request = Request::create('/node/' . $node->id(), 'GET', $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set('_route', 'entity.node.canonical');
    $request->attributes->set('_route_object', new Route('/node/{node}'));
    $request->attributes->set('node', $node);
    $this->container->get('request_stack')->push($request);
    $this->container->get('current_route_match')->resetRouteMatch();
  }

}
