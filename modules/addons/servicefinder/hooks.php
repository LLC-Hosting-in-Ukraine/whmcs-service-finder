<?php
/** Service finder: nightly rebuild of the Elastic index (only when backend = elastic). */
if (!defined('WHMCS')) die('This file cannot be accessed directly');

add_hook('DailyCronJob', 1, function () {
    try {
        require_once __DIR__ . '/lib/autoload.php';
        $s = ServiceFinder\Settings::load();
        if (($s['backend'] ?? 'sql') !== 'elastic') return;
        $n = (new ServiceFinder\ElasticBackend($s))->reindex();
        logActivity("ServiceFinder: Elastic index rebuilt ($n services)");
    } catch (\Throwable $e) {
        logActivity('ServiceFinder: Elastic reindex failed: ' . substr($e->getMessage(), 0, 200));
    }
});
