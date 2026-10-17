<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Plugin\Validation\Constraint;

use Drupal\Component\Uuid\Uuid;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/** Strictly validates data even when a save does not come from the widget. */
final class ActoolsLessonStepConstraintValidator extends ConstraintValidator {

  public function validate($value, Constraint $constraint): void {
    if ($value->isEmpty()) {
      return;
    }
    $row = $value->getValue();
    $original = $this->originalData($value);
    $original_components = $original['components'];
    $account = \Drupal::currentUser();
    $error = function (string $message, string $path = 'components') use ($constraint): void {
      $this->context->buildViolation($constraint->invalid, ['@message' => $message])->atPath($path)->addViolation();
    };
    if (!Uuid::isValid((string) ($row['step_id'] ?? ''))) {
      $error('The step identity is not a valid UUID.', 'step_id');
    }
    if (trim((string) ($row['title'] ?? '')) === '' || mb_strlen((string) ($row['title'] ?? '')) > 255) {
      $error('Give the step a title of at most 255 characters.', 'title');
    }
    if ((int) ($row['content_version'] ?? 0) < 1) {
      $error('The content version must be a positive integer.', 'content_version');
    }
    $json = (string) ($row['components'] ?? '');
    if (strlen($json) > 2000000) {
      $error('A step exceeds the two-megabyte structured content limit.');
      return;
    }
    try {
      $components = json_decode($json, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      $error('Step components are not valid structured data.');
      return;
    }
    if (!is_array($components) || !array_is_list($components) || count($components) > 60) {
      $error('A step must contain an ordered list of at most 60 components.');
      return;
    }
    if ($old = $original['steps'][$row['step_id'] ?? ''] ?? NULL) {
      $old_components = json_decode((string) $old['components'], TRUE) ?? [];
      $minimum = (int) $old['content_version'] + (int) ($old_components != $components);
      if ((int) $row['content_version'] < $minimum) {
        $error('Changed teaching content requires a newer content version; an existing version may not decrease.', 'content_version');
      }
    }
    $ids = [];
    foreach ($components as $component) {
      if (!is_array($component) || !Uuid::isValid((string) ($component['id'] ?? '')) || isset($ids[$component['id'] ?? ''])) {
        $error('Every component needs its own valid, unique identity.');
        continue;
      }
      $ids[$component['id']] = TRUE;
      if (array_diff(array_keys($component), ['id', 'type', 'heading', 'body', 'hint', 'answer', 'solution', 'tex', 'fid', 'alt', 'caption', 'description', 'source'])) {
        $error('A component contains unsupported properties.');
      }
      $type = $component['type'] ?? '';
      if (!in_array($type, ['text', 'equation', 'figure', 'result', 'error', 'example', 'practice', 'table'], TRUE)) {
        $error('A component has an unsupported type.');
      }
      foreach (['heading' => 255, 'tex' => 32000, 'alt' => 1024, 'caption' => 4000, 'description' => 12000, 'source' => 4000] as $property => $limit) {
        if (isset($component[$property]) && (!is_string($component[$property]) || mb_strlen($component[$property]) > $limit)) {
          $error('A component has invalid or oversized ' . $property . ' content.');
        }
      }
      foreach (['body', 'hint', 'answer', 'solution'] as $property) {
        if (!isset($component[$property])) {
          continue;
        }
        $text = $component[$property];
        if (!is_array($text) || !is_string($text['value'] ?? NULL) || !is_string($text['format'] ?? NULL) || strlen($text['value']) > 120000) {
          $error('A component has invalid or oversized formatted text.');
          continue;
        }
        if ($text['value'] !== '') {
          $format = FilterFormat::load($text['format']);
          if ($format === NULL || !$format->status()) {
            $error('A component selects an unavailable text format.');
          }
          elseif (($original_components[$component['id']][$property] ?? NULL) !== $text && !$format->access('use', $account)) {
            $error('You do not have permission to use the selected text format for new or changed content.');
          }
        }
      }
      if ($type === 'equation' && trim((string) ($component['tex'] ?? '')) === '') {
        $error('Enter TeX source for an equation component.');
      }
      if ($type === 'figure') {
        $fid = $component['fid'] ?? 0;
        $file = is_int($fid) && $fid > 0 ? File::load($fid) : NULL;
        if (!$file || !in_array($file->getMimeType(), ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], TRUE) || !\Drupal::service('image.factory')->get($file->getFileUri())->isValid()) {
          $error('Choose a valid uploaded PNG, JPEG, GIF or WebP figure.');
        }
        elseif (($original_components[$component['id']]['fid'] ?? NULL) !== $fid) {
          if (!$file->access('download', $account)) {
            $error('You may not attach a figure you cannot download.');
          }
          if (StreamWrapperManager::getScheme($file->getFileUri()) !== 'public') {
            $error('The lesson builder currently accepts public teaching figures only.');
          }
          if ($file->isTemporary() && ($account->isAnonymous() || (int) $file->getOwnerId() !== (int) $account->id())) {
            $error('You may attach only your own temporary figure uploads.');
          }
        }
        if (trim((string) ($component['alt'] ?? '')) === '') {
          $error('Add meaningful alternative text to the figure.');
        }
      }
    }
    // Step IDs must be unique across this lesson, not only inside one item.
    $list = $value->getParent();
    if (count($list) > 40) {
      $error('A lesson may contain at most 40 learning steps.');
    }
    $seen = [];
    $seen_components = [];
    foreach ($list as $item) {
      $id = (string) $item->step_id;
      if ($id !== '' && isset($seen[$id])) {
        $error('Two steps share the same identity. Duplicate through the builder to create a new identity.', 'step_id');
        break;
      }
      $seen[$id] = TRUE;
      foreach (json_decode((string) $item->components, TRUE) ?? [] as $component) {
        if (is_array($component) && isset($component['id'])) {
          if (isset($seen_components[$component['id']])) {
            $error('Component identities must be unique across every step in a lesson.');
            break 2;
          }
          $seen_components[$component['id']] = TRUE;
        }
      }
    }
  }

  /** Existing restricted-format text can remain, but cannot be edited freely. */
  private function originalData($item): array {
    $entity = $item->getEntity();
    if ($entity->isNew()) {
      return ['steps' => [], 'components' => []];
    }
    $original = $entity->getOriginal();
    if (!$original) {
      $storage = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId());
      $original = $storage instanceof RevisionableStorageInterface && $entity->getLoadedRevisionId()
        ? $storage->loadRevisionUnchanged($entity->getLoadedRevisionId())
        : $storage->loadUnchanged($entity->id());
    }
    $name = $item->getFieldDefinition()->getName();
    if ($original && $original->hasTranslation($entity->language()->getId())) {
      $original = $original->getTranslation($entity->language()->getId());
    }
    $out = [];
    $steps = [];
    if ($original && $original->hasField($name)) {
      foreach ($original->get($name) as $step) {
        $steps[(string) $step->step_id] = $step->getValue();
        foreach (json_decode((string) $step->components, TRUE) ?? [] as $component) {
          if (is_array($component) && isset($component['id'])) {
            $out[$component['id']] = $component;
          }
        }
      }
    }
    return ['steps' => $steps, 'components' => $out];
  }

}
