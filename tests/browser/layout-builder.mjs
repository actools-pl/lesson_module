import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';

/**
 * Actual captured shared render + core layout CSS. This verifies containment,
 * not Drupal's Layout Builder editing UI (covered by native Functional tests).
 */
export async function runLayoutBuilderRegression({browser, baseURL, moduleRoot, outputDir}) {
  const markup = await fs.readFile(path.join(moduleRoot, 'tests/browser/fixtures/layout-builder-canonical.html'), 'utf8');
  const errors = [];
  const context = await browser.newContext({viewport: {width: 1600, height: 1100}});
  context.on('page', (page) => page.on('pageerror', (error) => errors.push(error.message)));
  const page = await context.newPage();
  await page.goto(`${baseURL}/fixtures/layout-builder-host.html`);
  await page.addScriptTag({url: `${baseURL}/module/js/reader.js`});
  await page.addScriptTag({url: `${baseURL}/module/js/bs5-integration.js`});
  const mount = async (variant, targetPage = page, enhance = true) => {
    await targetPage.evaluate(({markup, variant, enhance}) => {
      const host = document.querySelector('#fixture');
      if (window.Drupal && enhance) Drupal.detachBehaviors(host, {}, 'unload');
      const template = document.createElement('template');
      template.innerHTML = markup;
      const documentWrapper = template.content.querySelector('.actools-lesson-layout-document');
      if (!documentWrapper) throw new Error('Actual shared render is missing its owned reader wrapper.');
      // The captured native markup stays unchanged in the baseline. Stress
      // variants move only this intact owned wrapper into exact core regions.
      template.content.querySelectorAll('[data-once]').forEach((element) => element.removeAttribute('data-once'));
      let shared;
      if (variant === 'native') shared = template.content.querySelector('.actools-lesson-shared-layout');
      else {
        shared = document.createElement('div');
        shared.className = 'actools-lesson-shared-layout';
        const layouts = {onecol:['layout--onecol',1],twocol:['layout--twocol-section layout--twocol-section--50-50',2],threecol:['layout--threecol-section layout--threecol-section--25-50-25',3],fourcol:['layout--fourcol-section',4]};
        const [classes, count] = layouts[variant];
        const layout = document.createElement('div'); layout.className = `layout ${classes}`;
        const names = count === 1 ? ['content'] : ['first','second','third','fourth'];
        for (let index = 0; index < count; index++) {
          const region = document.createElement('div'); region.className = `layout__region layout__region--${names[index]}`;
          if (index === 0) region.append(documentWrapper);
          else region.innerHTML = '<section class="fixture-companion"><h2>Additional reading</h2><p>Supplementary material stays separate from the protected lesson document.</p></section>';
          layout.append(region);
        }
        shared.append(layout);
      }
      if (!shared) throw new Error('Actual shared render is missing its outer wrapper.');
      host.replaceChildren();
      const frame = document.createElement('div'); frame.className = 'page';
      frame.innerHTML = '<header role="banner" class="fixture-page-header">Existing theme header</header><main></main>';
      frame.querySelector('main').append(shared); host.append(frame);
      // Long teaching material exercises page scroll and print fragmentation;
      // this is explicit fixture content, not a change to the stored document.
      const content = shared.querySelector('.actools-step__components');
      if (content) {
        const extra = document.createElement('section'); extra.className = 'actools-component actools-component--text';
        extra.innerHTML = Array.from({length:24}, () => '<p>The upward vertical surface force balances the weight of liquid above the reference reservoir level. Compare wetting and non-wetting surfaces before calculating the equilibrium capillary height.</p>').join('');
        content.append(extra);
        const scientific = document.createElement('section');
        scientific.className = 'actools-component actools-component--table';
        scientific.innerHTML = '<div class="actools-table-scroll" tabindex="0" role="region" aria-label="Capillary comparison table"><table><caption>Capillary comparison</caption><thead><tr><th>Tube radius (mm)</th><th>Contact angle</th><th>Surface tension (N/m)</th><th>Equilibrium height (mm)</th></tr></thead><tbody><tr><td>0.50</td><td>0 degrees</td><td>0.072</td><td>29.4</td></tr><tr><td>1.00</td><td>60 degrees</td><td>0.072</td><td>7.34</td></tr></tbody></table></div><figure class="actools-figure"><a href="/module/modules/actools_lesson_demo/assets/capillary-rise.png"><img data-fixture-diagram src="/module/modules/actools_lesson_demo/assets/capillary-rise.png" width="1800" height="960" alt="Capillary geometry with a raised meniscus and reservoir reference level"></a><figcaption>Compare the meniscus with the reservoir reference level.</figcaption></figure>';
        content.append(scientific);
      }
      shared.querySelectorAll('img').forEach((image) => {
        image.src = '/module/modules/actools_lesson_demo/assets/capillary-rise.png';
        // The normalized 1.3.0 demo master has new intrinsic dimensions.
        image.width = 1800;
        image.height = 960;
      });
      if (enhance) Drupal.attachBehaviors(shared);
      window.scrollTo(0,0);
    }, {markup, variant, enhance});
    if (enhance) await targetPage.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    else await targetPage.locator('.actools-lesson-layout-document').evaluate((element) => element.getBoundingClientRect().width);
  };
  const noOverflow = async (targetPage = page) => assert.equal(await targetPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  const columns = () => page.locator('.actools-lesson__layout').evaluate((element) => getComputedStyle(element).gridTemplateColumns.split(/\s+/));
  await mount('native');
  assert.equal(await page.locator('.actools-lesson-shared-layout').count(),1);
  assert.equal(await page.locator('.actools-lesson-layout-document').count(),1);
  assert.equal(await page.locator('.actools-lesson-layout-document h1').count(),1);
  assert.equal(await page.locator('.actools-lesson-layout-document [data-actools-reader]').count(),1);
  assert.equal(await page.locator('.actools-chapter-nav').count(),1,'Fixture must include actual chapter navigation.');
  await noOverflow();
  await page.emulateMedia({media:'print'});
  assert.equal(await page.locator('.actools-lesson-layout-document').evaluate((element) => getComputedStyle(element).containerType),'normal');
  const nativePDF = await page.pdf({format:'A4',printBackground:true});
  const nativePrintPages = (nativePDF.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length;
  assert(nativePrintPages >= 2, 'Actual native shared render must fragment across print pages.');
  if (outputDir) await fs.writeFile(path.join(outputDir,'native-shared-layout-print.pdf'),nativePDF);
  await page.emulateMedia({media:'screen'});
  await mount('onecol');
  assert.equal((await columns()).length,2);
  await page.evaluate(() => window.scrollTo(0,700));
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  assert.equal(await page.locator('.actools-chapter-nav').evaluate((element) => getComputedStyle(element).position),'sticky');
  const outlineTop = await page.locator('.actools-chapter-nav').evaluate((element) => element.getBoundingClientRect().top);
  assert(outlineTop >= 127 && outlineTop <= 129, `Sidebar should clear the112px theme header: ${outlineTop}`);
  await page.locator('.actools-chapter-nav a').first().focus();
  assert.equal(await page.locator('.actools-chapter-nav a').first().evaluate((element) => element === document.activeElement), true);
  const variants = ['twocol','threecol','fourcol'];
  for (const variant of variants) {
    await mount(variant);
    assert.equal((await columns()).length,1,`${variant} reader must stack inside its actual narrow region.`);
    assert.equal(await page.locator('.actools-chapter-nav').evaluate((element) => getComputedStyle(element).position),'static');
    assert.equal(await page.locator('.actools-lesson-layout-document h1').count(),1);
    await noOverflow();
  }
  // Core multicol CSS applies only to screens; Ctrl+P must linearize regions,
  // leave no inline-size containment, and retain the separate complete reader.
  await mount('twocol');
  await page.emulateMedia({media:'print'});
  assert.equal(await page.locator('.actools-lesson__layout').evaluate((element) => getComputedStyle(element).display),'block');
  assert.equal(await page.locator('.actools-lesson-layout-document').evaluate((element) => getComputedStyle(element).containerType),'normal');
  const regions = await page.locator('.layout--twocol-section > .layout__region').evaluateAll((elements) => elements.map((element) => ({top:element.getBoundingClientRect().top,bottom:element.getBoundingClientRect().bottom,width:element.getBoundingClientRect().width})));
  assert.equal(regions.length,2); assert(regions[1].top >= regions[0].bottom - 1); assert(Math.abs(regions[0].width - regions[1].width) <= 1);
  const pdf = await page.pdf({format:'A4',printBackground:true});
  const pages = (pdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length;
  assert(pages >= 2, 'Long composed print must fragment across pages.');
  if (outputDir) await fs.writeFile(path.join(outputDir,'shared-layout-print.pdf'),pdf);
  await page.emulateMedia({media:'screen'});
  await mount('onecol');
  await page.locator('html').evaluate((element) => { element.style.fontSize='200%'; });
  await noOverflow();
  assert.equal((await columns()).length,1);
  await page.locator('html').evaluate((element) => element.style.removeProperty('font-size'));
  await page.setViewportSize({width:320,height:800});
  await mount('twocol'); await noOverflow();
  assert.equal((await columns()).length,1);
  assert.equal(await page.locator('.actools-table-scroll').last().evaluate((element) => element.scrollWidth > element.clientWidth),true);
  await page.locator('img[data-fixture-diagram]').evaluate((image) => image.decode());
  const diagram = await page.locator('img[data-fixture-diagram]').evaluate((image) => ({width:image.getBoundingClientRect().width,height:image.getBoundingClientRect().height,naturalWidth:image.naturalWidth,naturalHeight:image.naturalHeight,fit:getComputedStyle(image).objectFit}));
  assert.equal(diagram.naturalWidth,1800);
  assert.equal(diagram.naturalHeight,960);
  assert(Math.abs(diagram.width / diagram.height - diagram.naturalWidth / diagram.naturalHeight) < 0.01,'The rendered diagram must preserve its actual intrinsic proportions.');
  assert.equal(diagram.fit,'contain');
  assert.equal(await page.locator('.actools-chapter-nav').evaluate((element) => element.open),false);
  const noJS = await browser.newContext({javaScriptEnabled:false,viewport:{width:320,height:800}});
  const plain = await noJS.newPage(); await plain.goto(`${baseURL}/fixtures/layout-builder-host.html`);
  await mount('twocol',plain,false); await noOverflow(plain);
  assert.equal(await plain.locator('.actools-chapter-nav').evaluate((element) => element.open),true);
  assert.equal(await plain.locator('.actools-lesson-layout-document h1').count(),1);
  assert.equal(await plain.locator('.actools-chapter-nav a').first().getAttribute('href') !== null,true);
  assert.deepEqual(errors,[]);
  return {actualSharedMarkup:true,coreLayouts:['native','onecol',...variants],oneProtectedHeading:true,narrowContainersStack:true,fullWidthStickyHeaderClearance:true,phone320:true,wideTableLocalScroll:true,uncroppedScientificFigure:true,text200Percent:true,noJS:true,linearPrint:true,nativePrintPages,printPages:pages,browserErrors:errors,scope:'Captured native shared render with explicit layout/content stress variants; not Layout Builder UI.'};
}
