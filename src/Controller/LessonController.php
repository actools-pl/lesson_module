<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Controller;

use Drupal\actools_lesson\Service\LessonDocumentBuilder;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Public reader routes: no caller-controlled revision selection.
 */
final class LessonController extends ControllerBase {

  public function __construct(private readonly LessonDocumentBuilder $documentBuilder) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('actools_lesson.document_builder'));
  }

  public function view(NodeInterface $node, Request $request): array {
    $query = $request->query->all();
    if (isset($query['view']) && (!is_string($query['view']) || !in_array($query['view'], ['guided', 'all'], TRUE))) {
      throw new BadRequestHttpException('Choose Guided or Read all.');
    }
    if (isset($query['step']) && !is_string($query['step'])) {
      throw new BadRequestHttpException('Invalid step.');
    }
    return $this->documentBuilder->build($node, $query['view'] ?? 'guided', $query['step'] ?? NULL);
  }

  public function printView(NodeInterface $node): array {
    return $this->documentBuilder->build($node, 'print');
  }

  public function title(NodeInterface $node): array {
    $access = $node->get('title')->access('view', NULL, TRUE);
    $build = $access->isAllowed() ? ['#plain_text' => $node->label()] : ['#plain_text' => 'Lesson'];
    \Drupal\Core\Cache\CacheableMetadata::createFromObject($node)->addCacheableDependency($access)->applyTo($build);
    return $build;
  }

}
