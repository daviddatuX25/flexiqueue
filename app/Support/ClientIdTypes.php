<?php

namespace App\Support;

final class ClientIdTypes
{
    public const PHILSYS = 'PhilSys';
    public const PHILHEALTH = 'PhilHealth';
    public const SSS = 'SSS';
    public const GSIS = 'GSIS';
    public const DRIVERS_LICENSE = "Driver's License";
    public const VOTERS_ID = "Voter's ID";
    public const POSTAL_ID = 'Postal ID';
    public const OTHER = 'Other';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::PHILSYS,
            self::PHILHEALTH,
            self::SSS,
            self::GSIS,
            self::DRIVERS_LICENSE,
            self::VOTERS_ID,
            self::POSTAL_ID,
            self::OTHER,
        ];
    }
}

