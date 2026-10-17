<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\actools_lesson\Service\OutlineResolver;
use Drupal\actools_lesson\Service\ProgressStore;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Native POST/CSRF reading controls, fully usable without JavaScript.
 */
class ProgressForm extends FormBase {

  public function __construct(
    protected ProgressStore $progress,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $account,
    protected OutlineResolver $outline,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('actools_lesson.progress'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('actools_lesson.outline'),
    );
  }

  public function getFormId(): string {
    return 'actools_lesson_progress_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $lesson = NULL, string $step_id = '', string $mode = 'guided'): array {
    if (!$lesson || !$this->account->isAuthenticated()) {
      throw new AccessDeniedHttpException();
    }
    $snapshot = $this->progress->snapshot($lesson, $this->account);
    $steps = $snapshot['steps'];
    if (!$steps) {
      return ['#cache' => ['max-age' => 0]];
    }
    if (!isset($steps[$step_id])) {
      $step_id = array_key_first($steps);
    }
    $mode = $mode === 'all' ? 'all' : 'guided';
    $form_state->set('actools_lesson_nid', (int) $lesson->id());
    $form_state->set('actools_lesson_step', $step_id);
    $form_state->set('actools_lesson_mode', $mode);
    $form['#method'] = 'post';
    $form['#action'] = Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()], [
      'query' => ['step' => $step_id, 'view' => $mode],
    ])->toString();
    $form['#attributes']['class'][] = 'actools-progress';
    $form['#attributes']['data-actools-completed-steps'] = json_encode(array_keys($snapshot['completed']), JSON_THROW_ON_ERROR);
    $form['#attributes']['data-actools-revisit-steps'] = json_encode(array_keys($snapshot['needs_review']), JSON_THROW_ON_ERROR);
    $form['#cache'] = [
      'max-age' => 0,
      'contexts' => ['user', 'user.permissions', 'languages:language_interface', 'languages:language_content'],
    ];
    // The meter is private account state, never the reader's current position.
    // An entirely optional lesson uses its real step total rather than max=0.
    $required = $snapshot['required_total'] > 0;
    $meter_value = $required ? $snapshot['required_complete'] : $snapshot['total_complete'];
    $meter_max = $required ? $snapshot['required_total'] : count($steps);
    $count_arguments = ['@done' => $meter_value, '@total' => $meter_max];
    $count_label = $required
      ? $this->t('@done of @total required steps read', $count_arguments)
      : $this->t('@done of @total optional steps read', $count_arguments);
    $count_id = Html::getUniqueId('actools-progress-count');
    $form['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['actools-progress__summary']],
      'count' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['id' => $count_id, 'class' => ['actools-progress__count']],
        '#value' => $count_label,
      ],
      'meter' => [
        '#type' => 'html_tag',
        '#tag' => 'progress',
        '#attributes' => [
          'class' => ['actools-progress__meter'],
          'value' => $meter_value,
          'max' => $meter_max,
          'aria-labelledby' => $count_id,
        ],
        '#value' => '',
      ],
    ];
    if (($mode === 'all' && $snapshot['needs_review']) || isset($snapshot['needs_review'][$step_id])) {
      $form['status'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['actools-progress__status']],
        'text' => ['#plain_text' => (string) ($mode === 'all'
          ? $this->t('Updated steps are labelled revisit below. Review the changes before marking them as read.')
          : $this->t('This step has changed since you marked it as read. Revisit it, then mark the updated version.'))],
      ];
    }
    $form['versions'] = ['#tree' => TRUE];
    $form['rendered_step'] = ['#type' => 'hidden', '#default_value' => $step_id];
    $options = [];
    foreach ($steps as $id => $step) {
      // Default values preserve the submitted rendered version, permitting a
      // stale-form check after an editor has changed the public step meanwhile.
      $form['versions'][$id] = ['#type' => 'hidden', '#default_value' => (string) $step['content_version']];
      $status = isset($snapshot['completed'][$id]) ? $this->t('read') : (isset($snapshot['needs_review'][$id]) ? $this->t('revisit') : $this->t('not marked'));
      $options[$id] = Html::escape($step['title'] . ' — ' . $status . ($step['optional'] ? ' (' . $this->t('optional') . ')' : ''));
    }

    if ($mode === 'guided') {
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['continue'] = isset($snapshot['completed'][$step_id])
        ? $this->button('continue', $this->t('Continue reading'), TRUE)
        : $this->button('complete_continue', $this->t('Mark read and continue'), TRUE);
    }
    $form['manage'] = [
      '#type' => 'details',
      '#title' => $mode === 'all' ? $this->t('Update read marks') : $this->t('Manage reading progress'),
      '#open' => $mode === 'all',
    ];
    $form['manage']['explanation'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['actools-progress__explanation']],
      '#value' => $this->t('Read marks track your reading, not an assessment result.'),
    ];
    if ($mode === 'guided') {
      $form['manage']['current_actions'] = ['#type' => 'actions'];
      $form['manage']['current_actions']['mark'] = isset($snapshot['completed'][$step_id])
        ? $this->button('undo', $this->t('Undo read mark'))
        : $this->button('complete', $this->t('Mark this step as read'));
      $form['manage']['current_actions']['save_place'] = $this->button('position', $this->t('Save my place here'));
    }
    $form['manage']['selected'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Choose steps to update'),
      '#description' => $this->t('Only selected steps change. Optional steps do not affect the required-step total.'),
      '#options' => $options,
    ];
    $form['manage']['bulk_complete'] = $this->button('bulk_complete', $this->t('Mark selected steps as read'));
    $form['manage']['bulk_undo'] = $this->button('bulk_undo', $this->t('Undo selected read marks'));
    $form['manage']['confirm_reset'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Clear all my read marks and saved place for this lesson'),
    ];
    $form['manage']['reset'] = $this->button('reset', $this->t('Reset this lesson’s reading progress'));
    return $form;
  }

  private function button(string $operation, mixed $label, bool $primary = FALSE): array {
    return [
      '#type' => 'actools_progress_submit',
      '#name' => 'actools_progress_' . $operation,
      '#value' => $label,
      '#progress_operation' => $operation,
      '#attributes' => [
        'data-actools-progress-action' => $operation,
        'class' => $primary ? ['actools-progress__primary'] : [],
      ],
    ];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->getRequest()->getMethod() !== 'POST') {
      throw new AccessDeniedHttpException('Reading progress changes require POST.');
    }
    $operation = $form_state->getTriggeringElement()['#progress_operation'] ?? '';
    $rendered_step = $form_state->getValue('rendered_step');
    if (!is_string($rendered_step) || $rendered_step !== $form_state->get('actools_lesson_step')) {
      $form_state->setErrorByName('rendered_step', $this->t('The reading route has changed. Reload this lesson before changing its reading progress.'));
    }
    if ($operation === 'reset' && !$form_state->getValue('confirm_reset')) {
      $form_state->setErrorByName('confirm_reset', $this->t('Confirm that you want to reset this lesson’s reading progress.'));
    }
    if (str_starts_with($operation, 'bulk_') && !array_filter($form_state->getValue('selected', []))) {
      $form_state->setErrorByName('selected', $this->t('Choose at least one step.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->getRequest()->getMethod() !== 'POST') {
      throw new AccessDeniedHttpException();
    }
    $lesson = $this->entityTypeManager->getStorage('node')->load((int) $form_state->get('actools_lesson_nid'));
    if (!$lesson instanceof NodeInterface) {
      throw new AccessDeniedHttpException();
    }
    $step_id = (string) $form_state->get('actools_lesson_step');
    $mode = (string) $form_state->get('actools_lesson_mode');
    $operation = $form_state->getTriggeringElement()['#progress_operation'] ?? '';
    $snapshot = $this->progress->snapshot($lesson, $this->account);
    if (!isset($snapshot['steps'][$step_id])) {
      $this->messenger()->addWarning($this->t('This step is no longer available. Your reading progress was not changed.'));
      $form_state->setRedirect('actools_lesson.reader', ['node' => $lesson->id()]);
      return;
    }
    $ids = str_starts_with($operation, 'bulk_')
      ? array_values(array_filter($form_state->getValue('selected', [])))
      : [$step_id];
    $versions = [];
    foreach ($form_state->getValue('versions', []) as $id => $version) {
      if (is_scalar($version) && ctype_digit((string) $version)) {
        $versions[$id] = (int) $version;
      }
    }
    $next = $this->nextDestination($lesson, $snapshot['steps'], $step_id, $mode);
    try {
      if (in_array($operation, ['complete', 'complete_continue', 'bulk_complete'], TRUE)) {
        $position = $operation === 'complete_continue' && $next['same_lesson'] ? $next['step'] : $step_id;
        $this->progress->mutate($lesson, $this->account, 'complete', $ids, $position, $versions);
        $this->messenger()->addStatus($this->t('Your read marks have been saved.'));
      }
      elseif (in_array($operation, ['undo', 'bulk_undo'], TRUE)) {
        $this->progress->mutate($lesson, $this->account, 'undo', $ids, $step_id);
        $this->messenger()->addStatus($this->t('The selected read marks have been removed.'));
      }
      elseif ($operation === 'reset') {
        $this->progress->mutate($lesson, $this->account, 'reset');
        $this->messenger()->addStatus($this->t('This lesson’s reading progress has been reset.'));
      }
      elseif (in_array($operation, ['position', 'continue'], TRUE)) {
        $position = $operation === 'continue' && $next['same_lesson'] ? $next['step'] : $step_id;
        $this->progress->mutate($lesson, $this->account, 'position', [], $position);
        if ($operation === 'position') {
          $this->messenger()->addStatus($this->t('Your reading place has been saved.'));
        }
      }
      else {
        throw new \InvalidArgumentException('Unknown reading-progress action.');
      }
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->messenger()->addWarning($this->t('Your reading progress was not changed. @reason', ['@reason' => $e->getMessage()]));
      $form_state->setRedirect('actools_lesson.reader', ['node' => $lesson->id()], ['query' => ['step' => $step_id, 'view' => $mode]]);
      return;
    }
    if (in_array($operation, ['continue', 'complete_continue'], TRUE)) {
      $form_state->setRedirectUrl($next['url']);
    }
    else {
      $form_state->setRedirect('actools_lesson.reader', ['node' => $lesson->id()], ['query' => ['step' => $step_id, 'view' => $mode]]);
    }
  }

  /**
   * Produces only routes derived from freshly accessible chapter/step data.
   */
  private function nextDestination(NodeInterface $lesson, array $steps, string $step_id, string $mode): array {
    $ids = array_keys($steps);
    $index = array_search($step_id, $ids, TRUE);
    if ($index !== FALSE && isset($ids[$index + 1])) {
      $next_id = $ids[$index + 1];
      return [
        'same_lesson' => TRUE,
        'step' => $next_id,
        'url' => Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()], ['query' => ['step' => $next_id, 'view' => $mode]]),
      ];
    }
    $outline = $this->outline->forLesson($lesson, $this->account);
    if (($outline['next'] ?? NULL) instanceof NodeInterface) {
      return ['same_lesson' => FALSE, 'step' => NULL, 'url' => Url::fromRoute('actools_lesson.reader', ['node' => $outline['next']->id()])];
    }
    if (($outline['chapter'] ?? NULL) instanceof NodeInterface) {
      return ['same_lesson' => FALSE, 'step' => NULL, 'url' => $outline['chapter']->toUrl()];
    }
    return [
      'same_lesson' => FALSE,
      'step' => NULL,
      'url' => Url::fromRoute('actools_lesson.reader', ['node' => $lesson->id()], ['query' => ['view' => 'all']]),
    ];
  }

}
