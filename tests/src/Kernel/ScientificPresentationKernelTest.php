<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Tests stable editorial equation references and bounded native derivatives. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class ScientificPresentationKernelTest extends LessonKernelTestBase {

  protected function setUp(): void {
    parent::setUp();
    $this->container->get('current_user')->setAccount($this->reader());
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('system.image')->set('toolkit', 'gd')->save();
    $this->container->get('module_handler')->loadInclude('actools_lesson', 'install');
    $request = Request::create('/scientific-presentation-test');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

  public function testInstallationCreatesOnlyTheMissingBoundedDiagramStyle(): void {
    $this->assertNull(ImageStyle::load('actools_lesson_diagram'));
    actools_lesson_install();
    $style = ImageStyle::load('actools_lesson_diagram');
    $this->assertNotNull($style);
    $effects = $style->getEffects()->getConfiguration();
    $this->assertCount(1, $effects);
    $effect = reset($effects);
    $this->assertSame('image_scale', $effect['id']);
    $this->assertSame(['width' => 1600, 'height' => 1600, 'upscale' => FALSE], $effect['data']);
    $this->assertContains('actools_lesson', $style->getDependencies()['module']);
    $saved = $style->toArray();
    actools_lesson_install();
    $this->assertSame($saved, ImageStyle::load('actools_lesson_diagram')->toArray());
  }

  public function testUpdateCreatesTheMissingStyleAndPreservesOperatorConfiguration(): void {
    $this->assertNull(ImageStyle::load('actools_lesson_diagram'));
    actools_lesson_update_11001();
    $style = ImageStyle::load('actools_lesson_diagram');
    $this->assertNotNull($style);
    $effects = $style->getEffects()->getConfiguration();
    $this->assertSame('image_scale', reset($effects)['id']);
    $style->delete();
    // A pre-existing operator style has no lesson-module ownership dependency.
    $style = ImageStyle::create(['name' => 'actools_lesson_diagram', 'label' => 'Our existing diagram style']);
    $style->addImageEffect(['id' => 'image_scale', 'data' => ['width' => 777, 'height' => 555, 'upscale' => FALSE]]);
    $style->save();
    $saved = $style->toArray();
    actools_lesson_update_11001();
    $this->assertSame($saved, ImageStyle::load('actools_lesson_diagram')->toArray());
    actools_lesson_install();
    $this->assertSame($saved, ImageStyle::load('actools_lesson_diagram')->toArray());
  }

  public function testNativeDerivativesKeepTheWholeDiagramWithoutUpscalingOrChangingOriginals(): void {
    actools_lesson_update_11001();
    $style = ImageStyle::load('actools_lesson_diagram');
    $lesson = $this->lesson('Diagram bounds');
    foreach ([[320, 160, 320, 160], [2000, 1000, 1600, 800]] as [$width, $height, $expected_width, $expected_height]) {
      $file = $this->image($width, $height);
      $original_hash = hash_file('sha256', $file->getFileUri());
      $derivative_uri = $style->buildUri($file->getFileUri());
      $this->assertTrue($style->createDerivative($file->getFileUri(), $derivative_uri));
      $derivative = $this->container->get('image.factory')->get($derivative_uri);
      $this->assertSame($expected_width, $derivative->getWidth());
      $this->assertSame($expected_height, $derivative->getHeight());
      $this->assertSame($width / $height, $derivative->getWidth() / $derivative->getHeight(), 'The full diagram retains its aspect ratio.');
      $this->assertSame($original_hash, hash_file('sha256', $file->getFileUri()), 'A derivative never overwrites the scientific original.');
      $component = ['id' => $this->container->get('uuid')->generate(), 'type' => 'figure', 'fid' => (int) $file->id(), 'alt' => 'Whole scientific diagram'];
      $build = $this->container->get('actools_lesson.component_renderer')->render($component, $lesson);
      $this->assertSame('image_style', $build['#figure']['#title']['#theme']);
      $this->assertSame($file->getFileUri(), $build['#figure']['#title']['#uri']);
      $this->assertContains('file:' . $file->id(), CacheableMetadata::createFromRenderArray($build)->getCacheTags());
      $this->assertContains('config:image.style.actools_lesson_diagram', CacheableMetadata::createFromRenderArray($build)->getCacheTags());
      $xpath = $this->documentXpath((string) $this->container->get('renderer')->renderRoot($build));
      $image = $xpath->query('//figure//img')->item(0);
      $link = $xpath->query('//figure//a')->item(0);
      $this->assertSame((string) $expected_width, $image->getAttribute('width'));
      $this->assertSame((string) $expected_height, $image->getAttribute('height'));
      $this->assertStringContainsString('/styles/actools_lesson_diagram/', $image->getAttribute('src'));
      $this->assertStringNotContainsString('/styles/', $link->getAttribute('href'));
      $this->assertStringEndsWith('/' . $file->getFilename(), $link->getAttribute('href'));
    }
  }

  public function testMissingStyleFallsBackToTheOriginalWithCreationCacheMetadata(): void {
    $file = $this->image(320, 160);
    $lesson = $this->lesson('A diagram without optional style');
    $component = ['id' => $this->container->get('uuid')->generate(), 'type' => 'figure', 'fid' => (int) $file->id(), 'alt' => 'Diagram fallback'];
    $renderer = $this->container->get('actools_lesson.component_renderer');
    $build = $renderer->render($component, $lesson);
    $this->assertSame('image', $build['#figure']['#title']['#theme']);
    $this->assertSame($file->getFileUri(), $build['#figure']['#title']['#uri']);
    $list_tags = $this->container->get('entity_type.manager')->getDefinition('image_style')->getListCacheTags();
    foreach ($list_tags as $tag) {
      $this->assertContains($tag, CacheableMetadata::createFromRenderArray($build)->getCacheTags());
    }
    actools_lesson_update_11001();
    $styled = $renderer->render($component, $lesson);
    $this->assertSame('image_style', $styled['#figure']['#title']['#theme']);
  }

  public function testEditorialEquationLabelsRemainEscapedAndStableAcrossModesAndReordering(): void {
    $uuid = $this->container->get('uuid');
    $components = [
      ['id' => $uuid->generate(), 'type' => 'equation', 'tex' => 'F = 2 S l', 'equation_number' => '(4.1)'],
      ['id' => $uuid->generate(), 'type' => 'equation', 'tex' => 'p = p_0 + h \\rho g'],
      ['id' => $uuid->generate(), 'type' => 'equation', 'tex' => '\\Delta p = 2 S / r', 'equation_number' => '<b>4.3 & check</b>'],
    ];
    $lesson = $this->lesson('Explicit equation references', 'core', FALSE);
    $lesson->set('field_actools_lesson_steps', [$this->step(['components' => json_encode($components, JSON_THROW_ON_ERROR)])]);
    $lesson->save();
    $lesson->setPublished();
    $this->chapter('Equations in a chapter', [$lesson]);
    foreach ([FALSE, TRUE] as $reordered) {
      if ($reordered) {
        $step = $lesson->get('field_actools_lesson_steps')->first()->getValue();
        $step['components'] = json_encode(array_reverse($components), JSON_THROW_ON_ERROR);
        $step['content_version'] = (int) $step['content_version'] + 1;
        $lesson->set('field_actools_lesson_steps', [$step]);
        $lesson->save();
      }
      foreach (['guided', 'all', 'print'] as $mode) {
        $build = $this->container->get('actools_lesson.document_builder')->build($lesson, $mode);
        $xpath = $this->documentXpath((string) $this->container->get('renderer')->renderRoot($build));
        $this->assertCount(3, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," actools-equation ")]'));
        foreach ($components as $component) {
          $section = '//*[@id="component-' . $component['id'] . '"]';
          $this->assertCount(1, $xpath->query($section));
          $labels = $xpath->query($section . '//*[contains(concat(" ",normalize-space(@class)," ")," actools-equation__number ")]');
          if (isset($component['equation_number'])) {
            $this->assertCount(1, $labels);
            $this->assertSame($component['equation_number'], $labels->item(0)->textContent);
            $this->assertSame('equation-number-' . $component['id'], $labels->item(0)->getAttribute('id'));
            $this->assertCount(0, $xpath->query($section . '//b'));
            $this->assertCount(1, $xpath->query($section . '//*[@aria-describedby="equation-number-' . $component['id'] . '"]'));
          }
          else {
            $this->assertCount(0, $labels);
            $this->assertCount(0, $xpath->query($section . '//*[@aria-describedby]'));
          }
        }
        $first_id = $reordered ? $components[2]['id'] : $components[0]['id'];
        $this->assertSame('component-' . $first_id, $xpath->query('//section[contains(concat(" ",normalize-space(@class)," ")," actools-component ")]')->item(0)->getAttribute('id'));
      }
    }
  }

  private function image(int $width, int $height): File {
    $pixels = imagecreatetruecolor($width, $height);
    imagefilledrectangle($pixels, 0, 0, $width - 1, $height - 1, imagecolorallocate($pixels, 235, 245, 255));
    ob_start();
    imagepng($pixels);
    $bytes = ob_get_clean();
    imagedestroy($pixels);
    $filename = 'scientific-presentation-' . $width . '-' . $height . '.png';
    $uri = $this->container->get('file_system')->saveData($bytes, 'public://' . $filename);
    $file = File::create(['uri' => $uri, 'filename' => $filename, 'filemime' => 'image/png', 'status' => 1, 'uid' => 1]);
    $file->save();
    return $file;
  }

  private function documentXpath(string $markup): \DOMXPath {
    $document = new \DOMDocument();
    @$document->loadHTML($markup);
    return new \DOMXPath($document);
  }

}
