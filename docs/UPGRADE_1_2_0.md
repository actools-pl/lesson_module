# Integrity and figure-placement corrections — 1.2.0

This release addresses the bounded implementation findings from the 18 October verification of 1.1.0. It keeps the existing revision-owned step field and optional shared Layout Builder presentation. Paragraphs is outside this release.

## Published ownership

Removing a published lesson from its saved chapter, clearing that chapter's managed role or deleting the chapter is rejected. Unpublish the affected lessons first. The check uses saved default content and also applies to defensive storage writes; a nondefault chapter draft does not change the public route.

For the module's lesson workflow, an authorized publisher can use **Unpublish lesson** in the editor. It saves the latest authorized lesson content as a new unpublished default revision. Earlier public and private revisions remain in history. Where core Content Moderation governs the content, use the site's configured unpublish transition instead.

To move a lesson: unpublish it, remove its reference from the old chapter, add it to the new chapter, then review and publish it again. Do not delete its step/component identities. No lesson is automatically moved, unpublished or deleted by a chapter edit.

The release does not automatically reassign already-orphaned lessons. Inspect the workbench for unassigned managed content and deliberately add each lesson to its intended chapter. An existing published orphan must be assigned before publishing it again or updating its published default; it may first be unpublished.

## Pending adoption and chapter saving

A published ordinary Resource can have a newer private managed revision created by a trusted integration. Editing must respect the latest saved managed revision: authorized editors enter the managed workflow; an inaccessible pending revision blocks the edit with a neutral message. A stale ordinary form cannot silently supersede a newer managed adoption. Unrelated ordinary Resources retain their native saving behaviour.

Chapters retain native Drupal saving or the site's existing moderation transitions. The chapter form now explains that saving a published chapter can change its public order immediately. Shared Layout Builder defaults remain configuration and take effect independently of lesson drafts. This release does not install a chapter moderation workflow.

## Figures inside teaching reveals

An ordinary scientific figure can be attached to an example or practice component in the same step. Choose the target and the placement: question/prompt, hint, answer or solution. A solution figure is rendered inside its solution disclosure; it is expanded for Print. Standalone figures remain available.

Figures remain flat, UUID-owned teaching components and count toward the existing component limits. Existing managed-file access, alternative text, captions, original-image links, revision usage and raster restrictions still apply. There are no new child entities or additional upload widgets on every practice component.

Duplicating a step remaps its component associations. Duplicating a parent with attached figures copies the group with independent identities when capacity permits. Removing the parent preserves its figures as standalone components. Invalid imported associations fall back to standalone output, while validation rejects new invalid associations.

## Upgrade and rollback

Replace the complete module directory through the reviewed deployment process and rebuild Drupal caches. Reload open editor forms. There is no database schema migration, no uninstall/reinstall step and no automatic content import. Retain the site's database, configuration, files, revisions and user progress. Follow [INSTALLATION.md](INSTALLATION.md) and [OPERATIONS.md](OPERATIONS.md), including BOA's new-platform procedure.

Back up before updating. Once new figure associations have been authored, an older release does not understand those properties. A rollback must preserve a consistent compatible code/data set; do not assume dropping in old code is sufficient. Restoring a database backup can affect all site edits made since that backup.

## Acceptance

- Attempt removal and single/bulk chapter deletion while lessons are published; confirm a recoverable rejection and unchanged stored content.
- Unpublish, move and republish a lesson; confirm its new chapter, Core/Extension neighbours and anonymous visibility.
- Save a private replacement draft before unpublishing; inspect the retained content and native revision history.
- Check an ordinary Resource and a pending managed adoption with different owners, including a stale editor tab.
- Upload a solution figure, save/reopen, duplicate, remove the parent and inspect history, no-JavaScript reading and Print.
- Check the real database, moderation configuration, theme, proxy cache and server limits on staging. Local SQLite checks do not certify Percona's concurrent transaction behaviour.

See [VALIDATION.md](../VALIDATION.md) for executed results. Bulk manuscript import, generated cross-reference tools and Paragraphs remain separate work.
