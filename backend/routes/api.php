<?php

use App\Http\Controllers\Api\V1\AccessController;
use App\Http\Controllers\Api\V1\AccessRequestController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConsentController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\FacilityDirectoryController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IntegrationAdminController;
use App\Http\Controllers\Api\V1\IntegrationIngestController;
use App\Http\Controllers\Api\V1\ManualEntryController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\PatientSearchController;
use App\Http\Controllers\Api\V1\RecordController;
use App\Http\Controllers\Api\V1\ReferralController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health/live', [HealthController::class, 'live']);
    Route::get('health/ready', [HealthController::class, 'ready']);

    Route::middleware('throttle:10,1,auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
    });

    Route::middleware(['auth:sanctum', 'account'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
    });

    Route::middleware(['auth:sanctum', 'account:practitioner', 'throttle:30,1,patient-search'])->group(function () {
        Route::post('patients/search', PatientSearchController::class);
        Route::get('provider/patients/{patient}/fhir', [RecordController::class, 'providerFhir']);
        Route::get('provider/patients/{patient}/timeline', [RecordController::class, 'providerTimeline']);
        Route::post('provider/patients/{patient}/documents', [DocumentController::class, 'upload']);
        Route::post('provider/access-requests', [AccessRequestController::class, 'store']);
        Route::get('provider/access-requests', [AccessRequestController::class, 'mine']);
        Route::post('provider/patients/{patient}/encounters', [ManualEntryController::class, 'encounter']);
        Route::post('provider/patients/{patient}/referrals', [ReferralController::class, 'store']);
        Route::get('provider/referrals/incoming', [ReferralController::class, 'incoming']);
        Route::post('provider/referrals/{referral}/decision', [ReferralController::class, 'decide']);
        Route::post('access/check', [AccessController::class, 'check']);
        Route::post('share-tokens/resolve', [AccessController::class, 'resolveToken']);
    });

    Route::middleware(['auth:sanctum', 'account:patient,practitioner', 'throttle:30,1,documents'])->get('documents/{document}/download', [DocumentController::class, 'download']);

    Route::middleware(['auth:sanctum', 'account:patient,practitioner', 'throttle:60,1,notifications'])->group(function () {
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read', [NotificationController::class, 'markRead']);
        Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
        Route::put('notifications/preferences', [NotificationController::class, 'updatePreference']);
    });

    Route::middleware(['auth:sanctum', 'account:patient,practitioner,admin', 'throttle:30,1,directory'])->get('facilities', [FacilityDirectoryController::class, 'index']);

    Route::prefix('patient')->middleware(['auth:sanctum', 'account:patient', 'throttle:30,1,patient-self'])->group(function () {
        Route::get('consents', [ConsentController::class, 'index']);
        Route::post('consents', [ConsentController::class, 'store']);
        Route::post('consents/{consent}/revoke', [ConsentController::class, 'revoke']);
        Route::get('access-history', [ConsentController::class, 'history']);
        Route::post('share-tokens', [AccessController::class, 'createToken']);
        Route::get('access-requests', [AccessRequestController::class, 'index']);
        Route::post('access-requests/{accessRequest}/approve', [AccessRequestController::class, 'approve']);
        Route::post('access-requests/{accessRequest}/deny', [AccessRequestController::class, 'deny']);
        Route::get('timeline', [RecordController::class, 'patientTimeline']);
        Route::get('fhir', [RecordController::class, 'patientFhir']);
        Route::post('records/{category}/{record}/corrections', [RecordController::class, 'requestCorrection']);
    });

    Route::middleware(['integration', 'throttle:60,1,integration'])->post('integrations/records', IntegrationIngestController::class);

    Route::prefix('admin')->middleware(['auth:sanctum', 'account:admin'])->group(function () {
        Route::post('facilities/{facility}/verify', [AdminController::class, 'verifyFacility']);
        Route::post('practitioners/{practitioner}/verify', [AdminController::class, 'verifyPractitioner']);
        Route::post('practitioners/{practitioner}/roles', [AdminController::class, 'assignRole']);
        Route::post('practitioner-roles/{role}/suspend', [AdminController::class, 'suspendRole']);
        Route::post('users/{user}/disable', [AdminController::class, 'disableUser']);
        Route::get('integrations', [IntegrationAdminController::class, 'index']);
        Route::post('integrations', [IntegrationAdminController::class, 'store']);
        Route::post('integrations/{endpoint}/disable', [IntegrationAdminController::class, 'disable']);
        Route::get('integrations/{endpoint}/jobs', [IntegrationAdminController::class, 'jobs']);
        Route::get('integrations/{endpoint}/reconciliation', [IntegrationAdminController::class, 'reconciliation']);
        Route::post('sync-jobs/{job}/replay', [IntegrationAdminController::class, 'replay']);
        Route::get('ops/metrics', [OperationsController::class, 'metrics']);
        Route::get('ops/failed-jobs', [OperationsController::class, 'failedJobs']);
        Route::post('ops/failed-jobs/{uuid}/retry', [OperationsController::class, 'retryFailed']);
        Route::get('incidents', [OperationsController::class, 'incidents']);
        Route::post('incidents', [OperationsController::class, 'storeIncident']);
        Route::patch('incidents/{incident}', [OperationsController::class, 'updateIncident']);
        Route::get('policy', [OperationsController::class, 'policy']);
        Route::put('policy/{key}', [OperationsController::class, 'updatePolicy']);
        Route::get('pending-verification', [OperationsController::class, 'pendingVerification']);
        Route::get('audit', [OperationsController::class, 'audit']);
    });
});
