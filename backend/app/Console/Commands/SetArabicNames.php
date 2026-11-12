<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 6 (B6): sets users.name_ar for the demo accounts (matched by their
 * English name from the seeders). Idempotent; other users are not touched.
 */
#[Signature('namaa:users:arabic-names')]
#[Description('Set the Arabic display names of the demo accounts.')]
class SetArabicNames extends Command
{
    /** English name => Arabic name. @var array<string, string> */
    public const NAMES = [
        'Demo Admin' => 'مدير العرض',
        'Ahmed' => 'أحمد',
        'Khalid' => 'خالد',
        'Makkah Municipality Viewer' => 'مراقب أمانة العاصمة المقدسة',
        'Safwa Admin' => 'مدير الصفوة',
        'Saeed' => 'سعيد',
    ];

    public function handle(): int
    {
        $updated = 0;
        foreach (self::NAMES as $english => $arabic) {
            $updated += User::where('name', $english)
                ->where(fn ($q) => $q->whereNull('name_ar')->orWhere('name_ar', '!=', $arabic))
                ->update(['name_ar' => $arabic]);
        }

        $this->info(sprintf('Arabic names set on %d user(s).', $updated));
        Log::info('namaa:users:arabic-names', ['updated' => $updated]);

        return self::SUCCESS;
    }
}
