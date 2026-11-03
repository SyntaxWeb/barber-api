<?php

namespace App\Services\Whatsapp;

use InvalidArgumentException;

class WhatsappPhone
{
    public static function normalize(string $phone): string
    {
        $international = str_starts_with(trim($phone), '+') || str_starts_with(trim($phone), '00');
        $number = preg_replace('/\D/', '', $phone);
        if (str_starts_with($number, '00')) $number = substr($number, 2);
        if (!$international && in_array(strlen($number), [10, 11], true)) {
            $number = config('services.whatsapp.country_code', '55').$number;
        }
        if (!preg_match('/^[1-9][0-9]{9,14}$/', $number)) {
            throw new InvalidArgumentException('Informe um número de WhatsApp válido com DDD e, para números internacionais, o código do país.');
        }
        return $number;
    }
}
