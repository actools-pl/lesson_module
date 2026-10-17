<?php

declare(strict_types=1);

namespace Drupal\actools_lesson_demo;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;

/** Imports original illustrative content only on explicit submodule enable. */
final class DemoInstaller {
  public static function install(): void {
    $admin = \Drupal\user\Entity\User::load(1);
    if (!$admin) {
      throw new \RuntimeException('The demonstration import requires the site owner account to exist.');
    }
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($admin);
    try {
      self::import();
    }
    finally {
      $switcher->switchBack();
    }
  }

  private static function import(): void {
    $state = \Drupal::state();
    if (($id = $state->get('actools_lesson_demo.chapter_id')) && Node::load($id)) {
      return;
    }
    $path = \Drupal::service('extension.list.module')->getPath('actools_lesson_demo');
    $data = json_decode(file_get_contents($path . '/content/surface-tension.json'), TRUE, 128, JSON_THROW_ON_ERROR);
    $directory = 'public://actools-lessons/demo';
    if (!\Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException('Actools Lessons demo could not create its public image directory.');
    }
    $files = [];
    foreach (['soap-film-force.png', 'drop-bubble-pressure.png', 'capillary-rise.png'] as $name) {
      $files[$name] = \Drupal::service('file.repository')->writeData(file_get_contents($path . '/assets/' . $name), $directory . '/' . $name, FileExists::Rename);
    }
    $lessons = [];
    foreach ($data['lessons'] as $entry) {
      $steps = [];
      foreach ($entry['steps'] as $step) {
        foreach ($step['components'] as &$component) {
          if (isset($component['asset'])) {
            $component['fid'] = (int) $files[$component['asset']]->id();
            unset($component['asset']);
          }
        }
        unset($component);
        $step['components'] = json_encode($step['components'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $steps[] = $step;
      }
      $node = Node::create([
        'type' => 'actools_resource', 'title' => $entry['title'], 'uid' => 1, 'status' => FALSE,
        'field_actools_lesson_managed' => TRUE, 'field_actools_kind' => 'lesson',
        'field_actools_path' => $entry['path'],
        'field_actools_objectives' => $entry['objectives'], 'field_actools_prerequisites' => $entry['prerequisites'],
        'field_actools_lesson_steps' => $steps,
      ]);
      $node->set('field_actools_summary', ['value' => $entry['summary'], 'format' => 'actools_lesson_text']);
      $node->save();
      $lessons[] = $node;
    }
    $chapter = Node::create([
      'type' => 'actools_topic', 'title' => $data['title'], 'uid' => 1, 'status' => TRUE,
      'field_actools_lesson_chapter' => TRUE, 'field_actools_chapter_code' => $data['code'],
      'field_actools_body' => ['value' => $data['introduction'], 'format' => 'actools_lesson_text'],
      'field_actools_objectives' => $data['objectives'], 'field_actools_prerequisites' => $data['prerequisites'],
      'field_actools_lessons' => array_map(static fn($node) => ['target_id' => $node->id()], $lessons),
    ]);
    $chapter->save();
    foreach ($lessons as $lesson) {
      $lesson->setPublished()->setNewRevision(TRUE);
      $lesson->save();
    }
    $state->set('actools_lesson_demo.chapter_id', (int) $chapter->id());
    $state->set('actools_lesson_demo.lesson_ids', array_map(static fn($node) => (int) $node->id(), $lessons));
  }
}
