<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Kernel;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Verifies signed editor identity and in-place concurrent change detection. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class EditorialSnapshotTest extends LessonKernelTestBase {

  public function testSnapshotIsBoundToAccountAndLesson(): void {
    $first = $this->reader();
    $second = $this->reader();
    $this->container->get('current_user')->setAccount($first);
    $lesson = $this->lesson('First draft', 'core', FALSE);
    $other = $this->lesson('Another draft', 'core', FALSE);
    $editorial = $this->container->get('actools_lesson.editorial');
    $token = $editorial->token($lesson);
    $snapshot = $editorial->decodeToken($token, $lesson);
    $this->assertNotNull($snapshot);
    $this->assertTrue($editorial->isFresh($lesson, $snapshot));
    $this->assertNull($editorial->decodeToken($token, $other));
    $this->assertNull($editorial->decodeToken($token . 'modified', $lesson));
    $this->container->get('current_user')->setAccount($second);
    $this->assertNull($editorial->decodeToken($token, $lesson));
  }

  public function testSameRevisionInPlaceChangeInvalidatesTheSnapshot(): void {
    $this->reader();
    $lesson = $this->lesson('The first saved draft', 'core', FALSE);
    $editorial = $this->container->get('actools_lesson.editorial');
    $snapshot = $editorial->decodeToken($editorial->token($lesson), $lesson);
    $revision = (int) $lesson->getRevisionId();
    $lesson->setNewRevision(FALSE);
    $lesson->setTitle('An API write without a new revision');
    $lesson->save();
    $this->assertSame($revision, (int) $lesson->getRevisionId());
    $this->assertFalse($editorial->isFresh($lesson, $snapshot), 'The digest detects writes even if revision ID and second-resolution timestamps do not change.');
  }

  public function testSnapshotRequiresThePrivateKeyAndItsEditorialContext(): void {
    $account = $this->reader();
    $this->container->get('current_user')->setAccount($account);
    $lesson = $this->lesson('Private key snapshot', 'core', FALSE);
    $editorial = $this->container->get('actools_lesson.editorial');
    $token = $editorial->token($lesson);
    [$payload, $signature] = explode('.', $token, 2);
    $key = $this->container->get('private_key')->get() . Settings::getHashSalt()
      . ':actools_lesson:editorial_snapshot:v2:' . $account->id()
      . ':node:actools_resource:' . $lesson->id();
    $this->assertSame(Crypt::hmacBase64($payload, $key), $signature);
    $old_token = $payload . '.' . Crypt::hmacBase64($payload, Settings::getHashSalt());
    $this->assertNull($editorial->decodeToken($old_token, $lesson), 'Open forms signed with only the hash salt require a safe reload.');
    $wrong_context = $payload . '.' . Crypt::hmacBase64($payload, str_replace(':editorial_snapshot:', ':inventory:', $key));
    $this->assertNull($editorial->decodeToken($wrong_context, $lesson));
    $this->assertNotNull($editorial->decodeToken($token, $lesson));
    // Unsaved entities also have a bundle-specific form context.
    $new_lesson = Node::create(['type' => 'actools_resource', 'title' => 'New lesson']);
    $new_chapter = Node::create(['type' => 'actools_topic', 'title' => 'New chapter']);
    $new_token = $editorial->token($new_lesson);
    $this->assertNotNull($editorial->decodeToken($new_token, $new_lesson));
    $this->assertNull($editorial->decodeToken($new_token, $new_chapter));
  }

  public function testOldSignatureRejectsSaveWhileRetainingUnsavedFormContent(): void {
    $this->container->get('current_user')->setAccount($this->reader());
    $lesson = $this->lesson('Stored lesson remains intact', 'core', FALSE);
    $editorial = $this->container->get('actools_lesson.editorial');
    [$payload] = explode('.', $editorial->token($lesson), 2);
    $old_token = $payload . '.' . Crypt::hmacBase64($payload, Settings::getHashSalt());
    $form_object = $this->createMock(EntityFormInterface::class);
    $form_object->method('getEntity')->willReturn($lesson);
    $form_state = (new FormState())->setFormObject($form_object);
    $form_state->set('actools_lesson.editorial', TRUE);
    $form_state->setValues([
      'actools_lesson_editorial_token' => $old_token,
      'title' => 'Unsaved scientific correction',
      'field_actools_lesson_steps' => ['steps' => ['authored' => ['tex' => '1e1']]],
    ]);
    $form = [];
    $editorial->validateForm($form, $form_state);
    $this->assertArrayHasKey('actools_lesson_editorial_token', $form_state->getErrors());
    $this->assertStringContainsString('Reload the editor and copy your changes', (string) $form_state->getErrors()['actools_lesson_editorial_token']);
    $this->assertSame('Unsaved scientific correction', $form_state->getValue('title'));
    $this->assertSame('1e1', $form_state->getValue(['field_actools_lesson_steps', 'steps', 'authored', 'tex']));
    $this->assertNull($form_state->get('actools_lesson.editorial_snapshot'));
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame('Stored lesson remains intact', $storage->loadUnchanged($lesson->id())->label());
  }

}
