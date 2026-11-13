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
        'version' => '1.2.0',
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
    $h = function ($v) { return ServiceFinder\View::h($v); };
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
    $V = 'ServiceFinder\View';

    echo $V::css() . '<div class="sf">';

    // ---- search card (the form posts to addonmodules.php; index.php would be the dashboard)
    echo '<div class="sf-hero"><form method="get" action="addonmodules.php" class="sf-bar">'
        . '<input type="hidden" name="module" value="servicefinder">'
        . '<div class="sf-input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
        . '<input type="text" name="q" value="' . $h($raw) . '" class="sf-input" placeholder="' . $h($t('search_placeholder')) . '" autofocus autocomplete="off"></div>'
        . '<select name="group" class="sf-select">' . $opt($groups, $filters['group'], $t('any_group')) . '</select>'
        . '<select name="server" class="sf-select">' . $opt($servers, $filters['server'], $t('any_server')) . '</select>'
        . '<input type="hidden" name="status" value="' . $h($filters['status']) . '">'
        . '<button type="submit" class="sf-btn">' . $h($t('search_button')) . '</button></form>';

    $keep = ['q' => $raw, 'group' => $filters['group'], 'server' => $filters['server']];
    echo '<div class="sf-row2"><span class="sf-lbl">' . $h($t('status_label')) . '</span><span class="sf-seg">'
        . '<a href="' . $h($V::url($keep)) . '" class="' . ($filters['status'] === '' ? 'on' : '') . '">' . $h($t('all')) . '</a> ';
    foreach (['Active', 'Suspended', 'Terminated', 'Cancelled'] as $st) {
        echo '<a href="' . $h($V::url($keep + ['status' => $st])) . '" class="' . ($filters['status'] === $st ? 'on' : '') . '">' . $h($st) . '</a> ';
    }
    echo '</span></div>';
    echo '<div class="sf-row2"><span class="sf-lbl">' . $h($t('try')) . '</span>';
    foreach (['status:suspended old:60', 'status:active old:1', 'ip:192.0.2', 'product:"FTP storage"'] as $ex) {
        echo '<a class="sf-chip" href="' . $h($V::url(['q' => $ex])) . '">' . $h($ex) . '</a>';
    }
    echo '</div><details class="sf-help"><summary>' . $h($t('operators_title')) . '</summary><div style="margin-top:6px">' . $t('operators') . '</div></details></div>';

    $q = Query::parse($raw, $filters);
    if ($q->isEmpty()) {
        echo '<div class="sf-card"><div class="sf-empty"><b>' . $h($t('hint_title')) . '</b>' . $h($t('hint_empty')) . '</div></div>';
        echo '</div>' . $V::js();
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
        $note = '<div class="sf-alert">' . $h($t('elastic_down', $e->getMessage())) . '</div>';
        list($rows, $total) = $sql->services($q);
    }
    echo $note;

    $found = function ($r) use ($q, $t) {
        $hit = [];
        $fields = [$t('f_domain') => $r->domain, $t('f_ip') => $r->dedicatedip . ' ' . $r->assignedips, $t('f_login') => $r->username, $t('f_notes') => $r->notes, $t('f_subscription') => $r->subscriptionid];
        foreach ($q->terms as $term) {
            foreach ($fields as $name => $val) if ($val !== '' && stripos((string)$val, $term) !== false) $hit[$name] = true;
        }
        return array_keys($hit);
    };

    // ---- summary
    $by = [];
    foreach ($rows as $r) $by[$r->domainstatus] = ($by[$r->domainstatus] ?? 0) + 1;
    echo '<div class="sf-sum"><h4>' . $h($t('services')) . '<span class="sf-count">' . (int)$total . '</span></h4>';
    foreach ($by as $st => $n) {
        $c = $V::statusColor($st);
        echo '<span class="sf-stat"><span class="sf-dot" style="background:' . $c[0] . '"></span>' . $h($st) . ' <b>' . (int)$n . '</b></span>';
    }
    if ($total > count($rows)) echo '<span class="sf-sub">' . $h($t('shown_first', count($rows))) . '</span>';
    echo '</div>';

    // ---- services table
    echo '<div class="sf-card">';
    if ($rows) {
        echo '<div class="table-responsive" style="border:0;margin:0"><table class="sf-table"><thead><tr><th>' . $h($t('col_id')) . '</th><th>' . $h($t('col_client')) . '</th><th>' . $h($t('col_product')) . '</th><th>' . $h($t('col_target')) . '</th><th class="sf-hide-s">' . $h($t('col_server')) . '</th><th>' . $h($t('col_status')) . '</th><th>' . $h($t('col_billing')) . '</th><th class="sf-hide-s">' . $h($t('col_found_in')) . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $c = $V::statusColor($r->domainstatus);
            $days = $r->nextduedate && $r->nextduedate !== '0000-00-00' ? (int)floor((time() - strtotime($r->nextduedate)) / 86400) : null;
            $late = '';
            if ($days !== null && $days > 0 && in_array($r->domainstatus, ['Active', 'Suspended'], true)) {
                $late = '<span class="sf-late ' . ($days > 30 ? 'bad' : 'warn') . '">+' . $days . ' ' . $h($t('days_short')) . '</span>';
            }
            $name = trim($r->firstname . ' ' . $r->lastname) ?: ($r->companyname ?: '—');
            $ips = preg_split('/[\s,;]+/', trim($r->dedicatedip . ' ' . $r->assignedips), -1, PREG_SPLIT_NO_EMPTY);
            $ipHtml = '';
            foreach (array_unique($ips) as $ip) $ipHtml .= '<span class="sf-ip" data-copy="' . $h($ip) . '" title="' . $h($t('click_copy')) . '">' . $V::hl($ip, $q->terms) . '</span>';
            echo '<tr style="--st:' . $c[0] . '"><td><a class="sf-id" href="clientsservices.php?userid=' . (int)$r->userid . '&amp;id=' . (int)$r->id . '">#' . (int)$r->id . '</a></td>'
                . '<td><div class="sf-person"><span class="sf-av" style="background:hsl(' . $V::hue($r->userid) . ',55%,48%)">' . $h($V::initials($name)) . '</span><div>'
                . '<a href="clientssummary.php?userid=' . (int)$r->userid . '">' . $V::hl($name, $q->terms) . '</a><div class="sf-sub">' . $V::hl($r->email, $q->terms) . '</div></div></div></td>'
                . '<td>' . $V::hl($r->product, $q->terms) . '<br><span class="sf-pill">' . $h($r->grp) . '</span></td>'
                . '<td><span class="sf-mono">' . $V::hl($r->domain, $q->terms) . '</span>' . ($ipHtml !== '' ? '<div>' . $ipHtml . '</div>' : '') . ($r->username !== '' ? '<div class="sf-user">' . $V::hl($r->username, $q->terms) . '</div>' : '') . '</td>'
                . '<td class="sf-hide-s">' . $V::hl($r->server ?: '—', $q->terms) . '</td>'
                . '<td><span class="sf-st" style="background:' . $c[1] . ';color:' . $c[2] . '"><span class="sf-dot" style="background:' . $c[0] . '"></span>' . $h($r->domainstatus) . '</span>' . ($r->suspendreason ? '<div class="sf-sub">' . $h($r->suspendreason) . '</div>' : '') . '</td>'
                . '<td>' . $h($r->nextduedate) . $late . '<div class="sf-sub">' . $h($r->amount) . ' · ' . $h($r->billingcycle) . '</div></td>'
                . '<td class="sf-hide-s">' . implode('', array_map(function ($f) use ($h) { return '<span class="sf-found">' . $h($f) . '</span>'; }, $found($r))) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<div class="sf-empty"><b>' . $h($t('nothing_found')) . '</b>' . $h($t('nothing_hint')) . '</div>';
    }
    echo '</div>';

    // ---- domains + clients side by side
    $dom = $sql->domains($q);
    $cl = $sql->clients($q);
    if ($dom || $cl) {
        echo '<div class="sf-lists">';
        if ($dom) {
            echo '<div class="sf-card"><h5>' . $h($t('domains')) . '<span class="sf-count">' . count($dom) . '</span></h5>';
            foreach ($dom as $d) {
                echo '<div class="sf-li"><a class="sf-id sf-mono" href="clientsdomains.php?userid=' . (int)$d->userid . '&amp;id=' . (int)$d->id . '">' . $V::hl($d->domain, $q->terms) . '</a>'
                    . '<div class="sf-sub">' . $h(trim($d->firstname . ' ' . $d->lastname)) . ' · ' . $h($d->registrar) . ' · ' . $h($d->status) . ' · ' . $h($d->expirydate) . '</div></div>';
            }
            echo '</div>';
        }
        if ($cl) {
            echo '<div class="sf-card"><h5>' . $h($t('clients')) . '<span class="sf-count">' . count($cl) . '</span></h5>';
            foreach ($cl as $c) {
                $nm = trim($c->firstname . ' ' . $c->lastname) ?: $c->companyname;
                echo '<div class="sf-li"><div class="sf-person"><span class="sf-av" style="background:hsl(' . $V::hue($c->id) . ',55%,48%)">' . $h($V::initials($nm)) . '</span><div>'
                    . '<a href="clientssummary.php?userid=' . (int)$c->id . '">#' . (int)$c->id . ' ' . $V::hl($nm, $q->terms) . '</a>'
                    . '<div class="sf-sub">' . $V::hl($c->email, $q->terms) . ' · ' . $V::hl($c->phonenumber, $q->terms) . ($c->companyname ? ' · ' . $h($c->companyname) : '') . '</div></div></div></div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>' . $V::js();
}
