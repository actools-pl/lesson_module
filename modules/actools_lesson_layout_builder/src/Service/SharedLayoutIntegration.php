<?php

declare(strict_types=1);

namespace Drupal\actools_lesson_layout_builder\Service;

use Drupal\actools_lesson\Service\LessonAccess;
use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Section;
use Drupal\node\NodeInterface;

/** Optional presentation; canonical teaching content stays in node revisions. */
final class SharedLayoutIntegration {

  use StringTranslationTrait;

  public const PROVIDER = 'actools_lesson_layout_builder';
  public const READER_BLOCK = 'actools_lesson_reader';

  // These remain stored for ordinary nodes sharing the same bundle/display.
  // A managed reader supplies its own title and teaching fields exactly once.
  private const UNMANAGED_BLOCKS = ['field_block', 'extra_field_block', 'page_title_block'];
  private const COMPANION_BLOCKS = ['block_content', 'inline_block', 'system_menu_block'];
  private const LAYOUTS = ['layout_onecol', 'layout_twocol', 'layout_twocol_bricks', 'layout_threecol_25_50_25', 'layout_threecol_33_34_33', 'layout_twocol_section', 'layout_threecol_section', 'layout_fourcol_section'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly ContextRepositoryInterface $contextRepository,
    private readonly RouteMatchInterface $routeMatch,
    private readonly BlockManagerInterface $blockManager,
  ) {}

  public function supportsDisplay(EntityViewDisplayInterface $display): bool {
    return $display->getTargetEntityTypeId() === 'node'
      && in_array($display->getMode(), ['full', 'default'], TRUE)
      && in_array($display->getTargetBundle(), ['actools_resource', 'actools_topic'], TRUE);
  }

  public function isEnabled(EntityViewDisplayInterface $display): bool {
    return (bool) $display->getThirdPartySetting(self::PROVIDER, 'enabled', FALSE);
  }

  /** Errors are also used by the native default-layout Save validation. */
  public function validationErrors(EntityViewDisplayInterface $display): array {
    if (!$this->isEnabled($display)) {
      return [];
    }
    if (!$this->supportsDisplay($display) || !$display instanceof LayoutEntityDisplayInterface || !$display->isLayoutBuilderEnabled()) {
      return [(string) $this->t('Shared Actools layouts require Layout Builder on the Full content or Default display of Resources or Topics.')];
    }
    $errors = [];
    $readers = 0;
    try {
      foreach ($display->getSections() as $section) {
        if (!$section instanceof Section) {
          $errors[] = (string) $this->t('The shared layout contains an invalid section.');
          continue;
        }
        if (!in_array($section->getLayoutId(), self::LAYOUTS, TRUE)) {
          $errors[] = (string) $this->t('Shared Actools layouts support the core one, two, three and four column layouts.');
          continue;
        }
        $regions = $section->getLayout()->getPluginDefinition()->getRegionNames();
        foreach ($section->getComponents() as $component) {
          $id = $component->getPluginId();
          $base = explode(':', $id, 2)[0];
          if (!in_array($component->getRegion(), $regions, TRUE)) {
            $errors[] = (string) $this->t('Every shared layout block must be in a region provided by its section.');
          }
          if (!$this->blockManager->hasDefinition($id)) {
            $errors[] = (string) $this->t('The shared layout contains an unavailable block.');
          }
          if ($id === self::READER_BLOCK) {
            $readers++;
            $config = $component->toArray()['configuration'];
            if (($config['context_mapping']['node'] ?? NULL) !== 'layout_builder.entity') {
              $errors[] = (string) $this->t('Map the complete Actools reader to the content item being viewed (layout_builder.entity).');
            }
            if (!empty($config['label_display'])) {
              $errors[] = (string) $this->t('Hide the complete reader block title; the reader supplies the page heading.');
            }
          }
          elseif (!in_array($base, [...self::UNMANAGED_BLOCKS, ...self::COMPANION_BLOCKS], TRUE)) {
            $errors[] = (string) $this->t('Shared Actools layouts support supplementary content blocks and menus. Other block types, including Views and entity embeds, are unsupported.');
          }
        }
      }
    }
    catch (PluginException | ContextException | \InvalidArgumentException | \UnexpectedValueException $exception) {
      $errors[] = (string) $this->t('The shared layout contains an unavailable or invalid layout or block.');
    }
    if ($readers !== 1) {
      $errors[] = (string) $this->t('The shared layout must contain exactly one Complete Actools chapter or lesson reader block.');
    }
    return array_values(array_unique($errors));
  }

