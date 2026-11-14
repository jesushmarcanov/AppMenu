<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ValidationException extends ApiException
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(array $fields, string $message = 'Revisa los campos marcados.')
    {
        parent::__construct('VALIDATION_ERROR', $message, 422, $fields);
    }
}
