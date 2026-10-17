<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Core\Session\UserSession;
use Drupal\filter\Entity\FilterFormat;

/**
 * Exercises actual revision storage, schema validation and historical file use.
 *
 * @group actools_lesson
 */
#[RunTestsInSeparateProcesses]
final class LessonStepFieldKernelTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'filter', 'text', 'node', 'file', 'image', 'actools_lesson'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter']);
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 1]));
    NodeType::create(['type' => 'step_test', 'name' => 'Step test'])->save();
    FieldStorageConfig::create(['field_name' => 'field_step_test', 'entity_type' => 'node', 'type' => 'actools_lesson_step', 'cardinality' => -1])->save();
    FieldConfig::create(['field_name' => 'field_step_test', 'entity_type' => 'node', 'bundle' => 'step_test'])->save();
  }

  private function step(array $components = []): array {
    return [
      'step_id' => $this->container->get('uuid')->generate(),
      'title' => 'Observe the meniscus',
      'optional' => 0,
      'reviewed' => 1,
      'content_version' => 1,
      'components' => json_encode($components, JSON_THROW_ON_ERROR),
    ];
  }

  public function testRevisionKeepsStableStepAndOldFigureUsage(): void {
    $filesystem = $this->container->get('file_system');
    $directory = 'public://lesson-test';
    $filesystem->prepareDirectory($directory, 1);
    $files = [];
    foreach (['first', 'second'] as $name) {
      $uri = $filesystem->saveData(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII='), $directory . '/' . $name . '.png');
      $file = File::create(['uri' => $uri, 'filename' => $name . '.png', 'filemime' => 'image/png', 'status' => 0, 'uid' => 1]);
      $file->save();
      $files[] = $file;
    }
    $component = ['id' => $this->container->get('uuid')->generate(), 'type' => 'figure', 'fid' => (int) $files[0]->id(), 'alt' => 'Concave water meniscus'];
    $step = $this->step([$component]);
    $node = Node::create(['type' => 'step_test', 'title' => 'Revision test', 'field_step_test' => [$step]]);
    $this->assertCount(0, $node->get('field_step_test')->validate());
    $node->save();
    $first_revision = $node->getRevisionId();
    $this->assertTrue(File::load($files[0]->id())->isPermanent());
    $component['fid'] = (int) $files[1]->id();
    $step['components'] = json_encode([$component], JSON_THROW_ON_ERROR);
    $step['content_version'] = 2;
    $node->setNewRevision(TRUE);
    $node->set('field_step_test', [$step]);
    $node->save();
    $old = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($first_revision);
    $this->assertSame($step['step_id'], $old->get('field_step_test')->step_id);
    $this->assertSame((int) $files[0]->id(), json_decode($old->get('field_step_test')->components, TRUE)[0]['fid']);
    foreach ($files as $file) {
      $uses = $this->container->get('file.usage')->listUsage($file);
      $this->assertSame(1, (int) $uses['actools_lesson']['node'][$node->id()]);
    }
    // Saving again must not inflate use counts or detach old-revision files.
    $node->save();
    $this->assertSame(1, (int) $this->container->get('file.usage')->listUsage($files[1])['actools_lesson']['node'][$node->id()]);
  }

  public function testInvalidStructuredDataAndDuplicateStepIdentityAreRejected(): void {
    $step = $this->step([['id' => 'not-a-uuid', 'type' => 'script', 'src' => 'https://invalid.example/script.js']]);
    $node = Node::create(['type' => 'step_test', 'title' => 'Invalid', 'field_step_test' => [$step, $step]]);
    $this->assertGreaterThan(0, $node->get('field_step_test')->validate()->count());
    $step['components'] = json_encode([['id' => $this->container->get('uuid')->generate(), 'type' => 'figure', 'fid' => 999999, 'alt' => '']], JSON_THROW_ON_ERROR);
    $node->set('field_step_test', [$step]);
    $violations = (string) $node->get('field_step_test')->validate();
    $this->assertStringContainsString('valid uploaded', $violations);
    $this->assertStringContainsString('alternative text', $violations);
  }

  public function testChangedTextCannotSelectForbiddenFormat(): void {
    FilterFormat::create(['format' => 'restricted_scientific', 'name' => 'Restricted scientific', 'filters' => []])->save();
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 23]));
    $component = ['id' => $this->container->get('uuid')->generate(), 'type' => 'text', 'body' => ['value' => '<p>A trusted existing passage.</p>', 'format' => 'restricted_scientific']];
    $step = $this->step([$component]);
    $node = Node::create(['type' => 'step_test', 'title' => 'Restricted format', 'field_step_test' => [$step]]);
    $this->assertStringContainsString('permission to use', (string) $node->get('field_step_test')->validate());
    // Trusted code can preserve a previously imported passage. A restricted
    // author may retain it unchanged, but must not edit it with that format.
    $node->save();
    $node = Node::load($node->id());
    $this->assertCount(0, $node->get('field_step_test')->validate());
    $component['body']['value'] = '<script>changed()</script>';
    $step['components'] = json_encode([$component], JSON_THROW_ON_ERROR);
    $node->set('field_step_test', [$step]);
    $this->assertStringContainsString('permission to use', (string) $node->get('field_step_test')->validate());
    $this->assertStringContainsString('newer content version', (string) $node->get('field_step_test')->validate());
  }

  public function testAnotherAuthorsTemporaryUploadAndDuplicateComponentAreRejected(): void {
    $filesystem = $this->container->get('file_system');
    $uri = $filesystem->saveData(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII='), 'public://foreign.png');
    $file = File::create(['uri' => $uri, 'filename' => 'foreign.png', 'filemime' => 'image/png', 'status' => 0, 'uid' => 99]);
    $file->save();
    $component = ['id' => $this->container->get('uuid')->generate(), 'type' => 'figure', 'fid' => (int) $file->id(), 'alt' => 'A figure owned by someone else'];
    $step = $this->step([$component]);
    $node = Node::create(['type' => 'step_test', 'title' => 'Foreign upload', 'field_step_test' => [$step]]);
    $this->assertStringContainsString('own temporary', (string) $node->get('field_step_test')->validate());
    $this->assertTrue(File::load($file->id())->isTemporary());
    $second = $this->step([$component]);
    $node->set('field_step_test', [$step, $second]);
    $this->assertStringContainsString('unique across every step', (string) $node->get('field_step_test')->validate());
  }

}
