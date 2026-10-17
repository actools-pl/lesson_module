<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\actools_lesson\StructureInstaller;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Verifies safe compatibility preflight for existing learning-path choices. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class PathChoicePreflightTest extends LessonKernelTestBase {

  protected bool $installStructure = FALSE;

  public function testMissingBothPathsFailsWithoutCreatingAnyConfiguration(): void {
    $this->existingPath(['advanced' => 'Site-specific advanced']);
    $this->assertRejectedWithoutChanges();
  }

  public function testMissingExtensionFailsBeforeOtherFieldsAreCreated(): void {
    $this->existingPath(['core' => 'Core']);
    $this->assertRejectedWithoutChanges();
  }

  public function testCompatibleAdditionalChoicesAndCustomizationsAreUntouched(): void {
    $storage = $this->existingPath(['core' => 'Foundations', 'extension' => 'Further study', 'advanced' => 'Site-specific route']);
    NodeType::create(['type' => 'actools_resource', 'name' => 'Existing resources'])->save();
    $field = FieldConfig::create(['field_storage' => $storage, 'bundle' => 'actools_resource', 'label' => 'Our learning routes', 'default_value' => [['value' => 'advanced']]]);
    $field->save();
    $before = [$storage->toArray(), $field->toArray()];
    StructureInstaller::install();
    $this->assertEquals($before, [FieldStorageConfig::loadByName('node', 'field_actools_path')->toArray(), FieldConfig::loadByName('node', 'actools_resource', 'field_actools_path')->toArray()]);
  }

  public function testLegitimateEntityAwareCallbackIsResolvedForEachRequiredValue(): void {
    NodeType::create(['type' => 'actools_resource', 'name' => 'Existing resources'])->save();
    $storage = $this->existingPath(['advanced' => 'Static fallback'], self::class . '::entityAwareValues');
    $field = FieldConfig::create(['field_storage' => $storage, 'bundle' => 'actools_resource', 'label' => 'Dynamic routes']);
    $field->save();
    $before = [$storage->toArray(), $field->toArray()];
    StructureInstaller::install();
    $this->assertEquals($before, [FieldStorageConfig::loadByName('node', 'field_actools_path')->toArray(), FieldConfig::loadByName('node', 'actools_resource', 'field_actools_path')->toArray()]);
    $this->assertNotNull(FieldStorageConfig::loadByName('node', 'field_actools_lesson_steps'));
  }

  public function testCallbackCannotHideIncompatibleValuesBehindStaticChoices(): void {
    $this->existingPath(['core' => 'Core', 'extension' => 'Extension'], self::class . '::incompatibleValues');
    $this->assertRejectedWithoutChanges();
  }

  public function testBrokenCallbackReportsRemediationWithoutPartialInstallation(): void {
    $this->existingPath(['core' => 'Core', 'extension' => 'Extension'], 'actools_nonexistent_allowed_values_callback');
    $this->assertRejectedWithoutChanges();
  }

  /** Matches options_allowed_values()'s documented three-argument contract. */
  public static function entityAwareValues(FieldStorageDefinitionInterface $definition, ?FieldableEntityInterface $entity = NULL, bool &$cacheable = TRUE): array {
    $cacheable = FALSE;
    if ($entity && $entity->bundle() === 'actools_resource' && $entity->hasField('field_actools_path')) {
      $selected = $entity->get('field_actools_path')->value;
      return ['Supported routes' => [$selected => 'Available selected route'], 'Custom routes' => ['advanced' => 'Existing custom route']];
    }
    return ['advanced' => 'Global choices are deliberately narrower'];
  }

  public static function incompatibleValues(FieldStorageDefinitionInterface $definition, ?FieldableEntityInterface $entity = NULL, bool &$cacheable = TRUE): array {
    $cacheable = FALSE;
    return ['advanced' => 'Existing route only'];
  }

  private function existingPath(array $values, string $callback = ''): FieldStorageConfig {
    $storage = FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_actools_path', 'type' => 'list_string', 'settings' => ['allowed_values' => $values, 'allowed_values_function' => $callback]]);
    $storage->save();
    return $storage;
  }

  private function assertRejectedWithoutChanges(): void {
    $storage = $this->container->get('config.storage');
    $before = $storage->readMultiple($storage->listAll());
    try {
      StructureInstaller::install();
      $this->fail('An incompatible path field must not be accepted.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('field_actools_path', $exception->getMessage());
      $this->assertStringContainsString('before installing', $exception->getMessage());
    }
    $this->assertEquals($before, $storage->readMultiple($storage->listAll()), 'All configuration remains unchanged after failed preflight.');
    $this->assertNull(FieldStorageConfig::loadByName('node', 'field_actools_lesson_steps'));
  }

}
