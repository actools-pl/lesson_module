<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Skipping form validation must not supersede a pending managed adoption. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class PendingManagedAdoptionStorageTest extends LessonKernelTestBase {

  public function testStaleOrdinaryApiSaveCannotSupersedeManagedPendingRevision(): void {
    $this->reader();
    [$default, $stale, $pending] = $this->fixture(TRUE);
    $before = $this->snapshot($default, $pending);
    $stale->setNewRevision(TRUE);
    $stale->setTitle('An obsolete ordinary API write');
    try {
      // Deliberately omit validate(): presave is the final defensive boundary.
      $stale->save();
      $this->fail('A stale ordinary object must not supersede the managed draft.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('This content has changed. Reload the editor before saving.', $exception->getMessage());
      $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
    }
    $this->assertSame($before, $this->snapshot($default, $pending));
  }

  public function testOrdinaryPendingRevisionDoesNotAcquireManagedAdoptionRestrictions(): void {
    $this->reader();
    [$default, $stale, $pending] = $this->fixture(FALSE);
    $before = $this->storage()->loadRevisionUnchanged($pending->getRevisionId())->toArray();
    $stale->setNewRevision(TRUE);
    $stale->setTitle('An allowed ordinary API write');
    $stale->save();
    $this->assertSame('An allowed ordinary API write', $this->storage()->loadUnchanged($default->id())->label());
    $this->assertSame($before, $this->storage()->loadRevisionUnchanged($pending->getRevisionId())->toArray());
    $this->assertFalse((bool) $this->storage()->loadUnchanged($default->id())->get('field_actools_lesson_managed')->value);
  }

  private function fixture(bool $managed): array {
    $default = Node::create(['type' => 'actools_resource', 'uid' => 1, 'title' => 'An ordinary published default', 'status' => 1, 'field_actools_kind' => 'notes', 'field_actools_lesson_managed' => FALSE]);
    $default->save();
    $stale = $this->storage()->loadUnchanged($default->id());
    $pending = clone $default;
    $pending->setNewRevision(TRUE);
    $pending->isDefaultRevision(FALSE);
    $pending->setUnpublished()->setTitle('PRIVATE retained adoption history');
    if ($managed) {
      $pending->set('field_actools_kind', 'lesson');
      $pending->set('field_actools_lesson_managed', TRUE);
      $pending->set('field_actools_path', 'core');
      $pending->set('field_actools_lesson_steps', [$this->step(['reviewed' => FALSE])]);
    }
    $pending->save();
    return [$default, $stale, $pending];
  }

  private function snapshot(NodeInterface $node, NodeInterface $pending): array {
    $this->storage()->resetCache([$node->id()]);
    return [
      'default' => $this->storage()->loadUnchanged($node->id())->toArray(),
      'latest' => $this->storage()->loadRevisionUnchanged($this->storage()->getLatestRevisionId($node->id()))->toArray(),
      'pending' => $this->storage()->loadRevisionUnchanged($pending->getRevisionId())->toArray(),
    ];
  }

  private function storage() {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

}
