<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Native no-JS progress submissions, account isolation and stale/invalid forms. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
class ProgressFormFunctionalTest extends BrowserTestBase {

  protected static $modules = ['actools_lesson', 'page_cache', 'dynamic_page_cache'];

  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('access content')->save();
  }

  public function testNoJsPostUndoBulkResetAndWarmCacheIsolation(): void {
    [$lesson, $a, $b] = $this->fixture();
    $url = Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]);
    $chapter_url = $this->container->get('actools_lesson.outline')->owner((int) $lesson->id())->toUrl();
    $alice = $this->drupalCreateUser(['access content']);
    $bob = $this->drupalCreateUser(['access content']);

    // Warm the public page twice. Viewing/navigating is never a progress write.
    $this->drupalGet(clone $url);
    $this->drupalGet(clone $url);
    $this->assertSession()->pageTextContains('Sign in to save your reading place');
    $this->drupalLogin($alice);
    $this->drupalGet(clone $url, ['query' => ['step' => $b, 'complete' => 1]]);
    $this->assertNull($this->record((int) $alice->id(), (int) $lesson->id()));
    $this->assertSession()->pageTextContains('0 of 2 required steps read');
    $this->assertMeter('0', '2');
    $this->assertSession()->elementExists('css', 'button[type="submit"].actools-progress__primary');
    $this->assertSession()->elementNotExists('css', '.actools-progress input[type="submit"]');
    $this->assertSession()->elementsCount('css', '.actools-progress__primary', 1);
    $this->assertSession()->elementExists('css', '.actools-progress details [data-actools-progress-action="position"]');

    $this->drupalGet(clone $url, ['query' => ['step' => $a]]);
    $this->submitForm([], 'Mark read and continue');
    $query = [];
    parse_str((string) parse_url($this->getSession()->getCurrentUrl(), PHP_URL_QUERY), $query);
    $this->assertSame($b, $query['step'] ?? NULL);
    $record = $this->record((int) $alice->id(), (int) $lesson->id());
    $this->assertSame(1, $record['completed'][$a]['content_version']);
    $this->assertSame($b, $record['position']);
    $this->assertSession()->pageTextContains('1 of 2 required steps read');
    $this->assertMeter('1', '2');
    $this->submitForm([], 'Mark read and continue');
    $this->assertCount(2, $this->record((int) $alice->id(), (int) $lesson->id())['completed']);
    $this->assertSession()->addressEquals($chapter_url);
    $this->assertSession()->pageTextContains('Review Surface forces and pressure');
    $this->drupalGet(clone $url, ['query' => ['step' => $a]]);
    $this->submitForm([], 'Undo read mark');
    $this->assertArrayNotHasKey($a, $this->record((int) $alice->id(), (int) $lesson->id())['completed']);
    $this->submitForm(['selected[' . $a . ']' => TRUE], 'Mark selected steps as read');
    $this->assertCount(2, $this->record((int) $alice->id(), (int) $lesson->id())['completed']);
    $this->drupalGet(clone $chapter_url);
    $this->assertSession()->pageTextContains('Continue learning: Surface forces and pressure');

    $this->drupalLogout();
    $this->drupalGet(clone $url);
    $this->assertSession()->pageTextContains('Sign in to save your reading place');
    $this->assertSession()->elementNotExists('css', '[data-actools-completed-steps]');
    $this->drupalGet(clone $chapter_url);
    $this->assertSession()->pageTextNotContains('Continue learning: Surface forces and pressure');
    $this->drupalLogin($bob);
    $this->drupalGet(clone $url);
    $this->drupalGet(clone $url);
    $this->assertSession()->pageTextContains('0 of 2 required steps read');
    $attribute = $this->assertSession()->elementExists('css', '[data-actools-completed-steps]')->getAttribute('data-actools-completed-steps');
    $this->assertSame([], json_decode($attribute, TRUE, 512, JSON_THROW_ON_ERROR));
    $this->assertNull($this->record((int) $bob->id(), (int) $lesson->id()));
    $this->drupalGet(clone $chapter_url);
    $this->assertSession()->pageTextNotContains('Continue learning: Surface forces and pressure');
    $this->drupalLogout();

    $this->drupalLogin($alice);
    $this->drupalGet(clone $url);
    $this->assertSession()->pageTextContains('2 of 2 required steps read');
    $this->submitForm(['confirm_reset' => TRUE], 'Reset this lesson’s reading progress');
    $record = $this->record((int) $alice->id(), (int) $lesson->id());
    $this->assertSame([], $record['completed']);
    $this->assertNull($record['position']);
  }

  public function testStaleVersionAndInvalidCsrfDoNotApproveContent(): void {
    [$lesson, $a] = $this->fixture();
    $reader = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($reader);
    $url = Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]);
    $this->drupalGet(clone $url, ['query' => ['step' => $a]]);
    // Keep the browser's old form open while a new public version is saved.
    $values = $lesson->get('field_actools_lesson_steps')->getValue();
    $values[0]['content_version'] = 2;
    $lesson->setNewRevision(TRUE);
    $lesson->set('field_actools_lesson_steps', $values)->save();
    $this->submitForm([], 'Mark this step as read');
    $this->assertSession()->pageTextContains('This step has changed');
    $this->assertNull($this->record((int) $reader->id(), (int) $lesson->id()));

    $this->drupalGet(clone $url, ['query' => ['step' => $a]]);
    $this->assertSession()->elementExists('css', 'input[name="form_token"]')->setValue('invalid-reading-csrf-token');
    $this->submitForm([], 'Mark this step as read');
    $this->assertNull($this->record((int) $reader->id(), (int) $lesson->id()));
    $this->drupalGet(clone $url, ['query' => ['step' => $a]]);
    $this->submitForm([], 'Mark this step as read');
    $this->assertSame(2, $this->record((int) $reader->id(), (int) $lesson->id())['completed'][$a]['content_version']);
  }

  public function testReadAllRequiresExplicitSelectionAndOptionalMeterUsesRealCounts(): void {
    [$lesson, $a, $b] = $this->fixture(TRUE);
    $reader = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($reader);
    $url = Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]);
    $this->drupalGet(clone $url, ['query' => ['view' => 'all']]);
    $this->assertSession()->pageTextContains('0 of 2 optional steps read');
    $this->assertMeter('0', '2');
    $this->assertSession()->elementsCount('css', '.actools-progress__summary', 1);
    $this->assertSession()->elementExists('css', '.actools-progress details[open]');
    $this->assertSession()->elementNotExists('css', '.actools-progress__primary');
    $this->assertSession()->elementNotExists('css', '[data-actools-progress-action="complete"]');
    $this->assertSession()->elementNotExists('css', '[data-actools-progress-action="complete_continue"]');
    $this->assertSession()->elementNotExists('css', '[data-actools-progress-action="position"]');
    $this->assertSession()->pageTextContains('Reading step 1 — not marked (optional)');
    $this->assertSession()->pageTextContains('Reading step 2 — not marked (optional)');

    // Finishing the continuous document does not imply that its first step was
    // chosen: a real explicit selection is required and only that UUID changes.
    $this->submitForm([], 'Mark selected steps as read');
    $this->assertSession()->pageTextContains('Choose at least one step.');
    $this->assertNull($this->record((int) $reader->id(), (int) $lesson->id()));
    $this->submitForm(['selected[' . $b . ']' => TRUE], 'Mark selected steps as read');
    $record = $this->record((int) $reader->id(), (int) $lesson->id());
    $this->assertSame([$b], array_keys($record['completed']));
    $this->assertArrayNotHasKey($a, $record['completed']);
    $this->assertSession()->pageTextContains('1 of 2 optional steps read');
    $this->assertMeter('1', '2');

    // Updated content is not counted as read; rendering remains read-only.
    $values = $lesson->get('field_actools_lesson_steps')->getValue();
    $values[1]['content_version'] = 2;
    $lesson->setNewRevision(TRUE);
    $lesson->set('field_actools_lesson_steps', $values)->save();
    $this->drupalGet(clone $url, ['query' => ['view' => 'all']]);
    $this->assertSession()->pageTextContains('0 of 2 optional steps read');
    $this->assertSession()->pageTextContains('Reading step 2 — revisit (optional)');
    $this->assertMeter('0', '2');
    $this->assertSame($record, $this->record((int) $reader->id(), (int) $lesson->id()));
  }

  private function assertMeter(string $value, string $max): void {
    $meter = $this->assertSession()->elementExists('css', 'progress.actools-progress__meter');
    $this->assertSame($value, $meter->getAttribute('value'));
    $this->assertSame($max, $meter->getAttribute('max'));
    $label_id = $this->assertSession()->elementExists('css', '.actools-progress__count')->getAttribute('id');
    $this->assertNotEmpty($label_id);
    $this->assertSame($label_id, $meter->getAttribute('aria-labelledby'));
  }

  private function record(int $uid, int $nid): mixed {
    return $this->container->get('user.data')->get('actools_lesson', $uid, 'lesson:' . $nid);
  }

  private function fixture(bool $optional = FALSE): array {
    $uuid = $this->container->get('uuid');
    $ids = [$uuid->generate(), $uuid->generate()];
    $steps = [];
    foreach ($ids as $delta => $id) {
      $steps[] = [
        'step_id' => $id,
        'title' => 'Reading step ' . ($delta + 1),
        'optional' => $optional,
        'reviewed' => TRUE,
        'content_version' => 1,
        'components' => json_encode([[
          'id' => $uuid->generate(),
          'type' => 'text',
          'body' => ['value' => 'The scientific explanation for step ' . ($delta + 1), 'format' => 'plain_text'],
        ]], JSON_THROW_ON_ERROR),
      ];
    }
    $lesson = Node::create([
      'type' => 'actools_resource',
      'title' => 'Surface forces and pressure',
      'status' => 0,
      'field_actools_kind' => 'lesson',
      'field_actools_lesson_managed' => TRUE,
      'field_actools_path' => 'core',
      'field_actools_lesson_steps' => $steps,
    ]);
    $lesson->save();
    Node::create([
      'type' => 'actools_topic',
      'title' => 'Surface tension',
      'status' => 1,
      'field_actools_lesson_chapter' => TRUE,
      'field_actools_lessons' => [['target_id' => $lesson->id()]],
    ])->save();
    $lesson->setPublished()->save();
    return [$lesson, ...$ids];
  }

}
