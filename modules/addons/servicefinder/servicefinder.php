<?php
/**
 * Service Finder for WHMCS: one search box over every field of a service (domain, any IP, panel login, hostname, notes,
 * custom fields, add-ons, client name/e-mail/phone, product, server). Read-only. Backend: SQL (default) or Elasticsearch (optional).
 */

if (!defined('WHMCS')) die('This file cannot be accessed directly');

require_once __DIR__ . '/lib/autoload.php';

use ServiceFinder\Query;
use ServiceFinder\SqlBackend;
use ServiceFinder\ElasticBackend;
use ServiceFinder\Settings;
use WHMCS\Database\Capsule;

function servicefinder_config()
{
    return [
        'name' => 'Service Finder',
        'description' => 'One search box over every field of a service: any IP, domain, panel login, hostname, client, notes. Read-only.',
        'author' => 'Hosting in Ukraine',
        'version' => '1.1.0',
        'fields' => [
            'backend' => ['FriendlyName' => 'Search engine', 'Type' => 'dropdown', 'Options' => ['sql' => 'SQL (default)', 'elastic' => 'Elasticsearch / OpenSearch'], 'Default' => 'sql',
                'Description' => 'Elastic only if you run it separately; on any error the search falls back to SQL'],
            'es_url' => ['FriendlyName' => 'Elastic: URL', 'Type' => 'text', 'Size' => '50', 'Description' => 'e.g. http://127.0.0.1:9200 (keep it off the billing host)'],
            'es_index' => ['FriendlyName' => 'Elastic: index', 'Type' => 'text', 'Size' => '30', 'Default' => 'whmcs_services'],
            'es_user' => ['FriendlyName' => 'Elastic: user', 'Type' => 'text', 'Size' => '30'],
            'es_password' => ['FriendlyName' => 'Elastic: password', 'Type' => 'password', 'Size' => '30'],
        ],
    ];
}

function servicefinder_activate()
{
    return ['status' => 'success', 'description' => 'Service Finder activated (Addons → Service Finder)'];
}

function servicefinder_deactivate()
{
    return ['status' => 'success', 'description' => 'Service Finder deactivated'];
}

