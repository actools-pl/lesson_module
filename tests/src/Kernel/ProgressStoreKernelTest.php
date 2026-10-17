<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Actual user.data, public-reader steps, account access and node revisions. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class ProgressStoreKernelTest extends LessonKernelTestBase {

  public function testPublicVersionsStableIdsAndAccountIsolation(): void {
    $alice = $this->reader();
    $bob = $this->reader();
    $this->container->get('current_user')->setAccount($alice);
    $lesson = $this->lesson('Capillary rise');
    $a = $lesson->get('field_actools_lesson_steps')->first()->step_id;
    $optional = $this->step(['title' => 'An optional model', 'optional' => TRUE]);
    $lesson->get('field_actools_lesson_steps')->appendItem($optional);
    $b = $optional['step_id'];
    $this->chapter('Surface tension', [$lesson]);
    $store = $this->container->get('actools_lesson.progress');
    $before = $store->snapshot($lesson, $alice);
    $this->assertSame(1, $before['required_total']);
    $this->assertNull($this->container->get('user.data')->get('actools_lesson', $alice->id(), 'lesson:' . $lesson->id()));

    $store->mutate($lesson, $alice, 'complete', [$a, $b], $a, [$a => 1, $b => 1]);
    $this->assertSame(1, $store->snapshot($lesson, $alice)['required_complete']);
    $this->assertSame(2, $store->snapshot($lesson, $alice)['total_complete']);
    $this->container->get('current_user')->setAccount($bob);
    $this->assertSame([], $store->snapshot($lesson, $bob)['completed']);
    $this->container->get('current_user')->setAccount($alice);

    // Display order is not identity: moving the second step retains completion.
    $values = $lesson->get('field_actools_lesson_steps')->getValue();
    $lesson->setNewRevision(TRUE);
    $lesson->set('field_actools_lesson_steps', array_reverse($values));
    $lesson->save();
    $this->assertSame(2, $store->snapshot($lesson, $alice)['total_complete']);

    // A new reviewed public content version is not silently read by Alice.
    $values = $lesson->get('field_actools_lesson_steps')->getValue();
    $values[1]['content_version'] = 2;
    $lesson->setNewRevision(TRUE);
    $lesson->set('field_actools_lesson_steps', $values);
    $lesson->save();
    $after = $store->snapshot($lesson, $alice);
    $this->assertSame([$a => TRUE], $after['needs_review']);
    $this->assertSame(0, $after['required_complete']);
    $store->mutate($lesson, $alice, 'complete', [$a], $a, [$a => 2]);
    $this->assertSame(1, $store->snapshot($lesson, $alice)['required_complete']);
  }

  public function testPublicProgressRejectsDraftRevision(): void {
    $reader = $this->reader();
    $this->container->get('current_user')->setAccount($reader);
    $lesson = $this->lesson('Public explanation');
    $this->chapter('Surface tension', [$lesson]);
    $lesson->setNewRevision(TRUE);
    $lesson->isDefaultRevision(FALSE);
    $lesson->setUnpublished();
    $lesson->save();
    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('actools_lesson.progress')->snapshot($lesson, $reader);
  }

  public function testFieldAccessIsCheckedBeforeReadingPersonalState(): void {
    $this->enableModules(['actools_lesson_test']);
    $reader = $this->reader();
    $this->container->get('current_user')->setAccount($reader);
    $lesson = $this->lesson('Restricted title');
    $this->chapter('Surface tension', [$lesson]);
    $this->container->get('state')->set('actools_lesson_test.hidden_titles', [(int) $lesson->id()]);
    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('actools_lesson.progress')->snapshot($lesson, $reader);
  }

  public function testChapterResumeUsesLatestValidAccountPositionWithoutWriting(): void {
    $alice = $this->reader();
    $bob = $this->reader();
    $this->container->get('current_user')->setAccount($alice);
    $first = $this->lesson('Core first');
    $second_step = $this->step(['title' => 'The second step']);
    $first->get('field_actools_lesson_steps')->appendItem($second_step);
    $second = $this->lesson('Core second');
    $extension = $this->lesson('Optional extension', 'extension');
    $chapter = $this->chapter('Surface tension', [$first, $second, $extension]);
    $a = $first->get('field_actools_lesson_steps')->first()->step_id;
    $b = $second_step['step_id'];
    $c = $second->get('field_actools_lesson_steps')->first()->step_id;
    $x = $extension->get('field_actools_lesson_steps')->first()->step_id;
    $store = $this->container->get('actools_lesson.progress');
    $resume = $this->container->get('actools_lesson.chapter_progress');
    $data = $this->container->get('user.data');

    $store->mutate($first, $alice, 'position', [], $b);
    $record = $data->get('actools_lesson', $alice->id(), 'lesson:' . $first->id());
    $record['updated_microtime'] = 20.0;
    $data->set('actools_lesson', $alice->id(), 'lesson:' . $first->id(), $record);
    $before = $data->get('actools_lesson', $alice->id());
    $build = $resume->build($chapter->id());
    $this->assertSame((int) $first->id(), (int) $build['link']['#url']->getRouteParameters()['node']);
    $this->assertSame($b, $build['link']['#url']->getOption('query')['step']);
    $this->assertSame(0, $build['#cache']['max-age']);
    $this->assertContains('user', $build['#cache']['contexts']);
    $this->assertSame($before, $data->get('actools_lesson', $alice->id()));

    // The chapter picker considers both paths when resuming an existing place.
    $store->mutate($extension, $alice, 'position', [], $x);
    $record = $data->get('actools_lesson', $alice->id(), 'lesson:' . $extension->id());
    $record['updated_microtime'] = 30.0;
    $data->set('actools_lesson', $alice->id(), 'lesson:' . $extension->id(), $record);
    $build = $resume->build($chapter->id());
    $this->assertSame((int) $extension->id(), (int) $build['link']['#url']->getRouteParameters()['node']);

    // Finishing the last core lesson never diverts into the extension route.
    $store->mutate($second, $alice, 'complete', [$c], $c, [$c => 1]);
    $build = $resume->build($chapter->id());
    $this->assertSame((int) $second->id(), (int) $build['link']['#url']->getRouteParameters()['node']);
    $this->assertStringContainsString('Review', (string) $build['link']['#title']);
    // Finishing the previous core lesson offers the next core lesson.
    $store->mutate($first, $alice, 'complete', [$a, $b], $b, [$a => 1, $b => 1]);
    $build = $resume->build($chapter->id());
    $this->assertSame((int) $second->id(), (int) $build['link']['#url']->getRouteParameters()['node']);
    $this->assertSame($c, $build['link']['#url']->getOption('query')['step']);

    $this->container->get('current_user')->setAccount($bob);
    $this->assertArrayNotHasKey('link', $resume->build($chapter->id()));
    $this->container->get('current_user')->setAccount(new \Drupal\Core\Session\AnonymousUserSession());
    $this->assertArrayNotHasKey('link', $resume->build($chapter->id()));
  }

}
