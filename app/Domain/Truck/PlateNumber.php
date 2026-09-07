<?php

declare(strict_types=1);

namespace App\Domain\Truck;

use App\Support\Digits;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * پلاک خودروی ایرانی: ۱۲ ب ۳۴۵ ایران ۶۷
 */
final class PlateNumber implements JsonSerializable, Stringable
{
    /** حروف مجاز پلاک شخصی/بار در ایران */
    public const LETTERS = [
        'الف', 'ب', 'پ', 'ت', 'ث', 'ج', 'چ', 'ح', 'خ', 'د', 'ذ', 'ر', 'ز', 'ژ',
        'س', 'ش', 'ص', 'ض', 'ط', 'ظ', 'ع', 'غ', 'ف', 'ق', 'ک', 'گ', 'ل', 'م',
        'ن', 'و', 'ه', 'ی', 'معلولین',
    ];

    public function __construct(
        public readonly string $two,
        public readonly string $letter,
        public readonly string $three,
        public readonly string $iran,
    ) {
        if (! preg_match('/^\d{2}$/', $this->two)) {
            throw new InvalidArgumentException('بخش دو رقمی پلاک نامعتبر است.');
        }

        if (! in_array($this->letter, self::LETTERS, true)) {
            throw new InvalidArgumentException('حرف پلاک نامعتبر است.');
        }

        if (! preg_match('/^\d{3}$/', $this->three)) {
            throw new InvalidArgumentException('بخش سه رقمی پلاک نامعتبر است.');
        }

        if (! preg_match('/^\d{2}$/', $this->iran)) {
            throw new InvalidArgumentException('کد ایران پلاک نامعتبر است.');
        }
    }

    public static function make(?string $two, ?string $letter, ?string $three, ?string $iran): self
    {
        return new self(
            str_pad(Digits::toLatin($two), 2, '0', STR_PAD_LEFT),
            trim((string) $letter),
            str_pad(Digits::toLatin($three), 3, '0', STR_PAD_LEFT),
            str_pad(Digits::toLatin($iran), 2, '0', STR_PAD_LEFT),
        );
    }

    public static function tryMake(?string $two, ?string $letter, ?string $three, ?string $iran): ?self
    {
        try {
            return self::make($two, $letter, $three, $iran);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * خواندنِ پلاک‌خوان را به همان کلیدی تبدیل می‌کند که در دیتابیس است.
     *
     * دستگاه‌ها یکسان نمی‌نویسند: «۱۲ب۳۴۵ایران۶۷»، «12 ب 345 - 67»،
     * «12ب34567». همه‌ی این‌ها باید به یک کلید برسند، وگرنه هر خواندنِ درست
     * هم «مغایرت پلاک» می‌شود و کل قانون بی‌اثر.
     *
     * اگر رشته اصلاً پلاک نباشد null برمی‌گردد — که در گیت یعنی مغایرت.
     */
    public static function normalizeKey(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $text = Digits::toLatin(trim($raw));

        // «ایران» و جداکننده‌ها حذف می‌شوند تا فقط ارقام و حرف بماند
        $text = str_replace(['ايران', 'ایران', 'IRAN', 'Iran', 'iran'], ' ', $text);
        $text = str_replace(['-', '_', '.', '،', ',', '|', '/', '\\'], ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        // «الف» و «معلولین» چندحرفی‌اند و باید قبل از حروف تک‌کاراکتری بیایند
        $letters = self::LETTERS;
        usort($letters, static fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $alternation = implode('|', array_map(
            static fn (string $l) => preg_quote($l, '/'),
            $letters,
        ));

        if (! preg_match('/^(\d{2})\s*('.$alternation.')\s*(\d{3})\s*(\d{2})$/u', $text, $m)) {
            return null;
        }

        return self::tryMake($m[1], $m[2], $m[3], $m[4])?->key();
    }

    /** کلید یکتای جستجو: 12-ب-345-67 */
    public function key(): string
    {
        return "{$this->two}-{$this->letter}-{$this->three}-{$this->iran}";
    }

    /** نمایش کوتاه برای جدول‌ها: ۱۲ ب ۳۴۵ | ۶۷ */
    public function short(): string
    {
        return sprintf(
            '%s %s %s | %s',
            Digits::toPersian($this->two),
            $this->letter,
            Digits::toPersian($this->three),
            Digits::toPersian($this->iran),
        );
    }

    public function full(): string
    {
        return sprintf(
            '%s %s %s ایران %s',
            Digits::toPersian($this->two),
            $this->letter,
            Digits::toPersian($this->three),
            Digits::toPersian($this->iran),
        );
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return [
            'two' => $this->two,
            'letter' => $this->letter,
            'three' => $this->three,
            'iran' => $this->iran,
            'key' => $this->key(),
            'short' => $this->short(),
            'full' => $this->full(),
        ];
    }

    public function __toString(): string
    {
        return $this->full();
    }
}
