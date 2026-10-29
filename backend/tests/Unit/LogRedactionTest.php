<?php

namespace Tests\Unit;

use App\Logging\StructuredRedactedLogging;
use PHPUnit\Framework\TestCase;

class LogRedactionTest extends TestCase
{
    public function test_sensitive_keys_are_masked_recursively_and_safe_keys_kept(): void
    {
        $out = StructuredRedactedLogging::redact([
            'correlation_id' => 'abc',
            'password' => 'x',
            'user' => ['email' => 'a@b.c', 'id' => 7, 'access_token' => 't'],
            'request_payload' => ['diagnosis' => 'x'],
        ]);

        $this->assertSame('abc', $out['correlation_id']);
        $this->assertSame('[REDACTED]', $out['password']);
        $this->assertSame('[REDACTED]', $out['user']['email']);
        $this->assertSame(7, $out['user']['id']);
        $this->assertSame('[REDACTED]', $out['user']['access_token']);
        $this->assertSame('[REDACTED]', $out['request_payload']);
    }
}
