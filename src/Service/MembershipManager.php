<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/** Canonical membership is the chapter reference field; the index guards races. */
final class MembershipManager {
  public const DETACH_ERROR = 'Unpublish the affected lessons before removing them from a chapter, turning off its chapter role, or deleting it.';

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager, private readonly Connection $database, private readonly TeachingContentCompleteness $completeness, private readonly SavedLessonState $savedState) {}

  public function owner(int $lessonId): ?NodeInterface {
    $id = $this->savedState->ownerId($lessonId);
    return $id ? $this->savedState->defaultNode($id) : NULL;
  }

  /** Serialize default ownership/publication changes until native SQL commit. */
  public function lockForMutation(NodeInterface $node): void {
    $ids = $node->id() ? [(int) $node->id()] : [];
    if ($node->bundle() === 'actools_topic') {
      if ($node->id()) {
        $ids = array_merge($ids, $this->savedState->references((int) $node->id(), FALSE));
      }
      if (LessonAccess::isChapter($node)) {
        $ids = array_merge($ids, array_column($node->get('field_actools_lessons')->getValue(), 'target_id'));
      }
    }
    elseif ($node->bundle() === 'actools_resource' && $node->id()) {
      if ($owner = $this->savedState->ownerId((int) $node->id(), FALSE)) {
        $ids[] = $owner;
      }
    }
    $this->savedState->lock($ids);
    // A competing chapter save may have added references since the first read.
    if ($node->bundle() === 'actools_topic' && $node->id()) {
      $this->savedState->lock($this->savedState->references((int) $node->id()));
    }
  }

  /** Deleting a revision object still deletes the entire node/default outline. */
  public function deletionErrors(NodeInterface $node): array {
    return $this->detachmentErrors($node, TRUE);
  }

  private function detachmentErrors(NodeInterface $node, bool $delete = FALSE): array {
    if (!$node->id() || $node->bundle() !== 'actools_topic' || (!$delete && !$node->isDefaultRevision())) {
      return [];
    }
    $saved = $this->savedState->defaultNode((int) $node->id());
    if (!$saved || !$this->savedState->isChapter((int) $node->id())) {
      return [];
    }
    $previous = $this->savedState->references((int) $node->id());
    $remaining = !$delete && LessonAccess::isChapter($node)
      ? array_map('intval', array_column($node->get('field_actools_lessons')->getValue(), 'target_id')) : [];
    $removed = array_values(array_diff($previous, $remaining));
    $this->savedState->lock($removed);
    foreach ($removed as $id) {
      // Always reload the saved default; never trust an unsaved cached fixture.
      $this->savedState->defaultNode($id);
      if ($this->savedState->isPublishedManagedDefault($id)) {
        return [self::DETACH_ERROR];
      }
    }
    return [];
  }

  /** Returns friendly errors both for Form API and defensive storage checks. */
  public function validate(NodeInterface $node): array {
    $errors = $this->detachmentErrors($node);
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
        $lesson = $this->savedState->defaultNode($id);
        if (!$lesson instanceof NodeInterface || !LessonAccess::isLesson($lesson) || !$this->savedState->isManaged($id)) {
          $errors[] = 'The chapter outline can contain only Actools managed lessons. Save new lessons as drafts first.';
          continue;
        }
        $owner = $this->savedState->ownerId($id);
        if ($owner && $owner !== (int) $node->id()) {
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
        if (!$node->id() || !$this->savedState->ownerId((int) $node->id())) {
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
    if ($node->bundle() === 'actools_resource' && !LessonAccess::isLesson($node) && $node->id() && $this->savedState->ownerId((int) $node->id())) {
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
