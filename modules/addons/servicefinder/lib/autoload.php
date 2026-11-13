<?php

spl_autoload_register(function ($class) {
    if (strpos($class, 'ServiceFinder\\') !== 0) return;
    $file = __DIR__ . '/' . substr($class, 14) . '.php';
    if (is_file($file)) require_once $file;
});
