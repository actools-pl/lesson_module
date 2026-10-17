<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\node\NodeInterface;

/** A bounded lookup of step-version high water marks in retained node history. */
final class StepVersionHistory {

  /**
   * Returns maximum saved versions, scoped to this lesson and these step UUIDs.
   *
   * Native SQL field mapping keeps table/column names portable and avoids
   * loading every historical lesson's potentially large component documents.
   * Deliberately purged revisions are no longer available to this lookup.
   *
   * @return array<string, int>
   *   Maximum versions keyed by stable step UUID.
   */
  public static function maximumVersions(NodeInterface $lesson, string $field, array $ids): array {
    if ($lesson->isNew() || !$ids) {
      return [];
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    if (!$storage instanceof SqlEntityStorageInterface || !($mapping = $storage->getTableMapping()) instanceof DefaultTableMapping) {
      throw new \LogicException('Lesson step history requires native SQL node revision storage.');
    }
    $definition = $lesson->getFieldDefinition($field)->getFieldStorageDefinition();
    $table = $mapping->getDedicatedRevisionTableName($definition);
    $uuid_column = $mapping->getFieldColumnName($definition, 'step_id');
    $version_column = $mapping->getFieldColumnName($definition, 'content_version');
    $query = \Drupal::database()->select($table, 'history')
      ->condition('history.entity_id', $lesson->id())
      ->condition('history.' . $uuid_column, array_values(array_unique($ids)), 'IN')
      ->groupBy('history.' . $uuid_column);
    $query->addField('history', $uuid_column, 'step_uuid');
    $query->addExpression('MAX(history.' . $version_column . ')', 'maximum_version');
    return array_map('intval', $query->execute()->fetchAllKeyed());
  }

}
