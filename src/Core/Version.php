<?php
namespace Kyte\Core;

class Version
{
    const MAJOR=4;
    const MINOR=1;
    const PATCH=0;

    public static function get()
    {
        return sprintf('v%s.%s.%s', self::MAJOR, self::MINOR, self::PATCH);
    }
}
