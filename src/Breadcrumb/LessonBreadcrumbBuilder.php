<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Breadcrumb;

use Drupal\actools_lesson\Service\LessonAccess;
use Drupal\actools_lesson\Service\OutlineResolver;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Uses canonical chapter ownership rather than a referer or another menu tree.
 */
final class LessonBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly OutlineResolver $outline,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL): bool {
    $node = $route_match->getParameter('node');
    $cacheable_metadata?->addCacheContexts(['route']);
    if ($node instanceof NodeInterface) {
      $cacheable_metadata?->addCacheableDependency($node);
    }
    return $node instanceof NodeInterface && LessonAccess::isLesson($node)
      && in_array($route_match->getRouteName(), ['entity.node.canonical', 'actools_lesson.reader', 'actools_lesson.print'], TRUE);
  }

  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $lesson = $route_match->getParameter('node');
    $outline = $this->outline->forLesson($lesson, $this->currentUser);
    $breadcrumb = (new Breadcrumb())
      ->addCacheContexts(['route', 'languages:language_content', 'languages:language_interface'])
      ->addCacheableDependency($lesson)
      ->addCacheableDependency($outline['cacheability'])
      ->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
    $chapter = $outline['chapter'] ?? NULL;
    if ($chapter instanceof NodeInterface) {
      $access = $chapter->access('view', $this->currentUser, TRUE);
      $title_access = $chapter->get('title')->access('view', $this->currentUser, TRUE);
      $breadcrumb->addCacheableDependency($chapter)->addCacheableDependency($access)->addCacheableDependency($title_access);
      if ($access->isAllowed() && $title_access->isAllowed()) {
        $breadcrumb->addLink($chapter->toLink());
      }
    }
    return $breadcrumb;
  }

}
