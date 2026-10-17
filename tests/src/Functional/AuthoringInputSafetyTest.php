<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Functional;

use Drupal\Component\Utility\Html;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;

/** Native and AJAX forms must never accept partially received lesson content. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class AuthoringInputSafetyTest extends BrowserTestBase {

  protected static $modules = ['actools_lesson'];
  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    FilterFormat::create(['format' => 'second_author_format', 'name' => 'Second author format', 'filters' => []])->save();
    $this->drupalLogin($this->drupalCreateUser([
      'access content', 'access actools lesson authoring', 'bypass node access',
      'administer nodes', 'use text format actools_lesson_text',
      'use text format second_author_format',
    ]));
  }

  private function fixture(int $steps = 3, int $components = 40): NodeInterface {
    $rows = [];
    for ($i = 0; $i < $steps; $i++) {
      $parts = [];
      for ($j = 0; $j < $components; $j++) {
        $part = ['id' => $this->container->get('uuid')->generate(), 'type' => 'practice', 'heading' => 'Practice ' . $j];
        foreach (['body', 'hint', 'answer', 'solution'] as $key) {
          $part[$key] = ['value' => $key . ' ' . $i . ':' . $j, 'format' => 'actools_lesson_text'];
        }
        $parts[] = $part;
      }
      $rows[] = ['step_id' => $this->container->get('uuid')->generate(), 'title' => 'Step ' . $i, 'reviewed' => 0, 'optional' => 0, 'content_version' => 1, 'components' => json_encode($parts, JSON_THROW_ON_ERROR)];
    }
    $node = Node::create(['type' => 'actools_resource', 'title' => 'Input safety lesson', 'status' => 0, 'field_actools_kind' => 'lesson', 'field_actools_lesson_managed' => 1, 'field_actools_path' => 'core', 'field_actools_lesson_steps' => $rows]);
    $node->save();
    return $node;
  }

  private function latest(NodeInterface $node): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->loadRevision($storage->getLatestRevisionId($node->id()));
  }

  private function client() {
    return $this->getSession()->getDriver()->getClient();
  }

  private function schema(array $values): array {
    return json_decode(base64_decode(explode('.', $values['actools_lesson_submission_complete'])[0]), TRUE, 32, JSON_THROW_ON_ERROR);
  }

  /** The independent review's 3 x 40 practice case exceeds the old 1000 limit. */
  public function testFullCapacityNativeSaveAndTruncatedPostsFailClosed(): void {
    $this->assertGreaterThanOrEqual(4096, (int) ini_get('max_input_vars'), 'Run this full-capacity test with the documented server input minimum.');
    $node = $this->fixture();
    $this->drupalGet($node->toUrl('edit-form'));
    $page = $this->getSession()->getPage();
    $prompts = $page->findAll('css', '.actools-author textarea[name$="[body][value]"]');
    $this->assertCount(120, $prompts);
    $form = $this->client()->getCrawler()->selectButton('Save draft')->form();
    $schema = $this->schema($form->getPhpValues());
    $this->assertGreaterThan(1000, count($schema['required']));
    $this->assertNotEmpty($schema['bindings'], 'The whole-form schema is bound to the rendered signed inventory.');
    $this->assertLessThanOrEqual(4096, $schema['budget']);
    foreach ([0, 60, 119] as $index) {
      $prompts[$index]->setValue('Changed native prompt ' . $index);
    }
    $page->pressButton('Save draft');
    $this->assertSession()->statusCodeEquals(200);
    $saved = $this->latest($node);
    $rows = $saved->get('field_actools_lesson_steps')->getValue();
    foreach ([[0, 0, 0], [1, 20, 60], [2, 39, 119]] as [$step, $component, $index]) {
      $this->assertSame('Changed native prompt ' . $index, json_decode($rows[$step]['components'], TRUE)[$component]['body']['value']);
    }
    $revision = $saved->getRevisionId();
    $before = $rows;
    // Keep the valid tail while removing first, middle, and last teaching input.
    foreach ([0, 60, 119] as $index) {
      $this->drupalGet($node->toUrl('edit-form'));
      $crawler = $this->client()->getCrawler();
      $form = $crawler->selectButton('Save draft')->form();
      $values = $form->getPhpValues();
      $sid = array_keys($values['field_actools_lesson_steps']['steps'])[intdiv($index, 40)];
      $cid = array_keys($values['field_actools_lesson_steps']['steps'][$sid]['components'])[$index % 40];
      unset($values['field_actools_lesson_steps']['steps'][$sid]['components'][$cid]['body']['value']);
      $this->client()->request('POST', $form->getUri(), $values);
      $this->assertSession()->statusCodeEquals(400);
      $this->assertSession()->pageTextContains('Nothing was saved');
      $this->assertSame($revision, $this->latest($node)->getRevisionId());
      $this->assertSame($before, $this->latest($node)->get('field_actools_lesson_steps')->getValue());
    }
    // Prefix truncation drops the end sentinel, including PHP max_input_vars.
    $this->drupalGet($node->toUrl('edit-form'));
    $form = $this->client()->getCrawler()->selectButton('Save draft')->form();
    $values = $form->getPhpValues();
    unset($values['actools_lesson_submission_complete']);
    $this->client()->request('POST', $form->getUri(), $values);
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSame($revision, $this->latest($node)->getRevisionId());
    // An empty/discarded PHP POST cannot be treated as a new empty editor.
    $this->client()->request('POST', $form->getUri(), []);
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSame($before, $this->latest($node)->get('field_actools_lesson_steps')->getValue());
    // Exercise PHP's real parser limit, rather than only removing controls.
    $this->drupalGet($node->toUrl('edit-form'));
    $form = $this->client()->getCrawler()->selectButton('Save draft')->form();
    $values = $form->getPhpValues();
    $tail = $values['actools_lesson_submission_complete'];
    unset($values['actools_lesson_submission_complete']);
    $values['padding'] = array_fill(0, (int) ini_get('max_input_vars'), 'unused');
    $values['actools_lesson_submission_complete'] = $tail;
    $this->client()->request('POST', $form->getUri(), $values);
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSession()->pageTextContains('Nothing was saved');
    $this->assertSame($revision, $this->latest($node)->getRevisionId());
    $this->assertSame($before, $this->latest($node)->get('field_actools_lesson_steps')->getValue());
  }

  /** Actual Drupal AJAX callbacks refresh the whole-form tail after mutations. */
  public function testFullCapacityAjaxAndStaleSchemaCannotLoseNewContent(): void {
    $node = $this->fixture();
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertSession()->statusCodeEquals(200);
    $original = $this->client()->getResponse()->getContent();
    $button = $this->client()->getCrawler()->selectButton('Remove component')->eq(1);
    $form = $button->form();
    $values = $form->getPhpValues();
    $all = [];
    foreach ($values['field_actools_lesson_steps']['steps'] as $sid => &$step) {
      foreach ($step['components'] as $cid => &$part) {
        $all[] = [$sid, $cid];
      }
      unset($part);
    }
    unset($step);
    foreach ([0, 60, 119] as $index) {
      [$sid, $cid] = $all[$index];
      $values['field_actools_lesson_steps']['steps'][$sid]['components'][$cid]['body']['value'] = 'Changed AJAX prompt ' . $index;
    }
    $commands = $this->ajax($form->getUri(), $values, $button->attr('name'));
    $html = $this->applyCommands($original, $commands);
    $updated = (new Crawler($html, $form->getUri()))->selectButton('Save draft')->form();
    $this->assertNotSame($values['actools_lesson_submission_complete'], $updated->getPhpValues()['actools_lesson_submission_complete']);
    $this->client()->request('POST', $updated->getUri(), $updated->getPhpValues());
    $this->assertSession()->statusCodeEquals(200);
    $rows = $this->latest($node)->get('field_actools_lesson_steps')->getValue();
    $parts = [];
    foreach ($rows as $row) {
      $parts = array_merge($parts, json_decode($row['components'], TRUE));
    }
    $this->assertCount(119, $parts);
    foreach ([0, 60, 119] as $index) {
      $this->assertContains('Changed AJAX prompt ' . $index, array_column(array_column($parts, 'body'), 'value'));
    }
    // Grow a smaller editor, then replay an older valid tail against new IDs.
    $small = $this->fixture(1, 1);
    $this->drupalGet($small->toUrl('edit-form'));
    $original = $this->client()->getResponse()->getContent();
    $button = $this->client()->getCrawler()->selectButton('Add component')->first();
    $form = $button->form();
    $values = $form->getPhpValues();
    $old_tail = $values['actools_lesson_submission_complete'];
    $commands = $this->ajax($form->getUri(), $values, $button->attr('name'));
    $html = $this->applyCommands($original, $commands);
    $updated = (new Crawler($html, $form->getUri()))->selectButton('Save draft')->form();
    $valid = $updated->getPhpValues();
    $sid = array_key_first($valid['field_actools_lesson_steps']['steps']);
    $cid = array_key_last($valid['field_actools_lesson_steps']['steps'][$sid]['components']);
    $bad = $valid;
    $bad['actools_lesson_submission_complete'] = $old_tail;
    unset($bad['field_actools_lesson_steps']['steps'][$sid]['components'][$cid]['body']['value']);
    $before = $this->latest($small)->getRevisionId();
    $this->client()->request('POST', $updated->getUri(), $bad);
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSame($before, $this->latest($small)->getRevisionId());
    $bad['field_actools_lesson_steps']['inventory'] = $values['field_actools_lesson_steps']['inventory'];
    $this->client()->request('POST', $updated->getUri(), $bad);
    $this->assertSession()->statusCodeEquals(400, 'An old tail/inventory pair cannot authorize newer cached widget state.');
    $this->assertSame($before, $this->latest($small)->getRevisionId());
    $valid['field_actools_lesson_steps']['steps'][$sid]['components'][$cid]['body']['value'] = 'The new component survives a complete AJAX submission.';
    $this->client()->request('POST', $updated->getUri(), $valid);
    $this->assertSession()->statusCodeEquals(200);
    $row = $this->latest($small)->get('field_actools_lesson_steps')->first()->getValue();
    $this->assertCount(2, json_decode($row['components'], TRUE));
    $this->assertSame('The new component survives a complete AJAX submission.', json_decode($row['components'], TRUE)[1]['body']['value']);
  }

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
        foreach ($xpath->query('//input[@name="form_build_id"]') as $input) {
          $input->setAttribute('value', $command['new']);
        }
      }
      if (($command['command'] ?? '') !== 'insert' || !isset($command['selector'], $command['data'])) {
        continue;
      }
      $selector = $command['selector'];
      $node = str_starts_with($selector, '#') ? $document->getElementById(substr($selector, 1)) : $xpath->query('//*[@' . trim($selector, '[]') . ']')->item(0);
      if (!$node) {
        continue;
      }
      $fragment = Html::load($command['data'])->getElementsByTagName('body')->item(0);
      foreach (iterator_to_array($fragment->childNodes) as $child) {
        $node->parentNode->insertBefore($document->importNode($child, TRUE), $node);
      }
      $node->parentNode->removeChild($node);
    }
    return Html::serialize($document);
  }

  public function testUnmanagedResourceStaysEmptyAndExistingStepsArePreserved(): void {
    foreach (['notes', 'assignment'] as $kind) {
      $this->drupalGet('/node/add/actools_resource');
      $page = $this->getSession()->getPage();
      $this->assertCount(0, $page->findAll('css', '.actools-author__step'));
      $page->fillField('Title', 'Ordinary ' . $kind);
      $page->selectFieldOption('Resource kind', $kind);
      $page->pressButton('Save');
      $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->condition('title', 'Ordinary ' . $kind)->execute();
      $this->assertCount(1, $ids);
      $node = Node::load(reset($ids));
      $this->assertTrue($node->get('field_actools_lesson_steps')->isEmpty());
      $this->drupalGet($node->toUrl('edit-form'));
      $this->getSession()->getPage()->pressButton('Save');
      $this->assertTrue($this->latest($node)->get('field_actools_lesson_steps')->isEmpty());
    }
    $this->drupalGet('/admin/content/actools-lessons/add/lesson');
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', '.actools-author__step'));
    $node = $this->fixture(1, 1);
    $rows = $node->get('field_actools_lesson_steps')->getValue();
    $node->set('field_actools_lesson_managed', 0)->set('field_actools_kind', 'notes')->save();
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', '.actools-author__step'));
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertEquals($rows, $this->latest($node)->get('field_actools_lesson_steps')->getValue());
  }

  public function testStoredFiguresDoNotCountAsNewUploadControls(): void {
    $directory = 'public://input-safety';
    $this->container->get('file_system')->prepareDirectory($directory, 1);
    $uri = $this->container->get('file_system')->saveData(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII='), $directory . '/figure.png');
    $file = File::create(['uri' => $uri, 'filename' => 'figure.png', 'filemime' => 'image/png', 'status' => 1]);
    $file->save();
    $node = $this->fixture(1, 1);
    $row = $node->get('field_actools_lesson_steps')->first()->getValue();
    $parts = [];
    for ($i = 0; $i < 21; $i++) {
      $parts[] = ['id' => $this->container->get('uuid')->generate(), 'type' => 'figure', 'fid' => (int) $file->id(), 'alt' => 'Stored figure ' . $i];
    }
    $row['components'] = json_encode($parts, JSON_THROW_ON_ERROR);
    $row['content_version']++;
    $node->set('field_actools_lesson_steps', [$row])->save();
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertSession()->statusCodeEquals(200);
    $form = $this->client()->getCrawler()->selectButton('Save draft')->form();
    $this->assertSame(0, $this->schema($form->getPhpValues())['uploads']);
    $this->assertCount(0, $this->getSession()->getPage()->findAll('css', '.actools-author input[type=file]'));
    $original = $this->client()->getResponse()->getContent();
    $crawler = $this->client()->getCrawler();
    $settings = json_decode($crawler->filter('script[data-drupal-selector="drupal-settings-json"]')->text(), TRUE, 512, JSON_THROW_ON_ERROR);
    $button = $crawler->filter('input[type=submit][value="Remove"]')->first();
    $ajax = $settings['ajax'][$button->attr('id')];
    $file_form = $button->form();
    $origin = parse_url($file_form->getUri(), PHP_URL_SCHEME) . '://' . parse_url($file_form->getUri(), PHP_URL_HOST) . ':' . parse_url($file_form->getUri(), PHP_URL_PORT);
    $commands = $this->ajax($origin . $ajax['url'], $file_form->getPhpValues(), $button->attr('name'));
    foreach ($commands as &$command) {
      if (($command['command'] ?? '') === 'insert' && $command['selector'] === NULL) {
        $command['selector'] = '#' . $ajax['wrapper'];
      }
    }
    unset($command);
    $html = $this->applyCommands($original, $commands);
    $crawler = new Crawler($html, $file_form->getUri());
    $after_file = $crawler->selectButton('Save draft')->form();
    $this->assertSame(1, $this->schema($after_file->getPhpValues())['uploads'], 'Core file AJAX refreshed the outside schema.');
    $button = $crawler->selectButton('Remove component')->first();
    $commands = $this->ajax($file_form->getUri(), $button->form()->getPhpValues(), $button->attr('name'));
    $html = $this->applyCommands($html, $commands);
    $after_builder = (new Crawler($html, $file_form->getUri()))->selectButton('Save draft')->form();
    $this->client()->request('POST', $after_builder->getUri(), $after_builder->getPhpValues());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(20, json_decode($this->latest($node)->get('field_actools_lesson_steps')->first()->components, TRUE));
  }

  public function testPotentialUploadsAboveServerCountCannotBeSubmitted(): void {
    $maximum = (int) ini_get('max_file_uploads');
    if ($maximum >= 60) {
      $this->markTestSkipped('Run this upload-count admission case with max_file_uploads below 60, such as the PHP default 20.');
    }
    $node = $this->fixture(1, 1);
    $before = $node->getRevisionId();
    $this->drupalGet($node->toUrl('edit-form'));
    // Build unsaved upload controls through supported limited-validation
    // actions; invalid missing-image data must never be seeded into storage.
    for ($i = 0; $i <= $maximum; $i++) {
      $this->getSession()->getPage()->selectFieldOption('Teaching component', 'figure');
      $this->getSession()->getPage()->pressButton('Add component');
      $this->assertSession()->statusCodeEquals(200);
    }
    $this->assertSession()->pageTextContains('This complete lesson form exceeds a server limit');
    $this->assertTrue($this->getSession()->getPage()->findButton('Save draft')->hasAttribute('disabled'));
    $form = $this->client()->getCrawler()->filter('form')->form();
    $this->assertSame($maximum + 1, $this->schema($form->getPhpValues())['uploads']);
    $this->client()->request('POST', $form->getUri(), $form->getPhpValues());
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSame($before, $this->latest($node)->getRevisionId());
  }

  public function testDirectNumericStepAndComponentOrdering(): void {
    $node = $this->fixture(2, 2);
    $before = $node->get('field_actools_lesson_steps')->getValue();
    $before_parts = json_decode($before[0]['components'], TRUE);
    $this->drupalGet($node->toUrl('edit-form'));
    $page = $this->getSession()->getPage();
    $steps = $page->findAll('css', '.actools-author__step');
    $steps[0]->findField('Step order')->setValue('10');
    $steps[1]->findField('Step order')->setValue('0');
    $parts = $steps[0]->findAll('css', '.actools-author__component');
    $parts[0]->findField('Component order')->setValue('10');
    $parts[1]->findField('Component order')->setValue('0');
    // The initial GET is deliberately uncached: this covers token restoration,
    // raw-input capture/sorting, then current-phase validation on first POST.
    $page->pressButton('Save draft');
    $this->assertSession()->statusCodeEquals(200);
    $after = $this->latest($node)->get('field_actools_lesson_steps')->getValue();
    $this->assertSame([$before[1]['step_id'], $before[0]['step_id']], array_column($after, 'step_id'));
    $after_parts = json_decode($after[1]['components'], TRUE);
    $this->assertSame([$before_parts[1]['id'], $before_parts[0]['id']], array_column($after_parts, 'id'));
    $this->assertSame($before_parts[0]['body']['value'], $after_parts[1]['body']['value']);
    $this->assertSame(1, (int) $after[0]['content_version'], 'Moving a step alone preserves its teaching-content version.');
    $this->assertSame(2, (int) $after[1]['content_version'], 'Moving teaching components changes their step content version.');
  }

}
