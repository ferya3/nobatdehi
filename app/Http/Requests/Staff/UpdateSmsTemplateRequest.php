<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Sms\SmsTemplates;
use App\Models\SmsTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSmsTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        return [
            // ۴۰۰ نویسه یعنی حدود سه پیامکِ فارسی؛ بیشتر از این را کسی نمی‌خواند
            'body' => ['required', 'string', 'min:5', 'max:400'],
            'is_active' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var SmsTemplate|null $template */
                $template = $this->route('template');

                if ($template === null) {
                    return;
                }

                $known = array_keys(SmsTemplates::variablesFor($template->key));

                // متغیری که سامانه موقع فرستادن ندارد، خام برای راننده می‌رود.
                // بهتر است همین‌جا جلویش گرفته شود تا در گوشیِ راننده دیده شود.
                preg_match_all('/\{([a-z_]+)\}/', $this->string('body')->toString(), $found);

                $unknown = array_values(array_unique(array_diff($found[1], $known)));

                if ($unknown === []) {
                    return;
                }

                $list = implode('، ', array_map(fn (string $n) => '{'.$n.'}', $unknown));

                $validator->errors()->add(
                    'body',
                    "این متغیرها در این پیامک وجود ندارند و خام ارسال می‌شوند: {$list}",
                );
            },
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'متن پیامک نمی‌تواند خالی باشد.',
            'body.min' => 'متن پیامک خیلی کوتاه است.',
            'body.max' => 'متن پیامک بیش از حد بلند است.',
        ];
    }
}
