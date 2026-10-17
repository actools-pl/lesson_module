<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_lesson\Functional;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Exercises actual native form drafts, publication and stale browser frames. */
#[Group('actools_lesson')]
#[RunTestsInSeparateProcesses]
final class LessonEditorialWorkflowTest extends BrowserTestBase {

  protected static $modules = ['actools_lesson'];
  protected $defaultTheme = 'stark';

  protected function publisher(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access content', 'access actools lesson authoring', 'bypass node access',
      'administer nodes', 'use text format actools_lesson_text',
    ]));
  }

  public function testNewLessonSavesBeforeChapterAssignment(): void {
    $this->publisher();
    $this->drupalGet(Url::fromRoute('actools_lesson.add_lesson'));
    $page = $this->getSession()->getPage();
    $page->fillField('Title', 'A new lesson draft');
    $page->find('css', '.actools-author input[name$="[title]"]')->setValue('Observe a liquid surface');
    $page->find('css', '.actools-author textarea[name$="[body][value]"]')->setValue('An initial draft explanation.');
    $page->pressButton('Save draft');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Private draft preview');
    $this->assertSession()->pageTextContains('An initial draft explanation.');
    $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->condition('title', 'A new lesson draft')->execute();
    $this->assertCount(1, $ids);
    $node = Node::load(reset($ids));
    $this->assertFalse($node->isPublished());
    $this->assertTrue($node->isDefaultRevision());
    $this->assertNull($this->container->get('actools_lesson.outline')->owner((int) $node->id()));
  }

  public function testPublishedLessonKeepsPublicContentUntilReviewedPublication(): void {
    $this->publisher();
    $lesson = $this->publishedLesson();
    $publicRevision = (int) $lesson->getRevisionId();
    $this->drupalGet($lesson->toUrl('edit-form'));
    $page = $this->getSession()->getPage();
    $page->fillField('Title', 'A private revised title');
    $page->find('css', '.actools-author textarea[name$="[body][value]"]')->setValue('The newly revised private explanation.');
    $page->pressButton('Save draft');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/node/' . $lesson->id() . '/study/draft');
    $this->assertSession()->pageTextContains('The newly revised private explanation.');
    [$default, $draft] = $this->revisions($lesson);
    $this->assertSame($publicRevision, (int) $default->getRevisionId());
    $this->assertTrue($default->isPublished());
    $this->assertSame('The public lesson', $default->label());
    $this->assertFalse($draft->isDefaultRevision());
    $this->assertFalse($draft->isPublished());
    $this->assertSame('A private revised title', $draft->label());
    $this->assertFalse((bool) $draft->get('field_actools_lesson_steps')->get(0)->reviewed);
    $this->drupalGet('/node/' . $lesson->id() . '/study');
    $this->assertSession()->pageTextContains('The original public explanation.');
    $this->assertSession()->pageTextNotContains('The newly revised private explanation.');
    $this->assertSession()->pageTextNotContains('A private revised title');
    $this->drupalGet(Url::fromRoute('actools_lesson.dashboard'));
    $this->assertSession()->pageTextContains('Published with draft');
    $this->assertSession()->pageTextContains('Surface Tension');
    $this->assertSession()->pageTextContains('0 of 1 steps reviewed');
    $this->assertSession()->linkExists('Preview draft');
    $this->assertSession()->linkExists('View published');
    $this->drupalGet('/node/' . $lesson->id() . '/edit');
    $this->assertSession()->fieldValueEquals('Title', 'A private revised title');
    $this->getSession()->getPage()->pressButton('Publish reviewed lesson');
    $this->assertSession()->pageTextContains('Review every learning step before publishing this lesson.');
    [$default] = $this->revisions($lesson);
    $this->assertSame($publicRevision, (int) $default->getRevisionId());
    $this->getSession()->getPage()->find('css', '.actools-author input[name$="[reviewed]"]')->check();
    $this->getSession()->getPage()->pressButton('Publish reviewed lesson');
    $this->assertSession()->statusCodeEquals(200);
    [$default, $latest] = $this->revisions($lesson);
    $this->assertTrue($default->isPublished());
    $this->assertSame('A private revised title', $default->label());
    $this->assertSame((int) $latest->getRevisionId(), (int) $default->getRevisionId());
    $this->drupalGet('/node/' . $lesson->id() . '/study');
    $this->assertSession()->pageTextContains('The newly revised private explanation.');
    $this->assertSession()->pageTextNotContains('The original public explanation.');
  }

  public function testStaleBrowserFormAndForgedSnapshotCannotReplaceANewerDraft(): void {
    $this->publisher();
    $lesson = $this->publishedLesson();
    $this->drupalGet('/node/' . $lesson->id() . '/edit');
    // The browser keeps its first GET form while another writer saves a draft.
    $draft = clone $lesson;
    $draft->setNewRevision(TRUE);
    $draft->isDefaultRevision(FALSE);
    $draft->setUnpublished();
    $draft->setTitle('A newer saved draft from another editor');
    $draft->save();
    $this->getSession()->getPage()->fillField('Title', 'A stale browser edit');
    $this->getSession()->getPage()->pressButton('Save draft');
    $this->assertSession()->pageTextContains('A newer version of this lesson was saved.');
    [, $latest] = $this->revisions($lesson);
    $this->assertSame('A newer saved draft from another editor', $latest->label());
    $this->drupalGet('/node/' . $lesson->id() . '/edit');
    $this->getSession()->getPage()->find('css', 'input[name="actools_lesson_editorial_token"]')->setValue('forged.snapshot');
    $this->getSession()->getPage()->fillField('Title', 'A forged snapshot edit');
    $this->getSession()->getPage()->pressButton('Save draft');
    $this->assertSession()->pageTextContains('A newer version of this lesson was saved.');
    [, $latest] = $this->revisions($lesson);
    $this->assertSame('A newer saved draft from another editor', $latest->label());
  }

  public function testNativePublishPermissionAndPrivatePreviewAccessArePreserved(): void {
    $lesson = $this->publishedLesson();
    $this->drupalLogin($this->drupalCreateUser([
      'access content', 'access actools lesson authoring', 'bypass node access',
    ]));
    $this->drupalGet('/node/' . $lesson->id() . '/edit');
    $this->assertSession()->buttonExists('Save draft');
    $this->assertSession()->buttonNotExists('Publish reviewed lesson');
    $this->getSession()->getPage()->fillField('Title', 'An editor can save a private draft');
    $this->getSession()->getPage()->pressButton('Save draft');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Private draft preview');
    [$default] = $this->revisions($lesson);
    $this->assertTrue($default->isPublished());
    $this->assertSame('The public lesson', $default->label());
    $this->drupalLogout();
    $this->drupalGet('/node/' . $lesson->id() . '/study/draft');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('An editor can save a private draft');
  }

  public function testWorkbenchDoesNotExposeAnInaccessibleLatestDraft(): void {
    $author = $this->drupalCreateUser([
      'access content', 'access actools lesson authoring',
      'edit own actools_resource content',
    ]);
    $other = $this->drupalCreateUser(['access content']);
    $lesson = $this->publishedLesson();
    $lesson->setOwnerId($author->id());
    $lesson->save();
    $draft = clone $lesson;
    $draft->setNewRevision(TRUE);
    $draft->isDefaultRevision(FALSE);
    $draft->setUnpublished();
    $draft->setOwnerId($other->id());
    $draft->setTitle('RESTRICTED PRIVATE DRAFT TITLE');
    $rows = $draft->get('field_actools_lesson_steps')->getValue();
    $rows[0]['reviewed'] = FALSE;
    $rows[] = [
      'step_id' => $this->container->get('uuid')->generate(),
      'title' => 'A restricted second step', 'optional' => FALSE,
      'reviewed' => FALSE, 'content_version' => 1,
      'components' => json_encode([[
        'id' => $this->container->get('uuid')->generate(), 'type' => 'text',
        'body' => ['value' => 'RESTRICTED PRIVATE SECOND STEP', 'format' => 'plain_text'],
      ]], JSON_THROW_ON_ERROR),
    ];
    $draft->set('field_actools_lesson_steps', $rows);
    $draft->save();
    $this->assertTrue($lesson->access('update', $author));
    $this->assertFalse($draft->access('update', $author));
    $this->drupalLogin($author);
    $this->drupalGet(Url::fromRoute('actools_lesson.dashboard'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The public lesson');
    $this->assertSession()->pageTextContains('1 of 1 steps reviewed');
    $this->assertSession()->pageTextNotContains('RESTRICTED PRIVATE DRAFT TITLE');
    $this->assertSession()->pageTextNotContains('0 of 2 steps reviewed');
    $this->assertSession()->pageTextNotContains('Published with draft');
    $this->assertSession()->linkNotExists('Preview draft');
    $this->drupalGet('/node/' . $lesson->id() . '/study/draft');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('RESTRICTED PRIVATE DRAFT TITLE');
    $this->assertSession()->pageTextNotContains('RESTRICTED PRIVATE SECOND STEP');
    $this->drupalGet('/node/' . $lesson->id() . '/edit');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('RESTRICTED PRIVATE DRAFT TITLE');
  }

  private function revisions(NodeInterface $lesson): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$lesson->id()]);
    return [$storage->loadUnchanged($lesson->id()), $storage->loadRevisionUnchanged($storage->getLatestRevisionId($lesson->id()))];
  }

  private function publishedLesson(): NodeInterface {
    $uuid = $this->container->get('uuid');
    $lesson = Node::create([
      'type' => 'actools_resource', 'title' => 'The public lesson', 'status' => 0,
      'field_actools_kind' => 'lesson', 'field_actools_lesson_managed' => TRUE,
      'field_actools_path' => 'core',
      'field_actools_lesson_steps' => [[
        'step_id' => $uuid->generate(), 'title' => 'Observe the interface',
        'optional' => FALSE, 'reviewed' => TRUE, 'content_version' => 1,
        'components' => json_encode([[
          'id' => $uuid->generate(), 'type' => 'text',
          'body' => ['value' => 'The original public explanation.', 'format' => 'plain_text'],
        ]], JSON_THROW_ON_ERROR),
      ]],
    ]);
    $lesson->save();
    Node::create([
      'type' => 'actools_topic', 'title' => 'Surface Tension', 'status' => 1,
      'field_actools_lesson_chapter' => TRUE,
      'field_actools_lessons' => [['target_id' => $lesson->id()]],
    ])->save();
    $lesson->setPublished()->save();
    return $lesson;
  }

}
