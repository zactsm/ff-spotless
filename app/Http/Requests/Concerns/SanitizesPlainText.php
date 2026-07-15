<?php

namespace App\Http\Requests\Concerns;

trait SanitizesPlainText
{
    protected function sanitizePlainText(mixed $value): string
    {
        $value = is_string($value) ? strip_tags($value) : '';
        $withoutControls = preg_replace('/[\p{C}]/u', '', $value);
        $withoutExtraWhitespace = preg_replace('/\s+/u', ' ', $withoutControls ?? $value);

        return trim($withoutExtraWhitespace ?? $value);
    }

    protected function sanitizeUsername(mixed $value): string
    {
        return strtolower(trim(is_string($value) ? strip_tags($value) : ''));
    }
}
