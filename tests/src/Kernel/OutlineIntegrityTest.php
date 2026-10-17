<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests authoritative outline edits, revisions and invalid membership.
 *
 */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
class OutlineIntegrityTest extends LessonKernelTestBase {

  public function testReorderingUpdatesNavigationWithoutChangingLessonIds(): void {
    $reader = $this->reader();
    $first = $this->lesson('First');
    $second = $this->lesson('Second');
    $third = $this->lesson('Third');
    $chapter = $this->chapter('Chapter', [$first, $second, $third]);
    $chapter->setNewRevision(TRUE);
    $chapter->set('field_actools_lessons', [
      ['target_id' => $third->id()],
      ['target_id' => $first->id()],
      ['target_id' => $second->id()],
    ]);
    $this->assertCount(0, $chapter->validate());
    $chapter->save();
    $document = $this->container->get('actools_lesson.outline')->forLesson($first, $reader);
    $this->assertSame((int) $third->id(), (int) $document['previous']->id());
    $this->assertSame((int) $second->id(), (int) $document['next']->id());
    $this->assertSame((int) $chapter->id(), (int) $this->container->get('actools_lesson.outline')->owner((int) $first->id())->id());
  }

  public function testAnUnpublishedRevisionCannotReplaceThePublicOutline(): void {
    $reader = $this->reader();
    $first = $this->lesson('First public step in chapter');
    $second = $this->lesson('Second public step in chapter');
    $chapter = $this->chapter('Public chapter', [$first, $second]);
    $publicRevision = (int) $chapter->getRevisionId();
    $chapter->setNewRevision(TRUE);
    $chapter->isDefaultRevision(FALSE);
    $chapter->set('field_actools_lessons', [
      ['target_id' => $second->id()],
      ['target_id' => $first->id()],
    ]);
    $chapter->save();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $current = Node::load($chapter->id());
    $this->assertSame($publicRevision, (int) $current->getRevisionId());
    $document = $this->container->get('actools_lesson.outline')->forLesson(Node::load($first->id()), $reader);
    $this->assertNull($document['previous']);
    $this->assertSame((int) $second->id(), (int) $document['next']->id());
  }

  public function testDuplicateAndCrossChapterMembershipAreRejected(): void {
    $this->reader();
    $lesson = $this->lesson('A reusable identity, with one authoritative chapter');
    $chapter = $this->chapter('First chapter', [$lesson]);
    $duplicate = Node::create([
      'type' => 'actools_topic',
      'title' => 'Duplicate chapter',
      'field_actools_lesson_chapter' => TRUE,
      'field_actools_lessons' => [
        ['target_id' => $lesson->id()],
        ['target_id' => $lesson->id()],
      ],
    ]);
    $this->assertGreaterThan(0, $duplicate->validate()->count(), 'A chapter cannot contain duplicate or already-owned lesson references.');
    $duplicate->set('field_actools_lessons', [['target_id' => $lesson->id()]]);
    $this->assertGreaterThan(0, $duplicate->validate()->count(), 'A second chapter cannot silently take ownership.');
    $this->assertSame((int) $chapter->id(), (int) $this->container->get('actools_lesson.outline')->owner((int) $lesson->id())->id());
  }

  public function testLegacySupplementaryCardsCannotBecomeOutlineSteps(): void {
    $this->reader();
    $notes = Node::create([
      'type' => 'actools_resource',
      'title' => 'Legacy notes card',
      'status' => 1,
      'field_actools_kind' => 'notes',
    ]);
    $notes->save();
    $chapter = Node::create([
      'type' => 'actools_topic',
      'title' => 'Chapter outline',
      'field_actools_lesson_chapter' => TRUE,
      'field_actools_lessons' => [['target_id' => $notes->id()]],
    ]);
    $this->assertGreaterThan(0, $chapter->validate()->count());
    $this->assertCount(0, $notes->validate(), 'An unmanaged legacy resource continues using the existing resource workflow.');
  }

  public function testDatabaseRejectsASecondOwnerAfterStaleValidation(): void {
    $this->reader();
    $lesson = $this->lesson('One lesson identity');
    $first = $this->chapter('First owner', [$lesson]);
    $second = $this->chapter('Another chapter');
    // A second writer could have validated before the first writer committed.
    // Exercise the final database guard directly, after that earlier check.
    $second->set('field_actools_lessons', [['target_id' => $lesson->id()]]);
    try {
      $this->container->get('actools_lesson.membership')->synchronize($second);
      $this->fail('Stale validation must not bypass the database uniqueness guard.');
    }
    catch (IntegrityConstraintViolationException) {
      $owner = $this->container->get('database')->select('actools_lesson_membership', 'm')
        ->fields('m', ['chapter_nid'])->condition('lesson_nid', $lesson->id())->execute()->fetchField();
      $this->assertSame((int) $first->id(), (int) $owner);
    }
  }

  public function testPublishingRequiresAReviewedStepAndAnOwner(): void {
    $this->reader();
    $lesson = $this->lesson('Draft before chapter assignment', 'core', FALSE);
    $this->assertCount(0, $lesson->validate(), 'Authors can save a draft before its chapter exists.');
    $lesson->setPublished();
    $this->assertGreaterThan(0, $lesson->validate()->count(), 'Public lessons need an authoritative chapter.');
    $this->chapter('Assigned chapter', [$lesson]);
    $lesson->get('field_actools_lesson_steps')->get(0)->set('reviewed', FALSE);
    $this->assertGreaterThan(0, $lesson->validate()->count(), 'Unreviewed teaching steps cannot be published.');
    $lesson->get('field_actools_lesson_steps')->get(0)->set('reviewed', TRUE);
    $this->assertCount(0, $lesson->validate());
  }

}
