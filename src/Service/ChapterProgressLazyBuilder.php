<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** A private continue-reading action derived from existing account state. */
final class ChapterProgressLazyBuilder implements TrustedCallbackInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly OutlineResolver $outline,
    private readonly ProgressStore $progress,
  ) {}

  public static function trustedCallbacks(): array {
    return ['build'];
  }

  public function build(int|string $nid): array {
    $build = ['#cache' => [
      'max-age' => 0,
      'contexts' => ['user', 'user.permissions', 'languages:language_interface', 'languages:language_content'],
    ]];
    if (!$this->currentUser->isAuthenticated()) {
      return $build;
    }
    $chapter = $this->entityTypeManager->getStorage('node')->load((int) $nid);
    if (!$chapter instanceof NodeInterface || !LessonAccess::isChapter($chapter)
      || !$chapter->isPublished() || !$chapter->isDefaultRevision()
      || !$chapter->access('view', $this->currentUser)
      || !$chapter->get('title')->access('view', $this->currentUser)) {
      return $build;
    }
    $latest = NULL;
    $latest_state = NULL;
    // forChapter includes both core and extension paths while filtering access.
    foreach ($this->outline->forChapter($chapter, $this->currentUser)['lessons'] as $lesson) {
      try {
        $state = $this->progress->snapshot($lesson, $this->currentUser);
      }
      catch (AccessDeniedHttpException) {
        continue;
      }
      if ($state['position'] !== NULL && ($latest_state === NULL
        || $state['updated_microtime'] > $latest_state['updated_microtime'])) {
        $latest = $lesson;
        $latest_state = $state;
      }
    }
    if (!$latest instanceof NodeInterface || !$latest_state) {
      return $build;
    }

    $destination = $latest;
    $position = $latest_state['position'];
    $keys = array_keys($latest_state['steps']);
    $at_end = $position === end($keys);
    $all_required = $latest_state['required_total'] > 0
      ? $latest_state['required_complete'] === $latest_state['required_total']
      : $latest_state['total_complete'] === count($keys);
    $review = FALSE;
    if ($at_end && $all_required) {
      $route = $this->outline->forLesson($latest, $this->currentUser);
      $found = FALSE;
      foreach ($route['lessons'] as $candidate) {
        if ($candidate->id() === $latest->id()) {
          $found = TRUE;
          continue;
        }
        if (!$found) {
          continue;
        }
        try {
          $next_state = $this->progress->snapshot($candidate, $this->currentUser);
        }
        catch (AccessDeniedHttpException) {
          continue;
        }
        if ($next_state['steps']) {
          $destination = $candidate;
          $position = array_key_first($next_state['steps']);
          break;
        }
      }
      $review = $destination->id() === $latest->id();
    }
    $build['link'] = [
      '#type' => 'link',
      '#title' => $review ? t('Review @lesson', ['@lesson' => $destination->label()]) : t('Continue learning: @lesson', ['@lesson' => $destination->label()]),
      '#url' => Url::fromRoute('actools_lesson.reader', ['node' => $destination->id()], ['query' => ['step' => $position, 'view' => 'guided']]),
      '#attributes' => ['class' => ['actools-button', 'actools-button--secondary', 'actools-chapter__resume']],
    ];
    return $build;
  }

}
