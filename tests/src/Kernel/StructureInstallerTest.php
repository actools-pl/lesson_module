<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\actools_lesson\StructureInstaller;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ensures installation preserves site-owned configuration and legacy content.
 *
 */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
class StructureInstallerTest extends LessonKernelTestBase {

  public function testInstallationIsIdempotentAndDoesNotSeedContent(): void {
    $this->assertNotNull(NodeType::load('actools_topic'));
    $this->assertNotNull(NodeType::load('actools_resource'));
    $this->assertSame('actools_lesson_step', FieldStorageConfig::loadByName('node', 'field_actools_lesson_steps')->getType());
    $this->assertSame(-1, FieldStorageConfig::loadByName('node', 'field_actools_lesson_steps')->getCardinality());
    $before = $this->container->get('config.storage')->readMultiple($this->container->get('config.storage')->listAll());
    StructureInstaller::install();
    $after = $this->container->get('config.storage')->readMultiple($this->container->get('config.storage')->listAll());
    $this->assertEquals($before, $after, 'Re-running installation leaves every existing configuration object unchanged.');
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute());
    $this->assertFalse((bool) $this->container->get('config.factory')->get('core.extension')->get('module.actools_lesson_demo'));
  }

  public function testExistingFieldAndDisplayCustomizationsSurvive(): void {
    $field = FieldConfig::loadByName('node', 'actools_resource', 'field_actools_summary');
    $field->setLabel('A site-specific summary');
    $field->setRequired(TRUE);
    $field->setDescription('Existing editorial policy.');
    $field->save();
    $bundle = NodeType::load('actools_resource');
    $bundle->set('name', 'Our teaching resources');
    $bundle->setNewRevision(FALSE);
    $bundle->save();
    $display = $this->container->get('entity_display.repository')->getFormDisplay('node', 'actools_resource');
    $display->setComponent('field_actools_summary', ['type' => 'string_textarea', 'weight' => 17]);
    $display->save();
    $before = [$field->toArray(), $bundle->toArray(), $display->toArray()];
    StructureInstaller::install();
    $this->container->get('entity_type.manager')->getStorage('field_config')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('node_type')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('entity_form_display')->resetCache();
    $this->assertEquals($before, [
      FieldConfig::loadByName('node', 'actools_resource', 'field_actools_summary')->toArray(),
      NodeType::load('actools_resource')->toArray(),
      $this->container->get('entity_display.repository')->getFormDisplay('node', 'actools_resource')->toArray(),
    ]);
  }

  public function testUnmanagedResourceIsNotConvertedIntoALesson(): void {
    $resource = $this->container->get('entity_type.manager')->getStorage('node')->create([
      'type' => 'actools_resource',
      'title' => 'An existing supplementary notes card',
      'status' => 1,
      'field_actools_kind' => 'lesson',
      'field_actools_summary' => 'Existing content remains unchanged.',
    ]);
    $resource->save();
    StructureInstaller::install();
    $resource = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($resource->id());
    $this->assertFalse((bool) $resource->get('field_actools_lesson_managed')->value);
    $this->assertTrue($resource->get('field_actools_lesson_steps')->isEmpty());
    $this->assertFalse($this->container->get('actools_lesson.access')->isLesson($resource));
    $this->assertFalse($this->container->get('actools_lesson.access')->reader($resource, $this->reader())->isAllowed());
  }

}
