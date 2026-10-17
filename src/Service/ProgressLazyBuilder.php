<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\actools_lesson\Form\ProgressForm;
use Drupal\node\NodeInterface;

/**
 * Keeps personal forms outside publicly cached lesson render arrays.
 */
class ProgressLazyBuilder implements TrustedCallbackInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FormBuilderInterface $formBuilder,
    protected AccountProxyInterface $currentUser,
  ) {}

  public static function trustedCallbacks(): array {
    return ['build'];
  }

  public function build(int|string $nid, string $step_id = '', string $mode = 'guided'): array {
    $cache = [
      'max-age' => 0,
      'contexts' => ['user', 'user.permissions', 'languages:language_interface', 'languages:language_content'],
    ];
    $lesson = $this->entityTypeManager->getStorage('node')->load((int) $nid);
    if (!$lesson instanceof NodeInterface || !LessonAccess::isLesson($lesson) || !$lesson->isPublished()
      || !$lesson->isDefaultRevision() || !$lesson->access('view', $this->currentUser)
      || !$lesson->get('title')->access('view', $this->currentUser)
      || !$lesson->hasField('field_actools_lesson_steps')
      || !$lesson->get('field_actools_lesson_steps')->access('view', $this->currentUser)) {
      return ['#cache' => $cache];
    }
    $mode = $mode === 'all' ? 'all' : 'guided';
    if ($this->currentUser->isAnonymous()) {
      $destination = Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()], [
        'query' => array_filter(['step' => $step_id, 'view' => $mode]),
      ])->toString();
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['actools-progress']],
        'message' => [
          '#plain_text' => (string) t('Sign in to save your reading place and mark steps as read.'),
          '#suffix' => ' ',
        ],
        'login' => [
          '#type' => 'link',
          '#title' => t('Sign in'),
          '#url' => Url::fromRoute('user.login', [], ['query' => ['destination' => $destination]]),
        ],
        '#cache' => $cache,
      ];
    }
    $form = $this->formBuilder->getForm(ProgressForm::class, $lesson, $step_id, $mode);
    $form['#cache'] = $cache;
    return $form;
  }

}
