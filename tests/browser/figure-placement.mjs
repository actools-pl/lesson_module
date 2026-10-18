import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';

/**
 * Captured native Drupal reader and print responses, uploaded native PNG, actual
 * Drupal/once and module assets. No synthetic teaching-component markup.
 * Contexts live until the runner closes the browser for single-process hosts.
 */
export async function runFigurePlacementRegression({browser, baseURL, moduleRoot, outputDir}) {
  const fixtures = path.join(moduleRoot, 'tests/browser/fixtures');
  const [host, reader, print] = await Promise.all([
    fs.readFile(path.join(fixtures, 'figure-placement-host.html'), 'utf8'),
    fs.readFile(path.join(fixtures, 'figure-placement-reader.html'), 'utf8'),
    fs.readFile(path.join(fixtures, 'figure-placement-print.html'), 'utf8'),
  ]);
  for (const markup of [reader, print]) {
    assert(!/<(?:script|form|input|textarea|select)\b/i.test(markup), 'Captured public reader must contain no scripts, settings or form credentials.');
    assert(!/form_token|form_build_id|drupalSettings/.test(markup), 'No private Drupal form/settings data belongs in the fixture.');
  }
  const errors = [];
  const responses = [];
  const watch = (context) => context.on('page', (page) => {
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('response', (response) => {
      if (response.status() >= 400) responses.push(`${response.status()} ${response.url()}`);
    });
  });
  const context = await browser.newContext({viewport: {width: 1280, height: 1000}});
  watch(context);
  const open = async (markup, targetContext = context) => {
    const page = await targetContext.newPage();
    // Serve the intact captured fragment in the initial HTML response. This
    // also exercises ordinary native disclosures when JavaScript is disabled.
    await page.route('**/fixtures/figure-placement-host.html', (route) => route.fulfill({
      status: 200, contentType: 'text/html; charset=utf-8',
      body: host.replace('<!-- ACTOOLS_FIGURE_MARKUP -->', markup),
    }));
    await page.goto(`${baseURL}/fixtures/figure-placement-host.html`);
    assert.equal(await page.locator('[data-actools-reader]').count(), 1);
    assert.equal(await page.locator('.actools-answer--solution').count(), 1);
    assert.equal(await page.locator('.actools-figure img').count(), 1, 'Attached figure must render once, with no standalone duplicate.');
    assert.equal(await page.locator('.actools-answer--solution .actools-figure img').count(), 1);
    const ids = await page.locator('[id]').evaluateAll((nodes) => nodes.map((node) => node.id));
    assert.equal(new Set(ids).size, ids.length, 'Captured native component IDs must remain unique.');
    return page;
  };
  const solution = (page) => page.locator('.actools-answer--solution');
  const image = (page) => solution(page).locator('.actools-figure img');
  const caption = (page) => solution(page).locator('.actools-figure figcaption');
  const noOverflow = async (page) => assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Lesson must fit the phone viewport.');
  const assertUncropped = async (page) => {
    await image(page).evaluate((element) => element.decode());
    const metrics = await image(page).evaluate((element) => {
      const box = element.getBoundingClientRect();
      const parent = element.closest('.actools-figure').getBoundingClientRect();
      return {width:box.width, height:box.height, left:box.left, right:box.right, parentLeft:parent.left, parentRight:parent.right, naturalWidth:element.naturalWidth, naturalHeight:element.naturalHeight, fit:getComputedStyle(element).objectFit};
    });
    assert.equal(metrics.naturalWidth, 1200);
    assert.equal(metrics.naturalHeight, 600);
    assert(metrics.width > 0 && metrics.height > 0);
    assert(Math.abs(metrics.width / metrics.height - 2) < 0.01, 'A wide scientific diagram must retain its complete aspect ratio.');
    assert.equal(metrics.fit, 'contain');
    assert(metrics.left >= metrics.parentLeft - 1 && metrics.right <= metrics.parentRight + 1, 'Image must stay within its own figure.');
    return metrics;
  };

  const page = await open(reader);
  assert.equal(await solution(page).evaluate((element) => element.open), false);
  assert.equal(await image(page).isVisible(), false, 'Solution figure must stay hidden until the native disclosure is opened.');
  assert.equal(await caption(page).isVisible(), false);
  const alt = await image(page).getAttribute('alt');
  const captionText = (await caption(page).textContent()).trim();
  assert(alt && alt.trim(), 'Native upload must retain meaningful alternative text.');
  assert(captionText, 'Native upload must retain its authored caption.');
  await solution(page).locator(':scope > summary').click();
  assert.equal(await image(page).isVisible(), true);
  assert.equal(await caption(page).isVisible(), true);
  assert.equal(await solution(page).getByRole('link', {name:alt, exact:true}).count(), 1, 'Full-resolution diagram link must use its individual image description.');
  assert.equal(await image(page).locator('..').getAttribute('href'), '/fixtures/figure-placement-diagram.png');
  await assertUncropped(page);
  await solution(page).locator(':scope > summary').click();
  assert.equal(await image(page).isVisible(), false);

  // The shared reader's anchor helper must reveal an attached image inside a
  // closed solution without moving or copying the actual figure component.
  const componentID = await solution(page).locator('.actools-component--figure').getAttribute('id');
  assert(componentID, 'Attached figure must retain its stable component anchor.');
  await page.evaluate((id) => { window.location.hash = id; }, componentID);
  await page.waitForFunction(() => document.querySelector('.actools-answer--solution').open);
  assert.equal(await image(page).isVisible(), true);
  assert.equal(await page.locator('.actools-figure img').count(), 1);
  await page.evaluate(() => { history.replaceState(null, '', location.pathname); document.querySelector('.actools-answer--solution').open = false; });

  // Ctrl+P uses real reader.js before/afterprint listeners. Dedicated print
  // markup is tested separately below with all application JavaScript disabled.
  await page.emulateMedia({media:'print'});
  await page.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
  assert.equal(await solution(page).evaluate((element) => element.open), true);
  assert.equal(await image(page).isVisible(), true);
  await assertUncropped(page);
  await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
  assert.equal(await solution(page).evaluate((element) => element.open), false, 'Print must restore the reader’s closed solution state.');
  await page.emulateMedia({media:'screen'});
  await page.setViewportSize({width:320, height:800});
  await solution(page).locator(':scope > summary').click();
  await noOverflow(page);
  const phoneDiagram = await assertUncropped(page);
  if (outputDir) await page.screenshot({path:path.join(outputDir, 'attached-solution-figure-phone.png'), fullPage:true});

  const noJS = await browser.newContext({javaScriptEnabled:false, viewport:{width:320, height:800}});
  watch(noJS);
  const plain = await open(reader, noJS);
  assert.equal(await solution(plain).evaluate((element) => element.open), false);
  assert.equal(await image(plain).isVisible(), false);
  await solution(plain).locator(':scope > summary').click();
  assert.equal(await image(plain).isVisible(), true, 'Native HTML disclosure must reveal its diagram without JavaScript.');
  await noOverflow(plain);
  await assertUncropped(plain);
  const printed = await open(print, noJS);
  assert.equal(await solution(printed).evaluate((element) => element.open), true, 'Dedicated native print response must emit an open solution.');
  assert.equal(await image(printed).isVisible(), true);
  assert.equal(await image(printed).getAttribute('alt'), alt);
  assert.equal((await caption(printed).textContent()).trim(), captionText);
  await printed.emulateMedia({media:'print'});
  await assertUncropped(printed);
  if (outputDir) await printed.pdf({path:path.join(outputDir, 'attached-solution-figure-print.pdf'), format:'A4', printBackground:true});
  assert.deepEqual(errors, []);
  assert.deepEqual(responses, []);
  return {actualNativeReaderAndPrintMarkup:true, uploadedPNG:{width:1200,height:600}, onceOnly:true, solutionInitiallyHidden:true, nativeDisclosure:true, componentAnchorRevealsSolution:true, browserPrintOpensAndRestores:true, dedicatedPrintOpenWithoutJS:true, noJS:true, phone320:true, uncroppedDiagram:true, phoneDiagram:{width:phoneDiagram.width,height:phoneDiagram.height}, browserErrors:errors, failedResponses:responses};
}

