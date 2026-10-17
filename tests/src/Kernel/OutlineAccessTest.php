<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests public outline access boundaries and cache invalidation dependencies.
 *
 */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
class OutlineAccessTest extends LessonKernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'options', 'filter', 'file', 'image',
    'node', 'taxonomy', 'actools_lesson', 'actools_lesson_test',
  ];

  public function testNavigationSkipsUnpublishedAndOtherPathLessons(): void {
    $reader = $this->reader();
    $first = $this->lesson('First public lesson');
    $unpublished = $this->lesson('Secret unpublished lesson', 'core', FALSE);
    $extension = $this->lesson('Extension lesson', 'extension');
    $last = $this->lesson('Last public lesson');
    $chapter = $this->chapter('The public chapter', [$first, $unpublished, $extension, $last]);
    $outline = $this->container->get('actools_lesson.outline');
    $document = $outline->forLesson($first, $reader);
    $this->assertSame((int) $chapter->id(), (int) $document['chapter']->id());
    $this->assertNull($document['previous']);
    $this->assertSame((int) $last->id(), (int) $document['next']->id());
    $this->assertSame([(int) $first->id(), (int) $last->id()], $this->ids($document['lessons']));
    $this->assertContains('node:' . $unpublished->id(), $document['cacheability']->getCacheTags(), 'Denied children must invalidate cached navigation when their publication state changes.');
    $end = $outline->forLesson($last, $reader);
    $this->assertSame((int) $first->id(), (int) $end['previous']->id());
    $this->assertNull($end['next']);
    $chapterDocument = $outline->forChapter($chapter, $reader);
    $this->assertSame([(int) $first->id(), (int) $extension->id(), (int) $last->id()], $this->ids($chapterDocument['lessons']));
  }

  public function testDeniedParentDoesNotLeakChapterOrSiblingTitles(): void {
    $reader = $this->reader();
    $first = $this->lesson('Independently published lesson');
    $second = $this->lesson('A sibling in a private outline');
    $chapter = $this->chapter('Secret chapter title', [$first, $second], FALSE);
    $document = $this->container->get('actools_lesson.outline')->forLesson($first, $reader);
    $this->assertNull($document['chapter']);
    $this->assertSame([], $document['lessons']);
    $this->assertNull($document['previous']);
    $this->assertNull($document['next']);
    $this->assertContains('node:' . $chapter->id(), $document['cacheability']->getCacheTags());
    $this->assertTrue($this->container->get('actools_lesson.access')->reader($first, $reader)->isAllowed(), 'Parent publication is not a substitute for the independent lesson access policy.');
  }

  public function testFieldAccessAppliesToNavigationAndItsCacheMetadata(): void {
    $reader = $this->reader();
    $first = $this->lesson('First lesson');
    $restricted = $this->lesson('Title protected by field access');
    $last = $this->lesson('Last lesson');
    $chapter = $this->chapter('Chapter', [$first, $restricted, $last]);
    $this->container->get('state')->set('actools_lesson_test.hidden_titles', [(int) $restricted->id()]);
    $document = $this->container->get('actools_lesson.outline')->forLesson($first, $reader);
    $this->assertSame([(int) $first->id(), (int) $last->id()], $this->ids($document['lessons']));
    $this->assertSame((int) $last->id(), (int) $document['next']->id());
    $this->assertContains('actools_lesson_test:hidden_titles', $document['cacheability']->getCacheTags());
    $this->container->get('state')->set('actools_lesson_test.hidden_titles', [(int) $chapter->id()]);
    $document = $this->container->get('actools_lesson.outline')->forLesson($first, $reader);
    $this->assertNull($document['chapter']);
    $this->assertNull($document['next']);
  }

  private function ids(array $nodes): array {
    return array_values(array_map(static fn ($node): int => (int) $node->id(), $nodes));
  }

}
