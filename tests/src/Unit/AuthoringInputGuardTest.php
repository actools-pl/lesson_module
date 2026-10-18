<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Unit;

use Drupal\actools_lesson\Service\AuthoringInputGuard;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Distinguish native form submissions, optional controls and real truncation. */
#[Group('actools_lesson')]
final class AuthoringInputGuardTest extends UnitTestCase {

  private function requestState(bool $processing = TRUE): FormState {
    $stack = new RequestStack();
    $stack->push(Request::create('/node/1/edit', 'POST'));
    $container = new ContainerBuilder();
    $container->set('request_stack', $stack);
    $container->set('private_key', new class() {
      public function get(): string {
        return 'input-guard-unit-private-key';
      }
    });
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);
    $container->set('current_user', $account);
    \Drupal::setContainer($container);
    new Settings(['hash_salt' => 'input-guard-unit-hash-salt']);
    $object = $this->createMock(FormInterface::class);
    $object->method('getFormId')->willReturn('input_guard_unit_form');
    $state = new FormState();
    $state->setFormObject($object)->setProcessInput($processing);
    return $state;
  }

  private function input(string $name, array $extra = []): array {
    return $extra + [
      '#type' => 'textfield', '#theme' => 'input__textfield',
      '#name' => $name, '#parents' => [$name], '#array_parents' => [$name],
      '#value' => $name . '-value',
    ];
  }

  public function testConditionalExternalControlsRemainBudgetedWhileLessonInputsStayRequired(): void {
    $state = $this->requestState();
    $states = [':input[name="automatic"]' => ['checked' => TRUE]];
    $form = [
      'plain' => $this->input('plain'),
      'visible' => $this->input('visible', ['#states' => ['visible' => $states]]),
      'conditional_parent' => [
        '#type' => 'container', '#states' => ['disabled' => $states],
        'nested_alias' => $this->input('nested_alias'),
      ],
      'owned' => [
        '#actools_editor' => TRUE, '#actools_path' => ['owned'],
        // External alterations must not make owned text or identities optional.
        '#states' => ['enabled' => $states],
        'body' => $this->input('owned_body'),
        'inventory' => $this->input('owned_inventory', [
          '#attributes' => ['data-actools-identity-inventory' => ''],
          '#array_parents' => ['owned', 'inventory'],
        ]),
      ],
    ];
    foreach (['disabled', 'enabled', '!enabled', '!disabled'] as $index => $condition) {
      $form['alias_' . $index] = $this->input('alias_' . $index, ['#states' => [$condition => $states]]);
    }
    AuthoringInputGuard::attach($form);
    $form = AuthoringInputGuard::afterBuild($form, $state);
    $token = $form['actools_lesson_submission_complete']['#value'];
    $schema = json_decode(base64_decode(explode('.', $token)[0]), TRUE, 32, JSON_THROW_ON_ERROR);
    $this->assertSame(138, $schema['budget'], 'Every potential scalar is counted even when states can omit it.');
    $this->assertCount(5, $schema['required']);
    $this->assertArrayHasKey('plain', $schema['required']);
    $this->assertArrayHasKey('visible', $schema['required'], 'Visibility alone does not disable a browser input.');
    $this->assertArrayHasKey('owned_body', $schema['required']);
    $this->assertArrayHasKey('owned_inventory', $schema['required']);
    $this->assertCount(1, $schema['bindings']);
    foreach (['nested_alias', 'alias_0', 'alias_1', 'alias_2', 'alias_3'] as $name) {
      $this->assertArrayNotHasKey($name, $schema['required']);
    }
    $values = [
      'plain' => 'plain-value', 'visible' => 'visible-value',
      'owned_body' => 'owned_body-value', 'owned_inventory' => 'owned_inventory-value',
      'actools_lesson_submission_complete' => $token,
    ];
    $state->setUserInput($values);
    AuthoringInputGuard::assertComplete($state);
    $this->assertSame($schema, $state->getTemporaryValue('actools_lesson_submission_checked'));
    $state = $this->requestState();
    unset($values['owned_body']);
    $state->setUserInput($values);
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Nothing was saved');
    AuthoringInputGuard::assertComplete($state);
  }

  public function testUnrelatedPostDoesNotRequireTheLessonSubmissionTail(): void {
    $state = $this->requestState(FALSE);
    $state->setUserInput(['form_id' => 'another_form', 'other_value' => 'unrelated']);
    AuthoringInputGuard::assertComplete($state);
    $this->assertNull($state->getTemporaryValue('actools_lesson_submission_checked'));
    $state->setUserInput([]);
    AuthoringInputGuard::assertComplete($state);
    $this->assertFalse($state->isProcessingInput());
  }

  public function testProcessingSubmissionRequiresItsSignedTail(): void {
    $state = $this->requestState();
    $state->setUserInput(['form_id' => 'input_guard_unit_form']);
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Nothing was saved');
    AuthoringInputGuard::assertComplete($state);
  }

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
