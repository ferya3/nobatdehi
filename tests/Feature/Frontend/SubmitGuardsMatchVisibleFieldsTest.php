<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * دکمه‌ی ثبت نباید چیزی بخواهد که فرم نشان نمی‌دهد.
 *
 * این باگ یک بار روی باسکول اتفاق افتاد: شرط باز شدنِ دکمه «دلیل ورود دستی»
 * را لازم داشت، ولی آن فیلد فقط وقتی رسم می‌شد که پل باسکول روشن باشد. روی
 * نصبی که باسکول وصل نیست، اپراتور وزن را تایپ می‌کرد و دکمه تا ابد خاکستری
 * می‌ماند — بدون هیچ پیغامی که بگوید چه کم است.
 *
 * قاعده‌ی ساده‌ای که این آزمون نگه می‌دارد: اگر شرطِ دکمه به فیلدی وابسته
 * است که با v-if رسم می‌شود، همان شرطِ v-if باید داخل شرطِ دکمه هم دیده شود.
 */
final class SubmitGuardsMatchVisibleFieldsTest extends TestCase
{
    #[Test]
    public function test_submit_guards_never_require_a_field_the_form_hides(): void
    {
        $offenders = [];

        foreach ($this->vueFiles() as $path) {
            $code = (string) file_get_contents($path);
            $guards = $this->submitGuards($code);

            if ($guards === []) {
                continue;
            }

            foreach ($this->conditionalFields($code) as $field => $visibleWhen) {
                foreach ($guards as $name => $body) {
                    if (! str_contains($body, "form.{$field}")) {
                        continue;
                    }

                    if (str_contains($body, $visibleWhen)) {
                        continue;
                    }

                    $relative = str_replace(base_path().'/', '', $path);
                    $offenders[] = "{$relative}: «{$name}» فیلد «{$field}» را لازم دارد، "
                        ."ولی آن فیلد فقط با «{$visibleWhen}» رسم می‌شود.";
                }
            }
        }

        $this->assertSame([], $offenders, "شرط دکمه به فیلدی وابسته است که ممکن است اصلاً رسم نشود:\n".implode("\n", $offenders));
    }

    /** @return list<string> */
    private function vueFiles(): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * محاسبه‌هایی که باز و بسته بودن دکمه را تعیین می‌کنند — قرارداد نام: canX
     *
     * @return array<string, string> نام => بدنه
     */
    private function submitGuards(string $code): array
    {
        preg_match_all('/const (can[A-Z]\w*) = computed\(\(\) => \{(.*?)\n\}\);/s', $code, $blocks, PREG_SET_ORDER);
        preg_match_all('/const (can[A-Z]\w*) = computed\(\(\) => ([^\n]+)\);/', $code, $inline, PREG_SET_ORDER);

        $guards = [];

        foreach ([...$blocks, ...$inline] as $match) {
            $guards[$match[1]] ??= $match[2];
        }

        return $guards;
    }

    /**
     * فیلدهایی که شرطی رسم می‌شوند: نامشان (for) => شرط رسم شدن (v-if)
     *
     * @return array<string, string>
     */
    private function conditionalFields(string $code): array
    {
        $fields = [];

        foreach (array_slice(explode('<FormField', $code), 1) as $chunk) {
            $end = strpos($chunk, '>');

            if ($end === false) {
                continue;
            }

            $tag = substr($chunk, 0, $end);

            if (! preg_match('/\bv-if="([^"]+)"/', $tag, $when)) {
                continue;
            }

            if (! preg_match('/\bfor="([^"]+)"/', $tag, $name)) {
                continue;
            }

            $fields[$name[1]] = $when[1];
        }

        return $fields;
    }
}
