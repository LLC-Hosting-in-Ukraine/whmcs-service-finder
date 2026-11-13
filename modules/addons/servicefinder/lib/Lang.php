<?php

namespace ServiceFinder;

/** Strings: lang/english.php is the reference, the admin language (WHMCS passes it as $vars['_lang']) overrides it. */
class Lang
{
    private static $base = null;

    public static function make(array $vars)
    {
        if (self::$base === null) {
            $_ADDONLANG = [];
            if (!defined('WHMCS')) define('WHMCS', true); // lets the lang file load outside WHMCS (tests)
            require __DIR__ . '/../lang/english.php';
            self::$base = $_ADDONLANG;
        }
        $over = isset($vars['_lang']) && is_array($vars['_lang']) ? $vars['_lang'] : [];
        $all = array_merge(self::$base, $over);
        return function ($key, ...$args) use ($all) {
            $s = $all[$key] ?? $key;
            return $args ? vsprintf($s, $args) : $s;
        };
    }
}
