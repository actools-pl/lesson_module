<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Native chapter editing/deletion and explicit unpublish preserve membership. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class ChapterPublicationIntegrityFunctionalTest extends BrowserTestBase {

  private const MESSAGE = 'Unpublish the affected lessons before removing them from a chapter, turning off its chapter role, or deleting it.';

  protected static $modules = ['actools_lesson', 'actools_lesson_test', 'page_cache', 'dynamic_page_cache'];
  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('access content')->save();
  }

  public function testNativeChapterDetachAndFlagOffGiveFriendlyValidation(): void {
    $this->publisher();
    [$chapter, $lesson] = $this->fixture();
    $revision = (int) $chapter->getRevisionId();
    foreach (['detach', 'flag_off'] as $operation) {
      $this->drupalGet($chapter->toUrl('edit-form'));
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('Chapter publication');
      $this->assertSession()->pageTextContains('Saving a published chapter can immediately change its public lesson order.');
      $page = $this->getSession()->getPage();
      if ($operation === 'detach') {
        $page->fillField('field_actools_lessons[0][target_id]', '');
      }
      else {
        $page->uncheckField('Use the Actools chapter reader');
      }
      $page->pressButton('Save');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains(self::MESSAGE);
      $saved = $this->reload($chapter);
      $this->assertSame($revision, (int) $saved->getRevisionId());
      $this->assertTrue((bool) $saved->get('field_actools_lesson_chapter')->value);
      $this->assertSame((int) $lesson->id(), (int) $saved->get('field_actools_lessons')->target_id);
    }
  }

  public function testSingleAndBulkDeleteAreFriendlyAtomicAndDoNotNameRestrictedChildren(): void {
    $publisher = $this->publisher();
    [$chapter, $lesson] = $this->fixture();
    $lesson->setTitle('RESTRICTED CHILD TITLE')->save();
    $this->container->get('state')->set('actools_lesson_test.hidden_titles', [(int) $lesson->id()]);
    $this->drupalGet($chapter->toUrl('delete-form'));
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Delete');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains(self::MESSAGE);
    $this->assertSession()->pageTextContains('Nothing was deleted.');
    $this->assertSession()->pageTextNotContains('RESTRICTED CHILD TITLE');
    $this->assertNotNull($this->reload($chapter));

    $empty = $this->chapter('An independently deletable chapter');
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($publisher);
    try {
      $this->container->get('tempstore.private')->get('entity_delete_multiple_confirm')->set($publisher->id() . ':node', [
        $empty->id() => [$empty->language()->getId()],
        $chapter->id() => [$chapter->language()->getId()],
      ]);
    }
    finally {
      $switcher->switchBack();
    }
    $this->drupalGet(Url::fromRoute('entity.node.delete_multiple_form'));
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Delete');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains(self::MESSAGE);
    $this->assertSession()->pageTextContains('Nothing was deleted.');
    $this->assertSession()->pageTextNotContains('RESTRICTED CHILD TITLE');
    $this->assertNotNull($this->reload($empty));
    $this->assertNotNull($this->reload($chapter));
    $this->assertTrue($this->reload($lesson)->isPublished());
  }

  public function testExplicitUnpublishOfLatestDraftAllowsDetachReassignAndReviewedPublish(): void {
    $this->publisher();
    [$chapter, $lesson] = $this->fixture();
    $public_revision = (int) $lesson->getRevisionId();
    $draft = clone $lesson;
    $draft->setNewRevision(TRUE);
    $draft->isDefaultRevision(FALSE);
    $draft->setUnpublished()->setTitle('A newer unreviewed draft');
    $rows = $draft->get('field_actools_lesson_steps')->getValue();
    $parts = json_decode($rows[0]['components'], TRUE, 32, JSON_THROW_ON_ERROR);
    // Unpublishing is a safe takedown even when the latest draft is incomplete.
    $parts[0]['body']['value'] = '';
    $rows[0]['components'] = json_encode($parts, JSON_THROW_ON_ERROR);
    $rows[0]['content_version'] = 2;
    $rows[0]['reviewed'] = FALSE;
    $draft->set('field_actools_lesson_steps', $rows)->save();
    $draft_revision = (int) $draft->getRevisionId();
    $stored_draft_rows = $this->storage()->loadRevisionUnchanged($draft_revision)->get('field_actools_lesson_steps')->getValue();
    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->assertSession()->fieldValueEquals('Title', 'A newer unreviewed draft');
    $this->submitForm([], 'Unpublish lesson');
    $this->assertSession()->statusCodeEquals(200);
    $unpublished = $this->reload($lesson);
    $this->assertFalse($unpublished->isPublished());
    $this->assertTrue($unpublished->isDefaultRevision());
    $this->assertGreaterThan($draft_revision, (int) $unpublished->getRevisionId());
    $this->assertSame('A newer unreviewed draft', $unpublished->label());
    $this->assertFalse((bool) $unpublished->get('field_actools_lesson_steps')->reviewed);
    $this->assertSame('', json_decode($unpublished->get('field_actools_lesson_steps')->components, TRUE)[0]['body']['value']);
    $this->assertTrue($this->storage()->loadRevisionUnchanged($public_revision)->isPublished());
    $this->assertSame($stored_draft_rows, $this->storage()->loadRevisionUnchanged($draft_revision)->get('field_actools_lesson_steps')->getValue());
    $this->drupalGet($chapter->toUrl('edit-form'));
    $this->submitForm(['field_actools_lessons[0][target_id]' => ''], 'Save');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(0, $this->reload($chapter)->get('field_actools_lessons'));
    $destination = $this->chapter('A new authoritative chapter', [$unpublished]);
    $this->drupalGet($unpublished->toUrl('edit-form'));
    $this->getSession()->getPage()->find('css', '.actools-author textarea[name$="[body][value]"]')->setValue('The completed replacement explanation.');
    $this->getSession()->getPage()->find('css', '.actools-author input[name$="[reviewed]"]')->check();
    $this->getSession()->getPage()->pressButton('Publish reviewed lesson');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertTrue($this->reload($lesson)->isPublished());
    $this->assertSame((int) $destination->id(), (int) $this->container->get('actools_lesson.outline')->owner((int) $lesson->id())->id());
  }

  public function testNonPublisherHasNoUnpublishActionAndDraftSaveKeepsPublicDefault(): void {
    [$chapter, $lesson] = $this->fixture();
    $public_revision = (int) $lesson->getRevisionId();
    $this->drupalLogin($this->drupalCreateUser(['access content', 'access actools lesson authoring', 'bypass node access']));
    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->assertSession()->buttonNotExists('Unpublish lesson');
    $client = $this->getSession()->getDriver()->getClient();
    $form = $client->getCrawler()->selectButton('Save draft')->form();
    $values = $form->getPhpValues();
    unset($values['actools_lesson_save_draft']);
    $values['actools_lesson_unpublish'] = 'Unpublish lesson';
    $values['op'] = 'Unpublish lesson';
    $client->request('POST', $form->getUri(), $values);
    $this->assertContains($this->getSession()->getStatusCode(), [200, 400, 403], 'An inaccessible trigger may be rejected or treated as a permitted draft action.');
    $this->assertTrue($this->reload($lesson)->isPublished());
    $this->assertSame($public_revision, (int) $this->reload($lesson)->getRevisionId());
    $this->drupalGet($lesson->toUrl('edit-form'));
    $this->submitForm(['title[0][value]' => 'A permitted private draft'], 'Save draft');
    $this->assertSession()->statusCodeEquals(200);
    $saved = $this->reload($lesson);
    $this->assertSame($public_revision, (int) $saved->getRevisionId());
    $this->assertTrue($saved->isPublished());
    $this->assertSame((int) $chapter->id(), (int) $this->container->get('actools_lesson.outline')->owner((int) $lesson->id())->id());
  }

  public function testWarmedNavigationUpdatesAfterAllowedUnpublishedMove(): void {
    [$chapter, $first] = $this->fixture();
    [, $second] = $this->fixture('Second navigation lesson', FALSE);
    $chapter->set('field_actools_lessons', [['target_id' => $first->id()], ['target_id' => $second->id()]])->save();
    $second->setPublished()->save();
    $destination = $this->chapter('The destination chapter');
    $url = Url::fromRoute('actools_lesson.reader', ['node' => $first->id()]);
    $this->drupalGet(clone $url);
    $this->drupalGet(clone $url);
    $this->assertSession()->linkExists('Next lesson: Second navigation lesson');
    $second->setNewRevision(TRUE);
    $second->setUnpublished()->save();
    $chapter->setNewRevision(TRUE);
    $chapter->set('field_actools_lessons', [['target_id' => $first->id()]])->save();
    $destination->set('field_actools_lessons', [['target_id' => $second->id()]])->save();
    $second->setNewRevision(TRUE);
    $second->setPublished()->save();
    $this->drupalGet(clone $url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkNotExists('Next lesson: Second navigation lesson');
    $this->drupalGet(Url::fromRoute('actools_lesson.reader', ['node' => $second->id()]));
    $this->assertSession()->linkExists('The destination chapter');
    $this->assertSession()->linkNotExists('Previous lesson: Public integrity lesson');
  }

  private function publisher(): UserInterface {
    $account = $this->drupalCreateUser(['access content', 'access actools lesson authoring', 'bypass node access', 'administer nodes', 'access content overview', 'use text format actools_lesson_text']);
    $this->drupalLogin($account);
    return $account;
  }

  private function fixture(string $title = 'Public integrity lesson', bool $assign = TRUE): array {
    $lesson = Node::create([
      'type' => 'actools_resource', 'title' => $title, 'status' => 0,
      'field_actools_kind' => 'lesson', 'field_actools_lesson_managed' => TRUE, 'field_actools_path' => 'core',
      'field_actools_lesson_steps' => [[
        'step_id' => $this->container->get('uuid')->generate(), 'title' => 'The public teaching step',
        'optional' => FALSE, 'reviewed' => TRUE, 'content_version' => 1,
        'components' => json_encode([['id' => $this->container->get('uuid')->generate(), 'type' => 'text', 'body' => ['value' => 'A public explanation.', 'format' => 'plain_text']]], JSON_THROW_ON_ERROR),
      ]],
    ]);
    $lesson->save();
    $chapter = $assign ? $this->chapter('The integrity chapter', [$lesson]) : NULL;
    if ($assign) {
      $lesson->setPublished()->save();
    }
    return [$chapter, $lesson];
  }

  private function chapter(string $title, array $lessons = []): NodeInterface {
    $chapter = Node::create(['type' => 'actools_topic', 'title' => $title, 'status' => 1, 'field_actools_lesson_chapter' => TRUE, 'field_actools_lessons' => array_map(static fn (NodeInterface $lesson): array => ['target_id' => $lesson->id()], $lessons)]);
    $chapter->save();
    return $chapter;
  }

  private function reload(NodeInterface $node): ?NodeInterface {
    $this->storage()->resetCache([$node->id()]);
    return $this->storage()->loadUnchanged($node->id());
  }

  private function storage() {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

}
