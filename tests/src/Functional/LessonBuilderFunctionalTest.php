<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Functional;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The real, non-JavaScript editor journey must preserve unsaved content/IDs.
 *
 * @group actools_lesson
 */
#[RunTestsInSeparateProcesses]
final class LessonBuilderFunctionalTest extends BrowserTestBase {

  protected static $modules = ['node', 'field', 'text', 'filter', 'file', 'image', 'actools_lesson'];
  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    NodeType::create(['type' => 'step_ui_test', 'name' => 'Step UI test'])->save();
    FieldStorageConfig::create(['field_name' => 'field_step_ui', 'entity_type' => 'node', 'type' => 'actools_lesson_step', 'cardinality' => -1])->save();
    FieldConfig::create(['field_name' => 'field_step_ui', 'entity_type' => 'node', 'bundle' => 'step_ui_test', 'label' => 'Lesson steps'])->save();
    EntityFormDisplay::create(['targetEntityType' => 'node', 'bundle' => 'step_ui_test', 'mode' => 'default', 'status' => TRUE])
      ->setComponent('title', ['type' => 'string_textfield'])
      ->setComponent('field_step_ui', ['type' => 'actools_lesson_step_builder'])
      ->save();
    $this->drupalLogin($this->drupalCreateUser(['bypass node access', 'administer nodes']));
  }

  private function titleInputs(): array {
    return $this->getSession()->getPage()->findAll('css', '.actools-author input[name$="[title]"]');
  }

  public function testAddDuplicateMoveRemoveSaveAndReopen(): void {
    $this->drupalGet('/node/add/step_ui_test');
    $page = $this->getSession()->getPage();
    $page->fillField('Title', 'A real authored lesson');
    $titles = $this->titleInputs();
    $this->assertCount(1, $titles);
    $titles[0]->setValue('Observe the surface');
    $first_name = $titles[0]->getAttribute('name');
    $body = $page->find('css', '.actools-author textarea[name$="[body][value]"]');
    $body->setValue('An explanation entered through the actual form.');
    $page->pressButton('Add learning step');
    $titles = $this->titleInputs();
    $this->assertCount(2, $titles);
    $this->assertSame('Observe the surface', $titles[0]->getValue());
    $titles[1]->setValue('Derive the relation');
    $page->pressButton('Duplicate step');
    $this->assertCount(3, $this->titleInputs());
    $page->pressButton('Move step down');
    $titles = $this->titleInputs();
    $this->assertSame('Observe the surface (copy)', $titles[0]->getValue());
    $page->pressButton('Remove step');
    $titles = $this->titleInputs();
    $this->assertCount(2, $titles);
    $this->assertSame($first_name, $titles[0]->getAttribute('name'));
    $page->pressButton('Save');
    $this->assertSession()->pageTextNotContains('This value should not be null');
    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', 'A real authored lesson')->execute();
    $this->assertCount(1, $ids);
    $node = Node::load(reset($ids));
    $rows = $node->get('field_step_ui')->getValue();
    $this->assertCount(2, $rows);
    $this->assertSame('Observe the surface', $rows[0]['title']);
    $this->assertSame('Derive the relation', $rows[1]['title']);
    $this->assertSame('An explanation entered through the actual form.', json_decode($rows[0]['components'], TRUE)[0]['body']['value']);
    $this->drupalGet('/node/' . $node->id() . '/edit');
    $titles = $this->titleInputs();
    $this->assertSame($first_name, $titles[0]->getAttribute('name'));
    $titles[0]->setValue('Observe the water surface');
    $this->getSession()->getPage()->pressButton('Save');
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$node->id()]);
    $updated = Node::load($node->id())->get('field_step_ui')->getValue();
    $this->assertSame($rows[0]['step_id'], $updated[0]['step_id']);
    $this->assertSame(1, (int) $updated[0]['content_version'], 'A title-only rename preserves completed content version.');
    $this->assertSame(json_decode($rows[0]['components'], TRUE)[0]['id'], json_decode($updated[0]['components'], TRUE)[0]['id']);
    $this->drupalGet('/node/' . $node->id() . '/edit');
    $this->getSession()->getPage()->find('css', '.actools-author textarea[name$="[body][value]"]')->setValue('A substantively revised explanation.');
    $this->getSession()->getPage()->pressButton('Save');
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$node->id()]);
    $changed = Node::load($node->id())->get('field_step_ui')->getValue();
    $this->assertSame(2, (int) $changed[0]['content_version']);
  }

  public function testForgedFirstPostIdentityInventoryIsRejected(): void {
    $this->drupalGet('/node/add/step_ui_test');
    $page = $this->getSession()->getPage();
    $page->fillField('Title', 'A forged inventory must not save');
    $this->titleInputs()[0]->setValue('Invented identity');
    $page->find('css', 'input[name="field_step_ui[inventory]"]')->setValue('modified.invalid');
    $page->pressButton('Save');
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSame(0, (int) \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', 'A forged inventory must not save')->count()->execute());
  }

  /** Saved lessons stay compact; native actions/errors remain useful without JS. */
  public function testOutlineDisclosureStateAndErrorRecovery(): void {
    $rows = [];
    for ($i = 0; $i < 3; $i++) {
      $rows[] = [
        'step_id' => \Drupal::service('uuid')->generate(), 'title' => 'Learning step ' . ($i + 1),
        'content_version' => 1, 'reviewed' => 0, 'optional' => 0,
        'components' => json_encode([
          ['id' => \Drupal::service('uuid')->generate(), 'type' => 'text', 'heading' => 'Explanation heading', 'body' => ['value' => 'A saved explanation.', 'format' => 'plain_text']],
          ['id' => \Drupal::service('uuid')->generate(), 'type' => 'equation', 'tex' => 'F=ma'],
        ], JSON_THROW_ON_ERROR),
      ];
    }
    $node = Node::create(['type' => 'step_ui_test', 'title' => 'Compact saved lesson', 'field_step_ui' => $rows]);
    $node->save();
    $this->drupalGet('/node/' . $node->id() . '/edit');
    $page = $this->getSession()->getPage();
    $steps = $page->findAll('css', '.actools-author__step');
    $links = $page->findAll('css', '.actools-author__outline-list a');
    $this->assertCount(3, $links);
    $this->assertSame('#' . $steps[1]->getAttribute('id'), $links[1]->getAttribute('href'));
    $this->assertTrue($steps[0]->hasAttribute('open'));
    $this->assertFalse($steps[1]->hasAttribute('open'));
    $components = $steps[0]->findAll('css', '.actools-author__component');
    $this->assertTrue($components[0]->hasAttribute('open'));
    $this->assertFalse($components[1]->hasAttribute('open'));
    $page->find('css', '[data-actools-open-steps]')->setValue($rows[1]['step_id'] . ',' . $rows[2]['step_id']);
    $page->find('css', '[data-actools-open-components]')->setValue('');
    $steps[1]->pressButton('Duplicate step');
    $steps = $page->findAll('css', '.actools-author__step');
    $this->assertCount(4, $steps);
    $this->assertFalse($steps[0]->hasAttribute('open'), 'An unrelated closed section stays closed after an action.');
    $this->assertTrue($steps[1]->hasAttribute('open'));
    $this->assertTrue($steps[2]->hasAttribute('open'), 'The new copy opens for editing.');
    $this->assertTrue($steps[3]->hasAttribute('open'), 'Another previously open step stays open.');
    $this->assertSame('step:' . $steps[2]->getAttribute('data-actools-step'), $page->find('css', '.actools-author')->getAttribute('data-actools-focus'));
    $steps[2]->find('css', 'textarea[name$="[body][value]"]')->setValue('Edited duplicated explanation.');
    $page->pressButton('Save');
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$node->id()]);
    $saved = Node::load($node->id())->get('field_step_ui')->getValue();
    $copy_components = json_decode($saved[2]['components'], TRUE);
    $this->assertSame('Edited duplicated explanation.', $copy_components[0]['body']['value']);
    $this->assertNotSame(json_decode($saved[1]['components'], TRUE)[0]['id'], $copy_components[0]['id']);
    $this->drupalGet('/node/' . $node->id() . '/edit');
    $page = $this->getSession()->getPage();
    $steps = $page->findAll('css', '.actools-author__step');
    $steps[1]->find('css', 'input[name$="[title]"]')->setValue('');
    $steps[1]->find('css', 'textarea[name$="[tex]"]')->setValue('');
    $page->pressButton('Save');
    $steps = $page->findAll('css', '.actools-author__step');
    $this->assertTrue($steps[1]->hasAttribute('open'), 'A hidden invalid step opens on the server.');
    $components = $steps[1]->findAll('css', '.actools-author__component');
    $this->assertTrue($components[1]->hasAttribute('open'), 'A hidden invalid component opens without JavaScript.');
    $this->assertSame('', $components[1]->find('css', 'textarea[name$="[tex]"]')->getValue());
  }

}
