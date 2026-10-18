<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Functional;

use Drupal\Component\Utility\Html;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;

/** Exercises uploaded figures through real native and AJAX editor submissions. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class FigurePlacementFunctionalTest extends BrowserTestBase {

  protected static $modules = ['actools_lesson'];
  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser([
      'access content', 'access actools lesson authoring', 'bypass node access',
      'administer nodes', 'use text format actools_lesson_text',
    ]));
  }

  public function testNativeUploadPlacementReopenAndReviewedPublication(): void {
    $parent = $this->practice();
    $node = $this->lesson([[$parent]]);
    Node::create(['type' => 'actools_topic', 'title' => 'Diagram placement chapter', 'status' => 1,
      'field_actools_lesson_chapter' => 1, 'field_actools_lessons' => [['target_id' => $node->id()]]])->save();
    $this->drupalGet($node->toUrl('edit-form'));
    $page = $this->getSession()->getPage();
    $page->selectFieldOption('Teaching component', 'figure');
    $page->pressButton('Add component');
    $this->assertSession()->statusCodeEquals(200);
    $page = $this->getSession()->getPage();
    $upload = $page->find('css', '.actools-author input[type="file"]');
    $this->assertNotNull($upload);
    $upload->attachFile(dirname(__DIR__, 2) . '/browser/fixtures/figure-placement-diagram.png');
    $page->pressButton('Upload');
    $this->assertSession()->statusCodeEquals(200);
    $page = $this->getSession()->getPage();
    $page->selectFieldOption('Attach to example or practice', $parent['id']);
    $page->selectFieldOption('Figure placement', 'solution');
    $page->fillField('Alternative text', 'Force balance with upward tension and downward weight');
    $page->fillField('Figure caption', 'A reviewed force-balance solution diagram.');
    $page->pressButton('Save draft');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Private draft preview');
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', 'details.actools-answer--solution:not([open]) img'));
    $parts = $this->parts($this->latest($node))[0];
    $this->assertCount(2, $parts);
    $figure = $parts[1];
    $this->assertSame($parent['id'], $figure['parent_id']);
    $this->assertSame('solution', $figure['placement']);
    $file = File::load($figure['fid']);
    $this->assertTrue($file->isPermanent());
    $this->assertSame((int) $this->loggedInUser->id(), (int) $file->getOwnerId());
    $this->assertSame(1, (int) $this->container->get('file.usage')->listUsage($file)['actools_lesson']['node'][$node->id()]);
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertSession()->fieldValueEquals('Attach to example or practice', $parent['id']);
    $this->assertSession()->fieldValueEquals('Figure placement', 'solution');
    $this->assertSession()->fieldValueEquals('Alternative text', $figure['alt']);
    $this->assertSession()->fieldValueEquals('Figure caption', $figure['caption']);
    $this->getSession()->getPage()->find('css', '.actools-author input[name$="[reviewed]"]')->check();
    $this->getSession()->getPage()->pressButton('Publish reviewed lesson');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertTrue($this->latest($node)->isPublished());
    $this->drupalLogout();
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', 'details.actools-answer--solution:not([open]) img'));
    $this->capture('figure-placement-reader.html');
    $this->drupalGet('/node/' . $node->id() . '/study/print');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', 'details.actools-answer--solution[open] img'));
    $this->capture('figure-placement-print.html');
  }

  public function testNativeParentAndStepCopiesRemapIdentitiesAndParentRemovalDetaches(): void {
    [$parent, $figure] = $this->group();
    $node = $this->lesson([[$parent, $figure]]);
    $this->drupalGet($node->toUrl('edit-form'));
    $this->component($parent['id'])->pressButton('Duplicate component');
    $this->getSession()->getPage()->pressButton('Save draft');
    $original = $this->parts($this->latest($node))[0];
    $this->assertCount(4, $original);
    $parents = array_values(array_filter($original, static fn ($part) => $part['type'] === 'practice'));
    $figures = array_values(array_filter($original, static fn ($part) => $part['type'] === 'figure'));
    $this->assertCount(2, $parents);
    $this->assertCount(2, $figures);
    $this->assertNotSame($parents[0]['id'], $parents[1]['id']);
    $this->assertNotSame($figures[0]['id'], $figures[1]['id']);
    $this->assertEqualsCanonicalizing(array_column($parents, 'id'), array_column($figures, 'parent_id'));
    $this->assertSame($figures[0]['fid'], $figures[1]['fid']);
    $this->drupalGet($node->toUrl('edit-form'));
    $this->getSession()->getPage()->pressButton('Duplicate step');
    $this->getSession()->getPage()->pressButton('Save draft');
    $saved = $this->latest($node);
    $rows = $saved->get('field_actools_lesson_steps')->getValue();
    $this->assertCount(2, $rows);
    $this->assertNotSame($rows[0]['step_id'], $rows[1]['step_id']);
    $parts = $this->parts($saved);
    $allIds = array_merge(array_column($parts[0], 'id'), array_column($parts[1], 'id'));
    $this->assertCount(8, array_unique($allIds));
    foreach ($parts as $step) {
      foreach ($step as $part) {
        if ($part['type'] === 'figure') {
          $this->assertContains($part['parent_id'], array_column($step, 'id'));
        }
      }
    }
    $this->drupalGet($node->toUrl('edit-form'));
    $this->component($parent['id'])->pressButton('Remove component');
    $this->getSession()->getPage()->pressButton('Save draft');
    $parts = $this->parts($this->latest($node));
    $detached = array_values(array_filter($parts[0], static fn ($part) => $part['id'] === $figure['id']))[0];
    $this->assertArrayNotHasKey('parent_id', $detached);
    $this->assertArrayNotHasKey('placement', $detached);
    $this->assertSame($figure['fid'], $detached['fid']);
    $this->assertSame($figure['alt'], $detached['alt']);
    $this->assertSame($figure['caption'], $detached['caption']);
    $this->assertCount(4, $parts[1]);
    $this->assertSame(1, (int) $this->container->get('file.usage')->listUsage(File::load($figure['fid']))['actools_lesson']['node'][$node->id()]);
  }

  public function testRealAjaxMoveDuplicateAndRemovePreserveUnsavedFigureValues(): void {
    [$parent, $figure] = $this->group();
    $node = $this->lesson([[$parent, $figure]]);
    $this->drupalGet($node->toUrl('edit-form'));
    $html = $this->client()->getResponse()->getContent();
    $button = $this->client()->getCrawler()->filter('[data-actools-component="' . $figure['id'] . '"]')->selectButton('Move component up');
    $form = $button->form();
    $values = $form->getPhpValues();
    $sid = array_key_first($values['field_actools_lesson_steps']['steps']);
    $values['field_actools_lesson_steps']['steps'][$sid]['components'][$figure['id']]['caption'] = 'An unsaved AJAX caption survives every operation.';
    $html = $this->applyCommands($html, $this->ajax($form->getUri(), $values, $button->attr('name')));
    $crawler = new Crawler($html, $form->getUri());
    $button = $crawler->filter('[data-actools-component="' . $parent['id'] . '"]')->selectButton('Duplicate component');
    $form = $button->form();
    $html = $this->applyCommands($html, $this->ajax($form->getUri(), $form->getPhpValues(), $button->attr('name')));
    $crawler = new Crawler($html, $form->getUri());
    $this->assertCount(4, $crawler->filter('.actools-author__component'));
    $button = $crawler->filter('[data-actools-component="' . $parent['id'] . '"]')->selectButton('Remove component');
    $form = $button->form();
    $html = $this->applyCommands($html, $this->ajax($form->getUri(), $form->getPhpValues(), $button->attr('name')));
    $form = (new Crawler($html, $form->getUri()))->selectButton('Save draft')->form();
    $this->client()->request('POST', $form->getUri(), $form->getPhpValues());
    $this->assertSession()->statusCodeEquals(200);
    $parts = $this->parts($this->latest($node))[0];
    $this->assertCount(3, $parts);
    $this->assertSame($figure['id'], $parts[0]['id'], 'The actual AJAX move persists figure-first order.');
    $this->assertArrayNotHasKey('parent_id', $parts[0]);
    $this->assertSame('An unsaved AJAX caption survives every operation.', $parts[0]['caption']);
    $attached = array_values(array_filter($parts, static fn ($part) => $part['type'] === 'figure' && isset($part['parent_id'])))[0];
    $this->assertSame($parts[0]['caption'], $attached['caption']);
    $this->assertContains($attached['parent_id'], array_column($parts, 'id'));
  }

  public function testDuplicateGroupCapsAreAtomicAndFullPracticeFormDoesNotGrow(): void {
    foreach ([[59], [58, 60, 1]] as $counts) {
      [$parent, $figure] = $this->group();
      $steps = [];
      foreach ($counts as $i => $count) {
        $parts = $i === 0 ? [$parent, $figure] : [];
        while (count($parts) < $count) {
          $parts[] = ['id' => $this->uuid(), 'type' => 'text', 'body' => ['value' => 'Capacity filler.', 'format' => 'plain_text']];
        }
        $steps[] = $parts;
      }
      $node = $this->lesson($steps);
      $this->drupalGet($node->toUrl('edit-form'));
      $button = $this->component($parent['id'])->findButton('Duplicate component');
      $this->assertTrue($button->hasAttribute('disabled'));
      // Send the disabled trigger explicitly: native processing must also deny it.
      $form = $this->client()->getCrawler()->selectButton('Save draft')->form();
      $values = $form->getPhpValues();
      unset($values['op']);
      $values[$button->getAttribute('name')] = 'Duplicate component';
      $this->client()->request('POST', $form->getUri(), $values);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertCount(array_sum($counts), array_merge(...$this->parts($this->latest($node))), 'A forged disabled duplicate trigger cannot partially copy its group.');
      $this->drupalGet($node->toUrl('edit-form'));
      $this->assertCount(array_sum($counts), $this->getSession()->getPage()->findAll('css', '.actools-author__component'));
      $this->assertEqualsCanonicalizing(array_column($steps[0], 'id'), array_column($this->parts($this->latest($node))[0], 'id'));
    }
    $steps = [];
    for ($i = 0; $i < 3; $i++) {
      $steps[$i] = [];
      for ($j = 0; $j < 40; $j++) {
        $part = $this->practice();
        foreach (['hint', 'answer', 'solution'] as $key) {
          $part[$key] = ['value' => 'Full-capacity ' . $key, 'format' => 'actools_lesson_text'];
        }
        $steps[$i][] = $part;
      }
    }
    $node = $this->lesson($steps);
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertCount(120, $this->getSession()->getPage()->findAll('css', '.actools-author__component--practice'));
    $this->assertCount(0, $this->getSession()->getPage()->findAll('css', '.actools-author select[name$="[parent_id]"],.actools-author select[name$="[placement]"]'));
    $form = $this->client()->getCrawler()->selectButton('Save draft')->form();
    $values = $form->getPhpValues();
    $schema = json_decode(base64_decode(explode('.', $values['actools_lesson_submission_complete'])[0]), TRUE, 32, JSON_THROW_ON_ERROR);
    $this->assertLessThanOrEqual(4096, $schema['budget']);
    $values['title'][0]['value'] = 'A complete 120-practice lesson remains saveable';
    $this->client()->request('POST', $form->getUri(), $values);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('A complete 120-practice lesson remains saveable', $this->latest($node)->label());
    $this->assertCount(120, array_merge(...$this->parts($this->latest($node))));
  }

  private function uuid(): string { return $this->container->get('uuid')->generate(); }

  private function practice(): array {
    return ['id' => $this->uuid(), 'type' => 'practice', 'heading' => 'Resolve the forces',
      'body' => ['value' => 'Find the vertical force balance in the diagram.', 'format' => 'plain_text']];
  }

  private function group(): array {
    $parent = $this->practice();
    $source = dirname(__DIR__, 2) . '/browser/fixtures/figure-placement-diagram.png';
    $uri = $this->container->get('file_system')->saveData(file_get_contents($source), 'public://figure-' . $this->uuid() . '.png');
    $file = File::create(['uri' => $uri, 'filename' => basename($uri), 'filemime' => 'image/png', 'status' => 1, 'uid' => $this->loggedInUser->id()]);
    $file->save();
    return [$parent, ['id' => $this->uuid(), 'type' => 'figure', 'fid' => (int) $file->id(), 'parent_id' => $parent['id'], 'placement' => 'solution', 'alt' => 'A placed force diagram', 'caption' => 'A preserved scientific caption.']];
  }

  private function lesson(array $steps): NodeInterface {
    $rows = [];
    foreach ($steps as $i => $parts) {
      $rows[] = ['step_id' => $this->uuid(), 'title' => 'Figure teaching step ' . ($i + 1), 'optional' => 0, 'reviewed' => 0, 'content_version' => 1, 'components' => json_encode($parts, JSON_THROW_ON_ERROR)];
    }
    $node = Node::create(['type' => 'actools_resource', 'title' => 'Figures inside worked solutions', 'uid' => $this->loggedInUser->id(), 'status' => 0,
      'field_actools_kind' => 'lesson', 'field_actools_lesson_managed' => 1, 'field_actools_path' => 'core', 'field_actools_lesson_steps' => $rows]);
    $node->save();
    return $node;
  }

  private function latest(NodeInterface $node): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->loadRevisionUnchanged($storage->getLatestRevisionId($node->id()));
  }

  private function parts(NodeInterface $node): array {
    return array_map(static fn ($row) => json_decode($row['components'], TRUE, 32, JSON_THROW_ON_ERROR), $node->get('field_actools_lesson_steps')->getValue());
  }

  private function component(string $id) { return $this->getSession()->getPage()->find('css', '[data-actools-component="' . $id . '"]'); }
  private function client() { return $this->getSession()->getDriver()->getClient(); }

  private function ajax(string $uri, array $values, string $trigger): array {
    $values['_triggering_element_name'] = $trigger;
    $values['_triggering_element_value'] = $values[$trigger] ?? '';
    $this->client()->request('POST', $uri . (str_contains($uri, '?') ? '&' : '?') . 'ajax_form=1&_wrapper_format=drupal_ajax', $values, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/vnd.drupal-ajax']);
    $this->assertSession()->statusCodeEquals(200);
    return json_decode($this->client()->getResponse()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  private function applyCommands(string $html, array $commands): string {
    $document = Html::load($html);
    $xpath = new \DOMXPath($document);
    foreach ($commands as $command) {
      if (($command['command'] ?? '') === 'update_build_id') {
        foreach ($xpath->query('//input[@name="form_build_id"]') as $input) { $input->setAttribute('value', $command['new']); }
      }
      if (($command['command'] ?? '') !== 'insert' || !isset($command['selector'], $command['data'])) { continue; }
      $selector = $command['selector'];
      $node = str_starts_with($selector, '#') ? $document->getElementById(substr($selector, 1)) : $xpath->query('//*[@' . trim($selector, '[]') . ']')->item(0);
      if (!$node) { continue; }
      $fragment = Html::load($command['data'])->getElementsByTagName('body')->item(0);
      foreach (iterator_to_array($fragment->childNodes) as $child) { $node->parentNode->insertBefore($document->importNode($child, TRUE), $node); }
      $node->parentNode->removeChild($node);
    }
    return Html::serialize($document);
  }

  /** Opt-in local evidence capture; normal CI never writes source fixtures. */
  private function capture(string $filename): void {
    $directory = getenv('ACTOOLS_TEST_FIGURE_CAPTURE');
    if (!$directory) { return; }
    $root = $this->getSession()->getPage()->find('css', '[data-actools-reader]');
    $this->assertNotNull($root);
    file_put_contents($directory . '/' . $filename, $root->getOuterHtml());
  }

}
