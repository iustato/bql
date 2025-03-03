<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit87ff279c33156da0b53f9cefa886e1b9
{
    public static $prefixLengthsPsr4 = array (
        'i' => 
        array (
            'iustato\\Bql\\' => 12,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'iustato\\Bql\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit87ff279c33156da0b53f9cefa886e1b9::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit87ff279c33156da0b53f9cefa886e1b9::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit87ff279c33156da0b53f9cefa886e1b9::$classMap;

        }, null, ClassLoader::class);
    }
}
