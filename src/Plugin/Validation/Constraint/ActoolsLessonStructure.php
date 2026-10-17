<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/** Validates the canonical chapter/lesson structure. */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'ActoolsLessonStructure',
  label: new \Drupal\Core\StringTranslation\TranslatableMarkup('Actools chapter and lesson integrity'),
  type: 'entity:node',
)]
final class ActoolsLessonStructure extends Constraint {}
