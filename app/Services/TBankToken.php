<?php

namespace App\Services;

final class TBankToken
{
    /** @param array<string, mixed> $parameters */
    public function sign(array $parameters, #[\SensitiveParameter] string $password): string
    {
        unset($parameters['Token'], $parameters['Password']);
        $values = [];
        foreach ($parameters as $key => $value) {
            // Receipt, DATA and other nested structures are excluded by the protocol.
            if (is_string($value) || is_int($value) || is_bool($value)) {
                $values[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            } elseif ($value !== null && ! is_array($value)) {
                throw new TBankApiException('tbank_invalid_token_value');
            }
        }
        $values['Password'] = $password;
        ksort($values, SORT_STRING);

        return hash('sha256', implode('', $values));
    }

    /** @param array<string, mixed> $parameters */
    public function verify(array $parameters, #[\SensitiveParameter] string $password): bool
    {
        $token = $parameters['Token'] ?? null;
        if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1 || $password === '') {
            return false;
        }

        try {
            return hash_equals($this->sign($parameters, $password), $token);
        } catch (TBankApiException) {
            return false;
        }
    }
}
