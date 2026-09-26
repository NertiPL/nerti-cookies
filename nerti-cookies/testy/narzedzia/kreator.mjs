// Przejście kreatora w przeglądarce jako admin (lokalnie): zrzut każdego kroku.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const require = createRequire(import.meta.url);
const pw = require('C:/Claude/websites/.claude/skills/web-core/scripts/node_modules/playwright');
const b = await pw.chromium.launch(); const c = await b.newContext({ viewport: { width: 1280, height: 900 } });
await c.addCookies(JSON.parse(readFileSync(process.argv[2], 'utf8')));
const s = await c.newPage();
const errs = []; s.on('pageerror', e => errs.push(e.message));
for (let k = 1; k <= 4; k++) {
  await s.goto('http://try.local/wp-admin/admin.php?page=nerti-cookies-kreator&krok=' + k); await s.waitForTimeout(600);
  await s.screenshot({ path: process.argv[3] + '/kreator-' + k + '.png', fullPage: true });
  console.log('krok', k, (await s.locator('.nc-kreator__tresc h2').first().textContent()));
}
console.log('bledy JS:', errs);
await b.close();
