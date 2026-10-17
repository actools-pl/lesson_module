<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Service;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Drupal\file\Element\ManagedFile;

/** Bounds the native editor and rejects incomplete whole-form submissions. */
final class AuthoringInputGuard {

  public const MAX_COMPONENTS = 120;
  public const INPUT_RESERVE = 128;
  private const SENTINEL = 'actools_lesson_submission_complete';
  private const CHECKED = 'actools_lesson_submission_checked';
  private const PHASE_CHECKED = 'actools_lesson_submission_phase_checked';

  /** The field widget installs this on its containing form, before processing. */
  public static function attach(array &$form): void {
    if (isset($form[self::SENTINEL])) {
      return;
    }
    $form[self::SENTINEL] = [
      '#type' => 'hidden', '#default_value' => '', '#weight' => PHP_INT_MAX,
      // A field widget can attach after the root child list was snapshotted.
      // This owned control must render/process correctly even on that first GET.
      '#name' => self::SENTINEL, '#parents' => [self::SENTINEL], '#array_parents' => [self::SENTINEL],
      '#id' => 'edit-' . str_replace('_', '-', self::SENTINEL), '#input' => TRUE, '#theme' => 'input__hidden',
      '#attributes' => ['data-actools-submission-guard' => ''],
    ];
    $form['#after_build'][] = [self::class, 'afterBuild'];
  }

