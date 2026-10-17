<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\file\Entity\File;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Retained revision history prevents reuse of a removed step's read identity. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class RemovedStepVersionHistoryTest extends LessonKernelTestBase {

  public function testRemovedReadVersionRestoredFromVersionOneRequiresRevisit(): void {
    [$lesson, $old_id, $original, $anchor] = $this->fixture();
    $reader = $this->reader();
    $this->container->get('current_user')->setAccount($reader);
    $store = $this->container->get('actools_lesson.progress');
    $uuid = $original['step_id'];
    $store->mutate($lesson, $reader, 'complete', [$uuid], $uuid, [$uuid => 2]);
    $record = $this->container->get('user.data')->get('actools_lesson', $reader->id(), 'lesson:' . $lesson->id());
    $lesson = $this->saveSteps($lesson, [$anchor]);
    $this->assertSame([], $store->snapshot($lesson, $reader)['completed']);
    $lesson = $this->restore($lesson, $old_id);
    $row = $this->row($lesson, $uuid);
    $this->assertSame($original['components'], $row['components']);
    $this->assertGreaterThan(2, (int) $row['content_version']);
    $state = $store->snapshot($lesson, $reader);
    $this->assertArrayNotHasKey($uuid, $state['completed']);
    $this->assertSame([$uuid => TRUE], $state['needs_review']);
    $this->assertSame($record, $this->container->get('user.data')->get('actools_lesson', $reader->id(), 'lesson:' . $lesson->id()));
    $historical = $this->storage()->loadRevisionUnchanged($old_id);
    $this->assertSame(1, (int) $this->row($historical, $uuid)['content_version']);
  }

  public function testOrdinaryReintroductionAllocatesAboveAllRetainedVersions(): void {
    [$lesson, , $original, $anchor] = $this->fixture();
    $uuid = $original['step_id'];
    $lesson = $this->saveSteps($lesson, [$anchor]);
    $lesson = $this->saveSteps($lesson, [$anchor, $original]);
    $this->assertGreaterThan(2, (int) $this->row($lesson, $uuid)['content_version']);
    $first_reintroduced_version = (int) $this->row($lesson, $uuid)['content_version'];
    $lesson = $this->saveSteps($lesson, [$anchor]);
    $original['content_version'] = 2;
    $lesson = $this->saveSteps($lesson, [$original, $anchor]);
    $this->assertGreaterThan($first_reintroduced_version, (int) $this->row($lesson, $uuid)['content_version']);
    $this->assertSame($original['components'], $this->row($lesson, $uuid)['components']);
  }

  public function testSameContentRestoreAndReorderingPreserveCurrentIdentity(): void {
    [$lesson, $old_id, $original, $anchor] = $this->fixture();
    $lesson = $this->restore($lesson, $old_id);
    $uuid = $original['step_id'];
    $version = (int) $this->row($lesson, $uuid)['content_version'];
    $this->assertGreaterThan(2, $version);
    $lesson = $this->saveSteps($lesson, [$anchor, $this->row($lesson, $uuid)]);
    $lesson = $this->restore($lesson, $old_id);
    $this->assertSame($version, (int) $this->row($lesson, $uuid)['content_version']);
    $this->assertSame($uuid, $lesson->get('field_actools_lesson_steps')->first()->step_id);
  }

  public function testReintroductionIncludesPendingHistoryButIsScopedToLessonAndUuid(): void {
    [$lesson, , $original, $anchor] = $this->fixture();
    $uuid = $original['step_id'];
    // A retained non-default draft is still a previously used step identity.
    $draft = $this->storage()->createRevision($lesson, FALSE);
    $draft->setUnpublished();
    $draft->set('field_actools_lesson_steps', [$this->changeText($original, 'Pending version seven.', 7), $anchor]);
    $draft->save();
    $draft = $this->storage()->loadRevisionUnchanged($draft->getRevisionId());
    $draft->save();
    $this->assertSame(7, (int) $this->row($draft, $uuid)['content_version']);
    // Neither another UUID nor another lesson's use of this UUID is its max.
    $other = $this->lesson('An unrelated lesson');
    $other_row = $original;
    $other_row['content_version'] = 99;
    $other->set('field_actools_lesson_steps', [$other_row]);
    $this->chapter('An unrelated chapter', [$other]);
    $anchor['content_version'] = 100;
    $lesson = $this->saveSteps($lesson, [$anchor]);
    $lesson = $this->saveSteps($lesson, [$original, $anchor]);
    $this->assertSame(8, (int) $this->row($lesson, $uuid)['content_version']);
    $this->assertSame(99, (int) $this->row($this->storage()->loadUnchanged($other->id()), $uuid)['content_version']);
  }

  public function testModifiedHistoricalSourceAfterRemovalCannotReuseAnOldVersion(): void {
    [$lesson, $old_id, $original, $anchor] = $this->fixture();
    $lesson = $this->saveSteps($lesson, [$anchor]);
    $historical = $this->storage()->loadRevisionUnchanged($old_id);
    $rows = $historical->get('field_actools_lesson_steps')->getValue();
    $rows[0] = $this->changeText($original, 'A forged new passage reusing version one.', 1);
    $historical->set('field_actools_lesson_steps', $rows);
    $revision = $this->storage()->createRevision($historical);
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('newer content version');
    $revision->save();
  }

  public function testHistoricalFiguresRemainRetainedThroughRemovalAndRestore(): void {
    $filesystem = $this->container->get('file_system');
    $directory = 'public://history-test';
    $filesystem->prepareDirectory($directory, 1);
    $uri = $filesystem->saveData(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII='), $directory . '/original.png');
    $file = File::create(['uri' => $uri, 'filename' => 'original.png', 'filemime' => 'image/png', 'status' => 1, 'uid' => 1]);
    $file->save();
    $figure = $this->step(['components' => json_encode([[
      'id' => $this->container->get('uuid')->generate(), 'type' => 'figure',
      'fid' => (int) $file->id(), 'alt' => 'The original scientific figure',
    ]], JSON_THROW_ON_ERROR)]);
    $lesson = $this->lesson('Historical diagram');
    $anchor = $lesson->get('field_actools_lesson_steps')->first()->getValue();
    $lesson->set('field_actools_lesson_steps', [$figure, $anchor]);
    $this->chapter('Figure chapter', [$lesson]);
    $old_id = (int) $lesson->getRevisionId();
    $lesson = $this->saveSteps($lesson, [$anchor]);
    $usage = $this->container->get('file.usage');
    $this->assertSame(1, (int) $usage->listUsage($file)['actools_lesson']['node'][$lesson->id()]);
    $lesson = $this->restore($lesson, $old_id);
    $row = $this->row($lesson, $figure['step_id']);
    $this->assertGreaterThan(1, (int) $row['content_version']);
    $this->assertSame((int) $file->id(), json_decode($row['components'], TRUE)[0]['fid']);
    $this->assertSame(1, (int) $usage->listUsage($file)['actools_lesson']['node'][$lesson->id()]);
    $this->assertTrue(File::load($file->id())->isPermanent());
    $this->assertSame($figure['components'], $this->row($this->storage()->loadRevisionUnchanged($old_id), $figure['step_id'])['components']);
  }

  /** Version one and a second valid step, followed by changed content at v2. */
  private function fixture(): array {
    $lesson = $this->lesson('Reintroduced scientific step');
    $original = $lesson->get('field_actools_lesson_steps')->first()->getValue();
    $anchor = $this->step(['title' => 'A step retained throughout']);
    $lesson->set('field_actools_lesson_steps', [$original, $anchor]);
    $this->chapter('Version history chapter', [$lesson]);
    $old_id = (int) $lesson->getRevisionId();
    $lesson = $this->saveSteps($lesson, [$this->changeText($original, 'A corrected version two explanation.', 2), $anchor]);
    return [$lesson, $old_id, $original, $anchor];
  }

  private function changeText(array $row, string $text, int $version): array {
    $components = json_decode($row['components'], TRUE, 32, JSON_THROW_ON_ERROR);
    $components[0]['body']['value'] = $text;
    $row['components'] = json_encode($components, JSON_THROW_ON_ERROR);
    $row['content_version'] = $version;
    return $row;
  }

  private function saveSteps(NodeInterface $lesson, array $rows): NodeInterface {
    $lesson = $this->storage()->loadUnchanged($lesson->id());
    $lesson->setNewRevision(TRUE);
    $lesson->set('field_actools_lesson_steps', $rows)->save();
    return $this->storage()->loadUnchanged($lesson->id());
  }

  private function restore(NodeInterface $lesson, int $revision_id): NodeInterface {
    $restored = $this->storage()->createRevision($this->storage()->loadRevisionUnchanged($revision_id));
    $restored->save();
    return $this->storage()->loadUnchanged($lesson->id());
  }

  private function row(NodeInterface $lesson, string $uuid): array {
    foreach ($lesson->get('field_actools_lesson_steps')->getValue() as $row) {
      if ($row['step_id'] === $uuid) {
        return $row;
      }
    }
    throw new \LogicException('The expected stable step was not restored.');
  }

  private function storage() {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

}
