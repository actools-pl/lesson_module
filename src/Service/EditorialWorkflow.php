<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Adds a bounded draft/publish workflow to managed lessons' native node forms.
 *
 * Content Moderation remains authoritative when configured for the bundle.
 */
final class EditorialWorkflow {
  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly TeachingContentCompleteness $completeness,
    private readonly ?object $moderationInformation = NULL,
  ) {}

  public function manages(NodeInterface $node): bool {
    return LessonAccess::isLesson($node)
      && !($this->moderationInformation && $this->moderationInformation->isModeratedEntity($node));
  }

  /** Returns the latest saved revision; callers must check update access. */
  public function latest(NodeInterface $node): NodeInterface {
    if (!$node->id()) {
      return $node;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache([$node->id()]);
    $id = $storage->getLatestRevisionId($node->id());
    $latest = $id ? $storage->loadRevisionUnchanged($id) : NULL;
    if (!$latest instanceof NodeInterface) {
      return $node;
    }
    $langcode = $node->language()->getId();
    return $latest->hasTranslation($langcode) ? $latest->getTranslation($langcode) : $latest;
  }

  /** Runs before widgets build, so editing reopens the saved draft. */
  public function prepareForm(NodeInterface $node, string $operation, FormStateInterface $formState): void {
    if (!in_array($operation, ['default', 'edit'], TRUE) || !$this->manages($node)) {
      return;
    }
    if ($node->id()) {
      $latest = $this->latest($node);
      if (!$latest->access('update', $this->currentUser) || !LessonAccess::isLesson($latest)) {
        throw new AccessDeniedHttpException('This lesson is not available for editing.');
      }
      $latest->setRevisionLogMessage(NULL);
      $formState->getFormObject()->setEntity($latest);
    }
    $formState->set('actools_lesson.editorial', TRUE);
  }

  /** Adds explicit actions without replacing native validation, CSRF or widgets. */
  public function alterForm(array &$form, FormStateInterface $formState): void {
    $node = $formState->getFormObject()->getEntity();
    if (!$node instanceof NodeInterface || !$this->manages($node)) {
      return;
    }
    $formState->set('actools_lesson.editorial', TRUE);
    $form['#cache']['max-age'] = 0;
    $form['#attached']['library'][] = 'actools_lesson/author';
    $form['#entity_builders']['actools_lesson_editorial'] = 'actools_lesson_editorial_build_entity';
    $form['#validate'][] = 'actools_lesson_editorial_validate';
    // A signed posted snapshot survives Drupal's uncached GET form rebuilds.
    // Never use #value here: that would replace the browser's original token.
    $form['actools_lesson_editorial_token'] = [
      '#type' => 'hidden', '#default_value' => $this->token($node),
    ];
    if (isset($form['status'])) {
      $form['status']['#access'] = FALSE;
    }
    if (isset($form['revision'])) {
      $form['revision']['#access'] = FALSE;
      $form['revision']['#default_value'] = TRUE;
    }
    $publish = $node->get('status')->access('edit', $this->currentUser, TRUE);
    $default = $node->id() ? $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id()) : NULL;
    $hasPublic = $default instanceof NodeInterface && $default->isPublished();
    $form['actools_editorial_status'] = [
      '#type' => 'container', '#weight' => -95,
      '#attributes' => ['class' => ['actools-author-status']],
      'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $hasPublic ? $this->t('Published lesson — editing a draft') : $this->t('Lesson draft')],
      'description' => ['#markup' => $hasPublic
        ? $this->t('Save draft keeps the published lesson available to learners. Publish reviewed lesson replaces it after every step is ready.')
        : $this->t('Save your work as a draft, assign the lesson to a chapter, then publish when every step is reviewed.')],
    ];
    $readiness = $this->readiness($node, $this->currentUser);
    $form['actools_editorial_status']['checklist'] = [
      '#type' => 'container', '#attributes' => ['class' => ['actools-author-checklist']],
      'count' => ['#plain_text' => $readiness['label']],
    ];
    if (!$publish->isAllowed()) {
      $form['actools_editorial_status']['permission'] = ['#markup' => $this->t('You can save drafts. Publishing requires permission to change the native Published status.')];
    }
    if (isset($form['actions']['submit'])) {
      $save = $form['actions']['submit'];
      $save['#value'] = $this->t('Save draft');
      $save['#name'] = 'actools_lesson_save_draft';
      $save['#actools_lesson_action'] = 'draft';
      $save['#submit'] = ['::submitForm', 'actools_lesson_editorial_save'];
      $save['#button_type'] = 'secondary';
      $form['actions']['submit'] = $save;
      $form['actions']['actools_publish'] = $save;
      $form['actions']['actools_publish']['#value'] = $this->t('Publish reviewed lesson');
      $form['actions']['actools_publish']['#name'] = 'actools_lesson_publish';
      $form['actions']['actools_publish']['#actools_lesson_action'] = 'publish';
      $form['actions']['actools_publish']['#button_type'] = 'primary';
      $form['actions']['actools_publish']['#weight'] = 5;
      $nativeAccess = $save['#access'] ?? TRUE;
      $form['actions']['actools_publish']['#access'] = $publish->andIf($nativeAccess instanceof AccessResultInterface ? $nativeAccess : AccessResult::allowedIf((bool) $nativeAccess));
      $form['actions']['#attributes']['class'][] = 'actools-editorial-actions';
    }
  }

  /** Runs during both native entity validation and final submit construction. */
  public function buildEntity(NodeInterface $node, array $form, FormStateInterface $formState): void {
    if (!$formState->get('actools_lesson.editorial') || !$this->manages($node)) {
      return;
    }
    $publish = ($formState->getTriggeringElement()['#actools_lesson_action'] ?? 'draft') === 'publish';
    $node->setNewRevision(TRUE);
    $node->setRevisionCreationTime($this->time->getRequestTime());
    $node->setRevisionUserId($this->currentUser->id());
    if ($publish && $node->get('status')->access('edit', $this->currentUser)) {
      $node->setPublished();
      $node->isDefaultRevision(TRUE);
    }
    else {
      $default = $node->id() ? $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id()) : NULL;
      $node->setUnpublished();
      $node->isDefaultRevision(!($default instanceof NodeInterface) || !$default->isPublished());
    }
  }

  public function validateForm(array &$form, FormStateInterface $formState): void {
    if (!$formState->get('actools_lesson.editorial')) {
      return;
    }
    $node = $formState->getFormObject()->getEntity();
    $snapshot = $this->decodeToken((string) $formState->getValue('actools_lesson_editorial_token'), $node);
    if (!$snapshot || !$this->isFresh($node, $snapshot)) {
      $formState->setErrorByName('actools_lesson_editorial_token', $this->t('A newer version of this lesson was saved. Reload the editor and copy your changes into the latest draft before saving.'));
      return;
    }
    $formState->set('actools_lesson.editorial_snapshot', $snapshot);
    if (($formState->getTriggeringElement()['#actools_lesson_action'] ?? '') === 'publish'
      && !$node->get('status')->access('edit', $this->currentUser)) {
      $formState->setErrorByName('status', $this->t('You do not have permission to publish this lesson. Save a draft instead.'));
    }
  }

  /** Serializes final form saves and retains the native NodeForm save behavior. */
  public function save(array $form, FormStateInterface $formState): void {
    $node = $formState->getFormObject()->getEntity();
    $name = 'actools_lesson.editorial:' . ($node->id() ?: $node->uuid());
    if (!$this->lock->acquire($name, 30)) {
      \Drupal::messenger()->addError($this->t('This lesson is being saved by another editor. Reload the latest draft before saving again.'));
      $formState->setRebuild();
      return;
    }
    try {
      if (!$this->isFresh($node, $formState->get('actools_lesson.editorial_snapshot') ?? [])) {
        \Drupal::messenger()->addError($this->t('A newer version of this lesson was saved. Reload the editor before saving again.'));
        $formState->setRebuild();
        return;
      }
      $formState->getFormObject()->save($form, $formState);
      if (!$formState->isRebuilding() && $node->id() && !$node->isPublished()) {
        // A default-published lesson's pending draft has no public canonical URL.
        \Drupal::request()->query->remove('destination');
        $formState->setRedirect('actools_lesson.draft_preview', ['node' => $node->id()]);
      }
    }
    finally {
      $this->lock->release($name);
    }
  }

  /** Private preview access is independent of public reader access. */
  public function previewAccess(NodeInterface $node): AccessResultInterface {
    $result = AccessResult::allowedIf(LessonAccess::isLesson($node))->addCacheableDependency($node)
      ->andIf($node->access('update', $this->currentUser, TRUE));
    if (LessonAccess::isLesson($node)) {
      foreach (['title', 'field_actools_lesson_steps'] as $name) {
        $result = $result->andIf($node->get($name)->access('view', $this->currentUser, TRUE));
      }
    }
    return $result->setCacheMaxAge(0);
  }

  /** Read-only readiness information, respecting step field access. */
  public function readiness(NodeInterface $node, \Drupal\Core\Session\AccountInterface $account): array {
    $cache = CacheableMetadata::createFromObject($node);
    $access = $node->get('field_actools_lesson_steps')->access('view', $account, TRUE);
    $cache->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return ['label' => (string) $this->t('Readiness unavailable'), 'ready' => NULL, 'total' => NULL, 'cacheability' => $cache];
    }
    $ready = 0;
    $total = 0;
    foreach ($node->get('field_actools_lesson_steps') as $step) {
      if ($step->isEmpty()) {
        continue;
      }
      $total++;
      $complete = $this->completeness->isCompleteStep($step->getValue(), $cache);
      if ($step->reviewed && $complete) {
        $ready++;
      }
    }
    return ['label' => (string) $this->t('@ready of @total steps reviewed and complete', ['@ready' => $ready, '@total' => $total]), 'ready' => $ready, 'total' => $total, 'cacheability' => $cache];
  }

  /** Signed identity metadata only; no authored content is sent in this token. */
  public function token(NodeInterface $node): string {
    $latest = $node->id() ? $this->latest($node) : $node;
    $snapshot = [
      'nid' => (int) $node->id(), 'uid' => (int) $this->currentUser->id(),
      'revision' => (int) $latest->getRevisionId(),
      'digest' => $node->id() ? $this->digest($latest) : '',
    ];
    $payload = rtrim(strtr(base64_encode(json_encode($snapshot, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    return $payload . '.' . Crypt::hmacBase64($payload, Settings::getHashSalt());
  }

  public function decodeToken(string $token, NodeInterface $node): ?array {
    if (strlen($token) > 2048 || substr_count($token, '.') !== 1) {
      return NULL;
    }
    [$payload, $signature] = explode('.', $token, 2);
    if (!hash_equals(Crypt::hmacBase64($payload, Settings::getHashSalt()), $signature)) {
      return NULL;
    }
    $snapshot = json_decode(base64_decode(strtr($payload, '-_', '+/'), TRUE) ?: '', TRUE);
    if (!is_array($snapshot) || ($snapshot['nid'] ?? NULL) !== (int) $node->id()
      || ($snapshot['uid'] ?? NULL) !== (int) $this->currentUser->id()
      || !is_int($snapshot['revision'] ?? NULL) || !is_string($snapshot['digest'] ?? NULL)) {
      return NULL;
    }
    return $snapshot;
  }

  public function isFresh(NodeInterface $node, array $snapshot): bool {
    if (!$node->id()) {
      return ($snapshot['nid'] ?? NULL) === 0 && ($snapshot['revision'] ?? NULL) === 0;
    }
    $latest = $this->latest($node);
    return ($snapshot['revision'] ?? NULL) === (int) $latest->getRevisionId()
      && hash_equals($this->digest($latest), (string) ($snapshot['digest'] ?? ''));
  }

  private function digest(NodeInterface $node): string {
    return hash('sha256', serialize($node->toArray()));
  }

}