  /** Sign metadata only, after every field/widget has its processed input name. */
  public static function afterBuild(array $form, FormStateInterface $form_state): array {
    $required = [];
    $controls = [];
    $bindings = [];
    $uploads = 0;
    self::collect($form, $required, $controls, $uploads, $bindings);
    $budget = array_sum($controls) + self::INPUT_RESERVE;
    $payload = base64_encode(json_encode(['required' => $required, 'budget' => $budget, 'uploads' => $uploads, 'bindings' => $bindings], JSON_THROW_ON_ERROR));
    $form[self::SENTINEL]['#value'] = $payload . '.' . Crypt::hmacBase64($payload, self::key($form_state));
    $maximum = (int) ini_get('max_input_vars');
    $upload_maximum = (int) ini_get('max_file_uploads');
    $blocked = ($maximum >= 0 && $budget > $maximum) || $uploads > $upload_maximum || ($uploads > 0 && !ini_get('file_uploads'));
    $message = 'Lesson editor input budget: ' . $budget . ' including 128 reserved inputs for native/AJAX request metadata; server max_input_vars=' . $maximum . '.';
    if ($blocked) {
      $message .= ' Saving, preview and builder actions are disabled because this complete form exceeds a server input/upload-count limit. Ask the site operator to raise max_input_vars (4096 supports the documented native-editor capacity) and max_file_uploads to cover the displayed upload controls, then reload this editor. Saved content has not changed.';
      self::disableSubmit($form);
    }
    $post = self::bytes((string) ini_get('post_max_size'));
    $upload = self::bytes((string) ini_get('upload_max_filesize'));
    $message .= ' Potential single-file upload controls: ' . $uploads . '; server max_file_uploads=' . $upload_maximum . '. Full-capacity server settings: post_max_size at least 128M for the combined request and upload_max_filesize at least 20M per figure. Supporting 120 simultaneous figure uploads also requires max_file_uploads at least 120 and sufficient combined body capacity; 128M does not fit 120 new 20M files. Actual server values: post_max_size=' . ini_get('post_max_size') . ', upload_max_filesize=' . ini_get('upload_max_filesize') . '.';
    if (($post > 0 && $post < 128 * 1024 * 1024) || $upload < 20 * 1024 * 1024) {
      $message .= ' Smaller server body/upload limits restrict what this form can submit; use smaller requests or have the operator raise the limits before uploading large figures.';
    }
    $form['actools_lesson_input_capacity'] = [
      '#type' => 'container', '#weight' => -94,
      // Added after Form API processing: its error walker still visits every
      // display-only descendant when an authored field fails validation.
      '#parents' => ['actools_lesson_input_capacity'],
      '#array_parents' => ['actools_lesson_input_capacity'],
      '#id' => Html::getUniqueId('actools-lesson-input-capacity'),
      '#attributes' => ['class' => ['actools-author__notice'], 'data-actools-input-capacity' => ''],
      'status' => [
        '#type' => 'html_tag', '#tag' => 'p',
        '#parents' => ['actools_lesson_input_capacity', 'status'],
        '#array_parents' => ['actools_lesson_input_capacity', 'status'],
        '#value' => $blocked
          ? 'This complete lesson form exceeds a server limit. Saving and builder actions are blocked. Ask the site administrator to increase the limits, then reload the editor. Saved content is unchanged.'
          : 'The complete lesson form fits this server’s input and upload-count limits.',
        '#attributes' => ['role' => $blocked ? 'alert' : 'note'],
      ],
      'limits' => [
        '#type' => 'details', '#title' => 'Server limits for site administrator', '#open' => $blocked,
        '#parents' => ['actools_lesson_input_capacity', 'limits'],
        '#array_parents' => ['actools_lesson_input_capacity', 'limits'],
        '#id' => Html::getUniqueId('actools-lesson-input-capacity-limits'),
        'explanation' => [
          '#type' => 'html_tag', '#tag' => 'p', '#value' => Html::escape($message),
          '#parents' => ['actools_lesson_input_capacity', 'limits', 'explanation'],
          '#array_parents' => ['actools_lesson_input_capacity', 'limits', 'explanation'],
        ],
      ],
    ];
    // A button-specific validate callback may override this; the widget and its
    // action/massage entry points also check before touching authored values.
    $form['#validate'] ??= [];
    array_unshift($form['#validate'], [self::class, 'validateForm']);
    self::guardFileCallbacks($form);
    // Form API keeps a separate triggering-element copy before after_build.
    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#ajax']['callback'] ?? NULL) === [ManagedFile::class, 'uploadAjaxCallback']) {
      $trigger['#ajax']['callback'] = [self::class, 'fileAjax'];
      $form_state->setTriggeringElement($trigger);
    }
    return $form;
  }

  public static function validateForm(array &$form, FormStateInterface $form_state): void {
    self::assertComplete($form_state);
  }

  public static function validateWidget(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    self::assertComplete($form_state);
  }

  /** A rebuilt widget changes its input schema; update the outside tail too. */
  public static function ajaxResponse(array $form, array $widget, string $wrapper): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#' . $wrapper, $widget));
    return self::refreshGuard($response, $form);
  }

  /** Preserve core's upload behavior while refreshing the whole-form schema. */
  public static function fileAjax(array &$form, FormStateInterface &$form_state, Request $request): AjaxResponse {
    $copy = $form;
    $response = ManagedFile::uploadAjaxCallback($copy, $form_state, $request);
    return self::refreshGuard($response, $form);
  }

  private static function refreshGuard(AjaxResponse $response, array $form): AjaxResponse {
    $response->addCommand(new ReplaceCommand('[data-actools-submission-guard]', $form[self::SENTINEL]));
    $response->addCommand(new ReplaceCommand('[data-actools-input-capacity]', $form['actools_lesson_input_capacity']));
    return $response;
  }

  private static function guardFileCallbacks(array &$element): void {
    if (($element['#ajax']['callback'] ?? NULL) === [ManagedFile::class, 'uploadAjaxCallback']) {
      $element['#ajax']['callback'] = [self::class, 'fileAjax'];
    }
    foreach (Element::children($element) as $key) {
      self::guardFileCallbacks($element[$key]);
    }
  }

  /** Never reconstruct a missing submitted control from a persisted baseline. */
  public static function assertComplete(FormStateInterface $form_state): void {
    if (!\Drupal::request()->isMethod('POST')) {
      return;
    }
    if ($schema = $form_state->getTemporaryValue(self::CHECKED)) {
      self::assertCurrentPhase($form_state, $schema);
      return;
    }
    $limit = self::bytes((string) ini_get('post_max_size'));
    $length = (int) \Drupal::request()->server->get('CONTENT_LENGTH', 0);
    if ($limit > 0 && $length > $limit) {
      throw new HttpException(413, 'The lesson form exceeds the server post_max_size limit. Nothing was saved. Keep the editor tab, copy unsaved text before reloading, and ask the site operator to raise the request limit or submit fewer/smaller uploads.');
    }
    $input = $form_state->getUserInput();
    $token = $input[self::SENTINEL] ?? '';
    if (!is_string($token) || strlen($token) > 8000000 || substr_count($token, '.') !== 1) {
      self::incomplete();
    }
    [$payload, $signature] = explode('.', $token, 2);
    if (!hash_equals(Crypt::hmacBase64($payload, self::key($form_state)), $signature)) {
      self::incomplete();
    }
    $schema = json_decode(base64_decode($payload, TRUE) ?: '', TRUE, 32);
    if (!is_array($schema) || !is_array($schema['required'] ?? NULL) || !is_int($schema['budget'] ?? NULL) || !is_int($schema['uploads'] ?? NULL) || !is_array($schema['bindings'] ?? NULL)) {
      self::incomplete();
    }
    $maximum = (int) ini_get('max_input_vars');
    if ($maximum >= 0 && $schema['budget'] > $maximum) {
      self::incomplete();
    }
    if ($schema['uploads'] > (int) ini_get('max_file_uploads') || ($schema['uploads'] > 0 && !ini_get('file_uploads'))) {
      self::incomplete();
    }
    foreach ($schema['required'] as $path) {
      if (!is_array($path) || !$path) {
        self::incomplete();
      }
      $exists = FALSE;
      $value = NestedArray::getValue($input, $path, $exists);
      if (!$exists || !is_scalar($value)) {
        self::incomplete();
      }
    }
    // An earlier valid tail must not authorize a later, larger AJAX inventory.
    foreach ($schema['bindings'] as $binding) {
      $exists = FALSE;
      $value = NestedArray::getValue($input, $binding['path'], $exists);
      if (!$exists || !is_string($value) || !hash_equals($binding['digest'], hash('sha256', $value))) {
        self::incomplete();
      }
    }
    // Temporary FormState data is not cached into the next AJAX request.
    $form_state->setTemporaryValue(self::CHECKED, $schema);
    self::assertCurrentPhase($form_state, $schema);
  }

  private static function assertCurrentPhase(FormStateInterface $form_state, array $schema): void {
    if ($form_state->getTemporaryValue(self::PHASE_CHECKED)) {
      return;
    }
    $complete = $form_state->getCompleteForm();
    // The first check is before the field is built; defer this extra cached
    // phase comparison until validation, before any submit handler mutates it.
    if (!is_array($complete)) {
      return;
    }
    foreach ($schema['bindings'] as $binding) {
      $current = NestedArray::getValue($complete, $binding['array_parents']);
      if (!is_array($current) || !isset($current['#value'])) {
        return;
      }
      if (!hash_equals($binding['digest'], hash('sha256', (string) $current['#value']))) {
        self::incomplete();
      }
    }
    $form_state->setTemporaryValue(self::PHASE_CHECKED, TRUE);
  }

  public static function componentCount(array $steps): int {
    return array_sum(array_map(static fn (array $step): int => count($step['components']), $steps));
  }

  private static function incomplete(): never {
    throw new BadRequestHttpException('The lesson form submission is incomplete or exceeds the server input limit. Nothing was saved. Keep the editor tab and copy unsaved text before reloading. Ask the site operator to check max_input_vars (4096 for full native-editor capacity), post_max_size and upload limits.');
  }

  private static function key(FormStateInterface $form_state): string {
    $object = $form_state->getFormObject();
    $entity = method_exists($object, 'getEntity') ? $object->getEntity() : NULL;
    return \Drupal::service('private_key')->get() . Settings::getHashSalt() . ':' . \Drupal::currentUser()->id() . ':' . $object->getFormId() . ':' . ($entity ? $entity->getEntityTypeId() . ':' . $entity->bundle() . ':' . ($entity->id() ?: 'new') : 'form');
  }

  /** Collect actual input elements, excluding unchecked/disabled-only controls. */
  private static function collect(array $element, array &$required, array &$controls, int &$uploads, array &$bindings, bool $disabled = FALSE): void {
    // Core decides upload/remove visibility at pre-render, after Form API's
    // after_build. Count the controls that will actually be sent by the browser.
    if (($element['#type'] ?? '') === 'managed_file') {
      $element = ManagedFile::preRenderManagedFile($element);
    }
    $access = $element['#access'] ?? TRUE;
    if ($access === FALSE || ($access instanceof AccessResultInterface && !$access->isAllowed())) {
      return;
    }
    $disabled = $disabled || !empty($element['#disabled']) || !empty($element['#attributes']['disabled']);
    if ($disabled) {
      return;
    }
    $type = $element['#type'] ?? '';
    if ($type === 'file') {
      $uploads++;
    }
    $theme = $element['#theme'] ?? '';
    $input = is_string($theme) && (str_starts_with($theme, 'input__') || in_array($theme, ['textarea', 'select'], TRUE));
    if ($input && isset($element['#name'], $element['#parents']) && !in_array($type, ['submit', 'button', 'image_button', 'file'], TRUE)) {
      $name = $element['#name'];
      $multiple = !empty($element['#multiple']);
      $controls[$name] = $multiple ? max(1, self::optionCount($element['#options'] ?? [])) : 1;
      if (!$multiple && !in_array($type, ['checkbox', 'radio'], TRUE)) {
        $required[$name] = $element['#parents'];
      }
      if (array_key_exists('data-actools-identity-inventory', $element['#attributes'] ?? [])) {
        $bindings[$name] = ['path' => $element['#parents'], 'array_parents' => $element['#array_parents'], 'digest' => hash('sha256', (string) $element['#value'])];
      }
    }
    foreach (Element::children($element) as $key) {
      self::collect($element[$key], $required, $controls, $uploads, $bindings, $disabled);
    }
  }

  private static function optionCount(array $options): int {
    $count = 0;
    foreach ($options as $option) {
      $count += is_array($option) ? self::optionCount($option) : 1;
    }
    return $count;
  }

  private static function disableSubmit(array &$element): void {
    if (in_array($element['#type'] ?? '', ['submit', 'button', 'image_button'], TRUE)) {
      $element['#disabled'] = TRUE;
      $element['#attributes']['disabled'] = 'disabled';
    }
    foreach (Element::children($element) as $key) {
      self::disableSubmit($element[$key]);
    }
  }

  private static function bytes(string $value): int {
    $value = trim($value);
    $number = (float) $value;
    return (int) ($number * match (strtolower(substr($value, -1))) {
      'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
    });
  }

}
