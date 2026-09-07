#!/usr/bin/env node
'use strict';

/**
 * پلِ باسکول: پورت COM را می‌خواند و هر عدد تازه را به سامانه می‌فرستد.
 *
 * روی همان کامپیوتری اجرا می‌شود که نشان‌دهنده به آن وصل است. دو حالت دارد:
 *
 *   node bridge.js --sniff     فقط نشان می‌دهد دستگاه چه می‌فرستد (بدون ارسال)
 *   node bridge.js             حالت عادی: می‌خواند و POST می‌کند
 *
 * چرا --sniff هست: پروتکل TEC مستند نیست. اول با این می‌بینیم فریم چه شکلی
 * است، بعد پارسر دقیقش نوشته می‌شود. تا آن موقع پارسر raw کار را راه می‌اندازد.
 */

const fs = require('fs');
const path = require('path');

// ---------------------------------------------------------------- تنظیمات

const CONFIG_PATH = path.join(__dirname, 'config.json');

function loadConfig() {
    if (!fs.existsSync(CONFIG_PATH)) {
        console.error(`فایل تنظیمات پیدا نشد: ${CONFIG_PATH}`);
        console.error('config.example.json را کپی کنید به config.json و پرش کنید.');
        process.exit(1);
    }

    try {
        return JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
    } catch (error) {
        console.error(`config.json خوانده نشد: ${error.message}`);
        process.exit(1);
    }
}

// ------------------------------------------------------------------ ارسال

/**
 * ارسال یک خواندن به سامانه.
 *
 * خطا سرویس را نمی‌خواباند: شبکه‌ی اتاقک باسکول قطع و وصل می‌شود و یک
 * خواندنِ از دست رفته مهم نیست — خواندنِ بعدی چند صد میلی‌ثانیه بعد می‌آید.
 * چیزی که مهم است این است که پل زنده بماند.
 */
async function send(config, reading, scaleName) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 5000);

    try {
        const response = await fetch(config.url, {
            method: 'POST',
            signal: controller.signal,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Device-Token': config.token,
            },
            body: JSON.stringify({
                scale: scaleName,
                weight_kg: reading.weightKg,
                stable: reading.isStable,
                unit: config.unit || 'kg',
                device: config.deviceName || require('os').hostname(),
                raw: reading.raw,
            }),
        });

        if (!response.ok) {
            const body = await response.text().catch(() => '');
            warn(`سرور ${response.status} داد: ${body.slice(0, 200)}`);

            return false;
        }

        return true;
    } catch (error) {
        warn(`ارسال ناموفق: ${error.message}`);

        return false;
    } finally {
        clearTimeout(timeout);
    }
}

// -------------------------------------------------------------------- لاگ

const stamp = () => new Date().toLocaleTimeString('en-GB');
const info = (message) => console.log(`[${stamp()}] ${message}`);
const warn = (message) => console.warn(`[${stamp()}] ! ${message}`);

// ------------------------------------------------------------------ اجرا

function openPort(SerialPort, scale) {
    return new SerialPort({
        path: scale.port,
        baudRate: scale.baudRate ?? 9600,
        dataBits: scale.dataBits ?? 8,
        parity: scale.parity ?? 'none',
        stopBits: scale.stopBits ?? 1,
        autoOpen: false,
    });
}

function run() {
    const config = loadConfig();
    const sniffing = process.argv.includes('--sniff');

    let SerialPort;

    try {
        ({ SerialPort } = require('serialport'));
    } catch {
        console.error('بسته‌ی serialport نصب نیست. اول «npm install» را بزنید.');
        process.exit(1);
    }

    if (!Array.isArray(config.scales) || config.scales.length === 0) {
        console.error('در config.json حداقل یک باسکول تعریف کنید.');
        process.exit(1);
    }

    if (!sniffing && (!config.url || !config.token)) {
        console.error('برای حالت عادی، url و token در config.json لازم است.');
        process.exit(1);
    }

    if (sniffing) {
        info('حالت بررسی: چیزی به سامانه فرستاده نمی‌شود.');
        info('یک وزنه روی باسکول بگذارید و خروجی زیر را برای من بفرستید.');
        info('─'.repeat(60));
    }

    for (const scale of config.scales) {
        watchScale(SerialPort, config, scale, sniffing);
    }
}

function watchScale(SerialPort, config, scale, sniffing) {
    const protocol = require(`./protocols/${scale.protocol || 'raw'}.js`);

    let buffer = '';
    let lastSentAt = 0;
    let lastWeight = null;

    // فاصله‌ی حداقل بین دو ارسال. نشان‌دهنده ده‌ها بار در ثانیه می‌فرستد و
    // فرستادن همه‌ی آن‌ها فقط دیتابیس را پر می‌کند بی‌آنکه چیزی اضافه کند.
    const minGapMs = config.minGapMs ?? 400;

    const port = openPort(SerialPort, scale);

    port.on('data', (chunk) => {
        if (sniffing) {
            // بایتِ خام هم لازم است: بعضی دستگاه‌ها کاراکتر کنترلی می‌فرستند
            // که در متن دیده نمی‌شود ولی ساختار فریم را تعیین می‌کند.
            process.stdout.write(`HEX  ${chunk.toString('hex')}\n`);
            process.stdout.write(`TEXT ${JSON.stringify(chunk.toString('latin1'))}\n`);

            return;
        }

        buffer += chunk.toString('latin1');

        const parts = buffer.split(protocol.delimiter);
        buffer = parts.pop() ?? '';

        for (const frame of parts) {
            const reading = protocol.parse(frame);

            if (reading === null) continue;

            const now = Date.now();

            // عددِ تکراری در فاصله‌ی کوتاه، خبر جدیدی نیست
            if (reading.weightKg === lastWeight && now - lastSentAt < minGapMs) continue;

            lastWeight = reading.weightKg;
            lastSentAt = now;

            send(config, reading, scale.name);
        }
    });

    port.on('error', (error) => warn(`${scale.name} (${scale.port}): ${error.message}`));

    port.on('close', () => {
        warn(`${scale.name}: پورت بسته شد. ۵ ثانیه دیگر دوباره تلاش می‌شود.`);
        setTimeout(() => port.open((e) => e && warn(`${scale.name}: ${e.message}`)), 5000);
    });

    port.open((error) => {
        if (error) {
            warn(`${scale.name} (${scale.port}) باز نشد: ${error.message}`);
            warn('اگر نرم‌افزار دیگری این پورت را باز کرده باشد، اول باید بسته شود.');
            setTimeout(() => port.open((e) => e && warn(`${scale.name}: ${e.message}`)), 5000);

            return;
        }

        info(`${scale.name}: ${scale.port} @ ${scale.baudRate ?? 9600} باز شد.`);
    });
}

run();
