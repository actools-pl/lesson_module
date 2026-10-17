<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\actools_lesson\StructureInstaller;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests installation alongside independently created resource bundles.
 *
 */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
class StructureCompatibilityTest extends LessonKernelTestBase {

  protected bool $installStructure = FALSE;

  public function testCompatibleFormattedSummaryIsReused(): void {
    NodeType::create(['type' => 'actools_resource', 'name' => 'Existing resources'])->save();
    $storage = FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_actools_summary',
      'type' => 'text_long',
    ]);
    $storage->save();
    $field = FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'actools_resource',
      'label' => 'Existing formatted introduction',
      'required' => TRUE,
      'settings' => ['allowed_formats' => ['plain_text']],
    ]);
    $field->save();
    $before = [$storage->toArray(), $field->toArray()];
    StructureInstaller::install();
    $this->assertEquals($before, [
      FieldStorageConfig::loadByName('node', 'field_actools_summary')->toArray(),
      FieldConfig::loadByName('node', 'actools_resource', 'field_actools_summary')->toArray(),
    ]);
    $this->assertNotNull(FieldConfig::loadByName('node', 'actools_resource', 'field_actools_lesson_steps'));
  }

  public function testConflictingStorageFailsBeforeCreatingConfiguration(): void {
    NodeType::create(['type' => 'actools_topic', 'name' => 'Existing topics'])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_actools_lessons',
      'type' => 'string',
    ])->save();
    $storage = $this->container->get('config.storage');
    $before = $storage->readMultiple($storage->listAll());
    try {
      StructureInstaller::install();
      $this->fail('An unrelated field storage must never be adopted as a lesson reference field.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('field_actools_lessons', $exception->getMessage());
    }
    $this->assertEquals($before, $storage->readMultiple($storage->listAll()), 'Preflight rejects an incompatible field without partially changing site configuration.');
  }

  public function testWrongReferenceTargetFailsBeforeCreatingConfiguration(): void {
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_actools_lessons',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    $storage = $this->container->get('config.storage');
    $before = $storage->readMultiple($storage->listAll());
    try {
      StructureInstaller::install();
      $this->fail('A taxonomy reference storage cannot be reused for the lesson node outline.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('field_actools_lessons', $exception->getMessage());
    }
    $this->assertEquals($before, $storage->readMultiple($storage->listAll()));
  }

  public function testOptionalTaxonomyDoesNotAdoptConflictingStorage(): void {
    Vocabulary::create(['vid' => 'edu_topics', 'name' => 'Existing education topics'])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_actools_concepts',
      'type' => 'string',
      'cardinality' => -1,
    ])->save();
    $storage = $this->container->get('config.storage');
    $before = $storage->readMultiple($storage->listAll());
    try {
      StructureInstaller::install();
      $this->fail('Optional taxonomy integration must not silently adopt a non-reference field.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('field_actools_concepts', $exception->getMessage());
    }
    $this->assertEquals($before, $storage->readMultiple($storage->listAll()));
  }

}
