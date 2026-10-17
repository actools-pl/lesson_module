<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Component\Uuid\Uuid;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;

/**
 * Renders bounded scientific components without trusting stored HTML as markup.
 */
final class ComponentRenderer {

  private const TYPES = ['text', 'equation', 'figure', 'result', 'error', 'example', 'practice', 'table'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ImageFactory $imageFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Builds one component; denied figures return metadata without empty captions.
   */
  public function render(array $component, NodeInterface $lesson, bool $print = FALSE): array {
    $metadata = CacheableMetadata::createFromObject($lesson);
    $type = $component['type'] ?? '';
    $id = $component['id'] ?? '';
    if (!is_string($type) || !in_array($type, self::TYPES, TRUE) || !is_string($id) || !Uuid::isValid($id)) {
      $empty = [];
      $metadata->applyTo($empty);
      return $empty;
    }

    $build = [
      '#theme' => 'actools_lesson_component',
      '#id' => $id,
      '#component_type' => $type,
      '#heading' => $this->plain($component['heading'] ?? ''),
      '#body' => $this->richText($component['body'] ?? NULL, $lesson),
      '#hint' => $this->richText($component['hint'] ?? NULL, $lesson),
      '#answer' => $this->richText($component['answer'] ?? NULL, $lesson),
      '#solution' => $this->richText($component['solution'] ?? NULL, $lesson),
      '#tex' => is_string($component['tex'] ?? NULL) ? $component['tex'] : '',
      '#figure' => [],
      '#caption' => [],
      '#description' => [],
      '#source' => $this->plain($component['source'] ?? ''),
      '#equation_number' => [],
      '#expanded' => $print,
    ];

    if ($type === 'figure') {
      $fid = filter_var($component['fid'] ?? NULL, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
      $file = $fid ? $this->entityTypeManager->getStorage('file')->load($fid) : NULL;
      if (!$file instanceof FileInterface) {
        $empty = [];
        $metadata->addCacheTags(['file_list'])->applyTo($empty);
        return $empty;
      }
      $access = $file->access('download', $this->currentUser, TRUE);
      $metadata->addCacheableDependency($file)->addCacheableDependency($access);
      // The initial editor accepts ordinary raster images only. Do not inline
      // uploaded SVG/XML, nor interpret an author's arbitrary source URI.
      if (!$access->isAllowed() || !in_array($file->getMimeType(), ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], TRUE)) {
        $empty = [];
        $metadata->applyTo($empty);
        return $empty;
      }
      $metadata->addCacheTags(['config:system.image']);
      $image = NULL;
      try {
        $image = $this->imageFactory->get($file->getFileUri());
      }
      catch (PluginException) {
        // A misconfigured optional image toolkit must not take down the lesson.
        // The original raster image remains safe to display through core's
        // image theme; dimensions/derivatives resume when the site is repaired.
      }
      if ($image && !$image->isValid()) {
        $empty = [];
        $metadata->applyTo($empty);
        return $empty;
      }
      $alt = is_string($component['alt'] ?? NULL) ? $component['alt'] : '';
      $image_build = [
        '#theme' => 'image',
        '#uri' => $file->getFileUri(),
        '#alt' => $alt,
        '#width' => $image?->getWidth(),
        '#height' => $image?->getHeight(),
        '#attributes' => ['loading' => 'lazy', 'decoding' => 'async'],
      ];
      // Keep the native image render pipeline and optionally use the module's
      // non-cropping derivative, when a compatible style has been installed.
      $style = $this->entityTypeManager->getStorage('image_style')->load('actools_lesson_diagram');
      if ($style && $image) {
        $metadata->addCacheableDependency($style);
        $image_build['#theme'] = 'image_style';
        $image_build['#style_name'] = $style->id();
      }
      $build['#figure'] = [
        '#type' => 'link',
        '#title' => $image_build,
        '#url' => $this->fileUrlGenerator->generate($file->getFileUri()),
        // The linked image's meaningful alt text names this particular figure.
        '#attributes' => ['class' => ['actools-lesson-original']],
      ];
      $build['#caption'] = $this->plain($component['caption'] ?? '');
      $build['#description'] = $this->plain($component['description'] ?? '');
    }

    $metadata->merge(CacheableMetadata::createFromRenderArray($build))->applyTo($build);
    return $build;
  }

  /**
   * Uses Drupal's text filters; viewing text never requires format-use rights.
   */
  private function richText(mixed $value, NodeInterface $lesson): array {
    if (!is_array($value) || !is_string($value['value'] ?? NULL) || trim($value['value']) === '') {
      return [];
    }
    $format_id = is_string($value['format'] ?? NULL) && preg_match('/^[a-z0-9_]{1,64}$/', $value['format']) ? $value['format'] : '';
    $format = $format_id !== '' ? $this->entityTypeManager->getStorage('filter_format')->load($format_id) : NULL;
    if (!$format || !$format->status()) {
      return [
        '#plain_text' => $value['value'],
        '#cache' => ['tags' => $format_id !== '' ? ['config:filter.format.' . $format_id] : []],
      ];
    }
    return [
      '#type' => 'processed_text',
      '#text' => $value['value'],
      '#format' => $format_id,
      '#langcode' => $lesson->language()->getId(),
      '#cache' => ['tags' => $format->getCacheTags()],
    ];
  }

  private function plain(mixed $value): array {
    return is_string($value) && trim($value) !== '' ? ['#plain_text' => $value] : [];
  }

}
