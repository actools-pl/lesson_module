<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Plugin\Field\FieldType;

use Drupal\actools_lesson\Service\StepVersionHistory;
use Drupal\actools_lesson\Service\TeachingContentComparison;
use Drupal\Core\Field\FieldItemList;
use Drupal\node\NodeInterface;

/** Allocates retained-history identities once per field-list save operation. */
final class LessonStepItemList extends FieldItemList {

  public function preSave(): void {
    $this->prepareReintroducedVersions();
    parent::preSave();
  }

  /**
   * Reintroducing a removed UUID cannot reuse an earlier reading identity.
   *
   * All source reads are fresh for this operation and shared by sibling items.
   * A modified historical source gets no exemption from version validation.
   */
  private function prepareReintroducedVersions(): void {
    $entity = $this->getEntity();
    $field = $this->getFieldDefinition()->getName();
    if (!$entity instanceof NodeInterface || $entity->isNew()
      || $field !== 'field_actools_lesson_steps' || !$entity->getLoadedRevisionId() || !$this->count()) {
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $source = $storage->loadRevisionUnchanged($entity->getLoadedRevisionId());
    $langcode = $entity->language()->getId();
    if (!$source || !$source->hasTranslation($langcode)) {
      return;
    }
    $old_rows = [];
    foreach ($source->getTranslation($langcode)->get($field)->getValue() as $row) {
      $old_rows[$row['step_id']] = $row;
    }
    $historical_source = (int) $entity->getLoadedRevisionId() !== (int) $storage->getLatestRevisionId($entity->id());
    $ids = [];
    foreach ($this as $item) {
      $id = (string) $item->step_id;
      if ((int) $item->content_version >= 1 && ($historical_source || !isset($old_rows[$id]))) {
        $ids[] = $id;
      }
    }
    if (!$ids) {
      return;
    }
    $maximums = StepVersionHistory::maximumVersions($entity, $field, $ids);
    $current_rows = [];
    if ($historical_source) {
      $current = $storage->loadUnchanged($entity->id());
      if ($current && $current->hasTranslation($langcode)) {
        foreach ($current->getTranslation($langcode)->get($field)->getValue() as $row) {
          $current_rows[$row['step_id']] = $row;
        }
      }
    }
    foreach ($this as $item) {
      $id = (string) $item->step_id;
      if ((int) $item->content_version < 1) {
        continue;
      }
      $old = $old_rows[$id] ?? NULL;
      $maximum = $maximums[$id] ?? 0;
      if (!$old) {
        if ($maximum > 0) {
          $item->content_version = max((int) $item->content_version, $maximum + 1);
        }
      }
      elseif ($historical_source) {
        $now = $current_rows[$id] ?? NULL;
        $changed_source = !TeachingContentComparison::same((string) $old['components'], (string) $item->components);
        $changed_current = !$now || !TeachingContentComparison::same((string) $now['components'], (string) $item->components);
        // Native immutable restores already receive fresh versions. A modified
        // historical private source can otherwise make core compare against
        // that private source instead of the current public/default revision.
        if ((($changed_source || $changed_current) && (int) $item->content_version <= $maximum)
          || ($now && (int) $item->content_version < (int) $now['content_version'])) {
          throw new \InvalidArgumentException('Changed or reintroduced teaching content requires a newer content version than its retained history.');
        }
      }
    }
  }

}
