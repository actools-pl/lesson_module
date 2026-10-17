<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Unit;

use Drupal\actools_lesson\Service\AuthoringInputGuard;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Request-body rejection must run even when PHP discarded the entire POST. */
#[Group('actools_lesson')]
final class AuthoringInputGuardTest extends UnitTestCase {

  public function testDiscardedOversizedBodyFailsBeforeReconstructingEmptyValues(): void {
    if ((float) ini_get('post_max_size') <= 0) {
      $this->markTestSkipped('An effective positive post_max_size is needed to exercise its rejection path.');
    }
    $request = Request::create('/node/1/edit', 'POST', [], [], [], ['CONTENT_LENGTH' => (string) PHP_INT_MAX]);
    $stack = new RequestStack();
    $stack->push($request);
    $container = new ContainerBuilder();
    $container->set('request_stack', $stack);
    \Drupal::setContainer($container);
    $state = new FormState();
    $state->setUserInput([]);
    try {
      AuthoringInputGuard::assertComplete($state);
      $this->fail('An oversized, discarded POST must never be accepted as empty content.');
    }
    catch (HttpException $exception) {
      $this->assertSame(413, $exception->getStatusCode());
      $this->assertStringContainsString('post_max_size', $exception->getMessage());
      $this->assertStringContainsString('Nothing was saved', $exception->getMessage());
    }
  }

}
