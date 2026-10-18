<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Plugin\Field\FieldType;

use Drupal\actools_lesson\Service\StepVersionHistory;
use Drupal\actools_lesson\Service\TeachingContentComparison;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\file\Entity\File;
use Drupal\node\NodeInterface;

/**
 * A revision-owned learning step. JSON storage is never exposed as an editor.
 */
#[FieldType(
  id: 'actools_lesson_step',
  label: new TranslatableMarkup('Lesson learning step'),
  description: new TranslatableMarkup('A stable learning step with structured scientific components.'),
  default_widget: 'actools_lesson_step_builder',
  default_formatter: 'actools_lesson_step_summary',
  list_class: LessonStepItemList::class,
)]
final class LessonStepItem extends FieldItemBase {

  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'step_id' => DataDefinition::create('string')->setLabel(new TranslatableMarkup('Stable step ID'))->setRequired(TRUE),
      'title' => DataDefinition::create('string')->setLabel(new TranslatableMarkup('Step title'))->setRequired(TRUE),
      'optional' => DataDefinition::create('boolean')->setLabel(new TranslatableMarkup('Optional step')),
      'reviewed' => DataDefinition::create('boolean')->setLabel(new TranslatableMarkup('Content reviewed')),
      'content_version' => DataDefinition::create('integer')->setLabel(new TranslatableMarkup('Content version'))->setRequired(TRUE),
      'components' => DataDefinition::create('string')->setLabel(new TranslatableMarkup('Structured components'))->setRequired(TRUE),
    ];
  }

  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return ['columns' => [
      'step_id' => ['type' => 'varchar', 'length' => 36, 'not null' => FALSE],
      'title' => ['type' => 'varchar', 'length' => 255, 'not null' => FALSE],
      'optional' => ['type' => 'int', 'size' => 'tiny', 'default' => 0],
      'reviewed' => ['type' => 'int', 'size' => 'tiny', 'default' => 0],
      'content_version' => ['type' => 'int', 'unsigned' => TRUE, 'default' => 1],
      'components' => ['type' => 'blob', 'size' => 'big', 'not null' => FALSE],
    ]];
  }

  public static function mainPropertyName(): string {
    return 'title';
  }

  public function getConstraints(): array {
    $constraints = parent::getConstraints();
    $constraints[] = \Drupal::typedDataManager()->getValidationConstraintManager()->create('ActoolsLessonStep', []);
    return $constraints;
  }

  public function isEmpty(): bool {
    return trim((string) $this->title) === '' && in_array(trim((string) $this->components), ['', '[]'], TRUE);
  }

  /** Returns validated image IDs without consuming arbitrary URLs or HTML. */
  public function figureIds(): array {
    $components = json_decode((string) $this->components, TRUE);
    if (!is_array($components)) {
      return [];
    }
    $ids = [];
    foreach ($components as $component) {
      if (is_array($component) && ($component['type'] ?? '') === 'figure' && !empty($component['fid'])) {
        $ids[] = (int) $component['fid'];
      }
    }
    return array_values(array_unique($ids));
  }

  public function preSave(): void {
    parent::preSave();
    foreach ($this->figureIds() as $fid) {
      if (($file = File::load($fid)) && str_starts_with((string) $file->getMimeType(), 'image/')) {
        if ($file->isTemporary()) {
          $account = \Drupal::currentUser();
          if ($account->isAnonymous() || (int) $file->getOwnerId() !== (int) $account->id() || !$file->access('download', $account)) {
            throw new \InvalidArgumentException('A lesson may retain only the current author’s temporary image uploads.');
          }
          $file->setPermanent();
          $file->save();
        }
      }
    }
  }

  public function postSave($update): bool {
    $entity = $this->getEntity();
    if ($entity->getEntityTypeId() === 'node' && !$entity->isNew()) {
      $usage = \Drupal::service('file.usage');
      foreach ($this->figureIds() as $fid) {
        if ($file = File::load($fid)) {
          $uses = $usage->listUsage($file);
          // One use per node, retained while any historical revision needs it.
          if (empty($uses['actools_lesson']['node'][$entity->id()])) {
            $usage->add($file, 'actools_lesson', 'node', $entity->id());
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * Restoring immutable stored history creates a new content-version identity.
   *
   * Only an equivalent historical step set qualifies. Modified source
   * objects and ordinary writes still have to satisfy the version constraint.
   */
  public static function prepareHistoricalRestore(NodeInterface $revision, NodeInterface $source): void {
    $field = 'field_actools_lesson_steps';
    if ($source->isNew() || !$revision->isDefaultRevision() || !$revision->hasField($field) || !$source->getLoadedRevisionId()) {
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $current = $storage->loadUnchanged($source->id());
    if (!$current || (int) $current->getRevisionId() === (int) $source->getLoadedRevisionId()) {
      return;
    }
    $historical = $storage->loadRevisionUnchanged($source->getLoadedRevisionId());
    if (!$historical || (int) $historical->id() !== (int) $source->id() || !$historical->hasField($field)) {
      return;
    }
    $langcode = $source->language()->getId();
    if (!$historical->hasTranslation($langcode) || !$current->hasTranslation($langcode)) {
      return;
    }
    $historical = $historical->getTranslation($langcode);
    $current = $current->getTranslation($langcode);
    $rows = $historical->get($field)->getValue();
    if (!self::sameStoredSteps($source->get($field)->getValue(), $rows)
      || !self::sameStoredSteps($revision->get($field)->getValue(), $rows)) {
      return;
    }
    $current_steps = [];
    foreach ($current->get($field)->getValue() as $row) {
      $current_steps[$row['step_id']] = $row;
    }
    $maximums = StepVersionHistory::maximumVersions($revision, $field, array_column($rows, 'step_id'));
    foreach ($rows as &$row) {
      $now = $current_steps[$row['step_id']] ?? NULL;
      $same_content = $now && TeachingContentComparison::same((string) $now['components'], (string) $row['components']);
      $row['content_version'] = $same_content
        ? (int) $now['content_version']
        : max((int) $row['content_version'], $maximums[$row['step_id']] ?? 0) + 1;
    }
    unset($row);
    $revision->set($field, $rows);
  }

  /** Normalizes only native SQL columns; teaching JSON retains strict types. */
  private static function sameStoredSteps(array $first, array $second): bool {
    if (array_keys($first) !== array_keys($second)) {
      return FALSE;
    }
    foreach ($first as $delta => $row) {
      $other = $second[$delta];
      foreach (['step_id', 'title'] as $property) {
        if ((string) ($row[$property] ?? '') !== (string) ($other[$property] ?? '')) {
          return FALSE;
        }
      }
      foreach (['optional', 'reviewed', 'content_version'] as $property) {
        if ((int) ($row[$property] ?? 0) !== (int) ($other[$property] ?? 0)) {
          return FALSE;
        }
      }
      if (!TeachingContentComparison::same((string) ($row['components'] ?? ''), (string) ($other['components'] ?? ''))) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
