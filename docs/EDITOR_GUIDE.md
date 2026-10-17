# Editor guide — Actools Lessons 1.1.0

Open **Content → Actools Lessons** (`/admin/content/actools-lessons`). The workbench shows chapter assignment, step review readiness and whether a lesson is **Draft**, **Published** or **Published with draft**. Available actions depend on your native Drupal content, revision and text-format permissions. The workbench permission alone does not grant editing or publishing.

This guide describes the module's default lesson workflow. If Resource uses core Content Moderation, follow your site's configured moderation transitions instead; the module does not replace them.

## Create a lesson draft

1. Choose **Create lesson**. Enter a useful title, introduction and summary. Select **Core** or **Extension**. Enter one learning objective or prerequisite per line.
2. Give the first learning step a meaningful title. A step should keep its explanation, assumptions and supporting material together.
3. Add teaching components from the selector. Use native rich-text controls for prose and tables; use the equation and figure controls for scientific material. No JSON or HTML source editing is required.
4. Select **Save draft**. Saving persists the document as a native Drupal revision. Use the private saved-draft preview or native **Preview** to inspect the reader.

| Component | Authoring guidance |
| --- | --- |
| Explanation / Table | Use the rich-text editor's headings, lists and table tools. Your role must be allowed to use the selected text format. |
| Display equation | Enter editable TeX without dollar delimiters; inspect the typeset saved preview. Retain definitions and assumptions in nearby prose. |
| Scientific figure | Upload a PNG, JPEG, GIF or WebP, up to 20 MB. Supply meaningful alternative text; add a caption and longer diagram description where needed. |
| Key result / Common error | Keep the statement concise and explain its conditions or correction. |
| Worked example / Selected practice | Enter the question or given information, with optional hint, answer and worked solution. Reveals support study; they do not create a scored attempt. |

The component's **Source / reviewed variant reference** is displayed to learners. Record an appropriate public source or exact reviewed variant/version. Do not put confidential editorial notes there.

Figures use **public** storage. Their file URLs remain publicly accessible even if the containing lesson is a private draft or becomes unpublished. Use only material suitable for public distribution. Upload a new image when changing a figure so older revisions retain their original asset.

## Navigate and maintain a longer lesson

The numbered outline jumps to a step and opens it for editing. Saved lessons open compactly; open component summaries to edit their controls. Opening or closing a section does not alter teaching content. Required-field and step-field errors open affected sections so invalid hidden fields can be corrected. Publication errors also appear in Drupal’s native error messages.

Use **Add**, **Duplicate**, **Remove** and **Move up/down** controls for steps and components. They work with keyboard and without JavaScript. Numeric order controls are also available; lower numbers appear first. Edits are persisted only by saving the lesson. Duplicates receive independent identities; moving existing content preserves its stable links. The native builder permits up to 40 steps and 60 components in any step, with at most 120 components across the lesson when adding or duplicating. It checks the whole form against server input/upload limits before accepting changes. Existing larger stored documents are preserved; complete edits or removals require a server able to receive the whole form. See [authoring capacity](UPGRADE_1_0_3.md#authoring-capacity).

Meaningful changes to teaching components advance that step's content version. Learner read marks for older versions then ask for a revisit. Changing a step’s title or its position among steps retains its teaching-content version. Reordering teaching components within a step changes that step’s content version. Do not recreate an existing step merely to move it.

## Assign a chapter and publish

1. Save the lesson draft before assigning it. Choose **Create chapter**, enter its introduction and learning orientation, then add saved managed lessons to **Lessons in reading order**. Use the reference-row ordering controls.
2. A lesson belongs to one chapter. Core and Extension form separate learning routes within that chapter. Optional extension **steps** are a separate per-step choice: they do not increase the required reading total.
3. Preview the lesson and check prose, equations, assumptions, figures, alternative text and solutions. Mark each step's review checkbox only after reviewing its current saved teaching content.
4. Publish the chapter through its native Drupal form, and select **Publish reviewed lesson** for each ready lesson. The lesson must be assigned to a chapter and have reviewed, valid steps. Unpublished lesson references are not shown to learners.

Changing previously reviewed teaching components clears the review flag. Save the draft, inspect the changed preview, then reopen and mark that saved content reviewed before publishing. Checking a previously reviewed step while changing its teaching content in the same submission does not bypass this review reset.

The safe **Save draft / Publish reviewed lesson** actions apply to managed lessons. Chapters retain native Drupal saving/publication behavior; inspect their Published status and any site moderation workflow when changing an existing chapter.

## Improve an already published lesson

Choose **Edit** in the workbench. Where the module manages the workflow, editing reopens the latest authorized draft. **Save draft** retains the current published default for learners and saves the replacement privately. **Preview draft** shows your latest saved draft; **View published** shows the public version. The workbench reports **Published with draft** until the reviewed replacement is published.

When all changed steps are reviewed, **Publish reviewed lesson** makes the replacement the published default. If the action is missing, ask the site administrator to check native publication permissions and Content Moderation configuration; the module does not grant them automatically.

If another editor saves newer work, your stale form is rejected. Keep that tab open, copy your unsaved changes to a safe place, reopen the latest editor and reconcile them. Repeatedly submitting the stale form will not overwrite newer work. After a module update or cache rebuild, reload open editor forms before continuing.

## Reader and revision checks

Check **Guided**, **Read all** and **Print**; they use the same stored teaching components. Confirm chapter sequence, Core/Extension paths, optional-step labels, mobile layout and figure legibility. Print expands practice hints and solutions.

Learners explicitly mark steps read or use the guided read-and-continue action. Personal progress is a reading aid, not a grade, mastery judgment, examination result or attendance record. A plain navigation link does not mark completion.

Native Drupal revisions retain historical content and identities. Accounts with appropriate revision access can inspect an exact historical revision. A native restore creates a fresh teaching-content version where needed; it does not delete history. Restoring restricted-format text requires permission to use that format. Coordinate a restore with the publisher when a pending draft or moderation workflow exists.

Legacy Topic/Resource content is not automatically converted. The managed-reader flags opt content into the lesson/chapter experience; the old resource-card field is not the chapter's lesson order. Optional taxonomy references select existing `edu_topics` terms, while **Stable chapter code** is separate metadata. See [CONTENT_AND_EXTENSION.md](CONTENT_AND_EXTENSION.md).

## Shared presentation

When the optional Layout Builder add-on is enabled, site builders manage approved shared page layouts through Manage display. Continue authoring teaching content here; private previews and Print use the existing lesson document. Shared layout changes are site configuration and take effect independently of a lesson draft. See [LAYOUT_BUILDER.md](LAYOUT_BUILDER.md).
