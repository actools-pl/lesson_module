<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Functional;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Native editing follows a managed adoption's access, snapshot and draft rules. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class PendingManagedAdoptionFunctionalTest extends BrowserTestBase {

  protected static $modules = ['actools_lesson'];
  protected $defaultTheme = 'stark';

  public function testAuthorizedAdoptionOpensAndSavesLatestManagedDraft(): void {
    $owner = $this->owner();
    $default = $this->ordinary($owner, 'The public ordinary resource');
    $default_id = (int) $default->getRevisionId();
    $pending = $this->pending($default, $owner, TRUE);
    $historical = $this->storage()->loadRevisionUnchanged($pending->getRevisionId())->toArray();
    $this->drupalLogin($owner);
    $this->drupalGet('/node/' . $default->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('Title', 'PRIVATE managed adoption');
    $this->assertSession()->buttonExists('Save draft');
    $this->assertSession()->elementNotExists('css', '[type="submit"][value="Save"]');
    $this->assertSession()->elementExists('css', 'input[name="actools_lesson_editorial_token"]');
    $this->getSession()->getPage()->fillField('Title', 'A safely saved adoption draft');
    $this->getSession()->getPage()->pressButton('Save draft');
    $this->assertSession()->statusCodeEquals(200);
    [$saved, $latest] = $this->revisions($default);
    $this->assertSame($default_id, (int) $saved->getRevisionId());
    $this->assertFalse((bool) $saved->get('field_actools_lesson_managed')->value);
    $this->assertTrue($saved->isPublished());
    $this->assertSame('A safely saved adoption draft', $latest->label());
    $this->assertTrue((bool) $latest->get('field_actools_lesson_managed')->value);
    $this->assertFalse($latest->isDefaultRevision());
    $this->assertFalse($latest->isPublished());
    $this->assertSame($historical, $this->storage()->loadRevisionUnchanged($pending->getRevisionId())->toArray());
  }

  public function testUnauthorizedManagedLatestDeniesGetAndAnOlderNativePostWithoutDisclosure(): void {
    $owner_a = $this->owner();
    $owner_b = $this->owner();
    $default = $this->ordinary($owner_a, 'Owner A public resource');
    $this->drupalLogin($owner_a);
    // Preserve the real signed native form before another owner adopts it.
    $this->drupalGet('/node/' . $default->id() . '/edit');
    $client = $this->getSession()->getDriver()->getClient();
    $form = $client->getCrawler()->selectButton('Save')->form();
    $values = $form->getPhpValues();
    $values['title'][0]['value'] = 'An unauthorized stale native edit';
    $pending = $this->pending($default, $owner_b, TRUE);
    $before = $this->snapshot($default, $pending);
    $this->assertFalse($pending->access('update', $owner_a));
    $client->request('POST', $form->getUri(), $values);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('PRIVATE managed adoption');
    $this->assertSession()->pageTextNotContains('PRIVATE adoption teaching body');
    $this->assertSame($before, $this->snapshot($default, $pending));
    $this->drupalGet('/node/' . $default->id() . '/edit');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('PRIVATE managed adoption');
    $this->assertSession()->pageTextNotContains('PRIVATE adoption teaching body');
    $this->assertSame($before, $this->snapshot($default, $pending));
  }

  public function testStaleOrdinaryPostCannotSupersedeAnAuthorizedManagedAdoption(): void {
    $owner = $this->owner();
    $default = $this->ordinary($owner, 'A resource before adoption');
    $this->drupalLogin($owner);
    $this->drupalGet('/node/' . $default->id() . '/edit');
    $client = $this->getSession()->getDriver()->getClient();
    $form = $client->getCrawler()->selectButton('Save')->form();
    $values = $form->getPhpValues();
    $values['title'][0]['value'] = 'An obsolete ordinary form edit';
    $pending = $this->pending($default, $owner, TRUE);
    $before = $this->snapshot($default, $pending);
    $client->request('POST', $form->getUri(), $values);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('This content has changed. Reload the editor before saving.');
    $this->assertSame($before, $this->snapshot($default, $pending));
  }

  public function testOrdinaryPendingRevisionKeepsCoreResourceEditingBehavior(): void {
    $owner_a = $this->owner();
    $owner_b = $this->owner();
    $default = $this->ordinary($owner_a, 'An ordinary control resource');
    $pending = $this->pending($default, $owner_b, FALSE);
    $historical = $this->storage()->loadRevisionUnchanged($pending->getRevisionId())->toArray();
    $this->drupalLogin($owner_a);
    $this->drupalGet('/node/' . $default->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('Title', 'An ordinary control resource');
    $this->assertSession()->buttonExists('Save');
    $this->assertSession()->buttonNotExists('Save draft');
    $this->assertSession()->elementNotExists('css', 'input[name="actools_lesson_editorial_token"]');
    $this->getSession()->getPage()->fillField('Title', 'An ordinary native save');
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->statusCodeEquals(200);
    [$saved, $latest] = $this->revisions($default);
    $this->assertSame('An ordinary native save', $saved->label());
    $this->assertTrue($saved->isPublished());
    $this->assertFalse((bool) $latest->get('field_actools_lesson_managed')->value);
    $this->assertSame((int) $saved->getRevisionId(), (int) $latest->getRevisionId());
    $this->assertSame($historical, $this->storage()->loadRevisionUnchanged($pending->getRevisionId())->toArray());
  }

  private function owner(): UserInterface {
    return $this->drupalCreateUser(['access content', 'edit own actools_resource content']);
  }

  private function ordinary(UserInterface $owner, string $title): NodeInterface {
    $node = Node::create(['type' => 'actools_resource', 'title' => $title, 'uid' => $owner->id(), 'status' => 1, 'field_actools_kind' => 'notes', 'field_actools_lesson_managed' => 0]);
    $node->save();
    return $node;
  }

  private function pending(NodeInterface $default, UserInterface $owner, bool $managed): NodeInterface {
    $pending = clone $default;
    $pending->setNewRevision(TRUE);
    $pending->isDefaultRevision(FALSE);
    $pending->setOwnerId((int) $owner->id());
    $pending->setUnpublished()->setTitle($managed ? 'PRIVATE managed adoption' : 'PRIVATE ordinary pending');
    if ($managed) {
      $pending->set('field_actools_kind', 'lesson');
      $pending->set('field_actools_lesson_managed', TRUE);
      $pending->set('field_actools_path', 'core');
      $pending->set('field_actools_lesson_steps', [[
        'step_id' => $this->container->get('uuid')->generate(), 'title' => 'A private adoption step',
        'reviewed' => FALSE, 'optional' => FALSE, 'content_version' => 1,
        'components' => json_encode([['id' => $this->container->get('uuid')->generate(), 'type' => 'text', 'body' => ['value' => 'PRIVATE adoption teaching body', 'format' => 'plain_text']]], JSON_THROW_ON_ERROR),
      ]]);
    }
    $pending->save();
    return $pending;
  }

  private function snapshot(NodeInterface $node, NodeInterface $pending): array {
    [$default, $latest] = $this->revisions($node);
    return ['default' => $default->toArray(), 'latest' => $latest->toArray(), 'pending' => $this->storage()->loadRevisionUnchanged($pending->getRevisionId())->toArray()];
  }

  private function revisions(NodeInterface $node): array {
    $this->storage()->resetCache([$node->id()]);
    return [$this->storage()->loadUnchanged($node->id()), $this->storage()->loadRevisionUnchanged($this->storage()->getLatestRevisionId($node->id()))];
  }

  private function storage() {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

}
