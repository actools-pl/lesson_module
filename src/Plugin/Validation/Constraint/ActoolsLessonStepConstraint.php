<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;

/** Valid structured learning-step data. */
#[ConstraintAttribute(
  id: 'ActoolsLessonStep',
  label: new TranslatableMarkup('Valid structured lesson step', [], ['context' => 'Validation']),
  type: 'actools_lesson_step',
)]
final class ActoolsLessonStepConstraint extends Constraint {
  public string $invalid = '@message';
}
