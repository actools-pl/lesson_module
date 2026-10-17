<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\file\FileInterface;

/**
 * Checks presence of teaching content, independently of scientific review.
 *
 * Incomplete prose and question bodies are valid draft work. Publication and
 * readiness use this same predicate. Ordinary field validation retains its
 * existing structure, source/upload/alt, format, permission and size rules.
 */
final class TeachingContentCompleteness {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ImageFactory $imageFactory,
  ) {}

  /** Every component must contain the content required by its supported type. */
  public function isCompleteStep(array $step, ?CacheableMetadata $cacheability = NULL): bool {
    if (!is_string($step['title'] ?? NULL) || !$this->hasText($step['title'])) {
      return FALSE;
    }
    $components = is_string($step['components'] ?? NULL)
      ? json_decode($step['components'], TRUE, 32)
      : NULL;
    if (!is_array($components) || !array_is_list($components) || !$components) {
      return FALSE;
    }
    $complete = TRUE;
    foreach ($components as $component) {
      // Evaluate every component so cache metadata includes later dependencies.
      if (!is_array($component) || !$this->isMeaningfulComponent($component, $cacheability)) {
        $complete = FALSE;
      }
    }
    return $complete;
  }

  /** Hints, answers and solutions do not substitute for a missing prompt. */
  public function isMeaningfulComponent(array $component, ?CacheableMetadata $cacheability = NULL): bool {
    return match ($component['type'] ?? '') {
      'text', 'result', 'error', 'table', 'example', 'practice' => $this->hasBody($component['body'] ?? NULL, $cacheability),
      'equation' => is_string($component['tex'] ?? NULL) && $this->hasText($component['tex']),
      'figure' => $this->hasFigure($component, $cacheability),
      default => FALSE,
    };
  }

  private function hasBody(mixed $body, ?CacheableMetadata $cacheability): bool {
    if (!is_array($body) || !is_string($body['value'] ?? NULL) || !is_string($body['format'] ?? NULL)) {
      return FALSE;
    }
    $format = $this->entityTypeManager->getStorage('filter_format')->load($body['format']);
    if ($cacheability) {
      $cacheability->addCacheTags(['config:filter.format.' . $body['format']]);
      if ($format) {
        $cacheability->addCacheableDependency($format);
      }
    }
    return $format && $format->status() && $this->hasText($body['value']);
  }

  private function hasFigure(array $component, ?CacheableMetadata $cacheability): bool {
    $fid = $component['fid'] ?? NULL;
    if (!is_int($fid) || $fid < 1) {
      return FALSE;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if ($cacheability) {
      $cacheability->addCacheTags(['file:' . $fid, 'config:system.image']);
      if ($file) {
        $cacheability->addCacheableDependency($file);
      }
    }
    if (!$file instanceof FileInterface
      || !in_array($file->getMimeType(), ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], TRUE)
      || !is_string($component['alt'] ?? NULL)
      || !$this->hasText($component['alt'])) {
      return FALSE;
    }
    try {
      return $this->imageFactory->get($file->getFileUri())->isValid();
    }
    catch (PluginException) {
      // A missing toolkit is an incomplete figure, not a broken workbench.
      return FALSE;
    }
  }

  /** Treat editor scaffolding, nonbreaking spaces and invisible text as empty. */
  private function hasText(string $text): bool {
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $visible = preg_replace('/[\p{Z}\p{C}\s]+/u', '', $text);
    return is_string($visible) && $visible !== '';
  }

}
