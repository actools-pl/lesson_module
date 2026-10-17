<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\actools_lesson\Service\TeachingContentCompleteness;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Image\ImageFactory;
use Drupal\file\Entity\File;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Protects incomplete drafts and shared reviewed-publication readiness. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class TeachingContentCompletenessTest extends LessonKernelTestBase {

  protected function setUp(): void {
    parent::setUp();
    $this->reader();
    $this->container->get('current_user')->setAccount(User::load(1));
  }

  public function testEmptyProseAndQuestionsRemainDraftsWithoutClaimingReadiness(): void {
    $lesson = $this->lesson('An editable incomplete draft', 'core', FALSE);
    $this->chapter('The owner', [$lesson]);
    foreach (['text', 'result', 'error', 'table', 'example', 'practice'] as $type) {
      foreach (['', " \t\n", '<p><br></p>', '<p>&nbsp;&#160;&#x200B;</p>'] as $empty) {
        $component = $this->component($type, [
          'heading' => 'A heading is not an explanation or question',
          'body' => ['value' => $empty, 'format' => 'plain_text'],
          'hint' => ['value' => 'An optional hint.', 'format' => 'plain_text'],
          'answer' => ['value' => 'An optional answer.', 'format' => 'plain_text'],
        ]);
        $lesson->setUnpublished();
        $lesson->setNewRevision(TRUE);
        $lesson->set('field_actools_lesson_steps', [$this->step(['components' => json_encode([$component], JSON_THROW_ON_ERROR)])]);
        $this->assertCount(0, $lesson->validate(), $type . ' may be saved as unfinished draft work.');
        $lesson->save();
        $readiness = $this->container->get('actools_lesson.editorial')->readiness($lesson, $this->container->get('current_user'));
        $this->assertSame(0, $readiness['ready']);
        $this->assertSame(1, $readiness['total']);
        $lesson->setPublished();
        $this->assertStringContainsString('Complete every teaching component', (string) $lesson->validate());
      }
    }
    // Programmatic publication has the same guard even without entity validate().
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Complete every teaching component');
    $lesson->save();
  }

  public function testEverySupportedComponentCanPublishWithoutOptionalHintsOrAnswers(): void {
    $figure = $this->figure();
    $components = [];
    foreach (['text', 'result', 'error', 'table', 'example', 'practice'] as $type) {
      $body = $type === 'table' ? '<table><tr><td>Water</td><td>0.072</td></tr></table>' : '<p>Explain or calculate the force.</p>';
      $components[] = $this->component($type, ['body' => ['value' => $body, 'format' => 'actools_lesson_text']]);
    }
    $components[] = $this->component('equation', ['tex' => 'F = 2 S l']);
    $components[] = $this->component('figure', ['fid' => (int) $figure->id(), 'alt' => 'A concave water meniscus.']);
    $lesson = $this->lesson('Complete supported content', 'core', FALSE);
    $lesson->set('field_actools_lesson_steps', [$this->step(['components' => json_encode($components, JSON_THROW_ON_ERROR)])]);
    $lesson->save();
    $this->chapter('The owner', [$lesson]);
    $lesson->setPublished();
    $this->assertCount(0, $lesson->validate());
    $lesson->save();
    $readiness = $this->container->get('actools_lesson.editorial')->readiness($lesson, $this->container->get('current_user'));
    $this->assertSame(1, $readiness['ready']);
    $this->assertContains('file:' . $figure->id(), $readiness['cacheability']->getCacheTags());
    $this->assertContains('config:filter.format.actools_lesson_text', $readiness['cacheability']->getCacheTags());
    // Figure-only and equation-only learning steps are also legitimate.
    foreach (array_slice($components, -2) as $component) {
      $lesson->set('field_actools_lesson_steps', [$this->step(['components' => json_encode([$component], JSON_THROW_ON_ERROR)])]);
      $this->assertCount(0, $lesson->validate());
    }
  }

  public function testOneFilledComponentDoesNotHideAnEmptyQuestion(): void {
    $lesson = $this->lesson('Partly complete', 'core', FALSE);
    $this->chapter('The owner', [$lesson]);
    $components = [
      $this->component('text', ['body' => ['value' => 'A meaningful explanation.', 'format' => 'plain_text']]),
      $this->component('practice', ['body' => ['value' => '<p><br></p>', 'format' => 'plain_text']]),
    ];
    $lesson->set('field_actools_lesson_steps', [$this->step(['components' => json_encode($components, JSON_THROW_ON_ERROR)])]);
    $this->assertSame(0, $this->container->get('actools_lesson.editorial')->readiness($lesson, $this->container->get('current_user'))['ready']);
    $lesson->setPublished();
    $this->assertStringContainsString('Complete every teaching component', (string) $lesson->validate());
  }

  public function testIncompleteEquationsAndFiguresRetainExistingFieldRequirements(): void {
    $lesson = $this->lesson('Unfinished equation and figure', 'core', FALSE);
    $this->chapter('The owner', [$lesson]);
    $figure = $this->figure();
    foreach ([
      $this->component('equation', ['tex' => " \n\t"]),
      $this->component('figure', ['fid' => 0, 'alt' => '']),
      $this->component('figure', ['fid' => (int) $figure->id(), 'alt' => '']),
    ] as $component) {
      $lesson->setUnpublished();
      $lesson->set('field_actools_lesson_steps', [$this->step(['components' => json_encode([$component], JSON_THROW_ON_ERROR)])]);
      $this->assertGreaterThan(0, $lesson->validate()->count(), 'Equation source and figure upload/alt retain their existing field requirements.');
      $this->assertSame(0, $this->container->get('actools_lesson.editorial')->readiness($lesson, $this->container->get('current_user'))['ready']);
      $lesson->setPublished();
      $this->assertStringContainsString('Complete every teaching component', (string) $lesson->validate());
    }
    $lesson->setUnpublished();
    $lesson->set('field_actools_lesson_steps', [$this->step(['components' => json_encode([$this->component('figure', ['fid' => 999999, 'alt' => 'A claimed figure.'])], JSON_THROW_ON_ERROR)])]);
    $this->assertStringContainsString('valid uploaded', (string) $lesson->validate(), 'A supplied invalid file ID remains invalid even in a draft.');
  }

  public function testUnavailableToolkitMarksFigureIncompleteWithoutBreakingReadiness(): void {
    $file = $this->figure();
    $factory = $this->createMock(ImageFactory::class);
    $factory->method('get')->willThrowException(new PluginNotFoundException('missing_toolkit'));
    $completeness = new TeachingContentCompleteness($this->container->get('entity_type.manager'), $factory);
    $this->assertFalse($completeness->isMeaningfulComponent($this->component('figure', ['fid' => (int) $file->id(), 'alt' => 'A valid figure description.'])));
  }

  private function component(string $type, array $properties): array {
    return ['id' => $this->container->get('uuid')->generate(), 'type' => $type] + $properties;
  }

  private function figure(): File {
    $uri = $this->container->get('file_system')->saveData(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII='), 'public://completeness-' . $this->randomMachineName() . '.png');
    $file = File::create(['uri' => $uri, 'filename' => basename($uri), 'filemime' => 'image/png', 'status' => 1, 'uid' => 1]);
    $file->save();
    return $file;
  }

}
