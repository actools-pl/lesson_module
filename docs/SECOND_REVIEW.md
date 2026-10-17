# Actools Lessons — second review and necessary enhancements

**Release reviewed:** 1.0.0 → **revised release:** 1.0.1  
**Review date:** 17 October 2026

## Assessment

The module has the right product foundation: a complete lesson reader, meaningful teaching steps, separate chapter navigation, structured authoring, native revisions, local mathematics, continuous reading, printing and private reading progress. The additional work needed was clearer hierarchy and a safer editing journey.

This pass inspected the implementation and actual Drupal screens on desktop and phone, then incorporated the improvements below. The scientific content, stable step links and underlying progress model remain the basis of the experience.

## Necessary changes incorporated

| Priority | Finding | Change in 1.0.1 | Why it matters |
| --- | --- | --- | --- |
| High | Editing a published lesson did not offer a safe, clear draft action. | Explicit **Save draft** and **Publish reviewed lesson** actions. Pending revisions remain private while learners retain the published version. Reopening loads the latest authorized draft; stale or forged save snapshots are rejected. Existing core Content Moderation remains authoritative where configured. | Authors can improve a live lesson without withdrawing it or accidentally replacing newer work. |
| High | The progress panel repeated a long status message and presented competing actions. | One accurate private progress meter, a concise count and one guided primary action. Secondary controls are inside Manage reading progress. | The learner can see progress and the next action immediately. |
| High | A Read-all footer could mark a single, ambiguously selected step. | Read all now offers explicit step selection for bulk read marks. It does not silently mark the first step or the entire lesson. | The action matches what the learner actually read. |
| Medium | Core and Extension content shared a chapter list while the reader navigated one path at a time. | Separate **Core lessons** and **Optional extensions**, clear path labels and counts, and numbering within each route. | The chapter and lesson screens now describe the same learning journey. |
| Medium | Phone screens repeated the current title and used generous navigation spacing before the explanation. | Compact mobile heading/step selector, fewer repetitions and wrapping progress buttons at normal text size. | Learners reach the content sooner and controls fit narrow screens. |
| Medium | Large diagrams dominated the reading column; long pages left the chapter outline behind. | A consistent prose measure, a slightly wider uncropped figure area and restrained image height. The existing desktop outline stays available when there is adequate viewport space and becomes static for short viewports, phone and print. | The page reads like a professional lesson while retaining access to the full-resolution figure. |
| Medium | Long lesson builders expanded every section and lost useful context after actions. | Numbered step outline, compact saved sections, retained disclosure state, focus on the acted section and automatic exposure of invalid hidden fields. Duplicate-step editing now retains independently generated component identities correctly. | Larger lessons are manageable, and validation errors are recoverable. |
| Medium | The workbench did not show assignment, review readiness or pending draft state. | Chapter context, reviewed-step counts, **Published with draft**, and distinct Edit/Preview/View actions. Private revision details require access to that revision. | Authors can tell what needs attention without opening every lesson. |

## Presentation decisions

The scientific reading style remains restrained: strong heading hierarchy, comfortable text width, consistent spacing and color, clear component types, useful figure captions and accessible controls. Core mathematics, native disclosure behavior, keyboard focus, dark appearance and print layouts remain supported.

Objectives and prerequisites now render as semantic lists. Internal chapter codes no longer occupy the learner-facing heading. The new demonstration introduction contains learning orientation; import/provenance information belongs in the documentation. Existing imported content is not automatically rewritten during an upgrade.

The module continues to use the site's existing header and branding. Its default design is complete, and the header offset contract is respected. These changes add no competing global header or new frontend framework.

## Verification

The revised code is tested in an actual Drupal 11.4.10 installation with PHP 8.3.6 and SQLite. The package's **VALIDATION.md** records the final automated results and actual browser checks.

Browser checks cover the revised desktop/320-pixel reader, Core/Extension grouping, figure proportions, sticky outline and focus, private meter/action hierarchy, dark appearance, no-JavaScript fallback, equations, wide tables and print. The authoring checks cover saved section state, outline focus, validation recovery, and the full draft/review/publish journey while checking that the previous public text remains available.

The review also tightened revision-specific access in the workbench: permission to edit the published default does not authorize disclosure or editing of an inaccessible pending revision.

## Installation decision

Use the revised **1.0.1** package. For an existing 1.0.0 installation, replace the module directory and rebuild Drupal caches. Preserve the database and public files, reload editor forms, and do not uninstall or reimport demonstration content. No database schema migration is required for this update.

Verify the package on the actual PHP 8.4/Percona/BOA staging stack, particularly `actools_bs5` header offsets, editor appearance and the production content-security policy. Those host-specific checks remain necessary before live deployment.
