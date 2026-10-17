<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/** Central definition of a module-managed chapter or lesson. */
final class LessonAccess {

  public static function isLesson(NodeInterface $node): bool {
    return $node->bundle() === 'actools_resource'
      && $node->hasField('field_actools_lesson_managed')
      && (bool) $node->get('field_actools_lesson_managed')->value
      && $node->hasField('field_actools_kind')
      && $node->get('field_actools_kind')->value === 'lesson';
  }

  public static function isChapter(NodeInterface $node): bool {
    return $node->bundle() === 'actools_topic'
      && $node->hasField('field_actools_lesson_chapter')
      && (bool) $node->get('field_actools_lesson_chapter')->value;
  }

  public function reader(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    $result = AccessResult::allowedIf(self::isLesson($node) && $node->isDefaultRevision() && $node->isPublished())->addCacheableDependency($node);
    $result = $result->andIf($node->access('view', $account, TRUE));
    if (self::isLesson($node)) {
      foreach (['title', 'field_actools_lesson_steps'] as $field) {
        $result = $result->andIf($node->get($field)->access('view', $account, TRUE));
      }
    }
    return $result;
  }

}
