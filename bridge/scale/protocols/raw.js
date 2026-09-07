'use strict';

/**
 * پارسر «هر عددی که دیدی».
 *
 * پروتکل TEC هنوز مستند نشده، پس این تور ایمنی است: اولین عددِ اعشاری یا
 * صحیح در فریم را وزن حساب می‌کند و پرچم پایداری را از حروف رایج حدس می‌زند.
 *
 * برای راه‌اندازی اولیه خوب است، برای تولید نه — چون فریمی که دو عدد دارد
 * (مثلاً وزن و شماره‌ی باسکول) اشتباه خوانده می‌شود. به‌محض اینکه نمونه‌ی
 * واقعیِ TEC را داشتیم، protocols/tec.js جایش را می‌گیرد.
 */
module.exports = {
    name: 'raw',

    // فریم‌ها معمولاً با CR یا LF تمام می‌شوند
    delimiter: /[\r\n]+/,

    parse(frame) {
        const text = frame.trim();

        if (text === '') return null;

        const match = text.match(/[-+]?\d+(?:\.\d+)?/);

        if (!match) return null;

        const weight = Number(match[0]);

        if (!Number.isFinite(weight)) return null;

        // ST/S = stable، US/U = unstable — قرارداد رایج، نه قانون
        const stable = /\bST\b|^S[,\s]/i.test(text) ? true : /\bUS\b|^U[,\s]/i.test(text) ? false : null;

        return {
            weightKg: weight,
            // وقتی دستگاه پرچم نمی‌دهد، «پایدار» فرض می‌کنیم و در تنظیمات
            // سامانه اجبارِ پایداری خاموش می‌شود. اینجا دروغ نمی‌گوییم.
            isStable: stable === null ? true : stable,
            stableKnown: stable !== null,
            raw: text.slice(0, 128),
        };
    },
};
