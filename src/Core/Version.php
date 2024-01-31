<?php
namespace Kyte\Core;

class Version
{
    const MAJOR=3;
    const MINOR=5;
    const PATCH=1;

    public static function get()
    {
        $branch = substr(trim(exec('git branch | grep \'*\'')), 2);
        $commitHash = trim(exec('git log --pretty="%h" -n1 HEAD'));

        $commitDate = new \DateTime(trim(exec('git log -n1 --pretty=%ci HEAD')));
        $commitDate->setTimezone(new \DateTimeZone('UTC'));

        return sprintf('v%s.%s.%s-%s.%s (%s)', self::MAJOR, self::MINOR, self::PATCH, $branch, $commitHash, $commitDate->format('Y-m-d H:i:s'));
    }
}
