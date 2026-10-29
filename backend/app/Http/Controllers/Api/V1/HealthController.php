<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class HealthController extends Controller
{
    /** Liveness: the process is up. No dependency checks. */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Readiness: database, cache and private storage are usable. */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function () {
                Cache::put('health:probe', 1, 10);

                return Cache::get('health:probe') === 1;
            }),
            'storage' => $this->check(function () {
                $disk = Storage::disk('local');
                $disk->put('.health-probe', '1');

                return $disk->delete('.health-probe');
            }),
        ];

        $ok = ! in_array('fail', $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            return $probe() === false ? 'fail' : 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
