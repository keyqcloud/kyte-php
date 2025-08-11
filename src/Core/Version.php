<?php
namespace Kyte\Core;

class Version
{
    const MAJOR=3;
    const MINOR=7;
    const PATCH=6;

    public static function get()
    {
        return sprintf('v%s.%s.%s', self::MAJOR, self::MINOR, self::PATCH);
    }
}
