<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Controller;

use Drupal\actools_lesson\Service\EditorialWorkflow;
use Drupal\actools_lesson\Service\LessonDocumentBuilder;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Shows only an authorized editor the latest saved lesson revision. */
final class DraftPreviewController extends ControllerBase {

  public function __construct(private readonly EditorialWorkflow $editorial, private readonly LessonDocumentBuilder $builder) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('actools_lesson.editorial'), $container->get('actools_lesson.document_builder'));
  }

  public function view(NodeInterface $node, Request $request): array {
    $latest = $this->editorial->latest($node);
    if (!$this->editorial->previewAccess($latest)->isAllowed()) {
      throw new AccessDeniedHttpException();
    }
    $query = $request->query->all();
    if (isset($query['view']) && (!is_string($query['view']) || !in_array($query['view'], ['guided', 'all', 'print'], TRUE))) {
      throw new BadRequestHttpException('Choose Guided, Read all or print.');
    }
    if (isset($query['step']) && !is_string($query['step'])) {
      throw new BadRequestHttpException('Invalid step.');
    }
    $build = $this->builder->build($latest, $query['view'] ?? 'guided', $query['step'] ?? NULL, TRUE);
    $build['#cache']['max-age'] = 0;
    $build['#cache']['contexts'][] = 'user';
    $build['#attached']['library'][] = 'actools_lesson/author';
    return [
      '#cache' => ['max-age' => 0, 'contexts' => ['user']],
      'editorial_actions' => [
        '#type' => 'container', '#attributes' => ['class' => ['actools-author-status', 'actools-editorial-actions']],
        'notice' => ['#markup' => $this->t('Private preview of the latest saved draft. Learners see the published version.')],
        'edit' => ['#type' => 'link', '#title' => $this->t('Continue editing'), '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])],
      ],
      'lesson' => $build,
    ];
  }

}
