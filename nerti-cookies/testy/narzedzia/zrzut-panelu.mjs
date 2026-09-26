import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const require = createRequire(import.meta.url);
const pw = require('C:/Claude/websites/.claude/skills/web-core/scripts/node_modules/playwright');
const b = await pw.chromium.launch(); const c = await b.newContext({ viewport: { width: 1440, height: 900 } });
await c.addCookies(JSON.parse(readFileSync(process.argv[2], 'utf8')));
const s = await c.newPage();
for (const tab of process.argv.slice(4)) {
  await s.goto('about:blank'); await s.goto('http://try.local/wp-admin/admin.php?page=nerti-cookies#nc-' + tab); await s.waitForTimeout(700);
  await s.screenshot({ path: process.argv[3] + '/panel-' + tab + '.png', fullPage: true });
}
await b.close();
