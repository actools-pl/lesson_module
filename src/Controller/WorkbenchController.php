<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Controller;

use Drupal\actools_lesson\Service\LessonAccess;
use Drupal\actools_lesson\Service\EditorialWorkflow;
use Drupal\actools_lesson\Service\OutlineResolver;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Native content workbench and chapter landing page. */
final class WorkbenchController extends ControllerBase {

  public function __construct(EntityTypeManagerInterface $manager, private readonly EntityFormBuilderInterface $lessonFormBuilder, private readonly OutlineResolver $outline, AccountProxyInterface $account, private readonly EditorialWorkflow $editorial) {
    $this->entityTypeManager = $manager;
    $this->currentUser = $account;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('entity.form_builder'), $container->get('actools_lesson.outline'), $container->get('current_user'), $container->get('actools_lesson.editorial'));
  }

  public function addLesson(): array {
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'actools_resource', 'status' => FALSE,
      'field_actools_lesson_managed' => TRUE, 'field_actools_kind' => 'lesson', 'field_actools_path' => 'core',
    ]);
    return $this->lessonFormBuilder->getForm($node);
  }

  public function addChapter(): array {
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'actools_topic', 'status' => FALSE, 'field_actools_lesson_chapter' => TRUE,
    ]);
    return $this->lessonFormBuilder->getForm($node);
  }

  public function dashboard(): array {
    $account = $this->currentUser();
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['actools-author-workbench']],
      '#attached' => ['library' => ['actools_lesson/author']],
      'intro' => ['#type' => 'container', '#attributes' => ['class' => ['actools-author-help']], 'text' => ['#markup' => $this->t('Build lesson drafts, assign them to a chapter and publish reviewed steps. Editing a published lesson creates a private draft while learners keep reading the published version.')]],
    ];
    $build['actions'] = ['#type' => 'container', '#attributes' => ['class' => ['actools-editorial-actions']]];
    foreach (['actools_topic' => ['actools_lesson.add_chapter', 'Create chapter'], 'actools_resource' => ['actools_lesson.add_lesson', 'Create lesson']] as $bundle => [$route, $text]) {
      if ($this->entityTypeManager->getAccessControlHandler('node')->createAccess($bundle, $account)) {
        $build['actions'][$bundle] = ['#type' => 'link', '#title' => $this->t($text), '#url' => Url::fromRoute($route), '#attributes' => ['class' => ['button', 'button--primary']]];
      }
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE);
    $query->condition($query->orConditionGroup()->condition('field_actools_lesson_managed', 1)->condition('field_actools_lesson_chapter', 1));
    $ids = $query->sort('changed', 'DESC')->pager(50)->execute();
    $cache = (new CacheableMetadata())->addCacheContexts(['user', 'user.permissions', 'user.node_grants:view'])->addCacheTags(['node_list']);
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $operations = [];
      $edit = $node->access('update', $account, TRUE);
      $view = $node->access('view', $account, TRUE);
      $cache->addCacheableDependency($node)->addCacheableDependency($edit)->addCacheableDependency($view);
      $isLesson = LessonAccess::isLesson($node);
      $display = $node;
      $latestEditable = FALSE;
      if ($isLesson && $edit->isAllowed()) {
        $latest = $this->editorial->latest($node);
        $latestAccess = $latest->access('update', $account, TRUE);
        $cache->addCacheableDependency($latest)->addCacheableDependency($latestAccess);
        if ($latestAccess->isAllowed()) {
          $display = $latest;
          $latestEditable = TRUE;
        }
      }
      $access = $display->get('title')->access('view', $account, TRUE);
      $cache->addCacheableDependency($display)->addCacheableDependency($access);
      if (!$access->isAllowed() || (!$edit->isAllowed() && !$view->isAllowed())) {
        continue;
      }
      $pending = $isLesson && $edit->isAllowed() && (int) $display->getRevisionId() !== (int) $node->getRevisionId();
      if ($edit->isAllowed()) {
        $operations[] = Link::createFromRoute($this->t('Edit'), 'entity.node.edit_form', ['node' => $node->id()])->toRenderable();
        if ($isLesson && $latestEditable) {
          $preview = $this->editorial->previewAccess($display);
          $cache->addCacheableDependency($preview);
          if ($preview->isAllowed()) {
            $operations[] = Link::createFromRoute($this->t('Preview draft'), 'actools_lesson.draft_preview', ['node' => $node->id()])->toRenderable();
          }
        }
      }
      if ($view->isAllowed() && $node->isPublished()) {
        $publicAccess = $isLesson ? \Drupal::service('actools_lesson.access')->reader($node, $account) : $view;
        $cache->addCacheableDependency($publicAccess);
        if ($publicAccess->isAllowed()) {
          $operations[] = Link::fromTextAndUrl($this->t('View published'), $isLesson ? Url::fromRoute('actools_lesson.reader', ['node' => $node->id()]) : $node->toUrl())->toRenderable();
        }
      }
      $assignment = ['#plain_text' => $isLesson ? (string) $this->t('Not assigned to a chapter') : (string) $this->t('Chapter outline')];
      $readiness = ['#plain_text' => '—'];
      if ($isLesson) {
        if ($owner = $this->outline->owner((int) $node->id())) {
          $ownerAccess = $owner->access('view', $account, TRUE)->orIf($owner->access('update', $account, TRUE));
          $ownerAccess = $ownerAccess->andIf($owner->get('title')->access('view', $account, TRUE))
            ->andIf($owner->get('field_actools_lessons')->access('view', $account, TRUE));
          $cache->addCacheableDependency($owner)->addCacheableDependency($ownerAccess);
          $assignment = $ownerAccess->isAllowed()
            ? Link::fromTextAndUrl($owner->label(), $owner->access('view', $account) ? $owner->toUrl() : Url::fromRoute('entity.node.edit_form', ['node' => $owner->id()]))->toRenderable()
            : ['#plain_text' => (string) $this->t('Chapter assignment unavailable')];
        }
        $status = $this->editorial->readiness($display, $account);
        $cache = $cache->merge($status['cacheability']);
        $readiness = ['#plain_text' => $status['label']];
      }
      $state = $node->isPublished()
        ? ($pending ? $this->t('Published with draft') : $this->t('Published'))
        : $this->t('Draft');
      $rows[] = [
        ['data' => ['#plain_text' => $display->label()]],
        $isLesson ? $this->t('Lesson') : $this->t('Chapter'),
        ['data' => $assignment],
        ['data' => $readiness],
        $state,
        ['data' => ['#theme' => 'item_list', '#items' => $operations]],
      ];
    }
    $build['table'] = ['#type' => 'table', '#prefix' => '<div class="actools-author-table" role="region" aria-label="Lesson workbench" tabindex="0">', '#suffix' => '</div>', '#header' => [$this->t('Title'), $this->t('Type'), $this->t('Chapter'), $this->t('Readiness'), $this->t('State'), $this->t('Actions')], '#rows' => $rows, '#empty' => $this->t('Create your first chapter or lesson draft.')];
    $build['pager'] = ['#type' => 'pager'];
    $cache->applyTo($build);
    return $build;
  }

  public function chapter(NodeInterface $chapter, bool $preview = FALSE): array {
    $outline = $this->outline->forChapter($chapter, $this->currentUser(), $preview);
    $cache = $outline['cacheability'];
    $titleAccess = $chapter->get('title')->access('view', $this->currentUser(), TRUE);
    $cache->addCacheableDependency($titleAccess);
    $build = [
      '#theme' => 'actools_lesson_chapter',
      '#title' => ['#plain_text' => $titleAccess->isAllowed() ? $chapter->label() : (string) $this->t('Chapter')],
      '#code' => $this->field($chapter, 'field_actools_chapter_code', $cache),
      '#introduction' => $this->field($chapter, 'field_actools_body', $cache),
      '#objectives' => $this->lines($chapter, 'field_actools_objectives', $cache),
      '#prerequisites' => $this->lines($chapter, 'field_actools_prerequisites', $cache),
      '#lessons' => [], '#start_link' => [], '#preview' => $preview,
      '#continue_progress' => !$preview && $chapter->id() ? [
        '#lazy_builder' => ['actools_lesson.chapter_progress:build', [(int) $chapter->id()]],
        '#create_placeholder' => TRUE,
      ] : [],
      '#attached' => ['library' => ['actools_lesson/reader']],
    ];
    foreach ($outline['lessons'] as $index => $lesson) {
      $build['#lessons'][] = [
        'title' => ['#plain_text' => $lesson->label()],
        'summary' => $this->field($lesson, 'field_actools_summary', $cache),
        'url' => ($preview && !$lesson->isPublished() ? $lesson->toUrl() : Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()])),
        'number' => $index + 1,
        'path' => $lesson->get('field_actools_path')->value,
      ];
      if (!$build['#start_link'] && $lesson->get('field_actools_path')->value === 'core') {
        $build['#start_link'] = ['#type' => 'link', '#title' => $this->t('Start learning'), '#url' => ($preview && !$lesson->isPublished() ? $lesson->toUrl() : Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()])), '#attributes' => ['class' => ['actools-button', 'actools-button--primary']]];
      }
    }
    if (!$build['#start_link'] && $outline['lessons']) {
      $first = reset($outline['lessons']);
      $build['#start_link'] = ['#type' => 'link', '#title' => $this->t('Explore the lessons'), '#url' => ($preview && !$first->isPublished() ? $first->toUrl() : Url::fromRoute('actools_lesson.reader', ['node' => $first->id()])), '#attributes' => ['class' => ['actools-button', 'actools-button--primary']]];
    }
    $cache->applyTo($build);
    if ($preview) {
      $build['#cache']['max-age'] = 0;
    }
    return $build;
  }

  private function field(NodeInterface $node, string $name, CacheableMetadata $cache): array {
    if (!$node->hasField($name)) {
      return [];
    }
    $field = $node->get($name);
    $access = $field->access('view', $this->currentUser(), TRUE);
    $cache->addCacheableDependency($access);
    return $access->isAllowed() && !$field->isEmpty() ? $field->view(['label' => 'hidden']) : [];
  }

  private function lines(NodeInterface $node, string $name, CacheableMetadata $cache): array {
    if (!$node->hasField($name)) {
      return [];
    }
    $field = $node->get($name);
    $access = $field->access('view', $this->currentUser(), TRUE);
    $cache->addCacheableDependency($access);
    if (!$access->isAllowed() || $field->isEmpty()) {
      return [];
    }
    return ['#theme' => 'item_list', '#items' => array_map(static fn($line) => ['#plain_text' => $line], array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $field->value) ?: []))))];
  }
}
