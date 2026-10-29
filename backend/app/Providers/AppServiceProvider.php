<?php

namespace App\Providers;

use App\Models;
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
