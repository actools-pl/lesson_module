<?php

declare(strict_types=1);

namespace Drupal\actools_lesson\Element;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\HtmlTag;
use Drupal\Core\Render\Element\Submit;

/**
 * A normal Form API submit control whose visible label can wrap on a phone.
 *
 * Core still supplies button processing, name/value, validation and submission.
 * Only this module's progress controls opt into the button tag rendering.
 */
#[FormElement('actools_progress_submit')]
final class ProgressSubmit extends Submit {

  public function getInfo() {
    $info = parent::getInfo();
    $info['#theme_wrappers'] = [];
    return $info;
  }

  public static function preRenderButton($element) {
    $element = parent::preRenderButton($element);
    $element['#tag'] = 'button';
    // Preserve the original #value attribute for Form API triggering while
    // rendering the same label as escaped text inside the native button.
    $element['#value'] = Html::escape((string) $element['#value']);
    return HtmlTag::preRenderHtmlTag($element);
  }

}
