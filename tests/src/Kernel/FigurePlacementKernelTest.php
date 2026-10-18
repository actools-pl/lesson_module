<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Flat figure relationships must retain revision, access and file semantics. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class FigurePlacementKernelTest extends LessonKernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'options', 'filter', 'file', 'image',
    'node', 'taxonomy', 'actools_lesson', 'actools_lesson_test',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->container->get('current_user')->setAccount($this->reader());
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('system.image')->set('toolkit', 'gd')->save();
    $request = Request::create('/figure-placement-test');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

  public function testAllFourPlacementsAndStandaloneFiguresValidateWithoutSchemaLoopholes(): void {
    $file = $this->image();
    $parent = $this->parent();
    foreach (['example', 'practice'] as $type) {
      $parent['type'] = $type;
      foreach (['prompt', 'hint', 'answer', 'solution'] as $placement) {
        $node = $this->draft([$parent, $this->figure($file, $parent['id'], $placement)]);
        $this->assertCount(0, $node->get('field_actools_lesson_steps')->validate(), $type . ':' . $placement);
      }
    }
    $standalone = $this->figure($file);
    $this->assertCount(0, $this->draft([$standalone])->get('field_actools_lesson_steps')->validate());
    foreach (['unknown', 'self', 'figure_parent', 'text_parent', 'non_uuid', 'bad_placement', 'orphan_placement', 'missing_placement', 'cycle'] as $case) {
      $parent = $this->parent();
      $figure = $this->figure($file, $parent['id'], 'solution');
      $parts = [$parent, $figure];
      switch ($case) {
        case 'unknown': $parts[1]['parent_id'] = $this->uuid(); break;
        case 'self': $parts[1]['parent_id'] = $figure['id']; break;
        case 'figure_parent': $parts[0] = $this->figure($file); $parts[1]['parent_id'] = $parts[0]['id']; break;
        case 'text_parent': $parts[0]['type'] = 'text'; break;
        case 'non_uuid': $parts[1]['parent_id'] = 'not-a-uuid'; break;
        case 'bad_placement': $parts[1]['placement'] = 'script'; break;
        case 'orphan_placement': unset($parts[1]['parent_id']); break;
        case 'missing_placement': unset($parts[1]['placement']); break;
        case 'cycle': $parts = [$figure, $this->figure($file, $figure['id'], 'solution')]; $parts[0]['parent_id'] = $parts[1]['id']; break;
      }
      $this->assertGreaterThan(0, $this->draft($parts)->get('field_actools_lesson_steps')->validate()->count(), $case);
    }
    $otherStepParent = $this->parent();
    $node = $this->draft([$this->figure($file, $otherStepParent['id'], 'answer')]);
    $node->get('field_actools_lesson_steps')->appendItem($this->step(['components' => json_encode([$otherStepParent], JSON_THROW_ON_ERROR)]));
    $this->assertGreaterThan(0, $node->get('field_actools_lesson_steps')->validate()->count(), 'Parents cannot be selected from another step.');
  }

  public function testRenderedFiguresAppearOnceInTheCorrectDisclosureAndPrintExpandsThem(): void {
    $file = $this->image();
    $parent = $this->parent();
    $parts = [$parent];
    foreach (['prompt', 'hint', 'answer', 'solution'] as $placement) {
      $parts[] = $this->figure($file, $parent['id'], $placement, 'Diagram for ' . $placement);
    }
    $parts[] = $this->figure($file, NULL, NULL, 'Standalone diagram');
    $lesson = $this->draft($parts);
    $lesson->save();
    $lesson->setPublished();
    $this->chapter('A chapter with placed figures', [$lesson]);
    foreach (['guided', 'all', 'print'] as $mode) {
      $build = $this->container->get('actools_lesson.document_builder')->build($lesson, $mode);
      $markup = (string) $this->container->get('renderer')->renderRoot($build);
      $xpath = $this->documentXpath($markup);
      $this->assertCount(5, $xpath->query('//figure//img'));
      foreach (array_slice($parts, 1) as $figure) {
        $this->assertCount(1, $xpath->query('//*[@id="component-' . $figure['id'] . '"]'));
      }
      foreach (['hint', 'answer', 'solution'] as $placement) {
        $expression = '//details[contains(concat(" ",normalize-space(@class)," ")," actools-answer--' . $placement . ' ")]';
        $this->assertCount(1, $xpath->query($expression . '//img[@alt="Diagram for ' . $placement . '"]'));
        $this->assertSame($mode === 'print', $xpath->query($expression)->item(0)->hasAttribute('open'));
      }
      $this->assertCount(0, $xpath->query('//details[contains(@class,"actools-answer")]//img[@alt="Diagram for prompt"]'));
      $this->assertCount(0, $xpath->query('//*[@id="component-' . $parent['id'] . '"]//img[@alt="Standalone diagram"]'));
      $this->assertContains('file:' . $file->id(), CacheableMetadata::createFromRenderArray($build)->getCacheTags());
    }
  }

  public function testFigureOnlyOptionalRegionStillHasAClosedDisclosure(): void {
    $file = $this->image();
    $parent = $this->parent();
    unset($parent['hint'], $parent['answer'], $parent['solution']);
    $parts = [$parent, $this->figure($file, $parent['id'], 'solution', 'Image-only solution')];
    $node = $this->draft($parts);
    $build = $this->container->get('actools_lesson.component_renderer')->renderStep($parts, $node);
    $xpath = $this->documentXpath((string) $this->container->get('renderer')->renderRoot($build));
    $this->assertCount(1, $xpath->query('//details[contains(@class,"actools-answer--solution") and not(@open)]//img[@alt="Image-only solution"]'));
    $this->assertCount(0, $xpath->query('//details[contains(@class,"actools-answer--hint") or contains(@class,"actools-answer--answer")]'));
  }

  public function testCorruptOldRelationshipsFallBackToOneStandaloneFigure(): void {
    $file = $this->image();
    $parent = $this->parent();
    foreach ([$this->uuid(), $parent['id']] as $target) {
      $figure = $this->figure($file, $target, $target === $parent['id'] ? 'unavailable_slot' : 'solution');
      $parts = [$parent, $figure];
      $build = $this->container->get('actools_lesson.component_renderer')->renderStep($parts, $this->draft([$parent]));
      $xpath = $this->documentXpath((string) $this->container->get('renderer')->renderRoot($build));
      $this->assertCount(1, $xpath->query('//img'));
      $this->assertCount(0, $xpath->query('//*[@id="component-' . $parent['id'] . '"]//img'));
    }
  }

  public function testTeachingFieldDenialDoesNotExposeAttachedImagesOrCaptions(): void {
    $parent = $this->parent();
    $lesson = $this->draft([$parent, $this->figure($this->image(), $parent['id'], 'solution')]);
    $lesson->save();
    $lesson->setPublished();
    $this->chapter('Access-sensitive diagrams', [$lesson]);
    $this->container->get('state')->set('actools_lesson_test.hidden_fields', ['field_actools_lesson_steps']);
    $build = $this->container->get('actools_lesson.document_builder')->build($lesson);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringNotContainsString('<img', $markup);
    $this->assertStringNotContainsString('Placed scientific diagram', $markup);
    $this->assertContains('actools_lesson_test:hidden_fields', CacheableMetadata::createFromRenderArray($build)->getCacheTags());
  }

  public function testAttachedFileRevisionRestoreRetainsIdentityAndHistoricalUsage(): void {
    $fileA = $this->image();
    $fileB = $this->image();
    $parent = $this->parent();
    $figure = $this->figure($fileA, $parent['id'], 'solution');
    $lesson = $this->draft([$parent, $figure]);
    $lesson->save();
    $historicalId = $lesson->getRevisionId();
    $row = $lesson->get('field_actools_lesson_steps')->first()->getValue();
    $figure['fid'] = (int) $fileB->id();
    $figure['placement'] = 'answer';
    $row['components'] = json_encode([$parent, $figure], JSON_THROW_ON_ERROR);
    $row['content_version'] = 2;
    $lesson->setNewRevision(TRUE);
    $lesson->set('field_actools_lesson_steps', [$row])->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $restored = $storage->createRevision($storage->loadRevisionUnchanged($historicalId));
    $restored->save();
    $current = $storage->loadUnchanged($lesson->id());
    $parts = json_decode($current->get('field_actools_lesson_steps')->components, TRUE, 32, JSON_THROW_ON_ERROR);
    $this->assertSame($parent['id'], $parts[1]['parent_id']);
    $this->assertSame($figure['id'], $parts[1]['id']);
    $this->assertSame('solution', $parts[1]['placement']);
    $this->assertSame((int) $fileA->id(), $parts[1]['fid']);
    $this->assertGreaterThan(2, (int) $current->get('field_actools_lesson_steps')->content_version);
    foreach ([$fileA, $fileB] as $file) {
      $this->assertSame(1, (int) $this->container->get('file.usage')->listUsage($file)['actools_lesson']['node'][$lesson->id()]);
    }
  }

  public function testAttachmentEditsRetainUnchangedRestrictedTextAndUploadOwnershipRules(): void {
    $file = $this->image();
    $parent = $this->parent();
    FilterFormat::create(['format' => 'figure_restricted', 'name' => 'Restricted figure parent', 'filters' => []])->save();
    $parent['body'] = ['value' => 'Trusted imported explanation.', 'format' => 'figure_restricted'];
    $figure = $this->figure($file, $parent['id'], 'solution');
    $lesson = $this->draft([$parent, $figure]);
    // Use a trusted importer once, then return to an ordinary reader/editor.
    $account = $this->container->get('current_user')->getAccount();
    $this->container->get('current_user')->setAccount(\Drupal\user\Entity\User::load(1));
    $lesson->save();
    $this->container->get('current_user')->setAccount($account);
    $row = $lesson->get('field_actools_lesson_steps')->first()->getValue();
    $figure['caption'] = 'A revised public caption.';
    $row['components'] = json_encode([$parent, $figure], JSON_THROW_ON_ERROR);
    $row['content_version'] = 2;
    $lesson->set('field_actools_lesson_steps', [$row]);
    $this->assertCount(0, $lesson->get('field_actools_lesson_steps')->validate());
    $parent['body']['value'] = 'An unauthorized replacement.';
    $row['components'] = json_encode([$parent, $figure], JSON_THROW_ON_ERROR);
    $lesson->set('field_actools_lesson_steps', [$row]);
    $this->assertStringContainsString('permission to use', (string) $lesson->get('field_actools_lesson_steps')->validate());
    $foreign = $this->image(FALSE, 999);
    $validParent = $this->parent();
    $node = $this->draft([$validParent, $this->figure($foreign, $validParent['id'], 'hint')]);
    $this->assertStringContainsString('own temporary', (string) $node->get('field_actools_lesson_steps')->validate());
    $this->assertTrue(File::load($foreign->id())->isTemporary());
  }

  private function uuid(): string {
    return $this->container->get('uuid')->generate();
  }

  private function parent(): array {
    return ['id' => $this->uuid(), 'type' => 'practice', 'heading' => 'A force-balance question',
      'body' => ['value' => 'Explain the vertical force balance.', 'format' => 'plain_text']];
  }

  private function figure(File $file, ?string $parent = NULL, ?string $placement = NULL, string $alt = 'Placed scientific diagram'): array {
    $figure = ['id' => $this->uuid(), 'type' => 'figure', 'fid' => (int) $file->id(), 'alt' => $alt, 'caption' => $alt . ' caption.'];
    if ($parent !== NULL) {
      $figure['parent_id'] = $parent;
    }
    if ($placement !== NULL) {
      $figure['placement'] = $placement;
    }
    return $figure;
  }

  private function draft(array $parts): NodeInterface {
    return Node::create(['type' => 'actools_resource', 'title' => 'Figures within teaching items', 'status' => 0,
      'field_actools_kind' => 'lesson', 'field_actools_lesson_managed' => TRUE, 'field_actools_path' => 'core',
      'field_actools_lesson_steps' => [$this->step(['components' => json_encode($parts, JSON_THROW_ON_ERROR)])]]);
  }

  private function image(bool $permanent = TRUE, ?int $owner = NULL): File {
    $name = 'placement-' . $this->uuid() . '.png';
    $uri = $this->container->get('file_system')->saveData(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII='), 'public://' . $name);
    $file = File::create(['uri' => $uri, 'filename' => $name, 'filemime' => 'image/png', 'status' => (int) $permanent,
      'uid' => $owner ?? (int) $this->container->get('current_user')->id()]);
    $file->save();
    return $file;
  }

  private function documentXpath(string $markup): \DOMXPath {
    $document = new \DOMDocument();
    @$document->loadHTML($markup);
    return new \DOMXPath($document);
  }

}
