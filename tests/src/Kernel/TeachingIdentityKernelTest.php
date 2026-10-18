<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Database\Database;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Scientific changes and retained history keep their own reading identity. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class TeachingIdentityKernelTest extends LessonKernelTestBase {

  public function testNumericTexChangeRequiresANewVersionAndInvalidatesCompletion(): void {
    [$lesson, $row] = $this->equationLesson();
    $reader = $this->reader();
    $this->container->get('current_user')->setAccount($reader);
    $progress = $this->container->get('actools_lesson.progress');
    $id = $row['step_id'];
    $progress->mutate($lesson, $reader, 'complete', [$id], $id, [$id => 1]);
    $this->assertSame(1, $progress->snapshot($lesson, $reader)['required_complete']);
    $row = $this->changeTex($row, '1e1', 1);
    $lesson->set('field_actools_lesson_steps', [$row]);
    $this->assertStringContainsString('newer content version', (string) $lesson->get('field_actools_lesson_steps')->validate());
    $row['content_version'] = 2;
    $lesson = $this->saveRows($lesson, [$row]);
    $state = $progress->snapshot($lesson, $reader);
    $this->assertSame([], $state['completed']);
    $this->assertSame([$id => TRUE], $state['needs_review']);
    $this->assertSame(2, (int) $lesson->get('field_actools_lesson_steps')->content_version);
  }

  public function testCanonicalObjectOrderingPreservesVersionButListReorderingDoesNot(): void {
    [$lesson, $row] = $this->equationLesson();
    $components = json_decode($row['components'], TRUE, 32, JSON_THROW_ON_ERROR);
    $second = ['id' => $this->container->get('uuid')->generate(), 'type' => 'equation', 'tex' => 'x'];
    $components[] = $second;
    $row['components'] = json_encode($components, JSON_THROW_ON_ERROR);
    $row['content_version'] = 2;
    $lesson = $this->saveRows($lesson, [$row]);
    foreach ($components as &$component) {
      $component = array_reverse($component, TRUE);
    }
    unset($component);
    $row['components'] = json_encode($components, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $lesson->set('field_actools_lesson_steps', [$row]);
    $this->assertCount(0, $lesson->get('field_actools_lesson_steps')->validate());
    $row['components'] = json_encode(array_reverse($components), JSON_THROW_ON_ERROR);
    $lesson->set('field_actools_lesson_steps', [$row]);
    $this->assertStringContainsString('newer content version', (string) $lesson->get('field_actools_lesson_steps')->validate());
  }

  public function testNumericTexHistoricalRestoreAllocatesANewReadingIdentity(): void {
    [$lesson, $row] = $this->equationLesson();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $old_revision = (int) $lesson->getRevisionId();
    $lesson = $this->saveRows($lesson, [$this->changeTex($row, '1e1', 2)]);
    $reader = $this->reader();
    $progress = $this->container->get('actools_lesson.progress');
    $id = $row['step_id'];
    $progress->mutate($lesson, $reader, 'complete', [$id], $id, [$id => 2]);
    $restored = $storage->createRevision($storage->loadRevisionUnchanged($old_revision));
    $restored->save();
    $restored = $storage->loadUnchanged($lesson->id());
    $this->assertGreaterThan(2, (int) $restored->get('field_actools_lesson_steps')->content_version);
    $this->assertSame('10', json_decode($restored->get('field_actools_lesson_steps')->components, TRUE)[0]['tex']);
    $this->assertSame([$id => TRUE], $progress->snapshot($restored, $reader)['needs_review']);
    $this->assertSame(1, (int) $storage->loadRevisionUnchanged($old_revision)->get('field_actools_lesson_steps')->content_version);
  }

  public function testModifiedNumericTexHistoricalSourceCannotReuseRemovedVersion(): void {
    [$lesson, $row] = $this->equationLesson();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $old_revision = (int) $lesson->getRevisionId();
    $anchor = $this->step(['title' => 'Retained explanation']);
    $lesson = $this->saveRows($lesson, [$this->changeTex($row, '20', 2), $anchor]);
    $lesson = $this->saveRows($lesson, [$anchor]);
    $historical = $storage->loadRevisionUnchanged($old_revision);
    $historical->set('field_actools_lesson_steps', [$this->changeTex($row, '1e1', 1)]);
    $revision = $storage->createRevision($historical);
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('newer content version');
    $revision->save();
  }

  public function testModifiedHistoricalTitleCannotReuseOldNumericTeachingVersion(): void {
    [$lesson, $row] = $this->equationLesson();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $old_revision = (int) $lesson->getRevisionId();
    $lesson = $this->saveRows($lesson, [$this->changeTex($row, '1e1', 2)]);
    $historical = $storage->loadRevisionUnchanged($old_revision);
    $row['title'] = 'A clarified historical heading';
    $historical->set('field_actools_lesson_steps', [$row]);
    // Changed metadata is not an immutable native restore. Its old teaching
    // document still needs a fresh reading identity when it replaces v2.
    $revision = $storage->createRevision($historical);
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('newer content version');
    $revision->save();
  }

  public function testModifiedHistoricalPrivateSourceCannotReactivateOldReadMark(): void {
    [$lesson, $row] = $this->equationLesson();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $draft = $storage->createRevision($lesson, FALSE);
    $draft->setUnpublished();
    $draft->save();
    $private_revision = (int) $draft->getRevisionId();
    $reader = $this->reader();
    $progress = $this->container->get('actools_lesson.progress');
    $id = $row['step_id'];
    $progress->mutate($lesson, $reader, 'complete', [$id], $id, [$id => 1]);
    $this->assertSame(1, $progress->snapshot($lesson, $reader)['required_complete']);
    $lesson = $this->saveRows($lesson, [$this->changeTex($row, '1e1', 2)]);
    $historical = $storage->loadRevisionUnchanged($private_revision);
    $this->assertFalse($historical->wasDefaultRevision());
    $row['title'] = 'A clarified historical private heading';
    $historical->set('field_actools_lesson_steps', [$row]);
    $revision = $storage->createRevision($historical);
    $revision->setPublished();
    try {
      $revision->save();
      $this->fail('An old private source must not reactivate the old read identity over changed public teaching.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('newer content version', $exception->getMessage());
    }
    $current = $storage->loadUnchanged($lesson->id());
    $this->assertSame(2, (int) $current->get('field_actools_lesson_steps')->content_version);
    $this->assertSame('1e1', json_decode($current->get('field_actools_lesson_steps')->components, TRUE)[0]['tex']);
    $this->assertSame([$id => TRUE], $progress->snapshot($current, $reader)['needs_review']);
    $this->assertSame(0, $progress->snapshot($current, $reader)['required_complete']);
    $this->assertSame(1, (int) $storage->loadRevisionUnchanged($private_revision)->get('field_actools_lesson_steps')->content_version);
  }

  public function testUnmodifiedHistoricalPrivateRestoreAllocatesFreshVersion(): void {
    [$lesson, $row] = $this->equationLesson();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $draft = $storage->createRevision($lesson, FALSE);
    $draft->setUnpublished();
    $draft->save();
    $private_revision = (int) $draft->getRevisionId();
    $lesson = $this->saveRows($lesson, [$this->changeTex($row, '1e1', 2)]);
    $newer_revision = (int) $lesson->getRevisionId();
    $restored = $storage->createRevision($storage->loadRevisionUnchanged($private_revision));
    $restored->setPublished();
    $restored->save();
    $current = $storage->loadUnchanged($lesson->id());
    $this->assertSame(3, (int) $current->get('field_actools_lesson_steps')->content_version);
    $this->assertSame('10', json_decode($current->get('field_actools_lesson_steps')->components, TRUE)[0]['tex']);
    $this->assertSame(1, (int) $storage->loadRevisionUnchanged($private_revision)->get('field_actools_lesson_steps')->content_version);
    $this->assertSame(2, (int) $storage->loadRevisionUnchanged($newer_revision)->get('field_actools_lesson_steps')->content_version);
  }

  public function testFortyStepPreSaveReadsSourceOnceAndRefreshesNextOperation(): void {
    $lesson = $this->lesson('Forty step draft', 'core', FALSE);
    $rows = [];
    for ($i = 0; $i < 40; $i++) {
      $rows[] = $this->step(['title' => 'Scientific step ' . $i]);
    }
    $lesson = $this->saveRows($lesson, $rows);
    $field = $lesson->get('field_actools_lesson_steps');
    Database::startLog('actools-step-batch');
    $field->preSave();
    $queries = Database::getLog('actools-step-batch');
    $source_reads = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'node_revision__field_actools_lesson_steps'));
    $this->assertCount(1, $source_reads, 'One immutable source field read serves all 40 items.');
    // A retained higher version from a separate writer must be seen now.
    $other = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($lesson->id());
    $other_rows = $other->get('field_actools_lesson_steps')->getValue();
    $removed = $other_rows[0];
    $removed['content_version'] = 7;
    $other_rows[0] = $removed;
    $other = $this->saveRows($other, $other_rows);
    $other = $this->saveRows($other, array_slice($other_rows, 1));
    // Reuse the exact previously prepared field-list object: a process cache
    // would miss that its source is now historical and its first UUID removed.
    try {
      $field->preSave();
      $this->fail('A second operation must reread current and retained source state.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('newer content version', $exception->getMessage());
    }
    $same_object = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($lesson->id());
    $same_object->set('field_actools_lesson_steps', array_merge(array_slice($other_rows, 1), [$rows[0]]));
    $same_object->get('field_actools_lesson_steps')->preSave();
    $this->assertSame(8, (int) $same_object->get('field_actools_lesson_steps')->get(39)->content_version);
  }

  public function testNonFigureFileIdentityAndOptionalReferencesAreStrictlyValidated(): void {
    $lesson = $this->lesson('Component schema draft', 'core', FALSE);
    $id = $this->container->get('uuid')->generate();
    foreach (['text', 'equation', 'result', 'error', 'example', 'practice', 'table'] as $type) {
      foreach ([NULL, 0, '12', ['arbitrary' => 'data']] as $fid) {
        $component = ['id' => $id, 'type' => $type, 'tex' => 'x', 'fid' => $fid];
        $lesson->set('field_actools_lesson_steps', [$this->step(['components' => json_encode([$component], JSON_THROW_ON_ERROR)])]);
        $this->assertStringContainsString('Only figure components', (string) $lesson->get('field_actools_lesson_steps')->validate());
      }
    }
    foreach ([
      ['type' => 'equation', 'tex' => 'x', 'equation_number' => '4.12'],
      ['type' => 'practice', 'variant_ref' => 'ST-017/variant-a', 'variant_version' => 'reviewed-2026-10-18'],
      ['type' => 'practice', 'source' => 'Kvant 1988, No. 4'],
    ] as $component) {
      $component['id'] = $id;
      $lesson->set('field_actools_lesson_steps', [$this->step(['components' => json_encode([$component], JSON_THROW_ON_ERROR)])]);
      $this->assertCount(0, $lesson->get('field_actools_lesson_steps')->validate());
    }
    foreach ([
      ['type' => 'text', 'equation_number' => '4.12'],
      ['type' => 'equation', 'tex' => 'x', 'equation_number' => NULL],
      ['type' => 'equation', 'tex' => 'x', 'equation_number' => str_repeat('x', 65)],
      ['type' => 'practice', 'variant_ref' => 'ST-017'],
      ['type' => 'practice', 'variant_version' => '1'],
      ['type' => 'practice', 'variant_ref' => 'ST-017', 'variant_version' => ''],
      ['type' => 'practice', 'variant_ref' => 17, 'variant_version' => '1'],
      ['type' => 'practice', 'variant_ref' => 'ST-017', 'variant_version' => 1],
      ['type' => 'practice', 'variant_ref' => str_repeat('x', 256), 'variant_version' => '1'],
      ['type' => 'practice', 'variant_ref' => 'ST-017', 'variant_version' => str_repeat('x', 65)],
      ['type' => 'example', 'variant_ref' => 'ST-017', 'variant_version' => '1'],
    ] as $component) {
      $component['id'] = $id;
      $lesson->set('field_actools_lesson_steps', [$this->step(['components' => json_encode([$component], JSON_THROW_ON_ERROR)])]);
      $this->assertGreaterThan(0, $lesson->get('field_actools_lesson_steps')->validate()->count());
    }
  }

  private function equationLesson(): array {
    $lesson = $this->lesson('Numeric scientific content');
    $row = $this->equationRow($this->step());
    $lesson->set('field_actools_lesson_steps', [$row]);
    $this->chapter('Scientific identity chapter', [$lesson]);
    $lesson = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($lesson->id());
    return [$lesson, $lesson->get('field_actools_lesson_steps')->first()->getValue()];
  }

  private function equationRow(array $row): array {
    $row['components'] = json_encode([['id' => $this->container->get('uuid')->generate(), 'type' => 'equation', 'tex' => '10']], JSON_THROW_ON_ERROR);
    return $row;
  }

  private function changeTex(array $row, string $tex, int $version): array {
    $components = json_decode($row['components'], TRUE, 32, JSON_THROW_ON_ERROR);
    $components[0]['tex'] = $tex;
    $row['components'] = json_encode($components, JSON_THROW_ON_ERROR);
    $row['content_version'] = $version;
    return $row;
  }

  private function saveRows(NodeInterface $lesson, array $rows): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $lesson = $storage->loadUnchanged($lesson->id());
    $lesson->setNewRevision(TRUE);
    $lesson->set('field_actools_lesson_steps', $rows);
    $lesson->save();
    return $storage->loadUnchanged($lesson->id());
  }

}
