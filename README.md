# Actools Lessons

An installable Drupal module for an elegant, guided learning experience. It includes a complete default reader, structured lesson authoring, chapter navigation, continuous reading, print, locally typeset mathematics and private reading progress.

The module supplies the lesson experience. Your Drupal theme continues to supply the site's header, menus and branding. Existing Actools Topic and Resource content types are reused when compatible; the module also installs independently of the theme's proposed starter recipe.

## Documentation

- [Installation and BOA deployment](docs/INSTALLATION.md)
- [Lesson authoring and publication](docs/EDITOR_GUIDE.md)
- [Actools BS5 integration](docs/THEME_INTEGRATION.md)
- [Optional shared Layout Builder presentation](docs/LAYOUT_BUILDER.md)
- [1.2.0 integrity and figure-placement corrections](docs/UPGRADE_1_2_0.md)
- [Manuscript identity and asset mapping](docs/CONTENT_MAPPING.md)
- [Updates, backups and troubleshooting](docs/OPERATIONS.md)
- [Architecture and existing content](docs/ARCHITECTURE.md)
- [Tests](tests/README.md) and [executed validation](VALIDATION.md)
- [Necessary enhancements review](docs/SECOND_REVIEW.md)

See [the 1.2.0 corrections](docs/UPGRADE_1_2_0.md) for published ownership, pending adoption and figures inside solutions. See [the 1.0.3 corrective-upgrade notes](docs/UPGRADE_1_0_3.md) for the independent review findings and their fixes.

## Requirements

