<?php

declare(strict_types=1);

namespace Drupal\actools_lesson;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\OptGroup;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\NodeType;

/** Adds missing native structure; never reapplies the theme's starter recipe. */
final class StructureInstaller {

  public static function install(): void {
    $specs = self::specifications();
    // Preflight everything before changing site configuration.
    foreach ($specs as $name => $spec) {
      if ($storage = FieldStorageConfig::loadByName('node', $name)) {
        if (!in_array($storage->getType(), $spec['compatible'] ?? [$spec['type']], TRUE)) {
          throw new \RuntimeException(sprintf('Actools Lessons: existing %s uses %s; expected %s. No existing configuration was replaced.', $name, $storage->getType(), implode(' or ', $spec['compatible'] ?? [$spec['type']])));
        }
        if ($spec['type'] === 'entity_reference' && $storage->getSetting('target_type') !== ($spec['storage']['target_type'] ?? 'node')) {
          throw new \RuntimeException('Incompatible reference storage target on ' . $name);
        }
        if ($storage->getCardinality() !== ($spec['cardinality'] ?? 1)) {
          throw new \RuntimeException(sprintf('Actools Lessons requires %s to have cardinality %d. Review the existing storage before installing.', $name, $spec['cardinality'] ?? 1));
        }
        if ($name === 'field_actools_kind') {
          self::assertAllowedChoices($storage, ['lesson']);
        }
        if ($name === 'field_actools_path') {
          self::assertAllowedChoices($storage, ['core', 'extension']);
        }
      }
      foreach ($spec['bundles'] as $bundle) {
        if ($field = FieldConfig::loadByName('node', $bundle, $name)) {
          if ($spec['type'] === 'entity_reference' && $field->getSetting('target_type') && $field->getSetting('target_type') !== ($spec['storage']['target_type'] ?? 'node')) {
            throw new \RuntimeException('Incompatible reference target on ' . $name);
          }
        }
      }
    }
    if (\Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load('edu_topics')) {
      foreach (['field_actools_chapter_term', 'field_actools_concepts'] as $name) {
        if (($storage = FieldStorageConfig::loadByName('node', $name)) && ($storage->getType() !== 'entity_reference' || $storage->getSetting('target_type') !== 'taxonomy_term')) {
          throw new \RuntimeException('Incompatible taxonomy reference storage on ' . $name);
        }
      }
    }
    foreach (['actools_topic' => 'Topic', 'actools_resource' => 'Resource'] as $id => $label) {
      if (!NodeType::load($id)) {
        NodeType::create(['type' => $id, 'name' => $label, 'new_revision' => TRUE, 'display_submitted' => FALSE])->save();
      }
    }
    $created_format = !FilterFormat::load('actools_lesson_text');
    if ($created_format) {
      FilterFormat::create([
        'format' => 'actools_lesson_text', 'name' => 'Actools lesson text', 'weight' => 5,
        'filters' => [
          'filter_html' => ['status' => TRUE, 'settings' => ['allowed_html' => '<p> <br> <strong> <em> <ul> <ol start> <li> <blockquote> <h3> <h4> <code> <sub> <sup> <a href> <table> <caption> <thead> <tbody> <tfoot> <tr> <th rowspan colspan> <td rowspan colspan>', 'filter_html_help' => TRUE, 'filter_html_nofollow' => FALSE]],
          'filter_htmlcorrector' => ['status' => TRUE],
        ],
      ])->save();
    }
    if ($created_format && \Drupal::moduleHandler()->moduleExists('ckeditor5')) {
      \Drupal\editor\Entity\Editor::create([
        'format' => 'actools_lesson_text', 'editor' => 'ckeditor5',
        'settings' => [
          'toolbar' => ['items' => ['heading', '|', 'bold', 'italic', 'subscript', 'superscript', 'code', '|', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'insertTable', '|', 'undo', 'redo']],
          'plugins' => [
            'ckeditor5_heading' => ['enabled_headings' => ['heading3', 'heading4']],
            'ckeditor5_list' => ['properties' => ['reversed' => FALSE, 'startIndex' => TRUE, 'styles' => FALSE], 'multiBlock' => TRUE],
          ],
        ],
        'image_upload' => ['status' => FALSE],
      ])->save();
    }
    foreach ($specs as $name => $spec) {
      $storage = FieldStorageConfig::loadByName('node', $name);
      if (!$storage) {
        $storage = FieldStorageConfig::create([
          'field_name' => $name, 'entity_type' => 'node', 'type' => $spec['type'],
          'cardinality' => $spec['cardinality'] ?? 1,
          'settings' => $spec['storage'] ?? [],
          'translatable' => FALSE,
        ]);
        $storage->save();
      }
      foreach ($spec['bundles'] as $bundle) {
        if (FieldConfig::loadByName('node', $bundle, $name)) {
          continue;
        }
        $field = FieldConfig::create([
          'field_name' => $name, 'entity_type' => 'node', 'bundle' => $bundle,
          'label' => $spec['label'], 'description' => $spec['description'] ?? '',
          'required' => FALSE, 'settings' => $spec['settings'] ?? [],
          'default_value' => isset($spec['default']) ? [['value' => $spec['default']]] : [],
        ]);
        $field->save();
        $form = \Drupal::service('entity_display.repository')->getFormDisplay('node', $bundle, 'default');
        $weight = count($form->getComponents()) + 5;
        $widget = $spec['widget'] ?? match ($storage->getType()) {
          'boolean' => 'boolean_checkbox', 'entity_reference' => 'entity_reference_autocomplete',
          'list_string' => 'options_select', 'string_long' => 'string_textarea',
          'text_long', 'text_with_summary' => 'text_textarea', default => 'string_textfield',
        };
        $form->setComponent($name, ['type' => $widget, 'weight' => $weight])->save();
        $view = \Drupal::service('entity_display.repository')->getViewDisplay('node', $bundle, 'default');
        $view->removeComponent($name)->save();
      }
    }
    // The code field exists without requiring or modifying the external seed.
    if (\Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load('edu_topics')) {
      foreach (['actools_topic' => 'field_actools_chapter_term', 'actools_resource' => 'field_actools_concepts'] as $bundle => $name) {
        if (!FieldStorageConfig::loadByName('node', $name)) {
          FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => 'entity_reference', 'cardinality' => $bundle === 'actools_topic' ? 1 : FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, 'settings' => ['target_type' => 'taxonomy_term']])->save();
        }
        if (!FieldConfig::loadByName('node', $bundle, $name)) {
          FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => $bundle, 'label' => $bundle === 'actools_topic' ? 'Chapter taxonomy' : 'Concepts', 'settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['edu_topics' => 'edu_topics'], 'auto_create' => FALSE]]])->save();
          \Drupal::service('entity_display.repository')->getFormDisplay('node', $bundle)->setComponent($name, ['type' => 'entity_reference_autocomplete', 'weight' => 40])->save();
        }
      }
    }
  }

  /** Resolve choices through the same API used by native list field validation. */
  private static function assertAllowedChoices(FieldStorageConfig $storage, array $required): void {
    foreach ($required as $choice) {
      // Callbacks may legitimately use the resource bundle or current value.
      // This unsaved entity does not create content or configuration.
      $context = \Drupal::entityTypeManager()->getStorage('node')->create([
        'type' => 'actools_resource', 'status' => 0,
      ]);
      foreach (['field_actools_kind' => 'lesson', 'field_actools_lesson_managed' => 1, $storage->getName() => $choice] as $name => $value) {
        if ($context->hasField($name)) {
          $context->set($name, $value);
        }
      }
      // The native helper statically caches by field and entity-presence, not
      // entity identity. Resolve each prospective value afresh during preflight.
      drupal_static_reset('options_allowed_values');
      try {
        $values = options_allowed_values($storage, $context);
      }
      catch (\Throwable $exception) {
        throw new \RuntimeException(sprintf('Actools Lessons cannot resolve the allowed choices for %s. Review its allowed-values callback before installing. No existing configuration was replaced.', $storage->getName()), 0, $exception);
      }
      // Native list fields also accept grouped options returned by callbacks.
      if (!is_array($values) || !array_key_exists($choice, OptGroup::flattenOptions($values))) {
        throw new \RuntimeException(sprintf('Actools Lessons requires %s to permit %s for managed resources. Add the missing choice or correct its allowed-values callback before installing. No existing configuration was replaced.', $storage->getName(), implode(' and ', $required)));
      }
    }
  }

  private static function specifications(): array {
    $topic = ['actools_topic']; $resource = ['actools_resource']; $both = array_merge($topic, $resource);
    return [
      'field_actools_body' => ['type' => 'text_long', 'compatible' => ['text_long', 'text_with_summary'], 'bundles' => $both, 'label' => 'Introduction'],
      'field_actools_summary' => ['type' => 'string_long', 'compatible' => ['string_long', 'text_long', 'text_with_summary'], 'bundles' => $resource, 'label' => 'Summary'],
      'field_actools_kind' => ['type' => 'list_string', 'bundles' => $resource, 'label' => 'Resource kind', 'storage' => ['allowed_values' => ['lesson' => 'Lesson', 'notes' => 'Notes', 'worked_examples' => 'Worked examples', 'assignment' => 'Assignment']]],
      'field_actools_lesson_chapter' => ['type' => 'boolean', 'bundles' => $topic, 'label' => 'Use the Actools chapter reader', 'default' => 0],
      'field_actools_lessons' => ['type' => 'entity_reference', 'bundles' => $topic, 'label' => 'Lessons in reading order', 'description' => 'Add managed lessons, then drag rows to set the reading order. A lesson belongs to one chapter.', 'cardinality' => -1, 'storage' => ['target_type' => 'node'], 'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['actools_resource' => 'actools_resource'], 'auto_create' => FALSE]]],
      'field_actools_chapter_code' => ['type' => 'string', 'bundles' => $topic, 'label' => 'Stable chapter code', 'description' => 'For example PHY11_SURFACE_TENSION. This does not create or change taxonomy terms.'],
      'field_actools_lesson_managed' => ['type' => 'boolean', 'bundles' => $resource, 'label' => 'Use the Actools lesson reader', 'default' => 0],
      'field_actools_path' => ['type' => 'list_string', 'bundles' => $resource, 'label' => 'Learning path', 'default' => 'core', 'storage' => ['allowed_values' => ['core' => 'Core', 'extension' => 'Extension']]],
      'field_actools_objectives' => ['type' => 'string_long', 'bundles' => $both, 'label' => 'Learning objectives', 'description' => 'One objective per line.'],
      'field_actools_prerequisites' => ['type' => 'string_long', 'bundles' => $both, 'label' => 'Before you begin', 'description' => 'One prerequisite per line.'],
      'field_actools_lesson_steps' => ['type' => 'actools_lesson_step', 'bundles' => $resource, 'label' => 'Learning steps', 'description' => 'Build a clear learning sequence. Review each step before publishing.', 'cardinality' => -1, 'widget' => 'actools_lesson_step_builder'],
    ];
  }

}
