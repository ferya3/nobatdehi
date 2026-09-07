<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Sms\SmsManager;
use App\Support\Mobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSmsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        return [
            'sms_enabled' => ['required', 'boolean'],
            'sms_provider' => ['required', Rule::in(array_keys(SmsManager::providers()))],
            'sms_sender' => ['nullable', 'string', 'max:32'],
            'sms_username' => ['nullable', 'string', 'max:120'],
            'sms_password' => ['nullable', 'string', 'max:200'],
            'sms_api_key' => ['nullable', 'string', 'max:400'],
            'sms_afe_domain' => ['nullable', 'string', 'max:200'],
            'sms_custom_url' => ['nullable', 'string', 'max:600'],
            'sms_custom_method' => ['required', Rule::in(['GET', 'POST'])],
            'sms_optout' => ['nullable', 'string', 'max:40'],
            'sms_manager_recipients' => ['nullable', 'string', 'max:400'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ($this->managerRecipients() as $mobile) {
                    if (! Mobile::isValid($mobile)) {
                        $validator->errors()->add(
                            'sms_manager_recipients',
                            "شماره «{$mobile}» معتبر نیست.",
                        );

                        return;
                    }
                }

                $url = trim((string) $this->input('sms_custom_url'));

                if ($this->input('sms_provider') === 'custom' && $url === '') {
                    $validator->errors()->add('sms_custom_url', 'برای پنل سفارشی، لینک ارسال لازم است.');
                }

                if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
                    $validator->errors()->add('sms_custom_url', 'لینک باید با http:// یا https:// شروع شود.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'sms_provider.required' => 'سرویس‌دهنده پیامک را انتخاب کنید.',
            'sms_provider.in' => 'سرویس‌دهنده انتخاب‌شده معتبر نیست.',
        ];
    }

    /** @return array<int, string> */
    public function managerRecipients(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->input('sms_manager_recipients')),
        )));
    }

    /** @return array<string, string> مقادیر آماده برای ذخیره */
    public function settings(): array
    {
        $normalized = array_map(
            fn (string $mobile) => Mobile::normalize($mobile) ?? $mobile,
            $this->managerRecipients(),
        );

        return [
            'sms_enabled' => $this->boolean('sms_enabled') ? '1' : '0',
            'sms_provider' => $this->string('sms_provider')->toString(),
            'sms_sender' => trim((string) $this->input('sms_sender')),
            'sms_username' => trim((string) $this->input('sms_username')),
            // خالی یعنی «دست نزن» — Setting::putMany اسرار خالی را رد می‌کند
            'sms_password' => (string) $this->input('sms_password'),
            'sms_api_key' => (string) $this->input('sms_api_key'),
            'sms_afe_domain' => trim((string) $this->input('sms_afe_domain')),
            'sms_custom_url' => trim((string) $this->input('sms_custom_url')),
            'sms_custom_method' => $this->string('sms_custom_method')->toString(),
            'sms_optout' => trim((string) $this->input('sms_optout')),
            'sms_manager_recipients' => implode(',', $normalized),
        ];
    }
}
