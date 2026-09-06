import { chromium } from 'playwright';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const page = await browser.newPage({ viewport: { width: 420, height: 900 } });
page.on('console', (m) => console.log('CONSOLE', m.type(), m.text()));
page.on('pageerror', (e) => console.log('PAGEERROR', String(e)));

await page.goto('http://localhost:8000/queue/login', { waitUntil: 'networkidle' });
await page.fill('#mobile', '09121001001');
await page.click('button[type=submit]');
await page.waitForURL('**/queue/otp', { waitUntil: 'networkidle' });
const code = (await page.locator('.num.font-mono').textContent())?.trim() ?? '';
console.log('code =', JSON.stringify(code));
for (let i = 0; i < code.length; i++) await page.locator('input[inputmode=numeric]').nth(i).fill(code[i]);
await page.waitForTimeout(3000);
console.log('url =', page.url());
console.log('errors on page:', await page.evaluate(() => document.body.innerText.slice(0, 400)));
await browser.close();