function servicefinder_output($vars)
{
    $t = ServiceFinder\Lang::make($vars);
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $s = Settings::load();
    $raw = trim((string)($_GET['q'] ?? ''));
    $filters = ['status' => (string)($_GET['status'] ?? ''), 'group' => (int)($_GET['group'] ?? 0), 'server' => (int)($_GET['server'] ?? 0)];

    $statuses = ['Active' => 'Active', 'Suspended' => 'Suspended', 'Pending' => 'Pending', 'Terminated' => 'Terminated', 'Cancelled' => 'Cancelled', 'Fraud' => 'Fraud', 'Completed' => 'Completed'];
    $groups = Capsule::table('tblproductgroups')->orderBy('name')->pluck('name', 'id')->all();
    $servers = Capsule::table('tblservers')->orderBy('name')->pluck('name', 'id')->all();
    $opt = function ($map, $sel, $any) use ($h) {
        $o = '<option value="">' . $h($any) . '</option>';
        foreach ($map as $k => $v) $o .= '<option value="' . $h($k) . '"' . ((string)$sel === (string)$k && $sel !== '' && $sel !== 0 ? ' selected' : '') . '>' . $h($v) . '</option>';
        return $o;
    };

    // addons live at addonmodules.php?module=NAME (NOT index.php: that is the dashboard)
    echo '<form method="get" action="addonmodules.php" class="form-inline" style="margin-bottom:12px">'
        . '<input type="hidden" name="module" value="servicefinder">'
        . '<input type="text" name="q" value="' . $h($raw) . '" class="form-control" style="width:100%;max-width:520px;margin:0 8px 8px 0" placeholder="' . $h($t('search_placeholder')) . '" autofocus>'
        . '<select name="status" class="form-control" style="margin:0 8px 8px 0">' . $opt($statuses, $filters['status'], $t('any_status')) . '</select>'
        . '<select name="group" class="form-control" style="margin:0 8px 8px 0">' . $opt($groups, $filters['group'], $t('any_group')) . '</select>'
        . '<select name="server" class="form-control" style="margin:0 8px 8px 0">' . $opt($servers, $filters['server'], $t('any_server')) . '</select>'
        . '<button type="submit" class="btn btn-primary" style="margin:0 8px 8px 0">' . $h($t('search_button')) . '</button></form>';
    echo '<p class="text-muted" style="font-size:12px">' . $t('operators') . '</p>';

    $q = Query::parse($raw, $filters);
    if ($q->isEmpty()) {
        echo '<div class="alert alert-info">' . $h($t('hint_empty')) . '</div>';
        return;
    }

    $sql = new SqlBackend();
    $note = '';
    try {
        $useElastic = ($s['backend'] ?? 'sql') === 'elastic' && $q->terms && !$q->ops;
        if ($useElastic) {
            $es = new ElasticBackend($s);
            if ($es->enabled()) {
                $q2 = clone $q;
                $q2->ids = $es->ids($q);
                $q2->terms = [];
                list($rows, $total) = $sql->services($q2);
            } else { $useElastic = false; }
        }
        if (!$useElastic) list($rows, $total) = $sql->services($q);
    } catch (\Throwable $e) {
        $note = '<div class="alert alert-warning">' . $h($t('elastic_down', $e->getMessage())) . '</div>';
        list($rows, $total) = $sql->services($q);
    }
    echo $note;

    $badge = ['Active' => 'success', 'Suspended' => 'warning', 'Terminated' => 'default', 'Cancelled' => 'default', 'Pending' => 'info', 'Fraud' => 'danger', 'Completed' => 'default'];
    $found = function ($r) use ($q, $t) {
        $hit = [];
        $fields = [$t('f_domain') => $r->domain, $t('f_ip') => $r->dedicatedip . ' ' . $r->assignedips, $t('f_login') => $r->username, $t('f_notes') => $r->notes, $t('f_subscription') => $r->subscriptionid];
        foreach ($q->terms as $term) {
            foreach ($fields as $name => $val) if ($val !== '' && stripos((string)$val, $term) !== false) $hit[$name] = true;
        }
        return array_keys($hit);
    };

    echo '<h4>' . $h($t('services')) . ': ' . (int)$total . ($total > count($rows) ? ' (' . $h($t('shown_first', count($rows))) . ')' : '') . '</h4>';
    if ($rows) {
        echo '<div class="table-responsive"><table class="table table-condensed table-striped" style="font-size:12.5px"><thead><tr><th>' . $h($t('col_id')) . '</th><th>' . $h($t('col_client')) . '</th><th>' . $h($t('col_product')) . '</th><th>' . $h($t('col_target')) . '</th><th>' . $h($t('col_server')) . '</th><th>' . $h($t('col_status')) . '</th><th>' . $h($t('col_billing')) . '</th><th>' . $h($t('col_found_in')) . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $days = $r->nextduedate && $r->nextduedate !== '0000-00-00' ? (int)floor((time() - strtotime($r->nextduedate)) / 86400) : null;
            $due = $h($r->nextduedate) . ($days !== null && $days > 0 && in_array($r->domainstatus, ['Active', 'Suspended'], true) ? ' <span class="text-danger">(+' . $days . ' ' . $h($t('days_short')) . ')</span>' : '');
            $name = trim($r->firstname . ' ' . $r->lastname) ?: ($r->companyname ?: '—');
            $ips = trim($r->dedicatedip . ' ' . $r->assignedips);
            echo '<tr><td><a href="clientsservices.php?userid=' . (int)$r->userid . '&id=' . (int)$r->id . '">#' . (int)$r->id . '</a></td>'
                . '<td><a href="clientssummary.php?userid=' . (int)$r->userid . '">' . $h($name) . '</a><br><small class="text-muted">' . $h($r->email) . '</small></td>'
                . '<td>' . $h($r->product) . '<br><small class="text-muted">' . $h($r->grp) . '</small></td>'
                . '<td>' . $h($r->domain) . ($ips !== '' ? '<br><small>' . $h($ips) . '</small>' : '') . ($r->username !== '' ? '<br><small class="text-muted">' . $h($r->username) . '</small>' : '') . '</td>'
                . '<td>' . $h($r->server ?: '—') . '</td>'
                . '<td><span class="label label-' . ($badge[$r->domainstatus] ?? 'default') . '">' . $h($r->domainstatus) . '</span>' . ($r->suspendreason ? '<br><small class="text-muted">' . $h($r->suspendreason) . '</small>' : '') . '</td>'
                . '<td>' . $due . '<br><small>' . $h($r->amount) . ' · ' . $h($r->billingcycle) . '</small></td>'
                . '<td><small>' . $h(implode(', ', $found($r))) . '</small></td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<p class="text-muted">' . $h($t('nothing_found')) . '</p>';
    }

    $dom = $sql->domains($q);
    if ($dom) {
        echo '<h4>' . $h($t('domains')) . ': ' . count($dom) . '</h4><div class="table-responsive"><table class="table table-condensed" style="font-size:12.5px"><tbody>';
        foreach ($dom as $d) {
            echo '<tr><td><a href="clientsdomains.php?userid=' . (int)$d->userid . '&id=' . (int)$d->id . '">' . $h($d->domain) . '</a></td><td>' . $h(trim($d->firstname . ' ' . $d->lastname)) . '</td><td>' . $h($d->registrar) . '</td><td>' . $h($d->status) . '</td><td>' . $h($d->expirydate) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    $cl = $sql->clients($q);
    if ($cl) {
        echo '<h4>' . $h($t('clients')) . ': ' . count($cl) . '</h4><div class="table-responsive"><table class="table table-condensed" style="font-size:12.5px"><tbody>';
        foreach ($cl as $c) {
            echo '<tr><td><a href="clientssummary.php?userid=' . (int)$c->id . '">#' . (int)$c->id . ' ' . $h(trim($c->firstname . ' ' . $c->lastname)) . '</a></td><td>' . $h($c->companyname) . '</td><td>' . $h($c->email) . '</td><td>' . $h($c->phonenumber) . '</td><td>' . $h($c->status) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
