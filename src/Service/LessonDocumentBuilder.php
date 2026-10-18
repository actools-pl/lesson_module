<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One accessible lesson document, presented as guided, continuous or print.
 */
final class LessonDocumentBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly OutlineResolver $outline,
    private readonly ComponentRenderer $componentRenderer,
    private readonly EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Builds the reader. Public URLs never select a non-default revision.
   */
  public function build(NodeInterface $lesson, string $mode = 'guided', ?string $stepId = NULL, bool $preview = FALSE, bool $revisionPreview = FALSE): array {
    if (!in_array($mode, ['guided', 'all', 'print'], TRUE)) {
      $mode = 'guided';
    }
    if (!$preview) {
      if (!$lesson->id()) {
        throw new NotFoundHttpException();
      }
      $default = $this->entityTypeManager->getStorage('node')->load($lesson->id());
      if (!$default instanceof NodeInterface || !$default->isPublished()) {
        throw new AccessDeniedHttpException();
      }
      $lesson = $this->entityRepository->getTranslationFromContext($default);
    }
    $metadata = CacheableMetadata::createFromObject($lesson);
    // Only the native revision route supplies revisionPreview. Ordinary public
    // reader URLs always reload the default revision above.
    $access = $lesson->access($preview ? ($revisionPreview ? 'view revision' : 'update') : 'view', $this->currentUser, TRUE);
    $metadata->addCacheableDependency($access);
    if (!$this->isManaged($lesson) || !$access->isAllowed()) {
      throw new AccessDeniedHttpException();
    }
    $title_access = $lesson->get('title')->access('view', $this->currentUser, TRUE);
    $metadata->addCacheableDependency($title_access);
    if (!$title_access->isAllowed()) {
      throw new AccessDeniedHttpException();
    }

    $learning_path_label = [];
    $chapter_navigation_label = 'Chapter lessons';
    $position_prefix = 'Lesson';
    if ($lesson->hasField('field_actools_path')) {
      $path_field = $lesson->get('field_actools_path');
      $path_access = $path_field->access('view', $this->currentUser, TRUE);
      $metadata->addCacheableDependency($path_access)->addCacheableDependency($path_field->getFieldDefinition());
      if ($path_access->isAllowed()) {
        if ($path_field->value === 'core') {
          $learning_path_label = ['#plain_text' => 'Core lesson'];
          $chapter_navigation_label = 'Core lessons';
          $position_prefix = 'Core lesson';
        }
        elseif ($path_field->value === 'extension') {
          $learning_path_label = ['#plain_text' => 'Optional extension'];
          $chapter_navigation_label = 'Optional extensions';
          $position_prefix = 'Extension lesson';
        }
      }
    }

    $readable = $this->readableSteps($lesson, $preview);
    $metadata = $metadata->merge($readable['cacheability']);
    $steps = $readable['steps'];
    $current_index = 0;
    $status = [];
    if ($stepId !== NULL && $stepId !== '') {
      $found = FALSE;
      foreach ($steps as $index => $step) {
        if ($step['id'] === $stepId) {
          $current_index = $index;
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        $status = ['#plain_text' => 'That step is no longer available. Choose an available step below.'];
      }
    }
    $current = $steps[$current_index] ?? NULL;
    if (!$steps && !$preview) {
      $status = ['#plain_text' => 'This lesson is being prepared. No reviewed steps are available yet.'];
    }

    $outline = $lesson->id() ? $this->outline->forLesson($lesson, $this->currentUser, $preview) : [
      'chapter' => NULL, 'lessons' => [], 'previous' => NULL, 'next' => NULL, 'cacheability' => new CacheableMetadata(),
    ];
    $metadata = $metadata->merge($outline['cacheability']);
    $chapter = $outline['chapter'] ?? NULL;
    $chapter_link = $chapter instanceof NodeInterface ? $this->entityLink($chapter) : [];
    $chapter_items = [];
    $lesson_position = '';
    $outline_lessons = array_values($outline['lessons'] ?? []);
    foreach ($outline_lessons as $index => $listed_lesson) {
      // The resolver must filter, but rechecking the displayed label here keeps
      // this renderer safe if a caller supplies a less specialised resolver.
      $listed_access = $listed_lesson->access('view', $this->currentUser, TRUE);
      $listed_title_access = $listed_lesson->get('title')->access('view', $this->currentUser, TRUE);
      $metadata->addCacheableDependency($listed_lesson)->addCacheableDependency($listed_access)->addCacheableDependency($listed_title_access);
      if (!$listed_access->isAllowed() || !$listed_title_access->isAllowed()) {
        continue;
      }
      if ($preview && !$listed_lesson->isPublished()) {
        // The ordinary public study route deliberately rejects draft content.
        // Native canonical draft views remain private and separately authorised.
        $listed_url = $listed_lesson->toUrl('canonical')->toString(TRUE);
        $metadata->addCacheableDependency($listed_url);
        $listed_url_string = $listed_url->getGeneratedUrl();
      }
      else {
        $listed_url_string = $this->url($listed_lesson, 'guided', NULL, FALSE, $metadata);
      }
      $chapter_items[] = [
        'title' => ['#plain_text' => $listed_lesson->label()],
        'url' => $listed_url_string,
        'current' => $listed_lesson->id() === $lesson->id(),
        'number' => count($chapter_items) + 1,
      ];
    }
    foreach ($chapter_items as $item) {
      if ($item['current']) {
        $lesson_position = $position_prefix . ' ' . $item['number'] . ' of ' . count($chapter_items);
        break;
      }
    }

    $step_links = [];
    foreach ($steps as $index => $step) {
      $step_links[] = [
        'id' => $step['id'],
        'title' => ['#plain_text' => $step['title']],
        'url' => $this->url($lesson, $mode === 'guided' ? 'guided' : 'all', $step['id'], $preview, $metadata),
        'current' => $index === $current_index,
        'number' => $index + 1,
        'optional' => $step['optional'],
      ];
    }
    $steps_content = [];
    foreach ($steps as $index => $step) {
      if ($mode === 'guided' && $index !== $current_index) {
        continue;
      }
      $components = $this->componentRenderer->renderStep($step['components'], $lesson, $mode === 'print');
      $steps_content[] = [
        '#theme' => 'actools_lesson_step',
        '#id' => $step['id'],
        '#title' => ['#plain_text' => $step['title']],
        '#number' => $index + 1,
        '#content' => $components,
        '#optional' => $step['optional'],
        '#reviewed' => $step['reviewed'],
        '#preview' => $preview,
      ];
    }

    $all_url = $this->url($lesson, 'all', $current['id'] ?? NULL, $preview, $metadata);
    $guided_url = $this->url($lesson, 'guided', $current['id'] ?? NULL, $preview, $metadata);
    $print_url = $this->url($lesson, 'print', NULL, $preview, $metadata);
    $previous_step = $current_index > 0 ? $this->stepLink($lesson, $steps[$current_index - 1], 'Previous step: ', $preview) : [];
    $next_step = isset($steps[$current_index + 1]) ? $this->stepLink($lesson, $steps[$current_index + 1], 'Next: ', $preview) : [];
    $progress = [];
    if (!$preview && $mode !== 'print' && $lesson->id() && $current) {
      $progress = [
        '#lazy_builder' => ['actools_lesson.progress_form:build', [(int) $lesson->id(), $current['id'], $mode]],
        '#create_placeholder' => TRUE,
      ];
    }
    $build = [
      '#theme' => 'actools_lesson_reader',
      '#title' => ['#plain_text' => $lesson->label()],
      '#summary' => $this->field($lesson, 'field_actools_summary'),
      '#introduction' => ($mode !== 'guided' || $current_index === 0) ? $this->field($lesson, 'field_actools_body') : [],
      '#chapter_link' => $chapter_link,
      '#chapter_return' => $chapter_link,
      '#chapter_items' => $chapter_items,
      '#lesson_position' => $lesson_position,
      '#learning_path_label' => $learning_path_label,
      '#chapter_navigation_label' => $chapter_navigation_label,
      '#mode' => $mode,
      '#all_url' => $all_url,
      '#guided_url' => $guided_url,
      '#print_url' => $print_url,
      '#steps' => $step_links,
      '#current_step_title' => $current ? ['#plain_text' => $current['title']] : [],
      '#current_step_number' => $current ? $current_index + 1 : 0,
      '#total_steps' => count($steps),
      '#required_steps' => count(array_filter($steps, static fn (array $step): bool => !$step['optional'])),
      '#steps_content' => $steps_content,
      '#previous_step' => $previous_step,
      '#next_step' => $next_step,
      '#previous_lesson' => !$preview && ($current_index === 0 || $mode !== 'guided') && ($outline['previous'] ?? NULL) instanceof NodeInterface ? $this->entityLink($outline['previous'], 'Previous lesson: ') : [],
      '#next_lesson' => !$preview && (!isset($steps[$current_index + 1]) || $mode !== 'guided') && ($outline['next'] ?? NULL) instanceof NodeInterface ? $this->entityLink($outline['next'], 'Next lesson: ') : [],
      '#progress' => $progress,
      '#objectives' => $this->lines($lesson, 'field_actools_objectives'),
      '#prerequisites' => $this->lines($lesson, 'field_actools_prerequisites'),
      '#preview' => $preview,
      '#status' => $status,
      '#lesson_id' => $lesson->id(),
      '#attached' => ['library' => ['actools_lesson/reader']],
      '#cache' => [
        'keys' => ['actools_lesson', 'reader', $lesson->id() ?: 'new', $lesson->getRevisionId() ?: 'new', $mode, $current['id'] ?? 'none'],
        'contexts' => ['route', 'url.query_args:step', 'url.query_args:view', 'languages:language_content', 'languages:language_interface'],
      ],
    ];
    if ($preview) {
      $metadata->setCacheMaxAge(0);
      if (!$status) {
        $build['#status'] = ['#plain_text' => $revisionPreview
          ? 'Private revision preview — this is not the current public lesson.'
          : 'Private draft preview — this is not the public lesson.'];
      }
    }
    $metadata->merge(CacheableMetadata::createFromRenderArray($build))->applyTo($build);
    return $build;
  }

  /**
   * Returns validated step data plus access metadata for progress and rendering.
   *
   * Editorial "reviewed" is not the learner's personal completion state.
   *
   * @return array{steps: array, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   */
  public function readableSteps(NodeInterface $lesson, bool $preview = FALSE): array {
    $metadata = CacheableMetadata::createFromObject($lesson);
    $steps = [];
    if (!$lesson->hasField('field_actools_lesson_steps')) {
      return ['steps' => $steps, 'cacheability' => $metadata];
    }
    $items = $lesson->get('field_actools_lesson_steps');
    $access = $items->access('view', $this->currentUser, TRUE);
    $metadata->addCacheableDependency($access)->addCacheableDependency($items->getFieldDefinition());
    if (!$access->isAllowed()) {
      return ['steps' => $steps, 'cacheability' => $metadata];
    }
    $seen = [];
    $component_ids = [];
    foreach ($items as $item) {
      $value = $item->getValue();
      $id = $value['step_id'] ?? '';
      $title = $value['title'] ?? '';
      if (!is_string($id) || !Uuid::isValid($id) || isset($seen[$id]) || !is_string($title) || trim($title) === '') {
        continue;
      }
      if (!$preview && empty($value['reviewed'])) {
        continue;
      }
      $raw = $value['components'] ?? '[]';
      if (!is_string($raw) || strlen($raw) > 2_000_000) {
        continue;
      }
      try {
        $components = json_decode($raw, TRUE, 64, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        continue;
      }
      if (!is_array($components) || !array_is_list($components) || count($components) > 200) {
        continue;
      }
      $normalised_components = [];
      foreach ($components as $component) {
        $component_id = is_array($component) ? ($component['id'] ?? '') : '';
        if (!is_string($component_id) || !Uuid::isValid($component_id) || isset($component_ids[$component_id])) {
          continue;
        }
        $component_ids[$component_id] = TRUE;
        $normalised_components[] = $component;
      }
      $steps[] = [
        'id' => $id,
        'title' => $title,
        'optional' => !empty($value['optional']),
        'reviewed' => !empty($value['reviewed']),
        'content_version' => max(1, (int) ($value['content_version'] ?? 1)),
        'components' => $normalised_components,
      ];
      $seen[$id] = TRUE;
    }
    return ['steps' => $steps, 'cacheability' => $metadata];
  }

  private function isManaged(NodeInterface $lesson): bool {
    return $lesson->bundle() === 'actools_resource'
      && $lesson->hasField('field_actools_lesson_managed')
      && (bool) $lesson->get('field_actools_lesson_managed')->value
      && $lesson->hasField('field_actools_kind')
      && $lesson->get('field_actools_kind')->value === 'lesson';
  }

  private function field(NodeInterface $lesson, string $field_name): array {
    if (!$lesson->hasField($field_name)) {
      return [];
    }
    // FieldItemList::view retains field/formatter/deferred access and cacheability,
    // including metadata when the field is denied or empty.
    return $lesson->get($field_name)->view(['label' => 'hidden']);
  }

  /** Renders the authoring contract's one-item-per-line orientation fields. */
  private function lines(NodeInterface $lesson, string $field_name): array {
    if (!$lesson->hasField($field_name)) {
      return [];
    }
    $field = $lesson->get($field_name);
    $access = $field->access('view', $this->currentUser, TRUE);
    $metadata = CacheableMetadata::createFromObject($lesson)
      ->addCacheableDependency($field->getFieldDefinition())
      ->addCacheableDependency($access);
    $build = [];
    if ($access->isAllowed() && !$field->isEmpty()) {
      $lines = array_values(array_filter(
        array_map('trim', preg_split('/\R/u', (string) $field->value) ?: []),
        static fn (string $line): bool => $line !== '',
      ));
      if ($lines) {
        $build = ['#theme' => 'item_list', '#items' => array_map(static fn (string $line): array => ['#plain_text' => $line], $lines)];
      }
    }
    $metadata->applyTo($build);
    return $build;
  }

  private function entityLink(NodeInterface $node, string $prefix = ''): array {
    $access = $node->access('view', $this->currentUser, TRUE);
    $title_access = $node->get('title')->access('view', $this->currentUser, TRUE);
    $metadata = CacheableMetadata::createFromObject($node)->addCacheableDependency($access)->addCacheableDependency($title_access);
    $build = [];
    if ($access->isAllowed() && $title_access->isAllowed()) {
      $build = ['#type' => 'link', '#title' => $prefix . $node->label(), '#url' => $node->toUrl('canonical')];
    }
    $metadata->applyTo($build);
    return $build;
  }

  private function stepLink(NodeInterface $lesson, array $step, string $prefix, bool $preview): array {
    $url = $preview
      ? Url::fromRoute('<current>', [], ['query' => ['view' => 'guided', 'step' => $step['id']], 'fragment' => 'step-' . $step['id']])
      : Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()], ['query' => ['view' => 'guided', 'step' => $step['id']], 'fragment' => 'step-' . $step['id']]);
    return ['#type' => 'link', '#title' => $prefix . $step['title'], '#url' => $url];
  }

  private function url(NodeInterface $lesson, string $mode, ?string $step, bool $preview, CacheableMetadata $metadata): string {
    if ($preview) {
      $url = Url::fromRoute('<current>', [], ['query' => ['view' => $mode, 'step' => $step], 'fragment' => $step ? 'step-' . $step : '']);
    }
    elseif ($mode === 'print') {
      $url = Url::fromRoute('actools_lesson.print', ['node' => $lesson->id()]);
    }
    else {
      $url = Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()], ['query' => ['view' => $mode, 'step' => $step], 'fragment' => $step ? 'step-' . $step : '']);
    }
    $generated = $url->toString(TRUE);
    $metadata->addCacheableDependency($generated);
    return $generated->getGeneratedUrl();
  }

}
