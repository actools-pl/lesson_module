# Personal reading progress

Progress is a reading aid, not grading or evidence of understanding. It is available to signed-in readers of a published default lesson revision. Anonymous readers get a sign-in link and can use all ordinary lesson links without recording progress.

- A normal page view, a step link, Read all and printing never write progress.
- **Mark as read** records the displayed stable step UUID and its current `content_version`.
- **Mark read and continue** records the read mark and explicitly saves the next available step as the reading place before following its link. Ordinary Next remains independent of this action.
- **Save my place here** records a position without marking anything as read.
- Undo and selected-step bulk actions affect only this account and this lesson. Reset requires confirmation and clears its read marks and position.
- Required-step totals exclude optional steps. A new content version is shown as needing a revisit; it is not silently read because an earlier version was marked. Old open forms cannot approve the changed version.

The private progress meter counts current read marks, rather than navigation position. If every step is optional it shows the actual optional-step count. With JavaScript its original labelled summary moves above the content; without JavaScript it remains above the native form controls below the content. The summary is never duplicated. In guided mode only read-and-continue is primary, with other actions under Manage reading progress. Read all offers explicit bulk step selection and does not guess which displayed step should be marked.

Data is stored by Drupal `user.data` under module `actools_lesson`, account ID and key `lesson:NID`. It is not stored in shared render markup or browser localStorage. The versioned record contains the completion map, saved position and update timestamps. Mutations acquire an account-and-lesson-specific lock and load fresh state after acquiring it, so multiple tabs merge their changes. Failed lock acquisition asks the reader to retry.

The reader form and the chapter's Continue learning action are private lazy builders with zero cache lifetime and account/permission/language cache contexts. Chapter resume reads accessible core and extension lessons, chooses the latest valid saved place, and keeps next-lesson navigation within that place's path. At the end of a fully marked route it offers review. Opening chapter resume does not mutate data.

Developer API:

```php
$state = $progress->snapshot($lesson, $account);
$state = $progress->mutate(
  $lesson, $account, 'complete', [$step_uuid], $position_uuid,
  [$step_uuid => $version_rendered_to_the_reader],
);
```

Other operations are `undo`, `position` and `reset`. Every completion operation must supply the expected version for every selected UUID. The service independently checks current published/default entity and field access and uses the reader's validated, reviewed step list. Its callers must supply POST and CSRF protection; the supplied `ProgressForm` does so with Drupal Form API. Do not call mutations from a GET controller, render callback or client-only navigation hook.

Testing is split deliberately: unit tests exercise merge/state/version semantics; actual Drupal Kernel tests exercise native storage, access and revisions; no-JavaScript Functional tests exercise real forms, CSRF, stale versions, undo/bulk/reset, account isolation and warmed caches.
