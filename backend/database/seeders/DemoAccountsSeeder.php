<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Local-only sign-in accounts for the synthetic people, so the UI can be explored. Refuses to run outside `local`. */
class DemoAccountsSeeder extends Seeder
{
    public const PASSWORD = 'demo-password-local-only';

    public function run(): void
    {
        abort_unless(app()->environment('local'), 403, 'Demo accounts are for local development only.');

        $this->account('patient', 'ada@demo.invalid', Patient::where('given_name', 'Ada')->where('birth_date', '1990-04-12')->first(), true);
        $this->account('practitioner', 'doctor@demo.invalid', Practitioner::where('family_name', 'Doctor')->first(), false);
        $this->account('practitioner', 'nurse@demo.invalid', Practitioner::where('family_name', 'Nurse')->first(), false);
    }

    private function account(string $type, string $email, $profile, bool $patient): void
    {
        if (! $profile) {
            return;
        }
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill(['name' => $email, 'password' => self::PASSWORD, 'account_type' => $type, 'status' => 'active', 'mfa_enrolled_at' => $patient ? null : now()])->save();
        $profile->forceFill(['user_id' => $user->id])->save();
    }
}