- Drupal **11.3 or later within Drupal 11**, PHP **8.3+**. Tested versions and limits are recorded in [VALIDATION.md](VALIDATION.md).
- Core Node, Field, Text, Filter, CKEditor 5, Options, File, Image, User and Taxonomy modules; these dependencies are declared and enabled by Drupal.
- A writable public file directory for teaching diagrams. Full native-editor capacity needs `max_input_vars=4096`, `post_max_size=128M` and `upload_max_filesize=20M`; upload-count limits must cover the actual pending file controls. The form checks its complete input budget and blocks unsafe submissions. See [server capacity](docs/UPGRADE_1_0_3.md#authoring-capacity).
- JavaScript enables mathematics typesetting and small interface enhancements. Reading, navigation, authoring actions and account progress also work without JavaScript. TeX source remains visible when typesetting is unavailable.

The base module requires no Paragraphs, Layout Builder, Bootstrap, React, remote CDN, account service or extra Composer runtime package. Optional shared presentation uses Drupal core Layout Builder; Paragraphs is deferred to a later project phase. MathJax and its fonts are included locally with their upstream licenses.

## Install

Test on a staging copy of your Drupal site first, with a database/configuration/files backup.

1. Place the package at `web/modules/custom/actools_lesson` for a Composer recommended-project installation. The GitHub repository contains the module at its root; the directory must be named **actools_lesson**, even though the repository is named `lesson_module`. Use your site's actual document root if it differs. In a development project or a new BOA platform build:

   ```sh
   git clone https://github.com/actools-pl/lesson_module.git web/modules/custom/actools_lesson
   ```

   Review and pin the commit before deployment. For BOA, build and verify a new platform before migrating a site; follow [the deployment instructions](docs/INSTALLATION.md). A downloaded GitHub archive must have its outer directory renamed to `actools_lesson`.
2. Enable **Actools Lessons** in Extend, or run from your Drupal project:

   ```sh
   vendor/bin/drush en actools_lesson -y
   vendor/bin/drush cr
   ```

3. Open **Content → Actools Lessons** (`/admin/content/actools-lessons`). The site owner account can use the workbench immediately.
4. Configure author permissions deliberately: **Access Actools lesson authoring**, the native create/edit/revision/publish permissions for Topic and Resource, and **Use the Actools lesson text text format**. Grant **Access content** to the learner roles that should read published lessons. The module changes no role grants. Its Publish action uses native permission to edit the Published status. If core Content Moderation already governs Resource, that workflow remains authoritative.
5. Optionally enable **Actools Lessons — Surface Tension example**:

   ```sh
   vendor/bin/drush en actools_lesson_demo -y
   ```

   This explicit action creates a published Surface Tension chapter, three Core lessons, one Extension lesson and three original scientific diagrams. Find them in the workbench. It does not import the supplied manuscript, create examination questions, alter the external taxonomy seed or change the default theme. Disabling the example submodule leaves its editable content in place.

A storage conflict stops installation with an explanation. Resolve it through a reviewed configuration change; do not delete or replace existing Actools fields to force installation. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Upgrade from 1.x

Replace the module directory with this 1.2.0 release and rebuild Drupal caches (`vendor/bin/drush cr`). Preserve the site's database and public files. No module uninstall/reinstall or database schema migration is required. Reload any open lesson editor forms after updating.

Version 1.0.2 adds a scoped integration adapter for the current `actools_bs5` theme and keeps compatibility with its proposed header contract. See [the integration guide](docs/THEME_INTEGRATION.md) for the exact theme snapshot and validation scope.

Version 1.2.0 adds guarded ownership transitions, an authorized Unpublish lesson action and figure placement inside examples/practice disclosures. See [the correction guide](docs/UPGRADE_1_2_0.md). The update preserves saved steps, UUIDs, revisions, files and account progress. It changes authoring actions, reader presentation and private draft handling. Existing imported demonstration text is left intact; the cleaner sample introduction applies to new demo imports.

## Optional Layout Builder presentation

Enable `actools_lesson_layout_builder` when you want shared chapter/lesson page layouts. Add one complete reader block and supported supplementary content blocks/menus through core Layout Builder, then explicitly activate the shared layout on the relevant Topic/Resource display. The add-on changes no existing displays automatically. Authors keep the lesson editor; Study, private previews and dedicated Print retain the existing reader. Follow [the setup and rollback guide](docs/LAYOUT_BUILDER.md), including the implications for ordinary resources sharing those displays.

## Build a chapter

1. Select **Create lesson**. Give it a title, summary, learning path, objectives and prerequisites.
2. Add meaningful learning steps. Within each step, add text, equation, figure, key result, common error, worked example, practice or table components. Rich text uses Drupal text formats with a supplied CKEditor 5 toolbar for headings, lists, tables and scientific subscripts; equations accept editable TeX. Figures require alternative text.
3. Use the numbered step outline and the builder's add, duplicate, remove and move controls. Saved sections collapse for easier scanning; opening a step or following its outline link exposes its controls. Step and component links remain stable when reordered. Duplicating generates new identities. Select **Save draft** initially.
4. Select **Create chapter**, add the saved lesson drafts to **Lessons in reading order**, and reorder the reference rows. A lesson belongs to one chapter. Core and Extension paths have separate previous/next lesson routes.
5. Preview lessons using Drupal's native Preview. Review changed teaching content, then mark the steps reviewed. Publish the chapter, then select **Publish reviewed lesson** for each ready lesson. **Save draft** preserves an already published version while you work privately; reopening the editor loads the latest draft. If another save makes your editor form stale, copy your changes before reloading the latest draft. Publication is deliberately staged; a chapter's unpublished lesson references are invisible to learners until those lessons are published.

To move a published lesson, unpublish it first, remove it from its old chapter, assign its new chapter, then publish it again. Removing published lessons or deleting their chapter is otherwise blocked. Chapters retain native saving/moderation, with an explicit notice about immediate public order changes.

Changing previously reviewed teaching content clears its editorial reviewed flag. Save a draft, preview the result, review it and then publish. Changing a step title or order preserves its reading identity. Learner read marks refer to a stable step and the version of its teaching content; changed content asks the learner to revisit it.

## Read and navigate

- Open a managed lesson's canonical page or its **Study** tab (`/node/{id}/study`). The default view shows one step with a separate chapter outline.
- **Guided**, **Read all** and **Print** use the same revision-owned content. Print expands practice hints and solutions and removes reading controls.
- Step links use a stable UUID query value; shared links survive reordering. An obsolete link gives a clear notice and shows an available step.
- Plain next/previous links change position without claiming completion. Signed-in learners get one private progress meter and one guided read-and-continue action. Secondary controls live in Manage reading progress. In Read all, learners explicitly select which steps to mark rather than marking an ambiguous current step.
- Optional steps do not increase the required-step total. Reading progress is a personal reading aid; it is not a grade, mastery claim or assessment result.
- Guests can read published material and navigate without creating a progress record.

## Existing site and theme integration

The module does not reapply the theme recipe, rewrite stored Topic Layout Builder overrides, move old `field_actools_resources` cards into the lesson order, or convert legacy `kind=lesson` resources automatically. Only content with the explicit managed-reader flag uses this product experience. The optional Layout Builder add-on uses saved shared defaults for explicitly opted-in public managed displays; individual managed overrides remain stored but are not used or editable while it is enabled.

The reader consumes the optional existing theme variables `--actools-header-height` and `--actools-header-admin-offset` for sticky offsets. It adds no competing site header. The supplied default styles cover desktop, narrow screens, dark preference, focus visibility and printing. Verify your site's typography, sticky header, Layout Builder overrides and print header on staging; details are in [docs/THEME_INTEGRATION.md](docs/THEME_INTEGRATION.md).

If `edu_topics` already exists at installation, optional native chapter/concept term references are added. The stable chapter code is separate metadata. No vocabulary, term code field, seed constant or taxonomy term is guessed or rewritten. See [docs/CONTENT_AND_EXTENSION.md](docs/CONTENT_AND_EXTENSION.md).

## Data, access and removal

Native node revisions own the complete lesson document, including steps, component identities, text, TeX and file references. Native revision restore retains historical records and stable step links while creating a fresh teaching-content version. Restoring component text in a restricted format requires permission to use that format. Diagrams use Drupal's managed files; uses from older revisions are retained until the node is deleted. Public files remain publicly addressable independently of node publication. The included figure widget uses public storage. Confidential figures require a reviewed private-storage/download-access extension; this release does not provide that authoring workflow.

Public readers load the default published revision and honor node, title, step and navigation field access. Personal progress is stored in Drupal user data and rendered through private lazy builders. Mutations use authenticated, CSRF-protected Form API POST submissions. A GET does not mark anything read or save a position.

Drupal's native revision view retains the selected historical lesson for accounts allowed to view that revision. It uses a private preview with no learner progress controls; adding a revision query to the public reader does not select historical content.

Uninstall is guarded. Export content first. Drupal also prevents removal of the module while its custom step field storage exists. An intentional removal requires deleting/exporting the structured content, removing the custom step field, completing Drupal's field-data purge and then uninstalling. Back up before this destructive operation. User progress is removed on module uninstall, and a lesson's progress is removed when that node is deleted. Normal disabling of the demo importer does not remove lessons.

## Package contents

- `src/`, templates, styles and behavior: production module source.
- `modules/actools_lesson_demo/`: optional original Surface Tension example and diagrams.
- `modules/actools_lesson_layout_builder/`: optional core Layout Builder integration.
- `tests/`: Drupal Unit/Kernel/Functional tests, retained browser regressions and a disposable CI runner.
- `docs/`: structure, theme integration and future adapter boundaries.
- `VALIDATION.md`: executed validation and deployment limits.
- `libraries/`: pinned MathJax browser assets and local fonts with upstream notices.

The first implementation uses a bounded structured Drupal field rather than Paragraphs. Authors use controls, never JSON. This keeps the install core-only and each lesson revision atomic; the representation and migration boundaries are documented in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

Module code is GPL-2.0-or-later; bundled third-party assets retain their separate licenses. See `LICENSE.txt` and `THIRD_PARTY.md`.

