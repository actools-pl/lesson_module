<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\actools_lesson\Service\MembershipManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class ActoolsLessonStructureValidator extends ConstraintValidator implements ContainerInjectionInterface {
  public function __construct(private readonly MembershipManager $membership) {}
  public static function create(ContainerInterface $container): static {
    return new static($container->get('actools_lesson.membership'));
  }
  public function validate(mixed $value, Constraint $constraint): void {
    if ($value instanceof NodeInterface) {
      foreach ($this->membership->validate($value) as $message) {
        $this->context->addViolation($message);
      }
    }
  }
}