  /**
   * Builds saved shared defaults, or NULL to retain the original managed reader.
   *
   * No section-storage lookup is used: it could select an individual override.
   * Preview/revision routes deliberately retain the existing exact-node reader.
   */
  public function build(NodeInterface $node, EntityViewDisplayInterface $display): ?array {
    if ($node->isNew() || !$node->isPublished() || !$node->isDefaultRevision()
      || !empty($node->in_preview) || (!LessonAccess::isLesson($node) && !LessonAccess::isChapter($node))
      || in_array($this->routeMatch->getRouteName(), ['entity.node.preview', 'entity.node.revision', 'actools_lesson.draft_preview'], TRUE)) {
      return NULL;
    }
    $savedDisplay = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged($display->id());
    if (!$savedDisplay instanceof EntityViewDisplayInterface || !$this->isEnabled($savedDisplay)
      || $this->validationErrors($savedDisplay) || $savedDisplay->getTargetBundle() !== $node->bundle()) {
      return NULL;
    }
    // Display-alter hooks, tempstore or unsaved caller edits must not replace
    // the explicitly published shared presentation configuration.
    $display = $savedDisplay;
    // All companion blocks receive the published default, never a modified
    // in-memory entity or a caller-selected historical/private revision.
    $default = $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id());
    if (!$default instanceof NodeInterface || !$default->isPublished()
      || (!LessonAccess::isLesson($default) && !LessonAccess::isChapter($default))) {
      return NULL;
    }
    $node = $this->entityRepository->getTranslationFromContext($default);
    $access = $node->access('view', NULL, TRUE)->andIf($node->get('title')->access('view', NULL, TRUE));
    if (!$access->isAllowed()) {
      // Let the original reader enforce the same access boundary.
      return NULL;
    }
    $contexts = [
      'view_mode' => new Context(ContextDefinition::create('string'), $display->getMode()),
      'entity' => EntityContext::fromEntity($node),
      'display' => EntityContext::fromEntity($display),
      'layout_builder.entity' => EntityContext::fromEntity($node),
    ] + $this->contextRepository->getRuntimeContexts(array_keys($this->contextRepository->getAvailableContexts()));
    $build = [
      '#type' => 'container',
      // The node hook may replace a build during its existing #pre_render;
      // render-element defaults are not recalculated at that point.
      '#theme_wrappers' => ['container'],
      '#attributes' => ['class' => ['actools-lesson-shared-layout']],
      '#attached' => ['library' => ['actools_lesson_layout_builder/shared_layout']],
    ];
    try {
      foreach ($display->getSections() as $delta => $savedSection) {
        // Never edit the stored default. Native field blocks remain available
        // to unmanaged nodes of the same bundle in ordinary Layout Builder.
        $section = clone $savedSection;
        foreach ($section->getComponents() as $component) {
          if (in_array(explode(':', $component->getPluginId(), 2)[0], self::UNMANAGED_BLOCKS, TRUE)) {
            $section->removeComponent($component->getUuid());
          }
        }
        $build['sections'][$delta] = $section->toRenderArray($contexts, FALSE);
      }
    }
    catch (PluginException | ContextException | \InvalidArgumentException | \UnexpectedValueException $exception) {
      return NULL;
    }
    CacheableMetadata::createFromObject($node)->addCacheableDependency($display)->addCacheableDependency($access)
      ->addCacheContexts(['route', 'url.query_args:step', 'url.query_args:view', 'languages:language_content'])->applyTo($build);
    return $build;
  }

}

