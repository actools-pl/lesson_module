# Implementation architecture

## Product boundary

Actools Lessons is both an authoring module and a complete default lesson reader. A chapter establishes an ordered route through lessons. A lesson establishes an ordered route through teaching steps. Each step contains semantic teaching components. Chapter navigation and step navigation are separate controls.

This is the first installable implementation of the product described in the review addendum. It does not replace the whole CMS theme or implement a question bank, enrollment system, gradebook, SCORM player or DOCX round trip.

## Native content and fields

| Owner | Native field | Purpose |
| --- | --- | --- |
| Topic | `field_actools_lesson_chapter` | Explicitly opt into the chapter reader |
| Topic | `field_actools_lessons` | Canonical ordered references to managed lessons |
| Topic | `field_actools_chapter_code` | Stable external code, such as `PHY11_SURFACE_TENSION` |
| Topic | `field_actools_chapter_term` | Optional existing `edu_topics` term reference |
| Resource | `field_actools_lesson_managed` | Explicitly opt into the lesson reader |
| Resource | existing `field_actools_kind` | Must be `lesson` for managed lessons |
| Resource | `field_actools_path` | `core` or `extension` |
| Resource | `field_actools_lesson_steps` | Unlimited revision-owned structured step items, bounded to 40 by validation |
| Resource | existing `field_actools_summary` | Lead summary |
| Resource | `field_actools_concepts` | Optional references to existing `edu_topics` concepts |
| Both | existing `field_actools_body` | Introductory formatted text |
| Both | `field_actools_objectives` | Learning objectives |
| Both | `field_actools_prerequisites` | Before-you-begin notes |

The installer preflights existing storage before mutation, creates missing compatible configuration and preserves existing labels, required flags, widgets and view displays. New module fields get authoring widgets; existing field configuration is not silently rewritten. Existing `field_actools_resources` remains supplementary resources rather than the canonical lesson sequence.

## Revision model

The custom `actools_lesson_step` field stores a stable `step_id`, title, optional flag, editorial reviewed flag, content version and a bounded JSON array of teaching components. Every component has a stable UUID and one of eight semantic types. JSON is an internal storage representation; the field widget is a structured Form API builder.

A native node revision owns the whole document atomically. There are no independently published child components and no duplicated manuscript for the continuous/print view. The main reader, continuous mode and print mode select the same document and render it differently.

Choosing a custom field keeps this release installable with Drupal core and avoids requiring unmerged theme work or new contrib dependencies. A future migration to Paragraphs or another component entity model must preserve step UUIDs, component UUIDs, component types, content versions, node/revision provenance and file usage. It must not regenerate links or read marks based on row order.

A public reader reloads the current default published node. Arbitrary `revision` or `revision_id` query values do not select drafts. Native preview retains the authorized preview node, is private and never changes learner progress. An unpublished managed canonical page is an authorized editor preview; the public Study route remains closed.

## Editorial workflow in 1.0.1

Managed lessons outside an existing core moderation workflow use native node forms with explicit Save draft and Publish reviewed lesson actions. A draft of a published lesson is an unpublished non-default revision. The public reader remains on the published default; native editing loads the latest draft only after checking update access to that revision. The private draft preview route also checks the selected latest revision and is uncacheable.

A signed, account-and-node-bound revision snapshot survives Drupal's uncached GET form build. Revision ID and content digest detect newer or in-place saves; a final per-node lock/recheck protects the save operation. Native CSRF, field/schema, format/file and publication readiness checks remain in force. The Publish action uses native status-field edit access. Where core Content Moderation already governs Resource, this custom action workflow defers to it.

Workbench draft titles/readiness are shown only when the latest revision itself grants update access. An inaccessible latest revision prevents managed editing rather than silently overwriting that revision from the public default.

## Integrity and ordering

The ordered chapter reference field is authoritative. `actools_lesson_membership` is only a derived uniqueness index, with `lesson_nid` as its primary key. Friendly entity validation rejects wrong resource kinds, duplicate rows, multiple chapter owners and incomplete publication. A database uniqueness guard catches concurrent stale ownership checks. Index synchronization occurs only when saving the default chapter revision, inside native entity storage's transaction. Saving a non-default chapter draft does not reorder the public route.

The module does not claim chapter-wide atomic publication. The supported workflow is draft lessons → chapter references → publish chapter/individual reviewed lessons. Moving a lesson requires removing it from the previous chapter before assigning it to the next.

Unpublishing a chapter hides parent navigation from users who cannot view it. Independently published lessons remain independently addressable. Public file URLs also remain independent. Sites that require inherited enrollment or private asset rules should add a deliberate access policy; a hidden chapter is not such a policy.

## Rendering and cacheability

- Rich text uses `processed_text`; plain labels, captions and TeX use escaped output.
- Figures use Drupal managed File entities, download access and the native image renderer. Raster PNG/JPEG/GIF/WebP are supported; uploaded SVG is not inlined.
- Denied node and field access results contribute cacheability even when the link or figure is absent.
- Navigation checks title/path/outline/step field access before constructing labels, counts or next links.
- Reader render keys and contexts include the selected mode and stable step. Canonical render metadata is merged with document metadata.
- Personal controls are private lazy-builder placeholders. They do not inject account data into shared lesson content.
- MathJax, dynamic components, accessibility helpers and fonts are local. Safe-mode limits URLs, classes and styles from TeX. Without JavaScript the source remains available.

## Progress model

User data key `actools_lesson / uid / lesson:nid` stores schema version, explicit step completions, saved position and update time. Completions name the step's teaching-content version. The store checks public node/field access again and serializes account/lesson mutations with a lock before loading and merging the latest record. Stale rendered versions cannot silently complete newly changed content.

A GET reads progress without writing. The native form supplies POST, CSRF and no-JavaScript support. Completion, plain navigation and a saved place are different actions. Optional steps remain outside the required denominator. Removed steps disappear from the current summary; changed steps request review. There is no grade or inferred mastery field.

## Deliberate limits

This release targets one language per authored lesson. It does not enable field translation or test translated chapter routes. It uses ordinary raster diagrams and TeX, not an equation editor with OMML export. Tables use CKEditor 5 table controls and Drupal text filters; authors need no HTML editing. Private storage may be configured by a site, but the included author widget uploads figures to public storage. There is no automatic manuscript importer, assessment adapter implementation, discussion system, learning standard export or content moderation setup.

## Optional shared Layout Builder integration — 1.1.0

The optional `actools_lesson_layout_builder` submodule adds explicit per-display activation for shared default layouts on public canonical managed pages. One complete reader block delegates to the existing document/chapter builders; teaching content remains in the same custom field and native node revisions. Paragraphs is deferred.

A render-only section-storage guard prevents native stored overrides or unvalidated sections from executing before managed rendering. The adapter validates saved shared defaults, clones their sections and omits duplicate field/title components only from managed output. Unmanaged resources retain native field output and applicable overrides. Unsupported or stale composition falls back to the original reader without rewriting configuration.

Managed override routes and direct override forms are denied so they cannot act as a second lesson-save workflow. Private preview, history and dedicated Study/Print retain their original exact-node/default-revision boundaries. Shared layout configuration and supplementary content have their own lifecycle outside individual lesson revisions. See [LAYOUT_BUILDER.md](LAYOUT_BUILDER.md) for setup, supported blocks, rollback and acceptance.
