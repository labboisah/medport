<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_liveness_returns_ok_with_generated_correlation_id(): void
    {
        $response = $this->getJson('/api/v1/health/live');

        $response->assertOk()->assertJson(['status' => 'ok']);
        $this->assertNotEmpty($response->headers->get('X-Correlation-ID'));
    }

    public function test_valid_incoming_correlation_id_is_echoed_and_invalid_is_replaced(): void
    {
        $this->getJson('/api/v1/health/live', ['X-Correlation-ID' => 'abc-12345678'])
            ->assertHeader('X-Correlation-ID', 'abc-12345678');

        $response = $this->getJson('/api/v1/health/live', ['X-Correlation-ID' => "bad id\n<x>"]);
        $this->assertNotSame("bad id\n<x>", $response->headers->get('X-Correlation-ID'));
    }

    public function test_readiness_reports_dependency_checks(): void
    {
        $response = $this->getJson('/api/v1/health/ready');

        $response->assertJsonStructure(['status', 'checks' => ['database', 'cache', 'storage']]);
        $this->assertContains($response->status(), [200, 503]);
    }

    public function test_unknown_api_route_uses_consistent_error_shape(): void
    {
        $this->getJson('/api/v1/nope')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonStructure(['error' => ['code', 'message', 'correlation_id']]);
    }
}
