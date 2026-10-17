<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/** Canonical membership is the chapter reference field; the index guards races. */
final class MembershipManager {
  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager, private readonly Connection $database, private readonly TeachingContentCompleteness $completeness) {}

  public function owner(int $lessonId): ?NodeInterface {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(FALSE)
      ->condition('type', 'actools_topic')->condition('field_actools_lesson_chapter', 1)
      ->condition('field_actools_lessons.target_id', $lessonId)->sort('nid')->range(0, 1)->execute();
    return $ids ? $this->entityTypeManager->getStorage('node')->load(reset($ids)) : NULL;
  }

  /** Returns friendly errors both for Form API and defensive storage checks. */
  public function validate(NodeInterface $node): array {
    $errors = [];
    if (LessonAccess::isChapter($node)) {
      $seen = [];
      $items = $node->get('field_actools_lessons')->getValue();
      if (count($items) > 200) {
        $errors[] = 'A chapter can contain at most 200 lessons.';
      }
      foreach ($items as $item) {
        $id = (int) ($item['target_id'] ?? 0);
        if (isset($seen[$id])) {
          $errors[] = 'Each lesson can occur only once in a chapter.';
          continue;
        }
        $seen[$id] = TRUE;
        $lesson = $this->entityTypeManager->getStorage('node')->load($id);
        if (!$lesson instanceof NodeInterface || !LessonAccess::isLesson($lesson)) {
          $errors[] = 'The chapter outline can contain only Actools managed lessons. Save new lessons as drafts first.';
          continue;
        }
        $owner = $this->owner($id);
        if ($owner && (int) $owner->id() !== (int) $node->id()) {
          // Avoid leaking a restricted chapter's title to an editor.
          $errors[] = 'A selected lesson already belongs to another chapter. Remove it from that chapter before moving it.';
        }
      }
    }
    if ($node->bundle() === 'actools_resource' && $node->hasField('field_actools_lesson_managed') && $node->get('field_actools_lesson_managed')->value) {
      if (!LessonAccess::isLesson($node)) {
        $errors[] = 'A managed lesson must have Resource kind Lesson.';
        return $errors;
      }
      if (!$node->get('field_actools_path')->value || !in_array($node->get('field_actools_path')->value, ['core', 'extension'], TRUE)) {
        $errors[] = 'Choose a Core or Extension learning path.';
      }
      if ($node->isPublished() && $node->isDefaultRevision()) {
        if (!$node->id() || !$this->owner((int) $node->id())) {
          $errors[] = 'Save this lesson as a draft, add it to a chapter, then publish it.';
        }
        if ($node->get('field_actools_lesson_steps')->isEmpty()) {
          $errors[] = 'Add at least one learning step before publishing.';
        }
        $ids = [];
        foreach ($node->get('field_actools_lesson_steps') as $step) {
          $value = $step->getValue();
          if (!$this->completeness->isCompleteStep($value)) {
            $errors[] = 'Complete every teaching component before publishing: add its explanation or question prompt, equation source, or valid figure with alternative text.';
          }
          if (empty($value['reviewed'])) {
            $errors[] = 'Review every learning step before publishing this lesson.';
          }
          if (isset($ids[$value['step_id'] ?? ''])) {
            $errors[] = 'Learning step links must be unique within a lesson. Duplicate a step with the builder to create a new link.';
          }
          $ids[$value['step_id'] ?? ''] = TRUE;
        }
      }
    }
    // Clearing a flag cannot silently invalidate a chapter's references.
    if ($node->bundle() === 'actools_resource' && !LessonAccess::isLesson($node) && $node->id() && $this->owner((int) $node->id())) {
      $errors[] = 'Remove this resource from its chapter before turning off the lesson reader or changing its kind.';
    }
    return array_values(array_unique($errors));
  }

  public function synchronize(NodeInterface $node): void {
    if (!$node->isDefaultRevision() || !$node->id()) {
      return;
    }
    if ($node->bundle() !== 'actools_topic') {
      return;
    }
    $transaction = $this->database->startTransaction();
    $this->database->delete('actools_lesson_membership')->condition('chapter_nid', $node->id())->execute();
    if (LessonAccess::isChapter($node)) {
      foreach ($node->get('field_actools_lessons')->getValue() as $delta => $item) {
        $this->database->insert('actools_lesson_membership')->fields([
          'lesson_nid' => (int) $item['target_id'], 'chapter_nid' => (int) $node->id(), 'delta' => $delta,
        ])->execute();
      }
    }
    unset($transaction);
  }

  public function delete(NodeInterface $node): void {
    $query = $this->database->delete('actools_lesson_membership');
    $query->condition($query->orConditionGroup()->condition('chapter_nid', $node->id())->condition('lesson_nid', $node->id()))->execute();
  }
}
