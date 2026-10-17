<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\node\Entity\Node;
use Drupal\workflows\Entity\Workflow;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Verifies an actual configured core workflow remains authoritative. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class EditorialModerationCompatibilityTest extends LessonKernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'options', 'filter', 'file', 'image',
    'node', 'taxonomy', 'workflows', 'content_moderation', 'actools_lesson',
  ];

  public function testConfiguredContentModerationDefersTheCustomActions(): void {
    $node = Node::create([
      'type' => 'actools_resource', 'title' => 'A moderated lesson',
      'field_actools_kind' => 'lesson', 'field_actools_lesson_managed' => TRUE,
    ]);
    $editorial = $this->container->get('actools_lesson.editorial');
    $this->assertTrue($editorial->manages($node));
    Workflow::create([
      'id' => 'existing_editorial', 'label' => 'Existing editorial policy',
      'type' => 'content_moderation',
      'type_settings' => [
        'states' => [
          'draft' => ['label' => 'Draft', 'weight' => 0, 'published' => FALSE, 'default_revision' => FALSE],
          'published' => ['label' => 'Published', 'weight' => 1, 'published' => TRUE, 'default_revision' => TRUE],
        ],
        'transitions' => [
          'save_draft' => ['label' => 'Save draft', 'from' => ['draft', 'published'], 'to' => 'draft', 'weight' => 0],
          'publish' => ['label' => 'Publish', 'from' => ['draft', 'published'], 'to' => 'published', 'weight' => 1],
        ],
        'entity_types' => ['node' => ['actools_resource']],
        'default_moderation_state' => 'draft',
      ],
    ])->save();
    $this->assertTrue($this->container->get('content_moderation.moderation_information')->isModeratedEntity($node));
    $this->assertFalse($editorial->manages($node), 'The module does not override native actions/status when the site owns the workflow.');
  }

}
