<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Plugin\Field\FieldType;

use Drupal\actools_lesson\Service\StepVersionHistory;
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
    $this->prepareReintroducedVersion();
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
   * Reintroducing a removed UUID cannot reuse an earlier reading identity.
   *
   * This only allocates automatically for an item absent from its immutable
   * stored source revision. A modified historical source gets no exemption
   * from supplying a newer version for changed or reintroduced content.
   */
  private function prepareReintroducedVersion(): void {
    $entity = $this->getEntity();
    $field = $this->getFieldDefinition()->getName();
    if (!$entity instanceof NodeInterface || $entity->isNew()
      || $field !== 'field_actools_lesson_steps' || !$entity->getLoadedRevisionId()
      || (int) $this->content_version < 1) {
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $source = $storage->loadRevisionUnchanged($entity->getLoadedRevisionId());
    $langcode = $entity->language()->getId();
    if (!$source || !$source->hasTranslation($langcode)) {
      return;
    }
    $source = $source->getTranslation($langcode);
    $id = (string) $this->step_id;
    $old = NULL;
    foreach ($source->get($field)->getValue() as $row) {
      if ($row['step_id'] === $id) {
        $old = $row;
        break;
      }
    }
    $historical_source = (int) $entity->getLoadedRevisionId() !== (int) $storage->getLatestRevisionId($entity->id());
    if ($old && !$historical_source) {
      return;
    }
    $maximum = StepVersionHistory::maximumVersions($entity, $field, [$id])[$id] ?? 0;
    if (!$old) {
      if ($maximum > 0) {
        $this->content_version = max((int) $this->content_version, $maximum + 1);
      }
      return;
    }
    $current = $storage->loadUnchanged($entity->id());
    $present = FALSE;
    if ($current && $current->hasTranslation($langcode)) {
      foreach ($current->getTranslation($langcode)->get($field)->getValue() as $row) {
        $present = $present || $row['step_id'] === $id;
      }
    }
    $changed = json_decode((string) $old['components'], TRUE) != json_decode((string) $this->components, TRUE);
    if ((!$present || $changed) && (int) $this->content_version <= $maximum) {
      throw new \InvalidArgumentException('Changed or reintroduced teaching content requires a newer content version than its retained history.');
    }
  }

  /**
   * Restoring immutable stored history creates a new content-version identity.
   *
   * Only a byte-equivalent historical step set qualifies. Modified source
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
    if ($source->get($field)->getValue() != $rows || $revision->get($field)->getValue() != $rows) {
      return;
    }
    $current_steps = [];
    foreach ($current->get($field)->getValue() as $row) {
      $current_steps[$row['step_id']] = $row;
    }
    $maximums = StepVersionHistory::maximumVersions($revision, $field, array_column($rows, 'step_id'));
    foreach ($rows as &$row) {
      $now = $current_steps[$row['step_id']] ?? NULL;
      $same_content = $now && json_decode((string) $now['components'], TRUE) == json_decode((string) $row['components'], TRUE);
      $row['content_version'] = $same_content
        ? (int) $now['content_version']
        : max((int) $row['content_version'], $maximums[$row['step_id']] ?? 0) + 1;
    }
    unset($row);
    $revision->set($field, $rows);
  }

}
