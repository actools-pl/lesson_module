<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Exercises the core revision-restore API with versioned teaching content. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class NativeRevisionRestoreTest extends LessonKernelTestBase {
  public function testNativeRestoreKeepsHistoricalDocumentAndAdvancesContentIdentity(): void {
    $lesson = $this->lesson('Restorable scientific lesson');
    $this->chapter('Revision chapter', [$lesson]);
    $historical_id = $lesson->getRevisionId();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $lesson = $storage->loadUnchanged($lesson->id());
    $row = $lesson->get('field_actools_lesson_steps')->first()->getValue();
    $components = json_decode($row['components'], TRUE, 32, JSON_THROW_ON_ERROR);
    $components[0]['body']['value'] = 'A later corrected explanation.';
    $row['components'] = json_encode($components, JSON_THROW_ON_ERROR);
    $row['content_version'] = 2;
    $lesson->set('field_actools_lesson_steps', [$row]);
    $lesson->setNewRevision(TRUE);
    $lesson->save();
    $historical = $storage->loadRevision($historical_id);
    // This is the same API used by NodeRevisionRevertForm.
    $restored = $storage->createRevision($historical);
    $restored->save();
    $current = $storage->loadUnchanged($lesson->id());
    $current_row = $current->get('field_actools_lesson_steps')->first()->getValue();
    $restored_components = json_decode($current_row['components'], TRUE, 32, JSON_THROW_ON_ERROR);
    $this->assertSame('A lesson explanation.', $restored_components[0]['body']['value']);
    $this->assertSame($row['step_id'], $current_row['step_id']);
    $this->assertGreaterThan(2, (int) $current_row['content_version']);
    $this->assertSame(1, (int) $storage->loadRevision($historical_id)->get('field_actools_lesson_steps')->content_version);
  }
}