/** Bounded Bootstrap variable probe; this is not an installed-theme test. */
export async function runEditorActionStyleRegression({browser, baseURL, moduleRoot}) {
  const markup = await fs.readFile(path.join(moduleRoot, 'tests/browser/fixtures/editor-action-buttons.html'), 'utf8');
  const context = await browser.newContext({viewport:{width:1000,height:600}, colorScheme:'light'});
  const page = await context.newPage();
  await page.goto(`${baseURL}/fixtures/empty.html`);
  // These explicit fixture declarations exercise the Bootstrap button variable
  // contract. Actual module CSS supplies the scoped secondary correction.
  await page.addStyleTag({content:`
    .btn{padding:.5rem 1rem;color:var(--bs-btn-color);background-color:var(--bs-btn-bg);border:1px solid var(--bs-btn-border-color)}
    .btn-primary{--bs-btn-color:#fff;--bs-btn-bg:#0d6efd;--bs-btn-border-color:#0d6efd;--bs-btn-hover-color:#fff;--bs-btn-hover-bg:#0b5ed7;--bs-btn-hover-border-color:#0a58ca}
    .btn:hover{color:var(--bs-btn-hover-color);background-color:var(--bs-btn-hover-bg);border-color:var(--bs-btn-hover-border-color)}
  `});
  await page.addStyleTag({url:`${baseURL}/module/css/author.css`});
  await page.addStyleTag({url:`${baseURL}/module/css/bs5-integration.css`});
  await page.locator('#fixture').evaluate((root, markup) => {
    root.innerHTML = `<div class="actools-managed-form"><div class="actools-editorial-actions">${markup}<input type="submit" name="unrelated_primary_action" value="Unrelated primary action" class="button button--primary btn btn-primary"></div></div>`;
    // Audited actools_bs5 input-submit Twig adds both classes to all submits,
    // including native Drupal buttons whose semantic role is secondary.
    root.querySelectorAll('input').forEach((input) => input.classList.add('btn', 'btn-primary'));
  }, markup);
  const publish = page.locator('[name="actools_lesson_publish"]');
  const unpublish = page.locator('[name="actools_lesson_unpublish"]');
  const unrelated = page.locator('[name="unrelated_primary_action"]');
  const colors = (button) => button.evaluate((element) => {
    const style = getComputedStyle(element);
    return {background:style.backgroundColor, color:style.color, border:style.borderTopColor};
  });
  assert.equal(await unpublish.evaluate((element) => element.classList.contains('button--secondary') && !element.classList.contains('button--primary')), true);
  assert.equal(await publish.evaluate((element) => element.classList.contains('button--primary')), true);
  assert.deepEqual(await colors(unpublish), {background:'rgb(255, 255, 255)', color:'rgb(23, 45, 56)', border:'rgb(200, 213, 219)'});
  const blue = {background:'rgb(13, 110, 253)', color:'rgb(255, 255, 255)', border:'rgb(13, 110, 253)'};
  assert.deepEqual(await colors(publish), blue);
  assert.deepEqual(await colors(unrelated), blue);
  await unpublish.hover();
  assert.deepEqual(await colors(unpublish), {background:'rgb(234, 244, 242)', color:'rgb(23, 45, 56)', border:'rgb(20, 102, 106)'});
  assert.deepEqual(await colors(publish), blue);
  assert.deepEqual(await colors(unrelated), blue);
  const blueHover = {background:'rgb(11, 94, 215)', color:'rgb(255, 255, 255)', border:'rgb(10, 88, 202)'};
  await publish.hover();
  assert.deepEqual(await colors(publish), blueHover);
  await unrelated.hover();
  assert.deepEqual(await colors(unrelated), blueHover);
  return {actualNativeButtonFragment:true, auditedSubmitClassHandoff:true, unpublishSemanticSecondary:true, unpublishWhiteAndTintedHover:true, publishBlueUnchanged:true, unrelatedPrimaryUnchanged:true, scope:'Focused Bootstrap button-variable probe with actual module author.css and bs5-integration.css; not a full installed-theme test.'};
}
