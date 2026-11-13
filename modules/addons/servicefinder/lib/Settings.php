<?php

namespace ServiceFinder;

use WHMCS\Database\Capsule;

class Settings
{
    public static function load()
    {
        return Capsule::table('tbladdonmodules')->where('module', 'servicefinder')->pluck('value', 'setting')->all();
    }
}
