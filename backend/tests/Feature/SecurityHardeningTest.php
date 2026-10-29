<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        app('auth')->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function token(string $type): string
    {
        $u = (new User)->forceFill(['name' => $type, 'email' => "$type@example.test", 'password' => self::PASSWORD, 'account_type' => $type, 'mfa_enrolled_at' => now()]);
        $u->save();
        if ($type === 'patient') {
            Patient::create(['user_id' => $u->id, 'given_name' => 'A', 'family_name' => 'B', 'birth_date' => '1990-01-01']);
        }

        return $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => self::PASSWORD])->json('data.token');
    }

    /** @return array<int, array{string, string}> concrete method + URI for every API route */
    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $uri = preg_replace('/\{[^}]+\}/', '00000000-0000-0000-0000-000000000000', $route->uri());
            $out[] = [$route->methods()[0], '/'.$uri, $route->uri()];
        }

        return $out;
    }

    private const PUBLIC = ['api/v1/health/live', 'api/v1/health/ready', 'api/v1/auth/register', 'api/v1/auth/login'];

    public function test_every_route_requires_authentication_unless_deliberately_public(): void
    {
        $checked = 0;
        foreach ($this->routes() as [$method, $uri, $pattern]) {
            if (in_array($pattern, self::PUBLIC, true)) {
                continue;
            }
            $status = $this->json($method, $uri)->status();
            $this->assertContains($status, [401], "$method $pattern answered $status without credentials");
            $checked++;
        }
        $this->assertGreaterThan(40, $checked);
    }

    public function test_admin_routes_reject_patient_and_staff_tokens(): void
    {
        foreach (['patient', 'practitioner'] as $type) {
            $token = $this->token($type);
            foreach ($this->routes() as [$method, $uri, $pattern]) {
                if (str_starts_with($pattern, 'api/v1/admin/') || str_starts_with($pattern, 'api/v1/integrations/')) {
                    $status = $this->withToken($token)->json($method, $uri)->status();
                    $this->assertContains($status, [401, 403, 404], "$type reached $method $pattern with $status");
                }
            }
        }
    }

    public function test_responses_carry_security_headers_and_are_never_cached(): void
    {
        $response = $this->getJson('/api/v1/health/live');
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $authed = $this->withToken($this->token('patient'))->getJson('/api/v1/patient/timeline');
        $this->assertStringContainsString('no-store', $authed->headers->get('Cache-Control'));

        $errors = $this->getJson('/api/v1/nope');
        $errors->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_cors_allows_only_configured_origins_and_never_credentials(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.test']]);

        $ok = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.example.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertSame('https://app.example.test', $ok->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($ok->headers->get('Access-Control-Allow-Credentials'));

        $bad = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertNotSame('https://evil.example.test', $bad->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_sql_like_input_is_treated_as_data(): void
    {
        $token = $this->token('practitioner');
        $this->withToken($token)->postJson('/api/v1/patients/search', ['system' => "x' OR 1=1 --", 'value' => "' OR 1=1 --"])->assertStatus(403);
        $this->postJson('/api/v1/auth/login', ['email' => "admin@example.test' OR '1'='1", 'password' => 'x'])->assertStatus(422);
    }

    public function test_database_errors_are_logged_without_sql_or_bound_values(): void
    {
        $captured = [];
        Log::shouldReceive('shareContext')->zeroOrMoreTimes();
        Log::shouldReceive('error')->andReturnUsing(function ($message, $context = []) use (&$captured) {
            $captured[] = [$message, $context];
        });
        Log::shouldReceive('channel', 'stack', 'warning', 'info', 'debug', 'critical')->zeroOrMoreTimes()->andReturnSelf();

        report(new QueryException('mysql', 'select * from patients where family_name = ?', ['Smith-SECRET'], new \Exception('boom Smith-SECRET')));

        $this->assertNotEmpty($captured);
        $this->assertStringNotContainsString('Smith-SECRET', json_encode($captured));
    }
}
