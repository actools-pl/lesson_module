<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Plugin\Field\FieldWidget;

use Drupal\actools_lesson\Service\AuthoringInputGuard;
use Drupal\actools_lesson\Service\LessonAccess;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Site\Settings;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolationInterface;

/** Native, no-JavaScript-capable structured step and component authoring. */
#[FieldWidget(
  id: 'actools_lesson_step_builder',
  label: new TranslatableMarkup('Lesson builder'),
  field_types: ['actools_lesson_step'],
  multiple_values: TRUE,
)]
final class LessonStepBuilderWidget extends WidgetBase implements TrustedCallbackInterface {

  public const TYPES = [
    'text' => 'Explanation',
    'equation' => 'Display equation',
    'figure' => 'Scientific figure',
    'result' => 'Key result',
    'error' => 'Common error',
    'example' => 'Worked example',
    'practice' => 'Selected practice',
    'table' => 'Table / structured prose',
  ];

  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    AuthoringInputGuard::attach($form);
    AuthoringInputGuard::assertComplete($form_state);
    // The UUID inventory is server state, including on non-AJAX submissions.
    // Without form caching a new-node POST would rebuild unrelated UUIDs before
    // its submit handler can recover the user's entered step data.
    if (\Drupal::request()->isMethod('POST')) {
      $form_state->setCached(TRUE);
    }
    $parents = $form['#parents'];
    $name = $this->fieldDefinition->getName();
    $path = array_merge($parents, [$name]);
    $state = static::getWidgetState($parents, $name, $form_state);
    if (!isset($state['actools_steps'])) {
      $state['actools_baseline'] = $items->getValue();
      $state['actools_steps'] = static::editableRows($items->getValue());
      $submitted = NestedArray::getValue($form_state->getUserInput(), $path);
      if (is_array($submitted) && !empty($submitted['inventory'])) {
        $state['actools_steps'] = $this->restoreInventory((string) $submitted['inventory'], $state['actools_steps'], $items);
        $state['actools_steps'] = static::captureRows($submitted, $state['actools_steps']);
      }
      $entity = $items->getEntity();
      $initial_step = !$entity instanceof NodeInterface || $entity->bundle() !== 'actools_resource' || LessonAccess::isLesson($entity);
      if (!$state['actools_steps'] && $initial_step) {
        $step = static::newStep();
        $state['actools_steps'][$step['step_id']] = $step;
      }
      static::setWidgetState($parents, $name, $form_state, $state);
    }
    $wrapper = Html::getId('actools-lesson-builder-' . implode('-', $path));
    $steps = $state['actools_steps'];
    $first = array_key_first($steps);
    $ui = $state['actools_ui'] ?? ['steps' => $first ? [$first] : [], 'components' => $first ? [array_key_first($steps[$first]['components'])] : []];
    $submitted = NestedArray::getValue($form_state->getUserInput(), $path);
    $ui = static::captureUi(is_array($submitted) ? $submitted : [], $steps, $ui);
    $element += [
      '#type' => 'fieldset',
      '#attributes' => ['class' => ['actools-author']],
      '#actools_editor' => TRUE,
      '#actools_path' => $path,
      '#attached' => ['library' => ['actools_lesson/author']],
      '#prefix' => '<div id="' . $wrapper . '">',
      '#suffix' => '</div>',
      '#after_build' => [[static::class, 'afterBuildEditor']],
      '#element_validate' => [[AuthoringInputGuard::class, 'validateWidget']],
    ];
    if (!empty($state['actools_focus'])) {
      $element['#attributes']['data-actools-focus'] = $state['actools_focus'];
    }
    $element['intro'] = ['#markup' => '<p class="actools-author__intro">' . $this->t('Build meaningful learning steps. Keep an explanation, its figure and its assumptions together. Move controls work with keyboard or without JavaScript. Changes are saved only when you save the lesson.') . '</p>'];
    $count = AuthoringInputGuard::componentCount($state['actools_steps']);
    if (!empty($state['actools_action_notice'])) {
      $element['action_notice'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $state['actools_action_notice'], '#attributes' => ['class' => ['actools-author__notice'], 'role' => 'alert']];
    }
    $element['capacity'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('@count teaching components. New native-editor content is limited to 120 components across the lesson, 40 steps and 60 components per step. Existing larger documents are preserved; editing them requires sufficient server capacity.', ['@count' => $count]), '#attributes' => ['class' => ['actools-author__notice']]];
    // Drupal 11 forbids form-cache writes on the initial GET. Carry a signed
    // identity-only inventory into the first POST; authored content stays in
    // normal controls, never in a hidden content/JSON editor.
    $element['inventory'] = ['#type' => 'hidden', '#value' => $this->inventoryToken($state['actools_steps'], $items), '#attributes' => ['data-actools-identity-inventory' => '']];
    $element['ui_open_steps'] = ['#type' => 'hidden', '#default_value' => implode(',', $ui['steps']), '#attributes' => ['data-actools-open-steps' => '']];
    $element['ui_open_components'] = ['#type' => 'hidden', '#default_value' => implode(',', $ui['components']), '#attributes' => ['data-actools-open-components' => '']];
    $element['outline'] = ['#type' => 'html_tag', '#tag' => 'nav', '#attributes' => ['class' => ['actools-author__outline'], 'aria-label' => (string) $this->t('Lesson step outline')]];
    $element['outline']['heading'] = ['#markup' => '<strong>' . $this->t('Your lesson at a glance') . '</strong><p>' . $this->t('Choose a step to edit. Opening and closing sections does not change the lesson.') . '</p>'];
    $element['outline']['list'] = ['#theme' => 'item_list', '#list_type' => 'ol', '#items' => [], '#attributes' => ['class' => ['actools-author__outline-list']]];
    $element['steps'] = ['#type' => 'container', '#tree' => TRUE, '#attributes' => ['class' => ['actools-author__steps']]];
    $number = 0;
    foreach ($state['actools_steps'] as $index => $step) {
      $sid = $step['step_id'];
      $number++;
      $id = $wrapper . '-step-' . $sid;
      $title = $step['title'] ?: (string) $this->t('New learning step');
      $element['outline']['list']['#items'][] = [
        '#type' => 'html_tag', '#tag' => 'a', '#value' => Html::escape($title),
        '#attributes' => ['href' => '#' . $id, 'data-actools-jump' => $sid],
      ];
      $step_element = [
        '#type' => 'details',
        '#title' => $this->t('Step @number · @title', ['@number' => $number, '@title' => $title]),
        '#open' => in_array($sid, $ui['steps'], TRUE),
        '#id' => $id,
        '#summary_attributes' => ['data-actools-focus-key' => 'step:' . $sid],
        '#attributes' => ['class' => ['actools-author__step'], 'data-actools-step' => $sid, 'data-actools-number' => $number],
      ];
      $step_element['step_id'] = ['#type' => 'value', '#value' => $sid];
      $step_element['title'] = ['#type' => 'textfield', '#title' => $this->t('Step title'), '#default_value' => $step['title'], '#maxlength' => 255, '#required' => TRUE];
      $step_element['optional'] = ['#type' => 'checkbox', '#title' => $this->t('Optional extension step'), '#default_value' => $step['optional']];
      $step_element['reviewed'] = ['#type' => 'checkbox', '#title' => $this->t('I have reviewed this step’s content, figures and solutions'), '#default_value' => $step['reviewed'], '#description' => $this->t('Changing previously reviewed content clears its review. Save the draft, inspect the preview, then mark it reviewed.'), '#attributes' => ['data-actools-reviewed' => '']];
      $step_element['weight'] = ['#type' => 'number', '#title' => $this->t('Step order'), '#default_value' => $step['weight'], '#min' => -1000, '#max' => 1000, '#step' => 1, '#description' => $this->t('Lower numbers appear first. Stable step links do not change when reordered.'), '#wrapper_attributes' => ['class' => ['actools-author__order']]];
      $step_element['actions'] = ['#type' => 'container', '#attributes' => ['class' => ['actools-author__actions']]];
      foreach (['step_up' => 'Move step up', 'step_down' => 'Move step down', 'step_duplicate' => 'Duplicate step', 'step_remove' => 'Remove step'] as $action => $label) {
        $step_element['actions'][$action] = $this->actionButton($label, $action, $path, $wrapper, $sid);
        if ($action === 'step_duplicate' && $count + count($step['components']) > AuthoringInputGuard::MAX_COMPONENTS) {
          $step_element['actions'][$action]['#disabled'] = TRUE;
        }
      }
      $step_element['components'] = ['#type' => 'container', '#tree' => TRUE, '#attributes' => ['class' => ['actools-author__components']]];
      foreach ($step['components'] as $component) {
        $cid = $component['id'];
        $step_element['components'][$cid] = $this->componentElement($component, $path, $wrapper, $sid, in_array($cid, $ui['components'], TRUE), $step['components']);
        $duplicate_size = count(static::componentGroup($step['components'], $cid));
        if ($count + $duplicate_size > AuthoringInputGuard::MAX_COMPONENTS || count($step['components']) + $duplicate_size > 60) {
          $step_element['components'][$cid]['actions']['component_duplicate']['#disabled'] = TRUE;
          $step_element['components'][$cid]['duplicate_notice'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Duplicating this component and its attached figures would exceed the 60-component step or 120-component lesson limit. Remove content or use another step first.'), '#attributes' => ['class' => ['actools-author__notice']]];
        }
      }
      $step_element['new_type'] = ['#type' => 'select', '#title' => $this->t('Teaching component'), '#options' => array_map(fn ($label) => $this->t($label), self::TYPES), '#default_value' => 'text'];
      $step_element['add_component'] = $this->actionButton('Add component', 'component_add', $path, $wrapper, $sid);
      $step_element['add_component']['#disabled'] = $count >= AuthoringInputGuard::MAX_COMPONENTS;
      $element['steps'][$sid] = $step_element;
    }
    $element['add_step'] = $this->actionButton('Add learning step', 'step_add', $path, $wrapper);
    $element['add_step']['#disabled'] = $count >= AuthoringInputGuard::MAX_COMPONENTS;
    $element['add_step']['#attributes']['data-actools-focus-key'] = 'add-step';
    $element['notice'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Removing content here affects this unsaved revision. Existing published content remains until a replacement is published.'), '#attributes' => ['class' => ['actools-author__notice']]];
    return $element;
  }

  private function componentElement(array $component, array $path, string $wrapper, string $sid, bool $open, array $step_components): array {
    $type = $component['type'];
    $cid = $component['id'];
    $element = [
      '#type' => 'details',
      '#title' => $this->t('@type@heading', ['@type' => $this->t(self::TYPES[$type] ?? 'Teaching component'), '@heading' => empty($component['heading']) ? '' : ' · ' . $component['heading']]),
      '#open' => $open,
      '#id' => $wrapper . '-component-' . $cid,
      '#summary_attributes' => ['data-actools-focus-key' => 'component:' . $cid],
      '#attributes' => ['class' => ['actools-author__component', 'actools-author__component--' . $type], 'data-actools-component' => $cid, 'data-actools-type-label' => (string) $this->t(self::TYPES[$type] ?? 'Teaching component')],
      'id' => ['#type' => 'value', '#value' => $cid],
      'type' => ['#type' => 'value', '#value' => $type],
      'heading' => ['#type' => 'textfield', '#title' => $this->t('Component heading (optional)'), '#default_value' => $component['heading'] ?? '', '#maxlength' => 255],
      'weight' => ['#type' => 'number', '#title' => $this->t('Component order'), '#default_value' => $component['weight'] ?? 0, '#min' => -1000, '#max' => 1000, '#step' => 1, '#wrapper_attributes' => ['class' => ['actools-author__order']]],
    ];
    if ($type === 'equation') {
      $element['tex'] = ['#type' => 'textarea', '#title' => $this->t('Editable TeX equation'), '#default_value' => $component['tex'] ?? '', '#rows' => 3, '#required' => TRUE, '#description' => $this->t('Enter TeX without dollar delimiters. Use the saved lesson preview to inspect the actual typeset equation.')];
    }
    elseif ($type === 'figure') {
      $targets = ['' => $this->t('Standalone figure')];
      $position = 0;
      foreach ($step_components as $target) {
        $position++;
        if (in_array($target['type'], ['example', 'practice'], TRUE)) {
          $targets[$target['id']] = $this->t('@number. @type · @heading', ['@number' => $position, '@type' => $this->t(self::TYPES[$target['type']]), '@heading' => ($target['heading'] ?? '') ?: $this->t('Untitled component')]);
        }
      }
      $element['parent_id'] = ['#type' => 'select', '#title' => $this->t('Attach to example or practice'), '#options' => $targets, '#default_value' => is_string($component['parent_id'] ?? NULL) && isset($targets[$component['parent_id']]) ? $component['parent_id'] : '', '#description' => $this->t('Choose a component in this step, or keep the figure standalone. The image, caption and original-image link stay together. Removing the parent keeps its figures standalone.')];
      $element['placement'] = ['#type' => 'select', '#title' => $this->t('Figure placement'), '#options' => ['prompt' => $this->t('Prompt / given information'), 'hint' => $this->t('Hint'), 'answer' => $this->t('Answer'), 'solution' => $this->t('Worked solution')], '#default_value' => in_array($component['placement'] ?? '', ['prompt', 'hint', 'answer', 'solution'], TRUE) ? $component['placement'] : 'prompt', '#description' => $this->t('Applied when attached. Hint, Answer and Worked solution figures stay inside their disclosure until opened; Print view expands them. Duplicating a parent also copies its attached figures within the editor limits.')];
      $element['fid'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Figure image'),
        '#default_value' => empty($component['fid']) ? [] : [(int) $component['fid']],
        '#upload_location' => 'public://actools-lessons/figures',
        '#upload_validators' => ['FileExtension' => ['extensions' => 'png jpg jpeg gif webp'], 'FileSizeLimit' => ['fileLimit' => 20 * 1024 * 1024], 'FileIsImage' => []],
        '#description' => $this->t('Public teaching images only. Upload a new file when changing a figure so older revisions keep their original image. PNG, JPEG, GIF or WebP; maximum 20 MB.'),
        '#required' => TRUE,
      ];
      $element['alt'] = ['#type' => 'textfield', '#title' => $this->t('Alternative text'), '#default_value' => $component['alt'] ?? '', '#maxlength' => 1024, '#required' => TRUE];
      $element['caption'] = ['#type' => 'textarea', '#title' => $this->t('Figure caption'), '#default_value' => $component['caption'] ?? '', '#rows' => 2];
      $element['description'] = ['#type' => 'textarea', '#title' => $this->t('Long diagram description (optional)'), '#default_value' => $component['description'] ?? '', '#rows' => 3];
    }
    else {
      $element['body'] = $this->textElement($type === 'example' || $type === 'practice' ? 'Question / given information' : 'Explanation', $component['body'] ?? []);
      if (in_array($type, ['example', 'practice'], TRUE)) {
        foreach (['hint' => 'Hint (optional)', 'answer' => 'Answer', 'solution' => 'Worked solution'] as $property => $label) {
          $element[$property] = $this->textElement($label, $component[$property] ?? [], 3);
        }
      }
    }
    $element['source'] = ['#type' => 'textfield', '#title' => $this->t('Source / reviewed variant reference (shown to learners)'), '#default_value' => $component['source'] ?? '', '#maxlength' => 4000, '#description' => $this->t('This reference is displayed publicly with the component. Identify the source and exact reviewed variant/version; do not enter confidential editorial notes.')];
    $element['actions'] = ['#type' => 'container', '#attributes' => ['class' => ['actools-author__actions']]];
    foreach (['component_up' => 'Move component up', 'component_down' => 'Move component down', 'component_duplicate' => 'Duplicate component', 'component_remove' => 'Remove component'] as $action => $label) {
      $element['actions'][$action] = $this->actionButton($label, $action, $path, $wrapper, $sid, $cid);
    }
    return $element;
  }

  private function textElement(string $label, array $value, int $rows = 5): array {
    return [
      '#type' => 'text_format',
      '#title' => $this->t($label),
      '#default_value' => $value['value'] ?? '',
      '#format' => $value['format'] ?? NULL,
      '#rows' => $rows,
    ];
  }

  private function actionButton(string $label, string $action, array $path, string $wrapper, string $sid = '', string $cid = ''): array {
    return [
      '#type' => 'submit',
      '#value' => $this->t($label),
      '#name' => 'actools_' . substr(hash('sha256', implode(':', $path) . ':' . $action . ':' . $sid . ':' . $cid), 0, 20),
      '#submit' => [[static::class, 'submitAction']],
      '#limit_validation_errors' => [],
      '#ajax' => ['callback' => [static::class, 'ajaxRefresh'], 'wrapper' => $wrapper, 'effect' => 'none'],
      '#attributes' => ['data-disable-refocus' => 'true'],
      '#actools_action' => $action,
      '#actools_path' => $path,
      '#actools_step' => $sid,
      '#actools_component' => $cid,
    ];
  }

  public static function submitAction(array &$form, FormStateInterface $form_state): void {
    AuthoringInputGuard::assertComplete($form_state);
    $button = $form_state->getTriggeringElement();
    $path = $button['#actools_path'];
    $name = array_pop($path);
    $state = static::getWidgetState($path, $name, $form_state);
    $input = $form_state->getUserInput();
    $raw = NestedArray::getValue($input, $button['#actools_path']);
    $steps = static::captureRows(is_array($raw) ? $raw : [], $state['actools_steps']);
    $ui = static::captureUi(is_array($raw) ? $raw : [], $steps, $state['actools_ui'] ?? ['steps' => [], 'components' => []]);
    $action = $button['#actools_action'];
    $sid = $button['#actools_step'];
    $cid = $button['#actools_component'];
    $total = AuthoringInputGuard::componentCount($steps);
    unset($state['actools_action_notice']);
    $focus = $cid ? 'component:' . $cid : 'step:' . $sid;
    if ($action === 'step_add' && count($steps) < 40 && $total < AuthoringInputGuard::MAX_COMPONENTS) {
      $step = static::newStep();
      $steps[$step['step_id']] = $step;
      $sid = $step['step_id'];
      $cid = array_key_first($step['components']);
      $ui['components'][] = $cid;
      $focus = 'step:' . $sid;
    }
    elseif (isset($steps[$sid])) {
      if ($action === 'step_remove') {
        $keys = array_keys($steps);
        $position = array_search($sid, $keys, TRUE);
        unset($steps[$sid]);
        $sid = $keys[$position + 1] ?? $keys[$position - 1] ?? '';
        $focus = $sid ? 'step:' . $sid : 'add-step';
      }
      elseif ($action === 'step_duplicate' && count($steps) < 40 && $total + count($steps[$sid]['components']) <= AuthoringInputGuard::MAX_COMPONENTS) {
        $copy = $steps[$sid];
        $copy['step_id'] = static::uuid();
        $copy['title'] = trim($copy['title'] . ' (copy)');
        $copy['reviewed'] = FALSE;
        $copy['components'] = static::cloneComponents($copy['components']);
        $steps = static::insertAfter($steps, $sid, $copy['step_id'], $copy);
        $sid = $copy['step_id'];
        $ui['components'][] = array_key_first($copy['components']);
        $focus = 'step:' . $sid;
      }
      elseif ($action === 'step_up' || $action === 'step_down') {
        $steps = static::move($steps, $sid, $action === 'step_up' ? -1 : 1);
      }
      else {
        $components = $steps[$sid]['components'];
        $blocked_duplicate = FALSE;
        if ($action === 'component_add' && count($components) < 60 && $total < AuthoringInputGuard::MAX_COMPONENTS) {
          $type = $raw['steps'][$sid]['new_type'] ?? 'text';
          $component = static::newComponent(isset(self::TYPES[$type]) ? $type : 'text');
          $components[$component['id']] = $component;
          $cid = $component['id'];
          $focus = 'component:' . $cid;
        }
        elseif (isset($components[$cid])) {
          if ($action === 'component_remove') {
            $keys = array_keys($components);
            $position = array_search($cid, $keys, TRUE);
            unset($components[$cid]);
            foreach ($components as &$remaining) {
              if (($remaining['type'] ?? '') === 'figure' && ($remaining['parent_id'] ?? NULL) === $cid) {
                unset($remaining['parent_id'], $remaining['placement']);
              }
            }
            unset($remaining);
            $cid = $keys[$position + 1] ?? $keys[$position - 1] ?? '';
            $focus = $cid ? 'component:' . $cid : 'step:' . $sid;
          }
          elseif ($action === 'component_duplicate') {
            $group = static::componentGroup($components, $cid);
            if (count($components) + count($group) > 60 || $total + count($group) > AuthoringInputGuard::MAX_COMPONENTS) {
              $blocked_duplicate = TRUE;
              $state['actools_action_notice'] = (string) t('Nothing was duplicated. This component and its attached figures would exceed the 60-component step or 120-component lesson limit.');
            }
            else {
              $copies = static::cloneComponents($group, FALSE);
              $first_copy = array_key_first($copies);
              $after = $cid;
              foreach ($copies as $id => $copy) {
                $components = static::insertAfter($components, $after, $id, $copy);
                $after = $id;
              }
              $cid = $first_copy;
              $focus = 'component:' . $cid;
            }
          }
          elseif ($action === 'component_up' || $action === 'component_down') {
            $components = static::move($components, $cid, $action === 'component_up' ? -1 : 1);
          }
        }
        $steps[$sid]['components'] = $components;
        if (!$blocked_duplicate) {
          $steps[$sid]['reviewed'] = FALSE;
        }
      }
    }
    $weight = 0;
    foreach ($steps as &$step) {
      $step['weight'] = $weight++;
      $component_weight = 0;
      foreach ($step['components'] as &$component) {
        $component['weight'] = $component_weight++;
      }
      unset($component);
    }
    unset($step);
    $state['actools_steps'] = $steps;
    // Disclosure state is presentation only: accept known identities, then
    // reveal the result of the action without expanding unrelated sections.
    if ($sid && isset($steps[$sid])) {
      $ui['steps'][] = $sid;
      if ($cid && isset($steps[$sid]['components'][$cid])) {
        $ui['components'][] = $cid;
      }
    }
    $state['actools_ui'] = static::captureUi([
      'ui_open_steps' => implode(',', $ui['steps']),
      'ui_open_components' => implode(',', $ui['components']),
    ], $steps, ['steps' => [], 'components' => []]);
    $state['actools_focus'] = $focus;
    static::setWidgetState($path, $name, $form_state, $state);
    // Rebuild from captured values. Clearing only this input subtree preserves
    // unsaved title/metadata elsewhere and prevents stale input undoing moves.
    NestedArray::unsetValue($input, $button['#actools_path']);
    $form_state->setUserInput($input);
    $form_state->setRebuild();
  }

  public static function ajaxRefresh(array &$form, FormStateInterface $form_state): AjaxResponse|array {
    $parents = $form_state->getTriggeringElement()['#array_parents'];
    while ($parents) {
      array_pop($parents);
      $element = NestedArray::getValue($form, $parents);
      if (is_array($element) && !empty($element['#actools_editor'])) {
        return AuthoringInputGuard::ajaxResponse($form, $element, $form_state->getTriggeringElement()['#ajax']['wrapper']);
      }
    }
    return $form;
  }

  /** Keep core details processing, then reveal error-containing sections. */
  public static function afterBuildEditor(array $element, FormStateInterface $form_state): array {
    $element['#pre_render'][] = [static::class, 'revealErrors'];
    return $element;
  }

  public static function trustedCallbacks(): array {
    return ['revealErrors'];
  }

  /** Entity constraints have a step delta but may describe several components. */
  public function errorElement(array $element, ConstraintViolationInterface $error, array $form, FormStateInterface $form_state) {
    $property = explode('.', $error->getPropertyPath());
    $delta = array_shift($property);
    $keys = Element::children($element['steps']);
    $sid = is_numeric($delta) ? ($keys[(int) $delta] ?? NULL) : NULL;
    // A manually edited order can differ from the form's original DOM order.
    // Use the validated entity's identity where the native entity form has one.
    $object = $form_state->getFormObject();
    if (is_numeric($delta) && method_exists($object, 'getEntity')) {
      $entity = $object->getEntity();
      $name = $this->fieldDefinition->getName();
      if ($entity->hasField($name) && $entity->get($name)->get((int) $delta)) {
        $sid = (string) $entity->get($name)->get((int) $delta)->step_id;
      }
    }
    if ($sid && isset($element['steps'][$sid])) {
      $step = $element['steps'][$sid];
      return ($property[0] ?? '') === 'title' ? $step['title'] : $step['components'];
    }
    return $element;
  }

  /** Native field errors stay visible even when JavaScript is unavailable. */
  public static function revealErrors(array $element): array {
    foreach (Element::children($element['steps']) as $sid) {
      $step = &$element['steps'][$sid];
      $all = !empty($element['#errors']) || !empty($step['components']['#errors']);
      if ($all || !empty($step['#children_errors'])) {
        $step['#open'] = TRUE;
      }
      foreach (Element::children($step['components']) as $cid) {
        if ($all || !empty($step['components'][$cid]['#children_errors']) || !empty($step['components'][$cid]['#errors'])) {
          $step['components'][$cid]['#open'] = TRUE;
        }
      }
      unset($step);
    }
    return $element;
  }

  /** Read only UI state for identities already trusted by the native builder. */
  private static function captureUi(array $raw, array $steps, array $fallback): array {
    $known = ['steps' => array_keys($steps), 'components' => []];
    foreach ($steps as $step) {
      $known['components'] = array_merge($known['components'], array_keys($step['components']));
    }
    $ui = [];
    foreach ($known as $key => $ids) {
      $value = $raw['ui_open_' . $key] ?? NULL;
      $open = is_string($value) && strlen($value) <= 90000 ? explode(',', $value) : ($fallback[$key] ?? []);
      $ui[$key] = array_values(array_intersect($ids, $open));
    }
    return $ui;
  }

  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    AuthoringInputGuard::assertComplete($form_state);
    $state = static::getWidgetState($form['#parents'], $this->fieldDefinition->getName(), $form_state);
    $steps = static::captureRows($values, $state['actools_steps'] ?? []);
    $baseline = [];
    foreach ($state['actools_baseline'] ?? [] as $row) {
      $baseline[$row['step_id']] = $row;
    }
    $rows = [];
    foreach ($steps as $step) {
      $components = array_values(array_map(static function (array $component): array {
        unset($component['weight']);
        return $component;
      }, $step['components']));
      $row = [
        'step_id' => $step['step_id'],
        'title' => trim($step['title']),
        'optional' => (int) $step['optional'],
        'reviewed' => (int) $step['reviewed'],
        'content_version' => 1,
        'components' => json_encode($components, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      ];
      if (isset($baseline[$row['step_id']])) {
        $old = $baseline[$row['step_id']];
        $old_components = json_decode((string) $old['components'], TRUE) ?? [];
        // A heading rename or route position must not erase study completion.
        // Changes to the actual teaching components require a new version.
        $changed = $components != $old_components;
        $row['content_version'] = max(1, (int) $old['content_version']) + (int) $changed;
        if ($changed && !empty($old['reviewed'])) {
          $row['reviewed'] = 0;
        }
      }
      $rows[] = $row;
    }
    return $rows;
  }

  /** Captures only server-known identities/types; arbitrary JSON cannot enter. */
  public static function captureRows(array $values, array $known): array {
    $out = [];
    $submitted = $values['steps'] ?? [];
    if (!is_array($submitted)) {
      return $known;
    }
    foreach ($known as $sid => $step) {
      $input = $submitted[$sid] ?? NULL;
      if (!is_array($input)) {
        $out[$sid] = $step;
        continue;
      }
      $step['title'] = is_string($input['title'] ?? NULL) ? $input['title'] : $step['title'];
      $step['optional'] = !empty($input['optional']);
      $step['reviewed'] = !empty($input['reviewed']);
      $step['weight'] = is_numeric($input['weight'] ?? NULL) ? (int) $input['weight'] : $step['weight'];
      foreach ($step['components'] as $cid => &$component) {
        $raw = $input['components'][$cid] ?? NULL;
        if (!is_array($raw)) {
          continue;
        }
        foreach (['heading', 'tex', 'alt', 'caption', 'description', 'source'] as $key) {
          if (isset($raw[$key]) && is_string($raw[$key]) && ($raw[$key] !== '' || array_key_exists($key, $component))) {
            $component[$key] = $raw[$key];
          }
        }
        foreach (['body', 'hint', 'answer', 'solution'] as $key) {
          if (isset($raw[$key]) && is_array($raw[$key])) {
            $component[$key] = [
              'value' => is_string($raw[$key]['value'] ?? NULL) ? $raw[$key]['value'] : '',
              'format' => is_string($raw[$key]['format'] ?? NULL) ? $raw[$key]['format'] : (string) ($component[$key]['format'] ?? 'plain_text'),
            ];
          }
        }
        if ($component['type'] === 'figure' && array_key_exists('parent_id', $raw) && is_string($raw['parent_id'])) {
          if ($raw['parent_id'] === '') {
            unset($component['parent_id'], $component['placement']);
          }
          else {
            $component['parent_id'] = $raw['parent_id'];
            $component['placement'] = is_string($raw['placement'] ?? NULL) ? $raw['placement'] : ($component['placement'] ?? 'prompt');
          }
        }
        if ($component['type'] === 'figure' && isset($raw['fid'])) {
          $fids = is_array($raw['fid']) ? ($raw['fid']['fids'] ?? $raw['fid']) : $raw['fid'];
          $component['fid'] = is_array($fids) ? (int) reset($fids) : (int) $fids;
        }
        $component['weight'] = is_numeric($raw['weight'] ?? NULL) ? (int) $raw['weight'] : ($component['weight'] ?? 0);
      }
      unset($component);
      uasort($step['components'], static fn (array $a, array $b): int => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
      $out[$sid] = $step;
    }
    uasort($out, static fn (array $a, array $b): int => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
    return $out;
  }

  public static function editableRows(array $rows): array {
    $steps = [];
    foreach ($rows as $weight => $row) {
      if (empty($row['step_id'])) {
        continue;
      }
      $components = [];
      foreach (json_decode((string) ($row['components'] ?? '[]'), TRUE) ?? [] as $i => $component) {
        if (is_array($component) && isset($component['id'])) {
          $component['weight'] = $i;
          $components[$component['id']] = $component;
        }
      }
      $steps[$row['step_id']] = $row + ['optional' => FALSE, 'reviewed' => FALSE, 'title' => ''];
      $steps[$row['step_id']]['components'] = $components;
      $steps[$row['step_id']]['weight'] = $weight;
    }
    return $steps;
  }

  private static function newStep(): array {
    $component = static::newComponent('text');
    return ['step_id' => static::uuid(), 'title' => '', 'optional' => FALSE, 'reviewed' => FALSE, 'weight' => 1000, 'components' => [$component['id'] => $component]];
  }

  private static function newComponent(string $type): array {
    return ['id' => static::uuid(), 'type' => $type, 'heading' => '', 'weight' => 1000];
  }

  /** Parent duplication is atomic with all of its same-step figures. */
  private static function componentGroup(array $components, string $id): array {
    $group = [$id => $components[$id]];
    if (in_array($components[$id]['type'] ?? '', ['example', 'practice'], TRUE)) {
      foreach ($components as $cid => $component) {
        if (($component['type'] ?? '') === 'figure' && ($component['parent_id'] ?? NULL) === $id) {
          $group[$cid] = $component;
        }
      }
    }
    return $group;
  }

  /** Rekey a copied group; full-step copies never point to the source step. */
  private static function cloneComponents(array $components, bool $detach_external = TRUE): array {
    $ids = [];
    foreach ($components as $id => $component) {
      $ids[$id] = static::uuid();
    }
    $copies = [];
    foreach ($components as $id => $component) {
      $component['id'] = $ids[$id];
      if (($component['type'] ?? '') === 'figure' && !empty($component['parent_id'])) {
        if (is_string($component['parent_id']) && isset($ids[$component['parent_id']])) {
          $component['parent_id'] = $ids[$component['parent_id']];
        }
        elseif ($detach_external || !is_string($component['parent_id'])) {
          unset($component['parent_id'], $component['placement']);
        }
      }
      $copies[$component['id']] = $component;
    }
    return $copies;
  }

  private static function uuid(): string {
    return \Drupal::service('uuid')->generate();
  }

  private function inventoryKey(FieldItemListInterface $items): string {
    $entity = $items->getEntity();
    return \Drupal::service('private_key')->get() . Settings::getHashSalt() . ':' . \Drupal::currentUser()->id() . ':' . $entity->getEntityTypeId() . ':' . $entity->bundle() . ':' . ($entity->id() ?: 'new') . ':' . $this->fieldDefinition->getName();
  }

  private function inventoryToken(array $steps, FieldItemListInterface $items): string {
    $inventory = [];
    foreach ($steps as $sid => $step) {
      $inventory[$sid] = [];
      foreach ($step['components'] as $cid => $component) {
        $inventory[$sid][$cid] = $component['type'];
      }
      ksort($inventory[$sid]);
    }
    // This token authenticates identities/types, not editable numeric order.
    ksort($inventory);
    $payload = base64_encode(json_encode($inventory, JSON_THROW_ON_ERROR));
    return $payload . '.' . Crypt::hmacBase64($payload, $this->inventoryKey($items));
  }

  private function restoreInventory(string $token, array $baseline, FieldItemListInterface $items): array {
    if (strlen($token) > 350000 || !str_contains($token, '.')) {
      throw new BadRequestHttpException('The lesson builder identity inventory is invalid. Reopen the editor.');
    }
    [$payload, $signature] = explode('.', $token, 2);
    if (!hash_equals(Crypt::hmacBase64($payload, $this->inventoryKey($items)), $signature)) {
      throw new BadRequestHttpException('The lesson builder identity inventory cannot be verified. Reopen the editor.');
    }
    $inventory = json_decode(base64_decode($payload, TRUE) ?: '', TRUE, 8);
    if (!is_array($inventory) || count($inventory) > 40) {
      throw new BadRequestHttpException('The lesson builder identity inventory is invalid.');
    }
    $steps = [];
    foreach ($inventory as $sid => $types) {
      if (!is_array($types) || count($types) > 60) {
        throw new BadRequestHttpException('The lesson builder component inventory is invalid.');
      }
      $step = $baseline[$sid] ?? ['step_id' => $sid, 'title' => '', 'optional' => FALSE, 'reviewed' => FALSE, 'weight' => count($steps), 'components' => []];
      $components = [];
      foreach ($types as $cid => $type) {
        $components[$cid] = $step['components'][$cid] ?? ['id' => $cid, 'type' => $type, 'heading' => '', 'weight' => count($components)];
      }
      $step['components'] = $components;
      $steps[$sid] = $step;
    }
    return $steps;
  }

  private static function move(array $items, string $key, int $direction): array {
    $keys = array_keys($items);
    $index = array_search($key, $keys, TRUE);
    if ($index !== FALSE && isset($keys[$index + $direction])) {
      [$keys[$index], $keys[$index + $direction]] = [$keys[$index + $direction], $keys[$index]];
    }
    return array_replace(array_flip($keys), $items);
  }

  private static function insertAfter(array $items, string $after, string $key, array $value): array {
    $out = [];
    foreach ($items as $id => $item) {
      $out[$id] = $item;
      if ($id === $after) {
        $out[$key] = $value;
      }
    }
    return $out;
  }

}
