<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Unit;

use Drupal\actools_lesson\Service\LessonDocumentBuilder;
use Drupal\actools_lesson\Service\ProgressStore;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserDataInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Exercises durable-state semantics independently of Drupal reader integration.
 *
 */
#[CoversClass(ProgressStore::class)]
#[Group('actools_lesson')]
class ProgressStoreTest extends UnitTestCase {

  private const A = '11111111-1111-4111-8111-111111111111';
  private const B = '22222222-2222-4222-8222-222222222222';
  private const C = '33333333-3333-4333-8333-333333333333';

  private array $data = [];
  private int $writes = 0;
  private int $releases = 0;

  private function fixture(bool $lock_available = TRUE): array {
    $user_data = $this->createMock(UserDataInterface::class);
    $user_data->method('get')->willReturnCallback(fn($module, $uid, $key) => $this->data[$uid][$key] ?? NULL);
    $user_data->method('set')->willReturnCallback(function ($module, $uid, $key, $value): void {
      $this->data[$uid][$key] = $value;
      $this->writes++;
    });
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn($lock_available);
    $lock->method('release')->willReturnCallback(function (): void {
      $this->releases++;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1700000000);
    // The overridden normalized-step provider never uses this dependency.
    $builder = (new \ReflectionClass(LessonDocumentBuilder::class))->newInstanceWithoutConstructor();
    $store = new TestProgressStore($user_data, $lock, $time, $builder);
    $store->steps = [
      self::A => ['id' => self::A, 'title' => 'Force', 'optional' => FALSE, 'content_version' => 1],
      self::B => ['id' => self::B, 'title' => 'An extension', 'optional' => TRUE, 'content_version' => 1],
      self::C => ['id' => self::C, 'title' => 'Pressure', 'optional' => FALSE, 'content_version' => 1],
    ];
    $lesson = $this->createMock(NodeInterface::class);
    $lesson->method('id')->willReturn(9);
    return [$store, $lesson];
  }

  private function account(int $uid): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isAuthenticated')->willReturn($uid > 0);
    $account->method('isAnonymous')->willReturn($uid === 0);
    return $account;
  }

  /** Reading and a saved place never imply completion. */
  public function testReadAndPositionDoNotComplete(): void {
    [$store, $lesson] = $this->fixture();
    $user = $this->account(12);
    $snapshot = $store->snapshot($lesson, $user);
    $this->assertSame(0, $this->writes);
    $this->assertSame([], $snapshot['completed']);
    $this->assertNull($snapshot['position']);
    $store->mutate($lesson, $user, 'position', [], self::B);
    $snapshot = $store->snapshot($lesson, $user);
    $this->assertSame(self::B, $snapshot['position']);
    $this->assertSame(0, $snapshot['required_complete']);
    $this->assertSame([], $snapshot['completed']);
  }

  /** New versions require explicit re-reading, while reorder preserves marks. */
  public function testVersionChangeReorderAndDeletion(): void {
    [$store, $lesson] = $this->fixture();
    $user = $this->account(12);
    $store->mutate($lesson, $user, 'complete', [self::A], self::A, [self::A => 1]);
    $store->steps = array_reverse($store->steps, TRUE);
    $this->assertSame(1, $store->snapshot($lesson, $user)['required_complete']);
    $store->steps[self::A]['content_version'] = 2;
    $snapshot = $store->snapshot($lesson, $user);
    $this->assertSame(0, $snapshot['required_complete']);
    $this->assertSame([self::A => TRUE], $snapshot['needs_review']);
    $writes = $this->writes;
    try {
      $store->mutate($lesson, $user, 'complete', [self::A], self::A, [self::A => 1]);
      $this->fail('A stale form must not approve changed content.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('changed', $e->getMessage());
    }
    $this->assertSame($writes, $this->writes);
    $store->mutate($lesson, $user, 'complete', [self::A], self::A, [self::A => 2]);
    $this->assertSame(1, $store->snapshot($lesson, $user)['required_complete']);
    unset($store->steps[self::A]);
    $snapshot = $store->snapshot($lesson, $user);
    $this->assertSame([], $snapshot['completed']);
    $this->assertNull($snapshot['position']);
  }

  /** Account records are distinct, bulk merges, undo and reset are reversible. */
  public function testIsolationBulkUndoAndReset(): void {
    [$store, $lesson] = $this->fixture();
    $alice = $this->account(12);
    $bob = $this->account(13);
    $store->mutate($lesson, $alice, 'complete', [self::A, self::B], self::B, [self::A => 1, self::B => 1]);
    $alice_state = $store->snapshot($lesson, $alice);
    $this->assertSame(2, $alice_state['total_complete']);
    $this->assertSame(1, $alice_state['required_complete']);
    $this->assertSame(2, $alice_state['required_total']);
    $this->assertSame([], $store->snapshot($lesson, $bob)['completed']);
    // Simulate another tab completing C before an older tab undoes A. The
    // locked fresh merge must preserve C rather than writing an old snapshot.
    $store->mutate($lesson, $alice, 'complete', [self::C], self::C, [self::C => 1]);
    $store->mutate($lesson, $alice, 'undo', [self::A]);
    $this->assertSame([self::B => TRUE, self::C => TRUE], $store->snapshot($lesson, $alice)['completed']);
    $store->mutate($lesson, $bob, 'complete', [self::A], self::A, [self::A => 1]);
    $store->mutate($lesson, $alice, 'reset');
    $alice_state = $store->snapshot($lesson, $alice);
    $this->assertSame([], $alice_state['completed']);
    $this->assertNull($alice_state['position']);
    $this->assertSame([self::A => TRUE], $store->snapshot($lesson, $bob)['completed']);
    $this->assertSame($this->writes, $this->releases);
  }

  /** Unreadable IDs cannot be completed or used as a saved position. */
  public function testRejectsRemovedStepAtomically(): void {
    [$store, $lesson] = $this->fixture();
    $user = $this->account(12);
    unset($store->steps[self::B]);
    $this->expectException(\InvalidArgumentException::class);
    try {
      $store->mutate($lesson, $user, 'complete', [self::A, self::B], self::A, [self::A => 1, self::B => 1]);
    }
    finally {
      $this->assertSame(0, $this->writes);
    }
  }

  public function testAnonymousCannotWrite(): void {
    [$store, $lesson] = $this->fixture();
    $this->expectException(AccessDeniedHttpException::class);
    try {
      $store->mutate($lesson, $this->account(0), 'reset');
    }
    finally {
      $this->assertSame(0, $this->writes);
    }
  }

  public function testUnavailableLockNeverWritesOrReleasesAnothersLock(): void {
    [$store, $lesson] = $this->fixture(FALSE);
    $this->expectException(\RuntimeException::class);
    try {
      $store->mutate($lesson, $this->account(12), 'position', [], self::A);
    }
    finally {
      $this->assertSame(0, $this->writes);
      $this->assertSame(0, $this->releases);
    }
  }

}

/**
 * Supplies normalized public steps for isolated durable-state tests.
 *
 * Drupal entity/field access, CSRF and placeholder caching require the module's
 * separate functional/integration suite; these unit tests do not certify them.
 */
class TestProgressStore extends ProgressStore {

  public array $steps = [];

  protected function stepsFor(NodeInterface $lesson, AccountInterface $account): array {
    return $this->steps;
  }

}
