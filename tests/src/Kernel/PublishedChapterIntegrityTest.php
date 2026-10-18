<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Published lessons must retain their default authoritative chapter. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class PublishedChapterIntegrityTest extends LessonKernelTestBase {

  private const MESSAGE = 'Unpublish the affected lessons before removing them from a chapter, turning off its chapter role, or deleting it.';

  public function testDefaultDetachFlagOffAndDeleteFailBeforeChangingStoredOutline(): void {
    $reader = $this->reader();
    foreach (['detach', 'flag_off', 'delete'] as $operation) {
      $first = $this->lesson('PRIVATE first ' . $operation);
      $second = $this->lesson('PRIVATE second ' . $operation);
      $chapter = $this->chapter('Authoritative chapter ' . $operation, [$first, $second]);
      $revision = (int) $chapter->getRevisionId();
      $before = $this->storage()->loadUnchanged($chapter->id())->get('field_actools_lessons')->getValue();
      $outline = $this->container->get('actools_lesson.outline');
      $this->assertSame((int) $second->id(), (int) $outline->forLesson($first, $reader)['next']->id());
      if ($operation !== 'delete') {
        $chapter->setNewRevision(TRUE);
        $chapter->set($operation === 'detach' ? 'field_actools_lessons' : 'field_actools_lesson_chapter', $operation === 'detach' ? [['target_id' => $second->id()]] : FALSE);
        $errors = (string) $chapter->validate();
        $this->assertStringContainsString(self::MESSAGE, $errors);
        $this->assertStringNotContainsString('PRIVATE', $errors);
      }
      try {
        $operation === 'delete' ? $chapter->delete() : $chapter->save();
        $this->fail('Defensive storage checks must reject a published orphan.');
      }
      catch (EntityStorageException $exception) {
        $this->assertStringContainsString(self::MESSAGE, $exception->getMessage());
        $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
      }
      $saved = $this->storage()->loadUnchanged($chapter->id());
      $this->assertSame($revision, (int) $saved->getRevisionId());
      $this->assertSame($before, $saved->get('field_actools_lessons')->getValue());
      $this->assertTrue((bool) $saved->get('field_actools_lesson_chapter')->value);
      $this->assertSame((int) $chapter->id(), (int) $outline->owner((int) $first->id())->id());
      $this->assertSame((int) $second->id(), (int) $outline->forLesson($first, $reader)['next']->id());
      $this->assertCount(2, $this->memberships((int) $chapter->id()));
    }
  }

  public function testPendingChapterDraftCannotAlterDefaultMembershipOrBypassWholeNodeDelete(): void {
    $reader = $this->reader();
    $lesson = $this->lesson('A published child');
    $chapter = $this->chapter('The public chapter', [$lesson]);
    $default_revision = (int) $chapter->getRevisionId();
    $draft = clone $chapter;
    $draft->setNewRevision(TRUE);
    $draft->isDefaultRevision(FALSE);
    $draft->setUnpublished();
    $draft->set('field_actools_lesson_chapter', FALSE);
    $draft->set('field_actools_lessons', []);
    $this->assertCount(0, $draft->validate());
    $draft->save();
    $saved = $this->storage()->loadUnchanged($chapter->id());
    $this->assertSame($default_revision, (int) $saved->getRevisionId());
    $this->assertSame((int) $chapter->id(), (int) $this->container->get('actools_lesson.outline')->forLesson($lesson, $reader)['chapter']->id());
    $this->assertCount(1, $this->memberships((int) $chapter->id()));
    try {
      $draft->delete();
      $this->fail('Deleting a revision object deletes the entire node and must check its saved default.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString(self::MESSAGE, $exception->getMessage());
    }
    $this->assertNotNull($this->storage()->loadUnchanged($chapter->id()));
    $this->assertNotNull($this->storage()->loadRevisionUnchanged($draft->getRevisionId()));
  }

  public function testUnpublishDetachMoveAndRepublishUpdatesAuthoritativeOwner(): void {
    $this->reader();
    $lesson = $this->lesson('A movable lesson');
    $first = $this->chapter('First chapter', [$lesson]);
    $second = $this->chapter('Destination chapter');
    $lesson->setNewRevision(TRUE);
    $lesson->setUnpublished()->save();
    $first->setNewRevision(TRUE);
    $first->set('field_actools_lessons', []);
    $this->assertCount(0, $first->validate());
    $first->save();
    $this->assertNull($this->container->get('actools_lesson.outline')->owner((int) $lesson->id()));
    $second->setNewRevision(TRUE);
    $second->set('field_actools_lessons', [['target_id' => $lesson->id()]]);
    $second->save();
    $lesson->setNewRevision(TRUE);
    $lesson->setPublished()->save();
    $this->assertSame((int) $second->id(), (int) $this->container->get('actools_lesson.outline')->owner((int) $lesson->id())->id());
    $this->assertSame([], $this->memberships((int) $first->id()));
    $this->assertCount(1, $this->memberships((int) $second->id()));
    $first->delete();
    $this->assertNull($this->storage()->loadUnchanged($first->id()));
  }

  public function testFinalSaveRechecksAChildPublishedAfterEarlierValidation(): void {
    $this->reader();
    $lesson = $this->lesson('A newly published child', 'core', FALSE);
    $chapter = $this->chapter('Chapter with a draft child', [$lesson]);
    $stale = clone $chapter;
    $stale->setNewRevision(TRUE);
    $stale->set('field_actools_lessons', []);
    $this->assertCount(0, $stale->validate());
    $lesson->setPublished()->save();
    try {
      $stale->save();
      $this->fail('Earlier validation cannot authorize detaching a now-published child.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString(self::MESSAGE, $exception->getMessage());
    }
    $this->assertSame((int) $chapter->id(), (int) $this->container->get('actools_lesson.outline')->owner((int) $lesson->id())->id());
    $this->assertCount(1, $this->storage()->loadUnchanged($chapter->id())->get('field_actools_lessons'));
  }

  public function testMixedStorageDeleteDoesNotPartiallyDeleteBeforeBlockedChapter(): void {
    $this->reader();
    $empty = $this->chapter('An otherwise deletable chapter');
    $lesson = $this->lesson('Published protected child');
    $protected = $this->chapter('Protected chapter', [$lesson]);
    try {
      $this->storage()->delete([$empty->id() => $empty, $protected->id() => $protected]);
      $this->fail('A mixed deletion must fail without partially deleting the selection.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString(self::MESSAGE, $exception->getMessage());
    }
    $this->assertNotNull($this->storage()->loadUnchanged($empty->id()));
    $this->assertNotNull($this->storage()->loadUnchanged($protected->id()));
    $this->assertCount(1, $this->memberships((int) $protected->id()));
  }

  private function memberships(int $chapter): array {
    return $this->container->get('database')->select('actools_lesson_membership', 'm')->fields('m')->condition('chapter_nid', $chapter)->execute()->fetchAll();
  }

  private function storage() {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

}
