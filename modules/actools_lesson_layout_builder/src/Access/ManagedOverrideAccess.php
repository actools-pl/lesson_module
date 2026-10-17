<?php

declare(strict_types=1);

namespace Drupal\actools_lesson_layout_builder\Access;

use Drupal\actools_lesson\Service\LessonAccess;
use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\node\NodeInterface;

/** Individual layout forms must not become an alternate lesson-save workflow. */
final class ManagedOverrideAccess implements AccessInterface {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  public function access(SectionStorageInterface $section_storage) {
    $result = AccessResult::allowed()->addCacheContexts(['route']);
    if ($section_storage->getStorageType() !== 'overrides') {
      return $result;
    }
    try {
      $node = $section_storage->getContextValue('entity');
    }
    catch (ContextException $exception) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    return $node instanceof NodeInterface ? $this->nodeAccess($node) : $result;
  }

  /** Also protects a direct native form build, independent of routing. */
  public function nodeAccess(NodeInterface $node): AccessResultInterface {
    // Tempstore may change without a node save and is private to its editor.
    $result = AccessResult::allowed()->setCacheMaxAge(0)->addCacheContexts(['route'])->addCacheableDependency($node);
    $candidates = [$node];
    if ($node->id()) {
      $storage = $this->entityTypeManager->getStorage('node');
      $candidates[] = $storage->loadUnchanged($node->id());
      $latestId = $storage->getLatestRevisionId($node->id());
      $candidates[] = $latestId ? $storage->loadRevisionUnchanged($latestId) : NULL;
    }
    $managed = FALSE;
    foreach ($candidates as $candidate) {
      if ($candidate instanceof NodeInterface) {
        $result->addCacheableDependency($candidate);
        $managed = $managed || LessonAccess::isLesson($candidate) || LessonAccess::isChapter($candidate);
      }
    }
    return $managed ? AccessResult::forbidden()->inheritCacheability($result) : $result;
  }

}

