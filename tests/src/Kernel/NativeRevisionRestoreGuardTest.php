<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** A modified historical source is not an authorized unchanged restoration. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class NativeRevisionRestoreGuardTest extends LessonKernelTestBase {

  public function testModifiedHistoricalSourceCannotBypassVersionValidation(): void {
    $lesson = $this->lesson('Restore guard lesson');
    $this->chapter('Restore guard chapter', [$lesson]);
    $old_revision = $lesson->getRevisionId();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $lesson = $storage->loadUnchanged($lesson->id());
    $row = $lesson->get('field_actools_lesson_steps')->first()->getValue();
    $components = json_decode($row['components'], TRUE, 32, JSON_THROW_ON_ERROR);
    $components[0]['body']['value'] = 'Corrected version two.';
    $row['components'] = json_encode($components, JSON_THROW_ON_ERROR);
    $row['content_version'] = 2;
    $lesson->set('field_actools_lesson_steps', [$row]);
    $lesson->setNewRevision(TRUE);
    $lesson->save();
    $historical = $storage->loadRevisionUnchanged($old_revision);
    $forged = $historical->get('field_actools_lesson_steps')->first()->getValue();
    $components = json_decode($forged['components'], TRUE, 32, JSON_THROW_ON_ERROR);
    $components[0]['body']['value'] = 'A new edit masquerading as old version one.';
    $forged['components'] = json_encode($components, JSON_THROW_ON_ERROR);
    $historical->set('field_actools_lesson_steps', [$forged]);
    $revision = $storage->createRevision($historical);
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('newer content version');
    $revision->save();
  }

}
