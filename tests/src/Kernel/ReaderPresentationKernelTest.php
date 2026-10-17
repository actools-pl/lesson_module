<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Exercises generated URLs and native accessible figure-link rendering. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class ReaderPresentationKernelTest extends LessonKernelTestBase {

  public function testGeneratedReaderUrlsMayBeRelativeOrAbsolute(): void {
    $account = $this->reader();
    $this->container->get('current_user')->setAccount($account);
    $lesson = $this->lesson('Generated URL lesson');
    $this->chapter('Generated URL chapter', [$lesson]);

    foreach (['', 'https://lessons.example.test'] as $origin) {
      // Domain/language outbound processing can legitimately produce absolute
      // links. Supply that generator boundary without implementing such a site.
      $generator = $this->createMock(UrlGeneratorInterface::class);
      $generator->method('generateFromRoute')->willReturnCallback(static function ($name, array $parameters = [], array $options = [], $collect_bubbleable_metadata = FALSE) use ($origin) {
        $url = $origin . '/generated/' . str_replace('.', '/', $name);
        if ($options['query'] ?? []) {
          $url .= '?' . http_build_query($options['query']);
        }
        if (!empty($options['fragment'])) {
          $url .= '#' . $options['fragment'];
        }
        return $collect_bubbleable_metadata
          ? (new GeneratedUrl())->setGeneratedUrl($url)->addCacheTags(['reader_test:url_generation'])->addCacheContexts(['url.site'])
          : $url;
      });
      $this->container->set('url_generator', $generator);

      foreach (['guided', 'all', 'print'] as $mode) {
        $build = $this->container->get('actools_lesson.document_builder')->build($lesson, $mode);
        $this->assertStringStartsWith($origin . '/generated/actools_lesson/reader', $build['#guided_url']);
        $this->assertStringStartsWith($origin . '/generated/actools_lesson/reader', $build['#all_url']);
        $this->assertSame($origin . '/generated/actools_lesson/print', $build['#print_url']);
        $this->assertStringStartsWith($origin . '/generated/actools_lesson/reader', $build['#steps'][0]['url']);
        $cache = CacheableMetadata::createFromRenderArray($build);
        $this->assertContains('reader_test:url_generation', $cache->getCacheTags());
        $this->assertContains('url.site', $cache->getCacheContexts());
      }
    }
  }

  public function testFullResolutionLinksRetainDistinctImageAlternativeText(): void {
    $this->container->get('current_user')->setAccount($this->reader());
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('system.image')->set('toolkit', 'gd')->save();
    $lesson = $this->lesson('Scientific figure links');
    $this->chapter('Scientific figures', [$lesson]);
    $descriptions = ['Forces on the two faces of a soap film', 'Pressure difference across a curved liquid surface'];
    $components = [];
    foreach ($descriptions as $index => $description) {
      $uri = $this->container->get('file_system')->saveData(
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII='),
        'public://reader-figure-' . $index . '.png',
      );
      $file = File::create(['uri' => $uri, 'filename' => 'reader-figure-' . $index . '.png', 'filemime' => 'image/png', 'status' => 1, 'uid' => 1]);
      $file->save();
      $components[] = ['id' => $this->container->get('uuid')->generate(), 'type' => 'figure', 'fid' => (int) $file->id(), 'alt' => $description];
    }

    foreach ([FALSE, TRUE] as $styled) {
      if ($styled) {
        $style = ImageStyle::create(['name' => 'actools_lesson_diagram', 'label' => 'Lesson diagram']);
        $style->addImageEffect(['id' => 'image_scale', 'data' => ['width' => 200, 'height' => 200, 'upscale' => FALSE]]);
        $style->save();
      }
      $build = [];
      foreach ($components as $component) {
        $build[] = $this->container->get('actools_lesson.component_renderer')->render($component, $lesson);
      }
      $markup = (string) $this->container->get('renderer')->renderRoot($build);
      $document = new \DOMDocument();
      @$document->loadHTML($markup);
      $xpath = new \DOMXPath($document);
      $links = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " actools-lesson-original ")]');
      $this->assertCount(2, $links);
      foreach ($links as $index => $link) {
        $this->assertFalse($link->hasAttribute('aria-label'), 'A generic action label must not override the individual linked image alt.');
        $images = $xpath->query('.//img', $link);
        $this->assertCount(1, $images);
        $this->assertSame($descriptions[$index], $images->item(0)->getAttribute('alt'));
        $this->assertStringEndsWith('/reader-figure-' . $index . '.png', $link->getAttribute('href'));
        $this->assertStringNotContainsString('/styles/', $link->getAttribute('href'), 'The link opens the original, even when its child image uses a derivative.');
        $this->assertSame($styled, str_contains($images->item(0)->getAttribute('src'), '/styles/actools_lesson_diagram/'));
      }
    }
  }

}
