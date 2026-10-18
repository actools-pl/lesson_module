import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {runLayoutBuilderRegression} from './layout-builder.mjs';
import {runFigurePlacementRegression, runEditorActionStyleRegression} from './figure-placement.mjs';

const A = '11111111-1111-4111-8111-111111111111';
const B = '22222222-2222-4222-8222-222222222222';
const tex = String.raw`\[h=\frac{2S\cos\theta}{\rho ga}\]`;
const formula = `<span class="actools-equation__math">${tex}</span>`;
const reader = `<article class="actools-lesson" data-actools-reader>
  <h1>Capillary rise and depression in narrow tubes</h1>
  <details class="actools-chapter-nav" data-actools-disclosure="chapter" open><summary>Core lessons</summary><nav><a href="#step-title">Capillary rise</a></nav></details>
  <details class="actools-step-navigation" data-actools-disclosure="steps" open><summary>Step 2 of 2</summary><ol class="actools-step-rail">
    <li data-actools-step-link="${A}"><a href="#step-title">Concept</a><span data-actools-completion-label hidden></span></li>
    <li data-actools-step-link="${B}"><a href="#step-title">Derivation</a><span data-actools-completion-label hidden></span></li>
  </ol></details>
  <div data-actools-progress-summary hidden></div>
  <section class="actools-step" id="step-title"><h2>Balance the vertical forces</h2><div class="actools-equation">${formula}</div></section>
  <details class="actools-answer"><summary>Solution</summary><div>The upward surface force balances the column weight.</div></details>
  <button data-actools-print hidden>Print lesson</button><div data-progress-slot></div>
</article>`;
const privateForm = (complete, revisit, suffix) => `<form class="actools-progress" data-actools-completed-steps='${JSON.stringify(complete)}' data-actools-revisit-steps='${JSON.stringify(revisit)}'>
  <div class="actools-progress__summary"><span class="actools-progress__count" id="count-${suffix}">1 of 2 required steps read</span><progress class="actools-progress__meter" value="1" max="2" aria-labelledby="count-${suffix}"></progress></div>
  <button type="submit" data-actools-progress-action="complete">Mark read and continue</button>
</form>`;

