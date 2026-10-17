<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/** A minimal field display; the managed full view supplies the complete shell. */
#[FieldFormatter(
  id: 'actools_lesson_step_summary',
  label: new TranslatableMarkup('Lesson step links'),
  field_types: ['actools_lesson_step'],
)]
final class LessonStepSummaryFormatter extends FormatterBase {

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $entity = $items->getEntity();
    if ($entity->isNew()) {
      return $elements;
    }
    foreach ($items as $delta => $item) {
      if (!$item->reviewed) {
        continue;
      }
      $elements[$delta] = [
        '#type' => 'link',
        '#title' => $item->title,
        '#url' => Url::fromRoute('actools_lesson.reader', ['node' => $entity->id()], ['query' => ['step' => $item->step_id]]),
      ];
    }
    return $elements;
  }

}
