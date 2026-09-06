import { chromium } from 'playwright';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
const page = await ctx.newPage();
const errors = [];
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
page.on('pageerror', (e) => errors.push(String(e)));

await page.goto('http://localhost:8000/panel/login', { waitUntil: 'networkidle' });
await page.fill('#email', 'operator@example.test');
await page.fill('#password', 'password');
await page.click('button[type=submit]');
await page.waitForURL((u) => !u.pathname.endsWith('/login'), { waitUntil: 'networkidle' });

// منتظر برقراری اتصال زنده
await page.waitForSelector('text=زنده', { timeout: 20000 });
console.log('اتصال زنده برقرار شد');

// وضعیت همان ردیفِ پلاک ۴۵ ج ۲۳۱ را قبل و بعد می‌سنجیم
const rowStatus = () =>
    page.evaluate(() => {
        const row = [...document.querySelectorAll('table tbody tr')].find((tr) =>
            tr.innerText.replace(/\s+/g, ' ').includes('231'),
        );
        return row ? row.innerText.replace(/\s+/g, ' ') : null;
    });

const before = await rowStatus();

// polling پشتیبان را خاموش می‌کنیم: هر به‌روزرسانی از این به بعد فقط از WebSocket است
await page.evaluate(() => {
    const highest = setInterval(() => {}, 0);
    for (let i = 0; i <= Number(highest); i++) clearInterval(i);
});

// در یک تب دیگر، نگهبانی ورود یک کامیون را ثبت می‌کند
const gate = await browser.newContext({ viewport: { width: 900, height: 900 } });
const gp = await gate.newPage();
await gp.goto('http://localhost:8000/panel/login', { waitUntil: 'networkidle' });
await gp.fill('#email', 'gate@example.test');
await gp.fill('#password', 'password');
await gp.click('button[type=submit]');
await gp.waitForURL((u) => !u.pathname.endsWith('/login'), { waitUntil: 'networkidle' });
await gp.fill('#plate_two', '45');
await gp.selectOption('#plate_letter', 'ج');
await gp.fill('#plate_three', '231');
await gp.fill('#plate_iran', '67');
await gp.click('text=جستجوی نوبت');
await gp.waitForSelector('text=ثبت ورود', { timeout: 10000 });
await gp.click('text=ثبت ورود');
await gp.waitForTimeout(1500);

// پنل اپراتور باید بدون refresh دستی و بدون polling به‌روز شده باشد
const changed = await page
    .waitForFunction(
        (previous) => {
            const row = [...document.querySelectorAll('table tbody tr')].find((tr) =>
                tr.innerText.replace(/\s+/g, ' ').includes('231'),
            );
            return row && row.innerText.replace(/\s+/g, ' ') !== previous;
        },
        before,
        { timeout: 15000 },
    )
    .then(() => true)
    .catch(() => false);

const after = await rowStatus();

console.log(JSON.stringify({
    errors,
    before: before?.slice(0, 90),
    after: after?.slice(0, 90),
    updatedViaWebSocket: changed,
}, null, 1));
await browser.close();
