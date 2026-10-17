<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserDataInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Account-owned reading state; a read never records completion or position.
 *
 * Completion is explicit and names the exact content_version of a stable step.
 * This is a reading aid, not a record of mastery, grades or examination results.
 */
class ProgressStore {

  public function __construct(
    protected UserDataInterface $userData,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    protected LessonDocumentBuilder $documentBuilder,
  ) {}

  /**
   * Returns only currently readable steps and this account's corresponding state.
   *
   * @return array<string, mixed>
   *   steps, completed, needs_review, position, required_total, required_complete,
   *   total_complete, authenticated. No implicit write is performed.
   */
  public function snapshot(NodeInterface $lesson, AccountInterface $account): array {
    $steps = $this->stepsFor($lesson, $account);
    $record = $account->isAuthenticated()
      ? $this->load((int) $account->id(), (int) $lesson->id())
      : $this->emptyRecord();
    return $this->summarize($record, $steps, $account->isAuthenticated());
  }

  /**
   * Performs one explicit, locked read/merge/write operation.
   *
   * The caller must provide POST/CSRF protection, e.g. ProgressForm. Access and
   * target validity are checked again here, independently of the submitted form.
   * Operations: complete/undo (one or several steps), position, reset.
   */
  public function mutate(NodeInterface $lesson, AccountInterface $account, string $operation, array $step_ids = [], ?string $position = NULL, array $expected_versions = []): array {
    if (!$account->isAuthenticated() || (int) $account->id() < 1) {
      throw new AccessDeniedHttpException('Sign in to save reading progress.');
    }
    $steps = $this->stepsFor($lesson, $account);
    if (!in_array($operation, ['complete', 'undo', 'position', 'reset'], TRUE)) {
      throw new \InvalidArgumentException('Unknown reading-progress operation.');
    }
    if (count($step_ids) > count($steps)) {
      throw new \InvalidArgumentException('Invalid step selection.');
    }
    foreach ($step_ids as $id) {
      if (!is_string($id) || !isset($steps[$id])) {
        throw new \InvalidArgumentException('The selected step is no longer available.');
      }
      if ($operation === 'complete' && ($expected_versions[$id] ?? NULL) !== $steps[$id]['content_version']) {
        throw new \InvalidArgumentException('This step has changed. Reload and revisit it before marking it as read.');
      }
    }
    $step_ids = array_values(array_unique($step_ids));
    if (in_array($operation, ['complete', 'undo'], TRUE) && !$step_ids) {
      throw new \InvalidArgumentException('Select at least one available step.');
    }
    if ($position !== NULL && !isset($steps[$position])) {
      throw new \InvalidArgumentException('The saved reading position is no longer available.');
    }
    if ($operation === 'position' && $position === NULL) {
      throw new \InvalidArgumentException('A reading position is required.');
    }

    $uid = (int) $account->id();
    $nid = (int) $lesson->id();
    $lock_name = "actools_lesson:progress:$uid:$nid";
    if (!$this->lock->acquire($lock_name, 30.0)) {
      throw new \RuntimeException('Your reading progress is being saved in another request. Please retry.');
    }
    try {
      // Load only after acquiring the lock: concurrent tabs must merge rather
      // than overwrite a stale form's snapshot of other completed steps.
      $record = $operation === 'reset' ? $this->emptyRecord() : $this->load($uid, $nid);
      $record['completed'] = array_intersect_key($record['completed'], $steps);
      $now = $this->time->getCurrentTime();
      if ($operation === 'complete') {
        foreach ($step_ids as $id) {
          $record['completed'][$id] = [
            'content_version' => $steps[$id]['content_version'],
            'at' => $now,
          ];
        }
      }
      elseif ($operation === 'undo') {
        foreach ($step_ids as $id) {
          unset($record['completed'][$id]);
        }
      }
      if ($operation !== 'reset' && $position !== NULL) {
        $record['position'] = $position;
      }
      $record['updated'] = $now;
      $record['updated_microtime'] = max((float) $now, $this->time->getCurrentMicroTime());
      $this->userData->set('actools_lesson', $uid, "lesson:$nid", $record);
      return $this->summarize($record, $steps, TRUE);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Centralizes public-reader and field access, never a draft revision's steps.
   *
   * @return array<string, array<string, mixed>>
   *   Ordered normalized readable steps keyed by stable UUID.
   */
  protected function stepsFor(NodeInterface $lesson, AccountInterface $account): array {
    if (!LessonAccess::isLesson($lesson) || !$lesson->isDefaultRevision() || !$lesson->isPublished()
      || !$lesson->access('view', $account)
      || !$lesson->get('title')->access('view', $account)
      || !$lesson->hasField('field_actools_lesson_steps')
      || !$lesson->get('field_actools_lesson_steps')->access('view', $account)) {
      throw new AccessDeniedHttpException('Reading progress is not available for this lesson.');
    }
    $readable = $this->documentBuilder->readableSteps($lesson, FALSE);
    $steps = [];
    foreach ($readable['steps'] ?? [] as $step) {
      $id = $step['id'] ?? '';
      if (is_string($id) && Uuid::isValid($id) && !isset($steps[$id])
        && !empty($step['reviewed']) && (int) ($step['content_version'] ?? 0) >= 1) {
        $steps[$id] = [
          'id' => $id,
          'title' => (string) ($step['title'] ?? ''),
          'optional' => (bool) ($step['optional'] ?? FALSE),
          'content_version' => (int) $step['content_version'],
        ];
      }
    }
    return $steps;
  }

  /**
   * Reads account-scoped server data, tolerating absent/old malformed state.
   */
  private function load(int $uid, int $nid): array {
    $raw = $this->userData->get('actools_lesson', $uid, "lesson:$nid");
    if (!is_array($raw) || ($raw['schema'] ?? NULL) !== 1) {
      return $this->emptyRecord();
    }
    $record = $this->emptyRecord();
    $record['position'] = is_string($raw['position'] ?? NULL) ? $raw['position'] : NULL;
    foreach (is_array($raw['completed'] ?? NULL) ? $raw['completed'] : [] as $id => $item) {
      if (is_string($id) && Uuid::isValid($id) && is_array($item)
        && is_int($item['content_version'] ?? NULL) && $item['content_version'] >= 1) {
        $record['completed'][$id] = [
          'content_version' => $item['content_version'],
          'at' => (int) ($item['at'] ?? 0),
        ];
      }
    }
    $record['updated'] = (int) ($raw['updated'] ?? 0);
    $record['updated_microtime'] = is_numeric($raw['updated_microtime'] ?? NULL)
      ? (float) $raw['updated_microtime'] : (float) $record['updated'];
    return $record;
  }

  private function emptyRecord(): array {
    return ['schema' => 1, 'completed' => [], 'position' => NULL, 'updated' => 0, 'updated_microtime' => 0.0];
  }

  private function summarize(array $record, array $steps, bool $authenticated): array {
    $completed = $needs_review = [];
    $required_total = $required_complete = 0;
    foreach ($steps as $id => $step) {
      $previous = $record['completed'][$id]['content_version'] ?? NULL;
      if ($previous === $step['content_version']) {
        $completed[$id] = TRUE;
      }
      elseif ($previous !== NULL) {
        // A recorded previous completion never silently approves changed text.
        $needs_review[$id] = TRUE;
      }
      if (!$step['optional']) {
        $required_total++;
        $required_complete += isset($completed[$id]) ? 1 : 0;
      }
    }
    $position = isset($steps[$record['position'] ?? '']) ? $record['position'] : NULL;
    return [
      'authenticated' => $authenticated,
      'steps' => $steps,
      'completed' => $completed,
      'needs_review' => $needs_review,
      'position' => $position,
      'required_total' => $required_total,
      'required_complete' => $required_complete,
      'total_complete' => count($completed),
      'updated' => $record['updated'],
      'updated_microtime' => $record['updated_microtime'],
    ];
  }

}
