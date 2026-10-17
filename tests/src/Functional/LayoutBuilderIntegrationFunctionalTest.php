<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Functional;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\system\Entity\Menu;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Real default-layout forms, contextual rendering and editorial boundaries. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class LayoutBuilderIntegrationFunctionalTest extends BrowserTestBase {

  // Install the optional adapter explicitly after representative existing data.
  protected static $modules = [
    'actools_lesson', 'layout_builder', 'field_ui', 'page_cache',
    'dynamic_page_cache', 'actools_lesson_test', 'menu_link_content',
  ];

  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('access content')->save();
  }

  public function testInstallationPreservesExistingDisplaysOverridesAndLegacyResources(): void {
    $display = $this->display(FALSE, 'default');
    $display->setOverridable(TRUE)->save();
    $legacy = Node::create(['type' => 'actools_resource', 'title' => 'Existing ordinary resource', 'status' => 1]);
    $legacy->get('layout_builder__layout')->appendSection(new Section('layout_twocol_section', ['column_widths' => '50-50'], [
      new SectionComponent($this->container->get('uuid')->generate(), 'first', [
        'id' => 'system_powered_by_block', 'provider' => 'system', 'label_display' => '0',
      ]),
    ]));
    $legacy->save();
    $beforeDisplay = $this->container->get('config.storage')->read($display->getConfigDependencyName());
    $beforeLayout = $legacy->get('layout_builder__layout')->getValue();
    $beforeRevision = $legacy->getRevisionId();
    $beforePermissions = Role::load(AccountInterface::ANONYMOUS_ROLE)->getPermissions();
    $this->enableIntegration();
    $display = LayoutBuilderEntityViewDisplay::load($display->id());
    $this->assertSame($beforeDisplay, $this->container->get('config.storage')->read($display->getConfigDependencyName()));
    $this->assertSame($beforePermissions, Role::load(AccountInterface::ANONYMOUS_ROLE)->getPermissions());
    $this->assertFalse((bool) $display->getThirdPartySetting('actools_lesson_layout_builder', 'enabled', FALSE));
    $this->assertTrue($display->isOverridable());
    $this->drupalGet($legacy->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '.layout--twocol-section');
    $this->assertSession()->elementNotExists('css', '[data-actools-reader]');

    $display->getSection(0)->appendComponent($this->readerComponent());
    $display->setThirdPartySetting('actools_lesson_layout_builder', 'enabled', TRUE)->save();
    [$lesson] = $this->lesson();
    $this->drupalGet($lesson->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementsCount('css', '[data-actools-reader]', 1);
    $this->drupalGet($legacy->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '.layout--twocol-section');
    $this->assertSession()->elementNotExists('css', '[data-actools-reader]');
    $legacy = Node::load($legacy->id());
    $this->assertEquals($beforeLayout, $legacy->get('layout_builder__layout')->getValue());
    $this->assertSame($beforeRevision, $legacy->getRevisionId());
    $this->assertTrue(LayoutBuilderEntityViewDisplay::load($display->id())->isOverridable());
  }

  public function testNativeDefaultLayoutEditingAndOptOutPreserveLessonContent(): void {
    $this->enableIntegration();
    $display = $this->display();
    [$lesson] = $this->lesson();
    Menu::create(['id' => 'lesson_companion', 'label' => 'Shared lesson companion'])->save();
    MenuLinkContent::create([
      'title' => 'Help with this lesson', 'menu_name' => 'lesson_companion',
      'link' => ['uri' => 'internal:/node/' . $lesson->id()],
    ])->save();
    // Compare two persisted snapshots, rather than fixture PHP booleans/ints
    // with SQL's returned scalar strings after a subsequent request/reload.
    $persisted = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($lesson->id());
    $before = $persisted->get('field_actools_lesson_steps')->getValue();
    $revision = (int) $persisted->getRevisionId();
    $this->drupalLogin($this->drupalCreateUser(['access content', 'configure any layout', 'administer node display']));
    $this->drupalGet(Url::fromRoute('layout_builder.defaults.node.view', [
      'node_type' => 'actools_resource', 'view_mode_name' => 'full',
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Save layout');
    $this->clickLink('Add section', 1);
    $this->assertSession()->elementExists('css', 'a[href*="layout_twocol_section"]')->click();
    $this->submitForm([], 'Add section');
    $this->clickLink('Add block', 1);
    $this->clickLink('Shared lesson companion');
    $this->submitForm([], 'Add block');
    $this->submitForm([], 'Save layout');
    $this->assertSession()->statusCodeEquals(200);
    $display = $this->reloadDisplay($display->id());
    $this->assertCount(2, $display->getSections());
    $this->assertSame('layout_twocol_section', $display->getSection(1)->getLayoutId());
    $this->drupalGet($lesson->toUrl());
    $this->assertSession()->elementsCount('css', '.actools-lesson-shared-layout [data-actools-reader]', 1);
    $this->assertSession()->elementExists('css', '.actools-lesson-shared-layout .layout--twocol-section');
    $this->assertSession()->pageTextContains('First shared-layout explanation.');
    $this->assertSession()->pageTextContains('Help with this lesson');
    $sections = array_map(static fn (Section $section): array => $section->toArray(), $display->getSections());
    $this->drupalGet('admin/structure/types/manage/actools_resource/display/full');
    $this->assertSession()->checkboxChecked('actools_lesson_layout_builder[enabled]');
    $this->submitForm(['actools_lesson_layout_builder[enabled]' => FALSE], 'Save');
    $display = $this->reloadDisplay($display->id());
    $this->assertTrue($display->isLayoutBuilderEnabled());
    $this->assertSame($sections, array_map(static fn (Section $section): array => $section->toArray(), $display->getSections()));
    $this->drupalGet($lesson->toUrl());
    $this->assertSession()->elementsCount('css', '[data-actools-reader]', 1);
    $this->assertSession()->elementNotExists('css', '.actools-lesson-shared-layout');
    $lesson = Node::load($lesson->id());
    $this->assertSame($before, $lesson->get('field_actools_lesson_steps')->getValue());
    $this->assertSame($revision, (int) $lesson->getRevisionId());
  }

  public function testSharedLayoutKeepsModeStepAndPrivateProgressCacheBoundaries(): void {
    $this->enableIntegration();
    $this->display();
    [$lesson, $steps] = $this->lesson();
    $url = $lesson->toUrl();
    $this->drupalGet(clone $url);
    $this->assertSession()->elementsCount('css', '[data-actools-reader]', 1);
    $this->assertSession()->pageTextContains('First shared-layout explanation.');
    $this->assertSession()->pageTextNotContains('Second shared-layout explanation.');
    $this->drupalGet(clone $url, ['query' => ['step' => $steps[1]]]);
    $this->assertSession()->pageTextContains('Second shared-layout explanation.');
    $this->assertSession()->pageTextNotContains('First shared-layout explanation.');
    $this->drupalGet(clone $url, ['query' => ['view' => 'all']]);
    $this->assertSession()->pageTextContains('First shared-layout explanation.');
    $this->assertSession()->pageTextContains('Second shared-layout explanation.');
    $this->drupalGet(clone $url);
    $this->assertSession()->pageTextNotContains('Second shared-layout explanation.');
    $alice = $this->drupalCreateUser(['access content']);
    $bob = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($alice);
    $this->drupalGet(clone $url);
    $this->assertSession()->pageTextContains('0 of 2 required steps read');
    $this->submitForm([], 'Mark read and continue');
    $this->drupalGet(clone $url);
    $this->assertSession()->pageTextContains('1 of 2 required steps read');
    $this->drupalLogout();
    $this->drupalLogin($bob);
    $this->drupalGet(clone $url);
    $this->drupalGet(clone $url);
    $this->assertSession()->pageTextContains('0 of 2 required steps read');
    $this->assertNull($this->container->get('user.data')->get('actools_lesson', (int) $bob->id(), 'lesson:' . $lesson->id()));
    $this->drupalLogout();
    $this->drupalGet(clone $url);
    $this->assertSession()->pageTextContains('Sign in to save your reading place');
    $this->assertSession()->pageTextNotContains('1 of 2 required steps read');
    $this->drupalGet(Url::fromRoute('actools_lesson.print', ['node' => $lesson->id()]));
    $this->assertSession()->pageTextContains('First shared-layout explanation.');
    $this->assertSession()->pageTextContains('Second shared-layout explanation.');
    $this->assertSession()->elementNotExists('css', '.actools-lesson-shared-layout');
  }

  public function testManagedOverridesAreForbiddenWhileLegacyOverridesRemainAvailable(): void {
    $this->enableIntegration();
    $display = $this->display(TRUE, 'default');
    $display->setOverridable(TRUE)->save();
    [$lesson] = $this->lesson();
    $legacy = Node::create(['type' => 'actools_resource', 'title' => 'Ordinary editable resource', 'status' => 1]);
    $legacy->save();
    $this->drupalLogin($this->drupalCreateUser([
      'access content', 'configure any layout', 'administer node display', 'bypass node access',
    ]));
    $this->drupalGet('/node/' . $lesson->id() . '/layout');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet(Url::fromRoute('layout_builder.choose_section', [
      'section_storage_type' => 'overrides', 'section_storage' => 'node.' . $lesson->id(), 'delta' => 0,
    ]));
    $this->assertSession()->statusCodeEquals(403);
    foreach (['discard-changes', 'revert'] as $operation) {
      $this->drupalGet('/node/' . $lesson->id() . '/layout/' . $operation);
      $this->assertSession()->statusCodeEquals(403);
    }
    foreach (['layout_builder.add_block', 'layout_builder.update_block', 'layout_builder.remove_block'] as $route) {
      $this->drupalGet(Url::fromRoute($route, [
        'section_storage_type' => 'overrides', 'section_storage' => 'node.' . $lesson->id(),
        'delta' => 0, 'region' => 'content', 'plugin_id' => 'system_powered_by_block',
        'uuid' => $this->container->get('uuid')->generate(),
      ]));
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->drupalGet('/node/' . $legacy->id() . '/layout');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Save layout');
    // The default is still ordinary content, but its pending adoption is a
    // managed lesson. Core override forms must not save over that draft.
    $pending = clone $legacy;
    $pending->setNewRevision(TRUE);
    $pending->isDefaultRevision(FALSE);
    $pending->setUnpublished();
    $pending->set('field_actools_kind', 'lesson');
    $pending->set('field_actools_lesson_managed', TRUE);
    $pending->set('field_actools_lesson_steps', $lesson->get('field_actools_lesson_steps')->getValue());
    $pending->save();
    $this->drupalGet('/node/' . $legacy->id() . '/layout');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet(Url::fromRoute('layout_builder.defaults.node.view', [
      'node_type' => 'actools_resource', 'view_mode_name' => 'default',
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Save layout');
  }

  public function testPublicDraftAndHistoricalRevisionNeverShareAReaderContext(): void {
    $this->enableIntegration();
    $this->display();
    [$lesson] = $this->lesson();
    $historical = $lesson->getRevisionId();
    $this->changeFirstBody($lesson, 'The current public explanation.');
    $lesson->setNewRevision(TRUE);
    $lesson->save();
    $draft = clone $lesson;
    $draft->setNewRevision(TRUE);
    $draft->isDefaultRevision(FALSE);
    $draft->setUnpublished()->setTitle('PRIVATE LAYOUT DRAFT TITLE');
    $this->changeFirstBody($draft, 'PRIVATE LAYOUT DRAFT EXPLANATION');
    $draft->save();
    $this->drupalGet($lesson->toUrl(), ['query' => ['revision' => $draft->getRevisionId(), 'vid' => $draft->getRevisionId(), 'preview' => 1]]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The current public explanation.');
    $this->assertSession()->pageTextNotContains('PRIVATE LAYOUT DRAFT');
    $this->assertSession()->elementsCount('css', '.actools-lesson-shared-layout [data-actools-reader]', 1);
    $history = Url::fromRoute('entity.node.revision', ['node' => $lesson->id(), 'node_revision' => $historical]);
    $this->drupalGet(clone $history);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/node/' . $lesson->id() . '/study/draft');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['access content', 'view all revisions']));
    $this->drupalGet(clone $history);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('First shared-layout explanation.');
    $this->assertSession()->pageTextNotContains('The current public explanation.');
    $this->assertSession()->pageTextNotContains('PRIVATE LAYOUT DRAFT');
    $this->assertSession()->elementNotExists('css', '.actools-lesson-shared-layout');
    $this->assertSession()->elementNotExists('css', 'form.actools-lesson-progress-form');
    $this->drupalLogout();
    $this->drupalLogin($this->drupalCreateUser(['access content', 'bypass node access']));
    $this->drupalGet('/node/' . $lesson->id() . '/study/draft');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('PRIVATE LAYOUT DRAFT EXPLANATION');
    $this->assertSession()->elementNotExists('css', '.actools-lesson-shared-layout');
    $this->assertSession()->elementNotExists('css', 'form.actools-lesson-progress-form');
    $this->drupalGet($lesson->toUrl());
    $this->assertSession()->pageTextContains('The current public explanation.');
    $this->assertSession()->pageTextNotContains('PRIVATE LAYOUT DRAFT');
  }

  public function testWarmedLayoutRetainsFieldAccessDenialsAndInvalidation(): void {
    $this->enableIntegration();
    $this->display();
    [$lesson] = $this->lesson();
    $this->drupalGet($lesson->toUrl());
    $this->drupalGet($lesson->toUrl());
    $this->assertSession()->pageTextContains('First shared-layout explanation.');
    $this->container->get('state')->set('actools_lesson_test.hidden_fields', ['field_actools_lesson_steps']);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['actools_lesson_test:hidden_fields']);
    $this->drupalGet($lesson->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('First shared-layout explanation.');
    $this->assertSession()->pageTextNotContains('Second shared-layout explanation.');
    $this->container->get('state')->set('actools_lesson_test.hidden_fields', []);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['actools_lesson_test:hidden_fields']);
    $this->drupalGet($lesson->toUrl());
    $this->assertSession()->pageTextContains('First shared-layout explanation.');
  }

  public function testMalformedImportedLayoutsKeepOneSafeCompleteReader(): void {
    $this->enableIntegration();
    $display = $this->display();
    [$lesson] = $this->lesson();
    foreach (['missing_reader', 'duplicate_reader', 'wrong_mapping', 'missing_layout_plugin'] as $case) {
      $section = new Section('layout_onecol', [], [$this->readerComponent()]);
      $components = $section->getComponents();
      $reader = reset($components);
      if ($case === 'missing_reader') {
        $section->removeComponent($reader->getUuid());
      }
      elseif ($case === 'duplicate_reader') {
        $section->appendComponent($this->readerComponent());
      }
      elseif ($case === 'wrong_mapping') {
        $configuration = $reader->toArray()['configuration'];
        $configuration['context_mapping']['node'] = '@node.node_route_context:node';
        $reader->setConfiguration($configuration);
      }
      $sectionData = $section->toArray();
      if ($case === 'missing_layout_plugin') {
        // Native Section::toArray() resolves a layout plugin. A stale imported
        // layout is raw config, so alter its serialized ID after conversion.
        $sectionData['layout_id'] = 'layout_plugin_that_must_never_execute';
      }
      // Simulate an imported/stale configuration, intentionally bypassing the
      // adapter's ordinary save guard. Rendering must still remain safe.
      $this->container->get('config.factory')->getEditable($display->getConfigDependencyName())
        ->set('third_party_settings.layout_builder.sections', [$sectionData])->save();
      $this->container->get('entity_type.manager')->getStorage('entity_view_display')->resetCache([$display->id()]);
      $this->drupalGet($lesson->toUrl());
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->elementsCount('css', '[data-actools-reader]', 1);
      $this->assertSession()->elementsCount('css', 'h1', 1);
      $this->assertSession()->pageTextContains('First shared-layout explanation.');
      $this->assertSession()->elementNotExists('css', '.actools-lesson-shared-layout');
    }
  }

  public function testNativeDisableConfirmationRequiresExplicitAdapterOptOut(): void {
    $this->enableIntegration();
    $display = $this->display();
    [$lesson] = $this->lesson();
    $before = array_map(static fn (Section $section): array => $section->toArray(), $display->getSections());
    $this->drupalLogin($this->drupalCreateUser(['access content', 'configure any layout', 'administer node display']));
    $disable = Url::fromRoute('layout_builder.defaults.node.disable', [
      'node_type' => 'actools_resource', 'view_mode_name' => 'full',
    ]);
    $this->drupalGet(clone $disable);
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Confirm');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementTextContains('css', '[role="alert"]', 'Turn off Actools shared presentation in Manage display and save before disabling core Layout Builder.');
    $display = $this->reloadDisplay($display->id());
    $this->assertTrue($display->isLayoutBuilderEnabled());
    $this->assertTrue((bool) $display->getThirdPartySetting('actools_lesson_layout_builder', 'enabled', FALSE));
    $this->assertSame($before, array_map(static fn (Section $section): array => $section->toArray(), $display->getSections()));
    $this->drupalGet('admin/structure/types/manage/actools_resource/display/full');
    $this->submitForm(['actools_lesson_layout_builder[enabled]' => FALSE], 'Save');
    $this->drupalGet(clone $disable);
    $this->submitForm([], 'Confirm');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertFalse($this->reloadDisplay($display->id())->isLayoutBuilderEnabled());
    $this->drupalGet($lesson->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementsCount('css', '[data-actools-reader]', 1);
    $this->assertSession()->elementNotExists('css', '.actools-lesson-shared-layout');
    $this->assertSession()->pageTextContains('First shared-layout explanation.');
  }

  private function enableIntegration(): void {
    $this->container->get('module_installer')->install(['actools_lesson_layout_builder']);
    $this->container = \Drupal::getContainer();
  }

  private function reloadDisplay(string $id): LayoutBuilderEntityViewDisplay {
    $this->container->get('config.factory')->reset('core.entity_view_display.' . $id);
    $this->container->get('entity_type.manager')->getStorage('entity_view_display')->resetCache([$id]);
    return LayoutBuilderEntityViewDisplay::load($id);
  }

  private function display(bool $withReader = TRUE, string $mode = 'full'): LayoutBuilderEntityViewDisplay {
    $display = EntityViewDisplay::load('node.actools_resource.' . $mode) ?? EntityViewDisplay::create([
      'targetEntityType' => 'node', 'bundle' => 'actools_resource', 'mode' => $mode, 'status' => TRUE,
    ]);
    $this->assertInstanceOf(LayoutBuilderEntityViewDisplay::class, $display);
    $display->enableLayoutBuilder();
    $display->removeAllSections();
    $display->appendSection(new Section('layout_onecol', [], $withReader ? [$this->readerComponent()] : []));
    $display->unsetThirdPartySetting('actools_lesson_layout_builder', 'enabled');
    $display->save();
    if ($withReader) {
      $display->setThirdPartySetting('actools_lesson_layout_builder', 'enabled', TRUE);
      $display->save();
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

  private function lesson(): array {
    $uuid = $this->container->get('uuid');
    $steps = [];
    $ids = [];
    foreach (['First shared-layout explanation.', 'Second shared-layout explanation.'] as $delta => $body) {
      $ids[] = $id = $uuid->generate();
      $steps[] = [
        'step_id' => $id, 'title' => 'Shared layout step ' . ($delta + 1),
        'reviewed' => TRUE, 'optional' => FALSE, 'content_version' => 1,
        'components' => json_encode([[
          'id' => $uuid->generate(), 'type' => 'text',
          'body' => ['value' => $body, 'format' => 'plain_text'],
        ]], JSON_THROW_ON_ERROR),
      ];
    }
    $lesson = Node::create([
      'type' => 'actools_resource', 'title' => 'Shared-layout surface forces', 'status' => 0,
      'field_actools_kind' => 'lesson', 'field_actools_lesson_managed' => TRUE,
      'field_actools_path' => 'core', 'field_actools_lesson_steps' => $steps,
    ]);
    $lesson->save();
    Node::create([
      'type' => 'actools_topic', 'title' => 'Shared-layout Surface Tension chapter', 'status' => 1,
      'field_actools_lesson_chapter' => TRUE, 'field_actools_lessons' => [['target_id' => $lesson->id()]],
    ])->save();
    $lesson->setPublished()->save();
    return [$lesson, $ids];
  }

  private function changeFirstBody(Node $lesson, string $body): void {
    $steps = $lesson->get('field_actools_lesson_steps')->getValue();
    $components = json_decode($steps[0]['components'], TRUE, 512, JSON_THROW_ON_ERROR);
    $components[0]['body']['value'] = $body;
    $steps[0]['components'] = json_encode($components, JSON_THROW_ON_ERROR);
    $steps[0]['content_version']++;
    $lesson->set('field_actools_lesson_steps', $steps);
  }

}
