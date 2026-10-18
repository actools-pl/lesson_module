# Shared Layout Builder presentation — Actools Lessons 1.3.0

The optional **Actools Lessons — shared layouts** submodule lets site builders arrange supplementary blocks around the complete chapter or lesson reader. It uses Drupal core Layout Builder. Paragraphs is deferred to a later project phase; this release keeps the existing lesson content model.

## What changes

The existing reader continues to own teaching steps, the chapter route, navigation, private progress and its document heading. Authors continue using the lesson editor. Site builders use shared Layout Builder defaults for the public canonical chapter/lesson page.

| Surface | Behaviour |
| --- | --- |
| Published managed canonical page | Uses its explicitly enabled, validated shared default layout. |
| Study, Read all and dedicated Print routes | Keep the existing reader and the same revision-owned teaching content. |
| Private draft, native preview and historical revision view | Keep the existing private/exact-revision reader. Shared supplementary blocks are omitted. |
| Ordinary unmanaged Topic/Resource | Keeps Drupal's native display and existing applicable Layout Builder overrides. |
| Individual managed content Layout tab/routes | Unavailable while the add-on is enabled; shared defaults are the supported workflow. |

Installing the add-on does not enable Layout Builder on displays, create sections, overwrite existing display configuration, change the theme or migrate content. Existing per-content override data and the core `allow_custom` setting are preserved. Stored overrides are not used for managed reader pages while this add-on is enabled.

## Enable and configure

Use the staging/build procedure in [INSTALLATION.md](INSTALLATION.md), including the actual BOA codebase, PHP owner, Drupal root and site URI. For an ordinary Composer development project with Drush available:

```sh
vendor/bin/drush en actools_lesson_layout_builder -y
vendor/bin/drush cr
```

Drupal enables the required main module and core Layout Builder dependencies. To add supplementary reusable or inline content blocks, also enable core **Block Content** (`block_content`) if the site has not already enabled it. This does not install the theme's block recipes or change permissions.

Enable core **Field UI** (`field_ui`) when using the graphical Manage display/Manage layout screens. If the site has no content-block types, create the required types through Block Content or use the site’s approved theme/configuration recipe; this add-on installs none.

Configure Topic and Resource independently:

1. Open **Structure → Content types → Topic/Resource → Manage display**. Use the display serving Full content: ordinarily Default, or the separate Full content display if the site has enabled it. Teasers and other view modes are outside this integration.
2. Enable **Use Layout Builder** and save. Initially leave **Use the saved shared layout for managed chapters and lessons** unchecked. Existing native field blocks are needed by ordinary resources sharing the same bundle; keep those blocks unless deliberately changing their presentation.
3. Open **Manage layout**. Add exactly one **Complete Actools chapter or lesson reader** block, preferably in its own full-width one-column section. Its content context must remain the content item being viewed (`layout_builder.entity`). Its block title is hidden because the reader provides the document heading. The layout editor shows a placeholder instead of live learner controls.
4. Add supported supplementary content blocks or menus above, below or beside the reader. Save the shared layout. Keep all essential explanations, equations, figures and questions in the lesson editor so they are included in Study and Print.
5. Return to **Manage display**, check **Use the saved shared layout for managed chapters and lessons**, and save. Activation validates the layout. Correct any reported error before retrying.
6. Check a published managed page and an ordinary Note/Assignment/Topic. For managed pages, native field/title/extra-field blocks are omitted from a cloned layout because the complete reader already supplies them. Their saved configuration remains intact and their native output remains available to unmanaged content.

Use native layout/display administration permissions only for trusted site builders. Ordinary lesson authors do not need layout administration. This add-on grants no permissions or role changes.

For a new display, leave individual content customization off. **Do not turn off an existing customization setting or remove its layout field merely to use this integration.** Core may require reverting overrides and may remove the override field when disabling customization. The add-on preserves those records and blocks managed override editing separately, while ordinary content can retain its own native behaviour.

## Supported presentation

Core one-, two-, three- and four-column layouts are supported, including the core two-column bricks layout. A full-width reader with supplementary sections above/below is the recommended starting point. The add-on makes the reader collapse its internal sidebar when the actual layout region is narrow, including on a wide monitor.

Supported supplementary blocks are reusable/inline core content blocks and system menus. Native field, extra-field and page-title components may remain for unmanaged content and are filtered only from managed output. Views, arbitrary third-party block/layout plugins and full-node embedding are outside this supported composition. Do not embed the complete lesson/chapter inside a supplementary content block.

The actual theme's Hero, Text and image, FAQ or Resource cards can be used when they are implemented as compatible content-block types. This add-on does not supply those types, install unmerged theme changes or certify their rendering. Check their fields, access and reference view modes on staging. It works with the existing scoped `actools_bs5` header adapter; see [THEME_INTEGRATION.md](THEME_INTEGRATION.md).

## Publication and history

**Saving a shared layout changes presentation for published pages immediately.** Layout defaults are site configuration, not an individual lesson draft or part of that lesson's native revision. Supplementary shared blocks also have their own editorial lifecycle. Lesson revision restore preserves teaching content; it does not roll back a later site-wide layout change.

Review/export shared layout configuration through the site's normal deployment process. Keep teaching content and reviewed solutions in lesson steps. Existing content versions and learner read marks continue to describe those steps; rearranging supplementary presentation does not mark teaching content changed.

Per-content managed layout overrides are deliberately unavailable in this release, including core save/reset/discard and AJAX layout routes. This prevents layout editing from becoming a second lesson-save workflow outside the reviewed draft/publish controls. Existing core Content Moderation remains authoritative for teaching content where configured.

## Failure, rollback and removal

Native layout saves reject a missing/duplicate reader, incorrect reader context/title, unavailable blocks, invalid regions and unsupported layout/block types when the integration is active. If core Layout Builder is disabled through its confirmation form while shared presentation is active, a recoverable error directs the site builder to opt out of shared presentation first; configuration remains saved. If stale or imported configuration fails validation at rendering, the module falls back to its original complete reader. It does not repair or rewrite the saved layout automatically.

To stop using shared presentation, uncheck **Use the saved shared layout for managed chapters and lessons** on each opted-in display and save. The original managed reader resumes. Preserve core Layout Builder and stored overrides; disabling core customization is unnecessary.

Before uninstalling the optional add-on, remove its reader block components and opt-in settings from the affected display configuration using the normal configuration workflow. Export/back up configuration first: Drupal's dependency cleanup can remove dependent configuration during module uninstall. Do not uninstall the main lesson module or delete its fields as a presentation rollback.

## Acceptance checks

- Compare canonical Guided/Read all/step links with the direct Study and Print routes. Confirm one reader, one document H1 and working chapter navigation.
- Save a private replacement draft while anonymous visitors retain the published lesson. Check native preview and historical revision access separately.
- Warm pages as anonymous and two learner accounts; verify separate progress, field/node access and no GET progress writes.
- Confirm ordinary Notes, Assignments and unmanaged Topics still show their native fields and applicable saved overrides. Check that attempts to edit managed per-content layouts are denied.
- Inspect a narrow layout region, a 320-pixel phone, dark appearance, sticky header/admin toolbar and browser/dedicated printing with the actual installed theme.

Executed evidence belongs in [VALIDATION.md](../VALIDATION.md). Automated checks use a disposable Drupal installation; the production BOA/Percona/theme/CSP combination remains a staging acceptance responsibility.
