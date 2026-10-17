<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\actools_lesson\StructureInstaller;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Bootstraps actual Drupal storage for lesson integration tests.
 */
abstract class LessonKernelTestBase extends KernelTestBase {

  protected bool $installStructure = TRUE;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'options', 'filter', 'file', 'image',
    'node', 'taxonomy', 'actools_lesson',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('actools_lesson', ['actools_lesson_membership']);
    $this->installConfig(['system', 'user', 'filter', 'node']);
    // Kernel tests do not invoke module install hooks automatically.
    if ($this->installStructure) {
      StructureInstaller::install();
    }
  }

  protected function reader(): UserInterface {
    if (!Role::load('lesson_reader')) {
      Role::create([
        'id' => 'lesson_reader',
        'label' => 'Lesson reader',
        'permissions' => ['access content'],
      ])->save();
    }
    // Reserve user 1: Drupal intentionally grants that account all access.
    if (!User::load(1)) {
      User::create(['uid' => 1, 'name' => 'root-test', 'status' => 1])->save();
    }
    $user = User::create([
      'name' => 'reader-' . $this->randomMachineName(),
      'status' => 1,
      'roles' => ['lesson_reader'],
    ]);
    $user->save();
    return $user;
  }

  protected function lesson(string $title, string $path = 'core', bool $published = TRUE): NodeInterface {
    $node = Node::create([
      'type' => 'actools_resource',
      'title' => $title,
      'uid' => 1,
      'status' => $published,
      'field_actools_kind' => 'lesson',
      'field_actools_lesson_managed' => TRUE,
      'field_actools_path' => $path,
      'field_actools_lesson_steps' => [$this->step()],
    ]);
    // Saving draft fixtures avoids requiring a chapter to exist first. Publish
    // after a chapter outline has been saved, as the real workflow does.
    $node->setUnpublished();
    $node->save();
    if ($published) {
      $node->setPublished();
    }
    return $node;
  }

  protected function chapter(string $title, array $lessons = [], bool $published = TRUE): NodeInterface {
    $chapter = Node::create([
      'type' => 'actools_topic',
      'title' => $title,
      'uid' => 1,
      'status' => $published,
      'field_actools_lesson_chapter' => TRUE,
      'field_actools_lessons' => array_map(static fn (NodeInterface $lesson): array => ['target_id' => $lesson->id()], $lessons),
    ]);
    $chapter->save();
    foreach ($lessons as $lesson) {
      if ($lesson->isPublished()) {
        $lesson->save();
      }
    }
    return $chapter;
  }

  protected function step(array $overrides = []): array {
    return $overrides + [
      'step_id' => $this->container->get('uuid')->generate(),
      'title' => 'Understand the physical model',
      'optional' => FALSE,
      'reviewed' => TRUE,
      'content_version' => 1,
      'components' => json_encode([[
        'id' => $this->container->get('uuid')->generate(),
        'type' => 'text',
        'body' => ['value' => 'A lesson explanation.', 'format' => 'plain_text'],
      ]], JSON_THROW_ON_ERROR),
    ];
  }

}
