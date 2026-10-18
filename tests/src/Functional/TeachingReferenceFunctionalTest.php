<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Exercises optional teaching references through the actual native editor. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class TeachingReferenceFunctionalTest extends BrowserTestBase {

  protected static $modules = ['actools_lesson'];
  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('access content')->save();
    $this->drupalLogin($this->drupalCreateUser([
      'access content', 'access actools lesson authoring', 'bypass node access',
      'administer nodes', 'use text format actools_lesson_text',
    ]));
  }

  public function testPracticeReferencesValidateSaveReopenDuplicateAndClear(): void {
    $practice = $this->practice();
    $lesson = $this->lesson([[$practice]]);
    $original_revision = $lesson->getRevisionId();
    $reference = 'ST-bank/practice-041/variant-B';
    $version = 'review-2026-10-18.2';
    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->field($practice['id'], 'variant_ref')->setValue($reference);
    $this->getSession()->getPage()->pressButton('Save draft');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('A practice bank reference requires both');
    $this->assertSame((int) $original_revision, (int) $this->latest($lesson)->getRevisionId(), 'A half-entered reference does not save a revision.');

    $this->field($practice['id'], 'variant_ref')->setValue('');
    $this->field($practice['id'], 'variant_version')->setValue($version);
    $this->getSession()->getPage()->pressButton('Save draft');
    $this->assertSession()->pageTextContains('A practice bank reference requires both');
    $this->assertSame((int) $original_revision, (int) $this->latest($lesson)->getRevisionId());

    $this->field($practice['id'], 'variant_ref')->setValue($reference);
    $this->getSession()->getPage()->pressButton('Save draft');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Private draft preview');
    $this->assertSession()->pageTextNotContains($reference);
    $saved = $this->parts($this->latest($lesson))[0][0];
    $this->assertSame($reference, $saved['variant_ref']);
    $this->assertSame($version, $saved['variant_version']);
    $this->assertSame($practice['body'], $saved['body'], 'A reference does not fetch or replace the stored question.');

    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->assertSame($reference, $this->field($practice['id'], 'variant_ref')->getValue());
    $this->assertSame($version, $this->field($practice['id'], 'variant_version')->getValue());
    $this->component($practice['id'])->pressButton('Duplicate component');
    $this->getSession()->getPage()->pressButton('Save draft');
    $parts = $this->parts($this->latest($lesson))[0];
    $this->assertCount(2, $parts);
    $this->assertNotSame($parts[0]['id'], $parts[1]['id']);
    foreach ($parts as $part) {
      $this->assertSame($reference, $part['variant_ref']);
      $this->assertSame($version, $part['variant_version']);
      $this->assertSame($practice['body'], $part['body']);
    }

    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->getSession()->getPage()->pressButton('Duplicate step');
    $this->getSession()->getPage()->pressButton('Save draft');
    $rows = $this->latest($lesson)->get('field_actools_lesson_steps')->getValue();
    $this->assertCount(2, $rows);
    $this->assertNotSame($rows[0]['step_id'], $rows[1]['step_id']);
    $parts = $this->parts($this->latest($lesson));
    $this->assertCount(4, array_unique(array_column(array_merge(...$parts), 'id')));
    foreach (array_merge(...$parts) as $part) {
      $this->assertSame($reference, $part['variant_ref']);
      $this->assertSame($version, $part['variant_version']);
    }

    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->field($practice['id'], 'variant_ref')->setValue(' ');
    $this->field($practice['id'], 'variant_version')->setValue('');
    $this->getSession()->getPage()->pressButton('Save draft');
    $parts = $this->parts($this->latest($lesson));
    $this->assertArrayNotHasKey('variant_ref', $parts[0][0]);
    $this->assertArrayNotHasKey('variant_version', $parts[0][0]);
    $this->assertSame($reference, $parts[0][1]['variant_ref'], 'Clearing one question does not change its independent copy.');
    $this->assertSame($reference, $parts[1][0]['variant_ref']);
    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->assertSame('', $this->field($practice['id'], 'variant_ref')->getValue());
    $this->assertSame('', $this->field($practice['id'], 'variant_version')->getValue());
  }

  public function testBlankOptionalControlsPreserveExistingReviewAndVersion(): void {
    $equation = $this->equation('F = S l');
    $practice = $this->practice();
    $lesson = $this->lesson([[$equation, $practice]], TRUE, 7);
    $before = $lesson->get('field_actools_lesson_steps')->getValue()[0];
    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->field($equation['id'], 'equation_number')->setValue('  ');
    $this->field($practice['id'], 'variant_ref')->setValue('');
    $this->field($practice['id'], 'variant_version')->setValue(' ');
    $this->getSession()->getPage()->pressButton('Save draft');
    $this->assertSession()->statusCodeEquals(200);
    $after = $this->latest($lesson)->get('field_actools_lesson_steps')->getValue()[0];
    $this->assertSame(7, (int) $after['content_version']);
    $this->assertTrue((bool) $after['reviewed']);
    $this->assertSame(json_decode($before['components'], TRUE, 32, JSON_THROW_ON_ERROR), json_decode($after['components'], TRUE, 32, JSON_THROW_ON_ERROR));
    $parts = $this->parts($this->latest($lesson))[0];
    $this->assertArrayNotHasKey('equation_number', $parts[0]);
    $this->assertArrayNotHasKey('variant_ref', $parts[1]);
    $this->assertArrayNotHasKey('variant_version', $parts[1]);
    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->assertSame('', $this->field($equation['id'], 'equation_number')->getValue());
    $this->assertTrue($this->getSession()->getPage()->find('css', '.actools-author input[name$="[reviewed]"]')->isChecked());
  }

  public function testEquationLabelsSurviveReorderingAndRenderLiterallyInEveryView(): void {
    $first = $this->equation('F = S l');
    $second = $this->equation('F = 2 S l');
    $unlabelled = $this->equation('S = F / l');
    $lesson = $this->lesson([[$first, $second], [$unlabelled]]);
    $this->chapter($lesson);
    $labels = [$first['id'] => '(4.1)', $second['id'] => '<b>(4.2)</b>'];
    $this->drupalGet($lesson->toUrl('edit-form'));
    foreach ($labels as $id => $label) {
      $this->field($id, 'equation_number')->setValue($label);
    }
    // The non-JavaScript move submits unsaved labels through the real widget.
    $this->component($second['id'])->pressButton('Move component up');
    $this->getSession()->getPage()->pressButton('Save draft');
    $saved = $this->latest($lesson);
    $parts = $this->parts($saved);
    $this->assertSame([$second['id'], $first['id']], array_column($parts[0], 'id'));
    foreach ($parts[0] as $part) {
      $this->assertSame($labels[$part['id']], $part['equation_number']);
    }
    $this->assertArrayNotHasKey('equation_number', $parts[1][0]);
    $step_ids = array_column($saved->get('field_actools_lesson_steps')->getValue(), 'step_id');
    $this->drupalGet($lesson->toUrl('edit-form'));
    foreach ($labels as $id => $label) {
      $this->assertSame($label, $this->field($id, 'equation_number')->getValue());
    }
    foreach ($this->getSession()->getPage()->findAll('css', '.actools-author input[name$="[reviewed]"]') as $checkbox) {
      $checkbox->check();
    }
    $this->getSession()->getPage()->pressButton('Publish reviewed lesson');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertTrue($this->latest($lesson)->isPublished());
    $this->drupalLogout();

    $reader = '/node/' . $lesson->id() . '/study';
    foreach ([[$reader, []], [$reader, ['query' => ['view' => 'all']]], ['/node/' . $lesson->id() . '/study/print', []]] as [$url, $options]) {
      $this->drupalGet($url, $options);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->elementsCount('css', '.actools-equation__number', 2);
      foreach ($labels as $id => $label) {
        $this->assertSession()->elementTextEquals('css', '#component-' . $id . ' .actools-equation__number', $label);
        $this->assertSession()->elementNotExists('css', '#component-' . $id . ' .actools-equation__number b');
        $this->assertSession()->elementAttributeContains('css', '#component-' . $id . ' .actools-equation', 'aria-describedby', 'equation-number-' . $id);
      }
      $numbers = $this->getSession()->getPage()->findAll('css', '.actools-equation__number');
      $this->assertSame($labels[$second['id']], $numbers[0]->getText(), 'Labels travel with their equations rather than their positions.');
    }
    $this->drupalGet($reader, ['query' => ['step' => $step_ids[1]]]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementsCount('css', '.actools-equation', 1);
    $this->assertSession()->elementNotExists('css', '.actools-equation__number');
  }

  public function testNumericLookingTexEditRequiresReviewAndRevisitingPublishedContent(): void {
    $equation = $this->equation('10');
    $lesson = $this->lesson([[$equation]], TRUE, 3);
    $this->chapter($lesson);
    $lesson->setPublished()->save();
    $step_id = $lesson->get('field_actools_lesson_steps')->get(0)->step_id;
    $reader = '/node/' . $lesson->id() . '/study';
    $this->drupalGet($reader);
    $this->getSession()->getPage()->pressButton('Mark this step as read');
    $this->assertSession()->pageTextContains('1 of 1 required steps read');

    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->field($equation['id'], 'tex')->setValue('1e1');
    $this->getSession()->getPage()->pressButton('Save draft');
    $this->assertSession()->statusCodeEquals(200);
    $latest = $this->latest($lesson);
    $step = $latest->get('field_actools_lesson_steps')->getValue()[0];
    $this->assertSame('1e1', $this->parts($latest)[0][0]['tex']);
    $this->assertSame(4, (int) $step['content_version'], 'Numeric-looking TeX strings are distinct teaching content.');
    $this->assertFalse((bool) $step['reviewed']);
    $this->assertFalse($latest->isDefaultRevision());
    $this->drupalGet($reader);
    $this->assertSession()->pageTextContains('1 of 1 required steps read');

    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->getSession()->getPage()->pressButton('Publish reviewed lesson');
    $this->assertSession()->pageTextContains('Review every learning step before publishing this lesson.');
    $this->getSession()->getPage()->find('css', '.actools-author input[name$="[reviewed]"]')->check();
    $this->getSession()->getPage()->pressButton('Publish reviewed lesson');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertTrue($this->latest($lesson)->isPublished());
    $this->drupalGet($reader);
    $this->assertSession()->pageTextContains('0 of 1 required steps read');
    $this->assertSession()->pageTextContains('Teaching reference step 1 — revisit');
    $record = $this->container->get('user.data')->get('actools_lesson', $this->loggedInUser->id(), 'lesson:' . $lesson->id());
    $this->assertSame(3, $record['completed'][$step_id]['content_version'], 'Rendering invalidates completion by version without silently rewriting reading records.');
  }

  private function uuid(): string {
    return $this->container->get('uuid')->generate();
  }

  private function practice(): array {
    $part = ['id' => $this->uuid(), 'type' => 'practice', 'body' => ['value' => 'Find the force on the liquid boundary.', 'format' => 'actools_lesson_text']];
    foreach (['hint', 'answer', 'solution'] as $key) {
      $part[$key] = ['value' => '', 'format' => 'actools_lesson_text'];
    }
    return $part;
  }

  private function equation(string $tex): array {
    return ['id' => $this->uuid(), 'type' => 'equation', 'tex' => $tex];
  }

  private function lesson(array $steps, bool $reviewed = FALSE, int $version = 1): NodeInterface {
    $rows = [];
    foreach ($steps as $i => $parts) {
      $rows[] = ['step_id' => $this->uuid(), 'title' => 'Teaching reference step ' . ($i + 1), 'optional' => 0, 'reviewed' => (int) $reviewed,
        'content_version' => $version, 'components' => json_encode($parts, JSON_THROW_ON_ERROR)];
    }
    $lesson = Node::create(['type' => 'actools_resource', 'title' => 'Stable teaching references', 'uid' => $this->loggedInUser->id(), 'status' => 0,
      'field_actools_kind' => 'lesson', 'field_actools_lesson_managed' => 1, 'field_actools_path' => 'core', 'field_actools_lesson_steps' => $rows]);
    $lesson->save();
    return $lesson;
  }

  private function chapter(NodeInterface $lesson): void {
    Node::create(['type' => 'actools_topic', 'title' => 'Teaching reference chapter', 'status' => 1,
      'field_actools_lesson_chapter' => 1, 'field_actools_lessons' => [['target_id' => $lesson->id()]]])->save();
  }

  private function latest(NodeInterface $lesson): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$lesson->id()]);
    return $storage->loadRevisionUnchanged($storage->getLatestRevisionId($lesson->id()));
  }

  private function parts(NodeInterface $lesson): array {
    return array_map(static fn ($row) => json_decode($row['components'], TRUE, 32, JSON_THROW_ON_ERROR), $lesson->get('field_actools_lesson_steps')->getValue());
  }

  private function component(string $id) {
    return $this->assertSession()->elementExists('css', '[data-actools-component="' . $id . '"]');
  }

  private function field(string $id, string $key) {
    $field = $this->component($id)->find('css', '[name$="[' . $key . ']"]');
    $this->assertNotNull($field, 'The real native editor exposes ' . $key . '.');
    return $field;
  }

}
