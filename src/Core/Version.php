<?php
namespace Kyte\Core;

class Version
{
    const MAJOR=3;
    const MINOR=6;
    const PATCH=4;

    public static function get()
    {
        return sprintf('v%s.%s.%s', self::MAJOR, self::MINOR, self::PATCH);
    }
}
