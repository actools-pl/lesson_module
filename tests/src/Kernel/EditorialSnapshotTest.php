<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Verifies signed editor identity and in-place concurrent change detection. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class EditorialSnapshotTest extends LessonKernelTestBase {

  public function testSnapshotIsBoundToAccountAndLesson(): void {
    $first = $this->reader();
    $second = $this->reader();
    $this->container->get('current_user')->setAccount($first);
    $lesson = $this->lesson('First draft', 'core', FALSE);
    $other = $this->lesson('Another draft', 'core', FALSE);
    $editorial = $this->container->get('actools_lesson.editorial');
    $token = $editorial->token($lesson);
    $snapshot = $editorial->decodeToken($token, $lesson);
    $this->assertNotNull($snapshot);
    $this->assertTrue($editorial->isFresh($lesson, $snapshot));
    $this->assertNull($editorial->decodeToken($token, $other));
    $this->assertNull($editorial->decodeToken($token . 'modified', $lesson));
    $this->container->get('current_user')->setAccount($second);
    $this->assertNull($editorial->decodeToken($token, $lesson));
  }

  public function testSameRevisionInPlaceChangeInvalidatesTheSnapshot(): void {
    $this->reader();
    $lesson = $this->lesson('The first saved draft', 'core', FALSE);
    $editorial = $this->container->get('actools_lesson.editorial');
    $snapshot = $editorial->decodeToken($editorial->token($lesson), $lesson);
    $revision = (int) $lesson->getRevisionId();
    $lesson->setNewRevision(FALSE);
    $lesson->setTitle('An API write without a new revision');
    $lesson->save();
    $this->assertSame($revision, (int) $lesson->getRevisionId());
    $this->assertFalse($editorial->isFresh($lesson, $snapshot), 'The digest detects writes even if revision ID and second-resolution timestamps do not change.');
  }

}
