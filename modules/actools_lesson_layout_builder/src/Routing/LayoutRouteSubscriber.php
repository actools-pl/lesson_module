<?php

declare(strict_types=1);

namespace Drupal\actools_lesson_layout_builder\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/** Includes core-generated override routes as well as Layout Builder AJAX. */
final class LayoutRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection->all() as $route) {
      if ($route->hasRequirement('_layout_builder_access')) {
        $route->setRequirement('_actools_lesson_shared_layout_access', 'TRUE');
      }
    }
  }

  public static function getSubscribedEvents(): array {
    // Core Layout Builder creates its routes at priority -110.
    return [RoutingEvents::ALTER => ['onAlterRoutes', -200]];
  }

}

