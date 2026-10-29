<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\LogRecord;

/** Emits structured JSON logs and masks sensitive context before it is written. */
class StructuredRedactedLogging
{
    private const SENSITIVE = [
        'password', 'token', 'secret', 'authorization', 'cookie', 'api_key', 'apikey',
        'access_token', 'refresh_token', 'otp', 'national_id', 'nin', 'phone', 'email',
        'name', 'dob', 'date_of_birth', 'diagnosis', 'medication', 'allergy', 'payload', 'body',
    ];

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonFormatter);
            }
        }

        $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                context: self::redact($record->context),
                extra: self::redact($record->extra),
            );
        });
    }

    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE as $needle) {
            if ($key === $needle || str_contains($key, $needle) && strlen($needle) > 4) {
                return true;
            }
        }

        return false;
    }
}
