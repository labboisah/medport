<?php

namespace App\Providers;

use App\Http\Controllers\Api\V1\OperationsController;
use App\Models;
use App\Models\PolicySetting;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin-set policy values override the working defaults. A missing table (before migration) is ignored.
        try {
            foreach (PolicySetting::all() as $s) {
                $config = OperationsController::POLICY[$s->key]['config'] ?? null;
                if ($config && $s->value !== null) {
                    config([$config => $s->value]);
                }
            }
        } catch (\Throwable) {
        }

        // Stable record-type names stored in record_corrections.
        Relation::morphMap([
            'encounter' => Models\Encounter::class,
            'condition' => Models\Condition::class,
            'medication' => Models\Medication::class,
            'allergy' => Models\Allergy::class,
            'observation' => Models\Observation::class,
            'diagnostic_report' => Models\DiagnosticReport::class,
            'document_reference' => Models\DocumentReference::class,
            'referral' => Models\Referral::class,
        ]);
    }
}
