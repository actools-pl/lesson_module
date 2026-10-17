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

/**
 * Exercises the real Drupal routes without requiring JavaScript.
 *
 */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
class LessonDeliveryTest extends BrowserTestBase {

  protected static $modules = ['actools_lesson', 'page_cache', 'dynamic_page_cache', 'actools_lesson_test'];

  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('access content')->save();
  }

  public function testGuidedReadAllAndPrintDeliverTheSameStoredLesson(): void {
    [$lesson, $stepIds] = $this->createLesson();
    $reader = Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]);
    $this->drupalGet(clone $reader);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Force acts tangentially to the liquid interface.');
    $this->assertSession()->pageTextNotContains('Adding a second interface doubles the length contribution.');
    $this->drupalGet(clone $reader, ['query' => ['step' => $stepIds[1]]]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Adding a second interface doubles the length contribution.');
    $this->assertSession()->pageTextNotContains('Force acts tangentially to the liquid interface.');
    $this->drupalGet(clone $reader, ['query' => ['view' => 'all']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Force acts tangentially to the liquid interface.');
    $this->assertSession()->pageTextContains('Adding a second interface doubles the length contribution.');
    $this->drupalGet(Url::fromRoute('actools_lesson.print', ['node' => $lesson->id()]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Force acts tangentially to the liquid interface.');
    $this->assertSession()->pageTextContains('Adding a second interface doubles the length contribution.');
  }

  public function testPublicReaderDoesNotAcceptAnArbitraryRevision(): void {
    [$lesson] = $this->createLesson();
    $lesson->setNewRevision(TRUE);
    $lesson->isDefaultRevision(FALSE);
    $lesson->setTitle('PRIVATE UNREVIEWED DRAFT TITLE');
    $steps = $lesson->get('field_actools_lesson_steps')->getValue();
    $components = json_decode($steps[0]['components'], TRUE, 512, JSON_THROW_ON_ERROR);
    $components[0]['body']['value'] = 'PRIVATE UNREVIEWED DRAFT BODY';
    $steps[0]['components'] = json_encode($components, JSON_THROW_ON_ERROR);
    $steps[0]['content_version']++;
    $lesson->set('field_actools_lesson_steps', $steps);
    $lesson->save();
    $this->drupalGet(Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]), [
      'query' => ['revision' => $lesson->getRevisionId(), 'vid' => $lesson->getRevisionId(), 'preview' => 1],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Force acts tangentially to the liquid interface.');
    $this->assertSession()->pageTextNotContains('PRIVATE UNREVIEWED DRAFT TITLE');
    $this->assertSession()->pageTextNotContains('PRIVATE UNREVIEWED DRAFT BODY');
  }

  public function testCanonicalReaderKeepsStepAndViewCacheContexts(): void {
    [$lesson, $stepIds] = $this->createLesson();
    $canonical = $lesson->toUrl();
    // Repeat requests so a correctly implemented reader must survive warm
    // render/page caches, not merely render the right uncached document once.
    for ($round = 0; $round < 2; $round++) {
      $this->drupalGet(clone $canonical);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('Force acts tangentially to the liquid interface.');
      $this->assertSession()->pageTextNotContains('Adding a second interface doubles the length contribution.');
      $this->drupalGet(clone $canonical, ['query' => ['step' => $stepIds[1]]]);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('Adding a second interface doubles the length contribution.');
      $this->assertSession()->pageTextNotContains('Force acts tangentially to the liquid interface.');
      $this->drupalGet(clone $canonical, ['query' => ['view' => 'all']]);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('Force acts tangentially to the liquid interface.');
      $this->assertSession()->pageTextContains('Adding a second interface doubles the length contribution.');
    }
  }

  public function testNativeRevisionViewRetainsTheAuthorizedHistoricalDocument(): void {
    [$lesson] = $this->createLesson();
    $historical_revision = $lesson->getRevisionId();
    $steps = $lesson->get('field_actools_lesson_steps')->getValue();
    $components = json_decode($steps[0]['components'], TRUE, 512, JSON_THROW_ON_ERROR);
    $components[0]['body']['value'] = 'The current public explanation has been revised.';
    $steps[0]['components'] = json_encode($components, JSON_THROW_ON_ERROR);
    $steps[0]['content_version']++;
    $lesson->setNewRevision(TRUE);
    $lesson->set('field_actools_lesson_steps', $steps)->save();
    $history = Url::fromRoute('entity.node.revision', [
      'node' => $lesson->id(),
      'node_revision' => $historical_revision,
    ]);
    $this->drupalGet(clone $history);
    $this->assertSession()->statusCodeEquals(403);
    // Viewing editorial history requires revision permission, not edit access.
    $reviewer = $this->drupalCreateUser(['access content', 'view all revisions']);
    $this->drupalLogin($reviewer);
    $this->drupalGet(clone $history);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Private revision preview');
    $this->assertSession()->pageTextContains('Force acts tangentially to the liquid interface.');
    $this->assertSession()->pageTextNotContains('The current public explanation has been revised.');
    $this->assertSession()->elementNotExists('css', '.actools-progress-form');
    $this->drupalGet(Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]), [
      'query' => ['revision' => $historical_revision],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The current public explanation has been revised.');
    $this->assertSession()->pageTextNotContains('Private revision preview');
    $this->drupalLogout();
    $this->drupalGet(clone $history);
    $this->assertSession()->statusCodeEquals(403);
  }

  public function testUnmanagedAndUnpublishedContentCannotUseTheReader(): void {
    $legacy = Node::create([
      'type' => 'actools_resource',
      'title' => 'Legacy resource with lesson kind',
      'status' => 1,
      'field_actools_kind' => 'lesson',
    ]);
    $legacy->save();
    $this->drupalGet(Url::fromRoute('actools_lesson.reader', ['node' => $legacy->id()]));
    $this->assertSession()->statusCodeEquals(403);
    [$lesson] = $this->createLesson();
    $lesson->setUnpublished()->save();
    $this->drupalGet(Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]));
    $this->assertSession()->statusCodeEquals(403);
  }

  public function testComponentRichTextUsesDrupalFiltering(): void {
    [$lesson] = $this->createLesson();
    $steps = $lesson->get('field_actools_lesson_steps')->getValue();
    $components = json_decode($steps[0]['components'], TRUE, 512, JSON_THROW_ON_ERROR);
    $components[0]['body'] = [
      'format' => 'actools_lesson_text',
      'value' => '<p>Safe scientific explanation.</p><script data-actools-test="unsafe">window.actoolsUnsafe = true;</script><img src="x" onerror="window.actoolsUnsafe = true">',
    ];
    $steps[0]['components'] = json_encode($components, JSON_THROW_ON_ERROR);
    $steps[0]['content_version']++;
    $editor = $this->drupalCreateUser(['access content', 'use text format actools_lesson_text']);
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($editor);
    try {
      $lesson->set('field_actools_lesson_steps', $steps)->save();
    }
    finally {
      $switcher->switchBack();
    }
    $this->drupalGet(Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Safe scientific explanation.');
    $this->assertSession()->elementNotExists('css', 'script[data-actools-test]');
    $this->assertSession()->elementNotExists('css', '[onerror]');
  }

  public function testQueryValidationAndWithdrawnStepNotice(): void {
    [$lesson] = $this->createLesson();
    $reader = Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]);
    foreach ([['view' => ['all']], ['step' => ['not-a-step']], ['view' => 'preview']] as $query) {
      $this->drupalGet(clone $reader, ['query' => $query]);
      $this->assertSession()->statusCodeEquals(400);
    }
    $this->drupalGet(clone $reader, ['query' => ['step' => $this->container->get('uuid')->generate()]]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('That step is no longer available.');
    $this->assertSession()->pageTextContains('Force acts tangentially to the liquid interface.');
  }

  public function testPrintIncludesEveryAnswerWithOpenDisclosures(): void {
    [$lesson] = $this->createLesson();
    $steps = $lesson->get('field_actools_lesson_steps')->getValue();
    foreach ($steps as $index => &$step) {
      $components = json_decode($step['components'], TRUE, 512, JSON_THROW_ON_ERROR);
      $components[0]['answer'] = ['value' => 'Reviewed answer ' . $index, 'format' => 'plain_text'];
      $step['components'] = json_encode($components, JSON_THROW_ON_ERROR);
      $step['content_version']++;
    }
    unset($step);
    $lesson->set('field_actools_lesson_steps', $steps)->save();
    $this->drupalGet(Url::fromRoute('actools_lesson.print', ['node' => $lesson->id()]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementsCount('css', '[data-actools-reader]', 1);
    $this->assertSession()->elementsCount('css', '.actools-answer--answer[open]', 2);
    $this->assertSession()->pageTextContains('Reviewed answer 0');
    $this->assertSession()->pageTextContains('Reviewed answer 1');
  }

  public function testIntroductionAppearsOnceAtTheStartAndInCompleteViews(): void {
    [$lesson, $stepIds] = $this->createLesson();
    $lesson->set('field_actools_body', ['value' => 'One scientific introduction to the complete lesson.', 'format' => 'plain_text'])->save();
    $reader = Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]);
    $this->drupalGet(clone $reader);
    $this->assertSession()->pageTextContains('One scientific introduction to the complete lesson.');
    $this->assertSession()->elementsCount('css', '.actools-lesson__introduction', 1);
    $this->drupalGet(clone $reader, ['query' => ['step' => $stepIds[1]]]);
    $this->assertSession()->pageTextNotContains('One scientific introduction to the complete lesson.');
    $this->drupalGet(clone $reader, ['query' => ['view' => 'all']]);
    $this->assertSession()->elementsCount('css', '.actools-lesson__introduction', 1);
    $this->assertSession()->elementsCount('css', '[data-actools-reader]', 1);
    $this->drupalGet(Url::fromRoute('actools_lesson.print', ['node' => $lesson->id()]));
    $this->assertSession()->pageTextContains('One scientific introduction to the complete lesson.');
    $this->assertSession()->elementsCount('css', '.actools-lesson__introduction', 1);
  }

  public function testFieldDenialInvalidatesWarmReaderWithoutEmptyOrientation(): void {
    [$lesson] = $this->createLesson();
    $lesson->set('field_actools_summary', 'A summary that must disappear after field denial.')->save();
    $reader = Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]);
    $this->drupalGet(clone $reader);
    $this->drupalGet(clone $reader);
    $this->assertSession()->pageTextContains('A summary that must disappear after field denial.');
    $this->container->get('state')->set('actools_lesson_test.hidden_fields', ['field_actools_summary']);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['actools_lesson_test:hidden_fields']);
    $this->drupalGet(clone $reader);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('A summary that must disappear after field denial.');
    $this->assertSession()->elementNotExists('css', '.actools-lesson__summary');
    $this->assertSession()->elementNotExists('css', '.actools-lesson__orientation');
    $this->container->get('state')->set('actools_lesson_test.hidden_fields', []);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['actools_lesson_test:hidden_fields']);
    $this->drupalGet(clone $reader);
    $this->assertSession()->pageTextContains('A summary that must disappear after field denial.');
  }

  public function testLearningPathOrientationHonorsFieldAccessOnWarmReaders(): void {
    [$core] = $this->createLesson();
    $this->drupalGet(Url::fromRoute('actools_lesson.reader', ['node' => $core->id()]));
    $this->assertSession()->pageTextContains('Core lesson 1 of 1');
    $this->assertSession()->elementTextContains('css', '.actools-chapter-nav > summary', 'Core lessons');

    [$extension] = $this->createLesson('extension');
    $reader = Url::fromRoute('actools_lesson.reader', ['node' => $extension->id()]);
    $this->drupalGet(clone $reader);
    $this->drupalGet(clone $reader);
    $this->assertSession()->pageTextContains('Extension lesson 1 of 1');
    $this->assertSession()->elementTextContains('css', '.actools-chapter-nav > summary', 'Optional extensions');

    $this->container->get('state')->set('actools_lesson_test.hidden_fields', ['field_actools_path']);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['actools_lesson_test:hidden_fields']);
    $this->drupalGet(clone $reader);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Extension lesson 1 of 1');
    $this->assertSession()->pageTextNotContains('Optional extension');
    $this->container->get('state')->set('actools_lesson_test.hidden_fields', []);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['actools_lesson_test:hidden_fields']);
    $this->drupalGet(clone $reader);
    $this->assertSession()->pageTextContains('Extension lesson 1 of 1');
    $this->assertSession()->elementTextContains('css', '.actools-chapter-nav > summary', 'Optional extensions');
  }

  public function testLessonOrientationUsesSafeListsAndHonorsFieldDenial(): void {
    [$lesson] = $this->createLesson();
    $lesson->set('field_actools_objectives', "Identify <strong>interfaces</strong>.\n\nExplain the force direction.");
    $lesson->set('field_actools_prerequisites', "Resolve forces.\r\nUse SI units.")->save();
    $reader = Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()]);
    $this->drupalGet(clone $reader);
    $this->drupalGet(clone $reader);
    $this->assertSession()->elementsCount('css', '.actools-lesson__orientation section ul', 2);
    $this->assertSession()->elementsCount('css', '.actools-lesson__orientation section li', 4);
    $this->assertSession()->pageTextContains('Identify <strong>interfaces</strong>.');
    $this->assertSession()->elementNotExists('css', '.actools-lesson__orientation strong');

    $this->container->get('state')->set('actools_lesson_test.hidden_fields', ['field_actools_objectives', 'field_actools_prerequisites']);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['actools_lesson_test:hidden_fields']);
    $this->drupalGet(clone $reader);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Explain the force direction.');
    $this->assertSession()->pageTextNotContains('Resolve forces.');
    $this->assertSession()->elementNotExists('css', '.actools-lesson__orientation');

    $this->container->get('state')->set('actools_lesson_test.hidden_fields', []);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['actools_lesson_test:hidden_fields']);
    $this->drupalGet(clone $reader, ['query' => ['view' => 'all']]);
    $this->assertSession()->elementsCount('css', '.actools-lesson__orientation section li', 4);
    $this->drupalGet(Url::fromRoute('actools_lesson.print', ['node' => $lesson->id()]));
    $this->assertSession()->elementsCount('css', '.actools-lesson__orientation section li', 4);
  }

  public function testReadAllKeepsPreviousLessonWhenOpenedAtALaterStep(): void {
    [$first, $stepIds] = $this->createLesson();
    $second = $first->createDuplicate();
    $second->setTitle('The next surface tension lesson')->setUnpublished()->save();
    $chapter = $this->container->get('actools_lesson.outline')->owner((int) $first->id());
    $chapter->get('field_actools_lessons')->appendItem(['target_id' => $second->id()]);
    $chapter->save();
    $second->setPublished()->save();
    $this->drupalGet(Url::fromRoute('actools_lesson.reader', ['node' => $second->id()]), [
      'query' => ['view' => 'all', 'step' => $stepIds[1]],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementTextContains('css', '.actools-lesson__chapter-actions', 'Previous lesson: The tension in a liquid interface');
  }

  /**
   * Builds one real node revision and its authoritative chapter outline.
   */
  private function createLesson(string $path = 'core'): array {
    $uuid = $this->container->get('uuid');
    $stepIds = [$uuid->generate(), $uuid->generate()];
    $bodies = [
      'Force acts tangentially to the liquid interface.',
      'Adding a second interface doubles the length contribution.',
    ];
    $steps = [];
    foreach ($stepIds as $delta => $stepId) {
      $steps[] = [
        'step_id' => $stepId,
        'title' => 'Physical model ' . ($delta + 1),
        'optional' => FALSE,
        'reviewed' => TRUE,
        'content_version' => 1,
        'components' => json_encode([[
          'id' => $uuid->generate(),
          'type' => 'text',
          'body' => ['value' => $bodies[$delta], 'format' => 'plain_text'],
        ]], JSON_THROW_ON_ERROR),
      ];
    }
    $lesson = Node::create([
      'type' => 'actools_resource',
      'title' => 'The tension in a liquid interface',
      'status' => 0,
      'field_actools_kind' => 'lesson',
      'field_actools_lesson_managed' => TRUE,
      'field_actools_path' => $path,
      'field_actools_lesson_steps' => $steps,
    ]);
    $lesson->save();
    Node::create([
      'type' => 'actools_topic',
      'title' => 'Surface Tension',
      'status' => 1,
      'field_actools_lesson_chapter' => TRUE,
      'field_actools_lessons' => [['target_id' => $lesson->id()]],
    ])->save();
    $lesson->setPublished()->save();
    return [$lesson, $stepIds];
  }

}
