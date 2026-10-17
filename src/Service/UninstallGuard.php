<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/** Prevents a module uninstall from stranding structured lesson content. */
final class UninstallGuard implements ModuleUninstallValidatorInterface {
  use StringTranslationTrait;
  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}
  public function validate($module): array {
    if ($module !== 'actools_lesson') {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    foreach (['field_actools_lesson_managed', 'field_actools_lesson_chapter'] as $field) {
      try {
        if ($storage->getQuery()->accessCheck(FALSE)->condition($field, 1)->range(0, 1)->count()->execute()) {
          return [$this->t('Actools lesson/chapter content still exists. Export or deliberately remove it before uninstalling Actools Lessons.')];
        }
      }
      catch (\Drupal\Core\Entity\Query\QueryException) {
        // A failed install may not have created every field.
      }
    }
    return [];
  }
}