/** Called by run.mjs; contexts live until runner browser.close() for portability. */
export async function runRegressions({ browser, baseURL, moduleRoot, outputDir }) {
  const checks = {};
  const errors = [];
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  context.on('page', (page) => page.on('pageerror', (error) => errors.push(error.message)));
  const open = async (viewport) => {
    const page = await context.newPage();
    if (viewport) await page.setViewportSize(viewport);
    await page.goto(`${baseURL}/fixtures/empty.html`);
    return page;
  };
  const script = (page, file) => page.addScriptTag({ url: `${baseURL}/module/js/${file}` });
  const add = (page, markup, attach = true) => page.evaluate(({ markup, attach }) => {
    const holder = document.createElement('div');
    holder.innerHTML = markup;
    const root = holder.firstElementChild;
    document.querySelector('#fixture').append(root);
    if (attach) Drupal.attachBehaviors(root);
  }, { markup, attach });
  const tick = (page) => page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));

  // R06: document attachment precedes reader and lazy progress insertion. The
  // actual Drupal insertion contract passes each new root as the context.
  const late = await open({ width: 320, height: 800 });
  await script(late, 'reader.js');
  await script(late, 'progress.js');
  await late.evaluate(() => { window.printCalls = 0; window.print = () => { window.printCalls++; }; Drupal.attachBehaviors(document); });
  await add(late, reader);
  assert.equal(await late.locator('.actools-chapter-nav').evaluate((el) => el.open), false);
  assert.equal(await late.locator('[data-actools-print]').isVisible(), true);
  await late.evaluate((markup) => {
    const slot = document.querySelector('[data-progress-slot]');
    slot.innerHTML = markup;
    window.originalMeter = slot.querySelector('progress');
    Drupal.attachBehaviors(slot.firstElementChild);
  }, privateForm([A, B], [B], 'first'));
  assert.equal(await late.locator(`[data-actools-step-link="${A}"]`).evaluate((el) => el.classList.contains('is-complete')), true);
  assert.equal(await late.locator(`[data-actools-step-link="${B}"]`).evaluate((el) => el.classList.contains('needs-revisit')), true);
  assert.equal(await late.locator(`[data-actools-step-link="${B}"] [data-actools-completion-label]`).textContent(), 'Content changed; revisit this step');
  assert.equal(await late.locator('[data-actools-progress-summary]').isVisible(), true);
  assert.equal(await late.evaluate(() => document.querySelector('[data-actools-progress-summary] progress') === window.originalMeter), true);
  await late.evaluate(() => {
    const root = document.querySelector('[data-actools-reader]');
    Drupal.attachBehaviors(root); Drupal.attachBehaviors(root); Drupal.attachBehaviors(document);
    const form = root.querySelector('form'); Drupal.attachBehaviors(form);
  });
  assert.equal(await late.locator('.actools-progress__summary').count(), 1);
  assert.equal(await late.locator('progress').getAttribute('aria-labelledby'), 'count-first');
  await late.locator('[data-actools-print]').click();
  await late.waitForFunction(() => window.printCalls === 1);
  await late.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
  assert.equal(await late.locator('.actools-answer').evaluate((el) => el.open), true);
  await late.evaluate(() => window.dispatchEvent(new Event('afterprint')));
  assert.equal(await late.locator('.actools-answer').evaluate((el) => el.open), false);
  // A replaced personal form must replace the moved summary and clear old state.
  await late.evaluate((markup) => {
    const slot = document.querySelector('[data-progress-slot]');
    slot.innerHTML = markup; Drupal.attachBehaviors(slot.firstElementChild);
  }, privateForm([B], [], 'replacement'));
  assert.equal(await late.locator('.actools-progress__summary').count(), 1);
  assert.equal(await late.locator('progress').getAttribute('aria-labelledby'), 'count-replacement');
  assert.equal(await late.locator(`[data-actools-step-link="${A}"] [data-actools-completion-label]`).isVisible(), false);
  assert.equal(await late.locator(`[data-actools-step-link="${B}"] [data-actools-completion-label]`).textContent(), 'Marked as read');
  checks.rootReaderAndLatePrivateProgress = { realDrupalOnce: true, rootContexts: true, oneSummary: true, readAndRevisit: true, printHandlerOnce: true };

  // R05: screen appearance remains intentional; all print tokens become a
  // complete ink-on-paper palette under either explicit convention or OS dark.
  const palette = `<article class="actools-lesson"><h1>Capillary rise</h1><p class="actools-lesson__summary">Summary</p><div class="actools-equation">${formula}</div><section class="actools-component actools-component--result"><p class="actools-component__type">Key result</p><h2>Equilibrium height</h2></section><section class="actools-component actools-component--error"><p class="actools-component__type">Common error</p></section><details class="actools-answer" open><summary>Solution</summary><div>Balance both vertical forces.</div></details><figure class="actools-figure"><img alt="Capillary diagram" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20'%3E%3C/svg%3E"><figcaption>Measured relative to reservoir level.</figcaption></figure></article>`;
  const printCases = [
    { os: 'light' }, { os: 'dark' },
    { os: 'light', attribute: 'data-bs-theme', value: 'dark' },
    { os: 'light', attribute: 'data-theme', value: 'dark' },
    { os: 'dark', attribute: 'data-bs-theme', value: 'light' },
    { os: 'dark', attribute: 'data-theme', value: 'light' },
  ];
  for (const mode of printCases) {
    const page = await open();
    await page.emulateMedia({ colorScheme: mode.os, media: 'screen' });
    if (mode.attribute) await page.locator('html').evaluate((el, mode) => el.setAttribute(mode.attribute, mode.value), mode);
    await add(page, palette, false);
    const darkScreen = mode.value ? mode.value === 'dark' : mode.os === 'dark';
    assert.equal(await page.locator('h1').evaluate((el) => getComputedStyle(el).color), darkScreen ? 'rgb(230, 239, 240)' : 'rgb(25, 54, 64)');
    await page.emulateMedia({ media: 'print' });
    const colors = await page.locator('.actools-lesson').evaluate((root) => {
      const style = getComputedStyle(root);
      return { background: style.backgroundColor, scheme: style.colorScheme, h1: getComputedStyle(root.querySelector('h1')).color, summary: getComputedStyle(root.querySelector('.actools-lesson__summary')).color, result: getComputedStyle(root.querySelector('.actools-component--result .actools-component__type')).color, warning: getComputedStyle(root.querySelector('.actools-component--error .actools-component__type')).color, equation: getComputedStyle(root.querySelector('.actools-equation')).color, solution: getComputedStyle(root.querySelector('.actools-answer')).color, tokens: Object.fromEntries(['ink','muted','accent','accent-hover','paper','soft','tint','border','warning','warning-tint','focus','on-accent'].map((token) => [token, style.getPropertyValue(`--al-${token}`).trim()])) };
    });
    assert.equal(colors.background, 'rgb(255, 255, 255)'); assert.equal(colors.scheme, 'light');
    assert.equal(colors.h1, 'rgb(0, 0, 0)'); assert.equal(colors.summary, 'rgb(51, 51, 51)');
    for (const key of ['result', 'warning', 'equation', 'solution']) assert.equal(colors[key], 'rgb(0, 0, 0)', `${JSON.stringify(mode)} ${key}`);
    assert.deepEqual(colors.tokens, { ink:'#000', muted:'#333', accent:'#000', 'accent-hover':'#000', paper:'#fff', soft:'#fff', tint:'#fff', border:'#999', warning:'#000', 'warning-tint':'#fff', focus:'#000', 'on-accent':'#fff' });
  }
  checks.printPalette = { cases: printCases, completePalette: true, screenAppearanceRetained: true };

  // R06/R09: real bundled mathematics succeeds on a root inserted after load,
  // with exactly-once processing; fault injection covers a rejected renderer.
  const math = await open();
  await script(math, 'math-config.js');
  await math.addScriptTag({ url: `${baseURL}/module/libraries/mathjax/tex-chtml.js` });
  await math.evaluate(() => MathJax.startup.promise);
  await math.evaluate(() => { const actual = MathJax.typesetPromise.bind(MathJax); window.typesetCalls = 0; MathJax.typesetPromise = (roots) => { window.typesetCalls++; return actual(roots); }; });
  await add(math, `<article class="actools-lesson" id="successful-math">${formula}</article>`);
  await math.waitForFunction(() => document.querySelectorAll('#successful-math mjx-container').length === 1);
  await math.evaluate(() => { const root=document.querySelector('#successful-math'); Drupal.attachBehaviors(root); Drupal.attachBehaviors(document); });
  assert.equal(await math.evaluate(() => window.typesetCalls), 1);
  assert.equal(await math.locator('.actools-math-status').count(), 0);
  await add(math, `<section class="actools-chapter" id="late-chapter-math">${formula}</section>`);
  await math.waitForFunction(() => document.querySelectorAll('#late-chapter-math mjx-container').length === 1);
  assert.equal(await math.evaluate(() => window.typesetCalls), 2);
  await math.locator('html').evaluate((el) => el.setAttribute('data-bs-theme','dark'));
  await math.emulateMedia({ media:'print', colorScheme:'dark' });
  assert.equal(await math.locator('#successful-math mjx-container').evaluate((el)=>getComputedStyle(el).color), 'rgb(0, 0, 0)');
  if (outputDir) await math.pdf({ path:path.join(outputDir,'explicit-dark-math-print.pdf'), format:'A4', printBackground:true });
  await math.evaluate(() => { MathJax.typesetPromise = () => Promise.reject(new Error('Intentional renderer failure')); });
  await add(math, `<article class="actools-lesson" id="rejected-math">${formula}</article>`);
  await math.waitForFunction(() => document.querySelector('#rejected-math .actools-math-status'));
  await math.evaluate(() => { const root=document.querySelector('#rejected-math'); Drupal.attachBehaviors(root); Drupal.attachBehaviors(document); });
  assert.equal(await math.locator('#rejected-math .actools-math-status').count(), 1);
  assert.equal(await math.locator('#rejected-math .actools-equation__math').textContent(), tex);
  checks.mathSuccessfulAndRejected = { actualLocalMathJax:true, rootAndLateChapter:true, callsOnce:true, printInk:true, rejectedNoticeOnce:true, rawTeXRetained:true };

  // Missing and blocked entry scripts: no indefinite polling, and the same
  // single notice after final load or immediately for a late root context.
  const missing = await open();
  await script(missing,'math-config.js');
  await add(missing, `<article class="actools-lesson" id="missing-math">${formula}</article>`);
  await missing.waitForFunction(() => document.querySelector('.actools-math-status'));
  await missing.evaluate(() => Drupal.attachBehaviors(document.querySelector('.actools-lesson')));
  assert.equal(await missing.locator('.actools-math-status').count(),1);
  assert.equal(await missing.locator('.actools-equation__math').textContent(),tex);
  const blocked = await context.newPage();
  await blocked.route('**/module/libraries/mathjax/tex-chtml.js', (route) => route.abort('blockedbyclient'));
  await blocked.goto(`${baseURL}/fixtures/math-entry.html`);
  await blocked.waitForFunction(() => document.querySelector('.actools-math-status'));
  await blocked.evaluate(() => { Drupal.attachBehaviors(document); Drupal.attachBehaviors(document.querySelector('.actools-lesson')); });
  assert.equal(await blocked.locator('.actools-math-status').count(),1);
  assert.equal(await blocked.locator('.actools-equation__math').textContent(),tex);
  assert.equal(await blocked.locator('mjx-container').count(),0);
  const startup = await open(); await script(startup,'math-config.js');
  await startup.evaluate(() => { MathJax.startup.promise=Promise.reject(new Error('Intentional startup failure')); MathJax.typesetPromise=()=>Promise.resolve(); document.querySelector('#fixture').innerHTML='<article class="actools-lesson"><span class="actools-equation__math">\\[h=2S\\]</span></article>'; Drupal.attachBehaviors(document.querySelector('.actools-lesson')); });
  await startup.waitForFunction(()=>document.querySelector('.actools-math-status'));
  assert.equal(await startup.locator('.actools-math-status').count(),1);
  checks.mathMissingBlockedAndStartupRejected = { missingEntry:true, blockedEntry:true, beforeWindowLoad:true, lateRootAfterLoad:true, rejectedStartup:true, noticeOnce:true, rawTeXRetained:true };

  // R10: the PHP Kernel regression verifies original+derivative native markup;
  // its linked-image shape must expose each image's meaningful individual name.
  const componentPHP = await fs.readFile(path.join(moduleRoot,'src/Service/ComponentRenderer.php'),'utf8');
  assert.equal(componentPHP.includes("'aria-label' => 'Open full-resolution diagram'"),false);
  const figures = await open();
  await add(figures, `<article class="actools-lesson"><figure class="actools-figure"><a class="actools-lesson-original" href="/original-water.png"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="Water rises above the reservoir in a wetting tube"></a><figcaption>Water meniscus.</figcaption></figure><figure class="actools-figure"><a class="actools-lesson-original" href="/original-mercury.png"><img data-derivative-src="/styles/actools_lesson_diagram/mercury.png" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="Mercury is depressed below the reservoir in a non-wetting tube"></a><figcaption>Mercury meniscus.</figcaption></figure></article>`,false);
  assert.equal(await figures.getByRole('link',{name:'Water rises above the reservoir in a wetting tube',exact:true}).count(),1);
  assert.equal(await figures.getByRole('link',{name:'Mercury is depressed below the reservoir in a non-wetting tube',exact:true}).count(),1);
  assert.equal(await figures.getByRole('link',{name:'Open full-resolution diagram',exact:true}).count(),0);
  checks.individualDiagramLinkNames = true;

  // Preserve the optional main-theme adapter and native handoff, using the
  // inspected theme DOM and exact pinned CSS without changing a global header.
  const adapter = await open();
  await adapter.addStyleTag({ url:`${baseURL}/fixtures/main-sticky-header.css` });
  await adapter.addStyleTag({ content:'html{--drupal-displace-offset-top:34px}body{padding-top:34px}#page>header{height:88px;background:white}.fixture-long{height:2000px}' });
  await adapter.evaluate((markup)=>{ document.querySelector('#fixture').innerHTML=`<div id="page" class="page d-flex flex-column"><header role="banner">Existing theme header</header><main>${markup}<div class="fixture-long"></div></main></div>`; },reader);
  await script(adapter,'reader.js'); await script(adapter,'bs5-integration.js');
  await adapter.evaluate(()=>Drupal.attachBehaviors(document.querySelector('[data-actools-reader]')));
  await tick(adapter);
  assert.equal(await adapter.locator('.actools-lesson').evaluate((el)=>el.style.getPropertyValue('--actools-lesson-header-offset')),'122px');
  await adapter.evaluate(()=>{ const root=document.documentElement; root.style.setProperty('--actools-header-height','88px'); root.style.setProperty('--actools-header-admin-offset','34px'); root.classList.add('actools-sticky-header-active'); });
  await tick(adapter);
  assert.equal(await adapter.locator('.actools-lesson').evaluate((el)=>el.style.getPropertyValue('--actools-lesson-header-offset')),'');
  assert.equal(await adapter.evaluate(()=>document.documentElement.style.getPropertyValue('--actools-header-height')),'88px');
  assert.equal(await adapter.locator('#page>header').getAttribute('style'),null);
  assert.equal(await adapter.locator('#page>header').getAttribute('class'),null);
  checks.themeAdapterNativeHandoff = true;
  checks.sharedLayoutBuilder = await runLayoutBuilderRegression({browser,baseURL,moduleRoot,outputDir});
  checks.figurePlacement = await runFigurePlacementRegression({browser,baseURL,moduleRoot,outputDir});
  checks.editorActionStyle = await runEditorActionStyleRegression({browser,baseURL,moduleRoot});
  assert.deepEqual(errors,[]);
  checks.browserErrors = errors;
  return checks;
}
