<?php

declare(strict_types=1);

namespace Drupal\actools_lesson_layout_builder\Plugin\Block;

use Drupal\actools_lesson\Controller\WorkbenchController;
use Drupal\actools_lesson\Service\LessonAccess;
use Drupal\actools_lesson\Service\LessonDocumentBuilder;
use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/** One intact reader, never a recursive entity full-view render. */
#[Block(
  id: 'actools_lesson_reader',
  admin_label: new TranslatableMarkup('Complete Actools chapter or lesson reader'),
  category: new TranslatableMarkup('Actools Lessons'),
  context_definitions: [
    'node' => new EntityContextDefinition('entity:node', new TranslatableMarkup('Content item being viewed')),
  ],
)]
final class CompleteReaderBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition,
    private readonly LessonDocumentBuilder $documentBuilder,
    private readonly ClassResolverInterface $classResolver,
    private readonly RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition,
      $container->get('actools_lesson.document_builder'), $container->get('class_resolver'), $container->get('request_stack'));
  }

  public function defaultConfiguration(): array {
    return ['label_display' => '0', 'context_mapping' => ['node' => 'layout_builder.entity']];
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['label_display'] = ['#type' => 'value', '#value' => '0'];
    $form['reader_help'] = ['#type' => 'item', '#description' => $this->t('Keep this complete reader once in the shared layout. It supplies the page heading, chapter route, teaching steps and private progress. Add essential teaching content through the lesson editor.')];
    return $form;
  }

  private function node(): ?NodeInterface {
    try {
      $node = $this->getContextValue('node');
      return $node instanceof NodeInterface ? $node : NULL;
    }
    catch (ContextException $exception) {
      return NULL;
    }
  }

  protected function blockAccess(AccountInterface $account) {
    $node = $this->node();
    $access = AccessResult::allowedIf($node && !$node->isNew() && $node->isPublished() && $node->isDefaultRevision()
      && (LessonAccess::isLesson($node) || LessonAccess::isChapter($node))
      && ($this->configuration['context_mapping']['node'] ?? NULL) === 'layout_builder.entity');
    if ($node) {
      $access->addCacheableDependency($node);
      $access = $access->andIf($node->access('view', $account, TRUE))->andIf($node->get('title')->access('view', $account, TRUE));
    }
    return $access->addCacheContexts(['route', 'languages:language_content']);
  }

  public function build(): array {
    if ($this->inPreview) {
      return [
        '#type' => 'container', '#attributes' => ['class' => ['actools-lesson-layout-document', 'actools-reader-layout-placeholder'], 'data-actools-layout-document' => TRUE],
        'label' => ['#markup' => '<strong>' . $this->t('Complete Actools reader') . '</strong>'],
        'description' => ['#plain_text' => (string) $this->t('The published chapter or lesson appears here, including its heading, navigation, steps and private progress. This editor shows a placeholder. Individual content layout overrides are unsupported.')],
        '#cache' => ['max-age' => 0],
      ];
    }
    $node = $this->node();
    if (!$node || !$this->access(\Drupal::currentUser())) {
      $build = [];
      if ($node) {
        CacheableMetadata::createFromObject($node)->applyTo($build);
      }
      return $build;
    }
    if (LessonAccess::isChapter($node)) {
      $document = $this->classResolver->getInstanceFromDefinition(WorkbenchController::class)->chapter($node);
    }
    else {
      $request = $this->requestStack->getCurrentRequest();
      $mode = $request?->query->get('view') === 'all' ? 'all' : 'guided';
      $step = $request?->query->get('step');
      $document = $this->documentBuilder->build($node, $mode, is_string($step) ? $step : NULL);
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['actools-lesson-layout-document'], 'data-actools-layout-document' => TRUE],
      'document' => $document,
    ];
  }

  public function getCacheContexts(): array {
    return array_merge(parent::getCacheContexts(), ['route', 'url.query_args:step', 'url.query_args:view', 'languages:language_content']);
  }

}

