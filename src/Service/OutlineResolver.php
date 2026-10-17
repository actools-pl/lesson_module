<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/** Resolves ordered navigation, including the cacheability of denied content. */
final class OutlineResolver {
  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager, private readonly MembershipManager $membership) {}

  public function owner(int $nid): ?NodeInterface {
    return $this->membership->owner($nid);
  }

  public function forChapter(NodeInterface $chapter, AccountInterface $account, bool $preview = FALSE): array {
    $cache = (new CacheableMetadata())->addCacheTags(['node_list'])->addCacheContexts(['languages:language_content']);
    $cache->addCacheableDependency($chapter);
    $lessons = [];
    if (!LessonAccess::isChapter($chapter) || !$this->allowed($chapter, ['title', 'field_actools_lessons'], $account, $cache)) {
      return ['lessons' => [], 'cacheability' => $cache];
    }
    foreach ($chapter->get('field_actools_lessons')->referencedEntities() as $lesson) {
      $cache->addCacheableDependency($lesson);
      if (!$lesson instanceof NodeInterface || !LessonAccess::isLesson($lesson) || (!$lesson->isPublished() && (!$preview || !$lesson->access('update', $account)))) {
        continue;
      }
      if (!$this->allowed($lesson, ['title', 'field_actools_path', 'field_actools_lesson_steps'], $account, $cache)) {
        continue;
      }
      $lessons[] = $lesson;
    }
    if ($preview) {
      $cache->setCacheMaxAge(0);
    }
    return ['lessons' => $lessons, 'cacheability' => $cache];
  }

  public function forLesson(NodeInterface $lesson, AccountInterface $account, bool $preview = FALSE): array {
    $cache = (new CacheableMetadata())->addCacheTags(['node_list'])->addCacheContexts(['languages:language_content'])->addCacheableDependency($lesson);
    $result = ['chapter' => NULL, 'lessons' => [], 'previous' => NULL, 'next' => NULL, 'cacheability' => $cache];
    if (!$lesson->id() || !LessonAccess::isLesson($lesson)) {
      return $result;
    }
    $chapter = $this->owner((int) $lesson->id());
    if (!$chapter) {
      return $result;
    }
    $cache->addCacheableDependency($chapter);
    if (!$this->allowed($chapter, ['title', 'field_actools_lessons'], $account, $cache)) {
      return $result;
    }
    $outline = $this->forChapter($chapter, $account, $preview);
    $cache = $cache->merge($outline['cacheability']);
    $result['chapter'] = $chapter;
    if (!$this->allowed($lesson, ['field_actools_path'], $account, $cache)) {
      $result['cacheability'] = $cache;
      return $result;
    }
    $path = $lesson->get('field_actools_path')->value;
    $result['lessons'] = array_values(array_filter($outline['lessons'], static fn(NodeInterface $candidate) => $candidate->get('field_actools_path')->value === $path));
    foreach ($result['lessons'] as $index => $candidate) {
      if ($candidate->id() === $lesson->id()) {
        $result['previous'] = $result['lessons'][$index - 1] ?? NULL;
        $result['next'] = $result['lessons'][$index + 1] ?? NULL;
        break;
      }
    }
    $result['cacheability'] = $cache;
    return $result;
  }

  private function allowed(NodeInterface $node, array $fields, AccountInterface $account, CacheableMetadata $cache): bool {
    $access = $node->access('view', $account, TRUE);
    $cache->addCacheableDependency($access);
    $allowed = $access->isAllowed();
    foreach ($fields as $name) {
      if (!$node->hasField($name)) {
        $allowed = FALSE;
        continue;
      }
      $fieldAccess = $node->get($name)->access('view', $account, TRUE);
      $cache->addCacheableDependency($fieldAccess);
      $allowed = $allowed && $fieldAccess->isAllowed();
    }
    return $allowed;
  }
}
