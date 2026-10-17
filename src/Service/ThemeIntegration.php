<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Theme\ThemeManagerInterface;

/** Adds optional compatibility for the actual active front-end/admin theme. */
final class ThemeIntegration {

  public function __construct(private readonly ThemeManagerInterface $themeManager) {}

  public function attach(array &$build): void {
    // A cached document must not carry theme-specific assets into another theme.
    CacheableMetadata::createFromRenderArray($build)->addCacheContexts(['theme'])->applyTo($build);
    $theme = $this->themeManager->getActiveTheme();
    if ($theme->getName() !== 'actools_bs5' && !isset($theme->getBaseThemeExtensions()['actools_bs5'])) {
      return;
    }
    $libraries = $build['#attached']['library'] ?? [];
    $libraries[] = 'actools_lesson/bs5_integration';
    $build['#attached']['library'] = array_values(array_unique($libraries));
  }

}
