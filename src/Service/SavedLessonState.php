<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/** Reads persisted defaults, independently of mutable entity memory caches. */
final class SavedLessonState {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager, private readonly Connection $database) {}

  public function defaultNode(int $id): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache([$id]);
    $node = $storage->loadUnchanged($id);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /** Current reads also see changes committed after a SQL transaction began. */
  private function current(SelectInterface $query): SelectInterface {
    if ($this->database->inTransaction()) {
      $query->forUpdate();
    }
    return $query;
  }

  /** Native SQL entity storage keeps these locks through commit or rollback. */
  public function lock(array $ids): void {
    if (!$this->database->inTransaction()) {
      return;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    sort($ids, SORT_NUMERIC);
    if ($ids) {
      $this->database->select('node', 'n')->fields('n', ['nid'])->condition('nid', $ids, 'IN')
        ->orderBy('nid')->forUpdate()->execute()->fetchCol();
    }
  }

  public function latestRevisionId(int $id): ?int {
    $query = $this->database->select('node_revision', 'r')->fields('r', ['vid'])
      ->condition('nid', $id)->orderBy('vid', 'DESC')->range(0, 1);
    $revision = $this->current($query)->execute()->fetchField();
    return $revision === FALSE ? NULL : (int) $revision;
  }

  public function latest(NodeInterface $node): NodeInterface {
    if (!$node->id()) {
      return $node;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache([$node->id()]);
    $revision = $this->latestRevisionId((int) $node->id());
    $latest = $revision ? $storage->loadRevisionUnchanged($revision) : NULL;
    if (!$latest instanceof NodeInterface) {
      throw new EntityStorageException('This content has changed. Reload it before saving.');
    }
    $langcode = $node->language()->getId();
    return $latest->hasTranslation($langcode) ? $latest->getTranslation($langcode) : $latest;
  }

  /** Module-owned, untranslatable fields have one canonical scalar value. */
  private function value(string $field, int $id, ?int $revision = NULL): mixed {
    $table = ($revision === NULL ? 'node__' : 'node_revision__') . $field;
    if (!$this->database->schema()->tableExists($table)) {
      return NULL;
    }
    $query = $this->database->select($table, 'f')->fields('f', [$field . '_value'])
      ->condition('f.entity_id', $id)->condition('f.deleted', 0)->condition('f.delta', 0);
    $query->innerJoin($revision === NULL ? 'node_field_data' : 'node_field_revision', 'n', 'n.nid = f.entity_id AND n.langcode = f.langcode AND n.default_langcode = 1' . ($revision === NULL ? '' : ' AND n.vid = f.revision_id'));
    if ($revision !== NULL) {
      $query->condition('revision_id', $revision);
    }
    return $this->current($query)->execute()->fetchField();
  }

  public function isChapter(int $id): bool {
    return (bool) $this->value('field_actools_lesson_chapter', $id);
  }

  public function isManaged(int $id, ?int $revision = NULL): bool {
    return (bool) $this->value('field_actools_lesson_managed', $id, $revision)
      && $this->value('field_actools_kind', $id, $revision) === 'lesson';
  }

  public function references(int $id, bool $locking = TRUE): array {
    $query = $this->database->select('node__field_actools_lessons', 'f')
      ->fields('f', ['field_actools_lessons_target_id'])->condition('f.entity_id', $id)
      ->condition('f.deleted', 0)->orderBy('f.delta');
    $query->innerJoin('node_field_data', 'n', 'n.nid = f.entity_id AND n.langcode = f.langcode AND n.default_langcode = 1');
    return array_map('intval', ($locking ? $this->current($query) : $query)->execute()->fetchCol());
  }

  public function isPublishedManagedDefault(int $id): bool {
    $query = $this->database->select('node_field_data', 'n')->fields('n', ['status'])
      ->condition('nid', $id)->condition('type', 'actools_resource')->condition('default_langcode', 1);
    return (bool) $this->current($query)->execute()->fetchField() && $this->isManaged($id);
  }

  public function pendingManagedAdoption(int $id): bool {
    if ($this->isManaged($id)) {
      return FALSE;
    }
    $revision = $this->latestRevisionId($id);
    return $revision !== NULL && $this->isManaged($id, $revision);
  }

  /** The default reference field remains authoritative, rather than the index. */
  public function ownerId(int $lessonId, bool $locking = TRUE): ?int {
    $query = $this->database->select('node__field_actools_lessons', 'r');
    $query->innerJoin('node__field_actools_lesson_chapter', 'c', 'c.entity_id = r.entity_id AND c.langcode = r.langcode AND c.deleted = 0 AND c.field_actools_lesson_chapter_value = 1');
    $query->innerJoin('node', 'n', 'n.nid = r.entity_id AND n.type = :type', [':type' => 'actools_topic']);
    $query->innerJoin('node_field_data', 'd', 'd.nid = n.nid AND d.langcode = r.langcode AND d.default_langcode = 1');
    $query->addField('r', 'entity_id');
    $query->condition('r.field_actools_lessons_target_id', $lessonId)->condition('r.deleted', 0)
      ->orderBy('r.entity_id')->range(0, 1);
    $id = ($locking ? $this->current($query) : $query)->execute()->fetchField();
    return $id === FALSE ? NULL : (int) $id;
  }

}
