<?php

declare(strict_types=1);

namespace Drupal\actools_lesson_layout_builder\Plugin\SectionStorage;

use Drupal\actools_lesson\Service\LessonAccess;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\layout_builder\Attribute\SectionStorage;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Plugin\SectionStorage\DefaultsSectionStorage;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Prevents native sections/overrides executing before managed render validation.
 *
 * This render-only guard stores nothing and exposes no editor. The main reader
 * hook builds validated shared defaults, or retains its original document.
 */
#[SectionStorage(id: 'actools_lesson_managed', weight: -1000, context_definitions: [
  'display' => new EntityContextDefinition('entity_view_display'),
  'entity' => new EntityContextDefinition('entity:node'),
  'view_mode' => new ContextDefinition('string', default_value: 'full'),
], handles_permission_check: TRUE, allow_inline_blocks: FALSE)]
final class ManagedReaderSectionStorage extends DefaultsSectionStorage {

  public function isApplicable(RefinableCacheableDependencyInterface $cacheability) {
    $node = $this->getContextValue('entity');
    $display = $this->getContextValue('display');
    $cacheability->addCacheableDependency($node)->addCacheableDependency($display);
    return $node instanceof NodeInterface && $display instanceof LayoutEntityDisplayInterface
      && $display->getOriginalMode() === 'full'
      && (LessonAccess::isLesson($node) || LessonAccess::isChapter($node));
  }

  public function getSections(): array {
    return [];
  }

  public function count(): int {
    return 0;
  }

  public function buildRoutes(RouteCollection $collection): void {}

  public function buildLocalTasks($base_plugin_definition): array {
    return [];
  }

  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = AccessResult::forbidden();
    return $return_as_object ? $result : FALSE;
  }

  public function save() {
    throw new \LogicException('Managed reader section storage is render-only.');
  }

}

