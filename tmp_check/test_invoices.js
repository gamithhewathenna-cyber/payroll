const puppeteer = require('puppeteer');
const path = require('path');

(async () => {
  const browser = await puppeteer.launch({
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    headless: 'new',
  });
  const page = await browser.newPage();
  const consoleMsgs = [];
  page.on('console', msg => consoleMsgs.push(msg.text()));
  page.on('pageerror', err => consoleMsgs.push('PAGEERROR: ' + err.message));

  const fileUrl = 'file://' + path.resolve(__dirname, 'invoices_test.html').replace(/\\/g, '/');
  await page.goto(fileUrl);

  const before = await page.evaluate(() => {
    const el = document.getElementById('customMonthModal');
    return el ? { exists: true, classList: el.className, display: getComputedStyle(el).display } : { exists: false };
  });
  console.log('BEFORE CLICK:', JSON.stringify(before));

  // Click the Custom button
  const buttons = await page.$$('button');
  let clicked = false;
  for (const b of buttons) {
    const text = await page.evaluate(el => el.textContent, b);
    if (text.includes('Custom')) { await b.click(); clicked = true; break; }
  }
  console.log('Clicked Custom button:', clicked);

  await new Promise(r => setTimeout(r, 200));

  const after = await page.evaluate(() => {
    const el = document.getElementById('customMonthModal');
    if (!el) return { exists: false };
    const cs = getComputedStyle(el);
    return { exists: true, classList: el.className, display: cs.display, visibility: cs.visibility, opacity: cs.opacity, zIndex: cs.zIndex };
  });
  console.log('AFTER CLICK:', JSON.stringify(after));
  console.log('CONSOLE MESSAGES:', JSON.stringify(consoleMsgs));

  await browser.close();
})();
