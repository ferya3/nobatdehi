<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Slot\SlotGenerator;
use App\Models\Factory;
use Illuminate\Console\Command;

class GenerateSlots extends Command
{
    protected $signature = 'slots:generate {--factory= : slug کارخانه}';

    protected $description = 'ساخت اسلات‌های ظرفیت برای افق نوبت‌دهی';

    public function handle(SlotGenerator $generator): int
    {
        $factories = Factory::query()
            ->when($this->option('factory'), fn ($q, $slug) => $q->where('slug', $slug))
            ->where('is_active', true)
            ->get();

        if ($factories->isEmpty()) {
            $this->error('کارخانه‌ی فعالی پیدا نشد.');

            return self::FAILURE;
        }

        foreach ($factories as $factory) {
            $count = $generator->generateHorizon($factory)->count();
            $this->info("«{$factory->name}»: {$count} اسلات آماده شد.");
        }

        return self::SUCCESS;
    }
}
