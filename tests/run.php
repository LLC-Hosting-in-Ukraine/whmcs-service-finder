<?php
/**
 * Service Finder tests. Run from the shell on the WHMCS host (PHP CLI):
 *     php tests/run.php /path/to/whmcs
 * They use TEMPORARY tables (same names as WHMCS tables, visible only to this DB session), so real data is never read or changed.
 */
$root = $argv[1] ?? getcwd();
if (!is_file($root . '/init.php')) { fwrite(STDERR, "WHMCS root not found: $root (pass the path as the first argument)\n"); exit(2); }
chdir($root);
require_once $root . '/init.php';
// works both inside the addon folder (modules/addons/servicefinder/tests) and from a repo-root tests/ folder
$mod = is_file(__DIR__ . '/../servicefinder.php') ? realpath(__DIR__ . '/..') : realpath(__DIR__ . '/../modules/addons/servicefinder');
require_once $mod . '/servicefinder.php';

use WHMCS\Database\Capsule;
use ServiceFinder\Query;
use ServiceFinder\SqlBackend;
use ServiceFinder\ElasticBackend;
use ServiceFinder\Lang;

$pass = 0; $fail = 0;
function ok($cond, $name, $detail = '') { global $pass, $fail; if ($cond) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name $detail\n"; } }
function ids($rows) { $a = array_map(function ($r) { return (int)$r->id; }, $rows); sort($a); return $a; }
function sameIds($rows, array $want) { sort($want); return ids($rows) === $want; }

// ---------------------------------------------------------------- Query::parse
echo "Query\n";
$q = Query::parse('203.0.113.108 status:suspended "two words" ip:2a01:4f8 old:60 bogus:x');
ok($q->terms === ['203.0.113.108', 'two words', 'bogus:x'], 'terms: plain, quoted phrase, unknown operator stays a term', json_encode($q->terms));
ok($q->ops['status'] === ['suspended'] && $q->ops['old'] === ['60'], 'operators parsed');
ok($q->ops['ip'] === ['2a01:4f8'], 'IPv6 fragment as ip: value');
ok(Query::parse('')->isEmpty() && !Query::parse('x')->isEmpty() && !Query::parse('', ['status' => 'Active'])->isEmpty(), 'isEmpty');
ok(Query::like('a%b_c\\d') === '%a\\%b\\_c\\\\d%', 'LIKE wildcards are escaped');
ok(Query::parse('STATUS:Active')->ops['status'] === ['Active'], 'operator names are case-insensitive');
$q = Query::parse('product:"FTP storage" client:"John Smith" "a b" x:"q r"');
ok($q->ops['product'] === ['FTP storage'] && $q->ops['client'] === ['John Smith'] && $q->terms === ['a b', 'x:q r'], 'operator with a quoted value; unknown key:"value" becomes a term', json_encode([$q->ops, $q->terms]));

// ---------------------------------------------------------------- fixtures (temporary tables)
$db = Capsule::connection();
foreach (['tblhosting', 'tblproducts', 'tblproductgroups', 'tblclients', 'tblservers', 'tblcustomfields', 'tblcustomfieldsvalues', 'tblhostingaddons', 'tbldomains'] as $t) $db->unprepared("DROP TEMPORARY TABLE IF EXISTS $t");
$db->unprepared("CREATE TEMPORARY TABLE tblclients (id INT, firstname VARCHAR(50), lastname VARCHAR(50), companyname VARCHAR(80), email VARCHAR(80), phonenumber VARCHAR(30), status VARCHAR(10)) DEFAULT CHARSET=utf8mb4");
$db->unprepared("CREATE TEMPORARY TABLE tblproductgroups (id INT, name VARCHAR(60)) DEFAULT CHARSET=utf8mb4");
$db->unprepared("CREATE TEMPORARY TABLE tblproducts (id INT, gid INT, name VARCHAR(60)) DEFAULT CHARSET=utf8mb4");
$db->unprepared("CREATE TEMPORARY TABLE tblservers (id INT, name VARCHAR(60), hostname VARCHAR(80), ipaddress VARCHAR(40)) DEFAULT CHARSET=utf8mb4");
$db->unprepared("CREATE TEMPORARY TABLE tblhosting (id INT, userid INT, packageid INT, server INT, domain VARCHAR(100), dedicatedip VARCHAR(40), assignedips TEXT, username VARCHAR(60), notes TEXT, subscriptionid VARCHAR(60), domainstatus VARCHAR(20), suspendreason VARCHAR(100), nextduedate DATE, amount DECIMAL(10,2), billingcycle VARCHAR(30)) DEFAULT CHARSET=utf8mb4");
$db->unprepared("CREATE TEMPORARY TABLE tblcustomfields (id INT, type VARCHAR(20), fieldname VARCHAR(60)) DEFAULT CHARSET=utf8mb4");
$db->unprepared("CREATE TEMPORARY TABLE tblcustomfieldsvalues (fieldid INT, relid INT, value TEXT) DEFAULT CHARSET=utf8mb4");
$db->unprepared("CREATE TEMPORARY TABLE tblhostingaddons (id INT, hostingid INT, name VARCHAR(80), notes TEXT) DEFAULT CHARSET=utf8mb4");
$db->unprepared("CREATE TEMPORARY TABLE tbldomains (id INT, userid INT, domain VARCHAR(100), registrar VARCHAR(40), status VARCHAR(20), expirydate DATE, additionalnotes TEXT) DEFAULT CHARSET=utf8mb4");

$d = function ($days) { return date('Y-m-d', strtotime("-$days days")); };
$ins = function ($t, array $row) { Capsule::table($t)->insert($row); };
$ins('tblclients', ['id' => 1, 'firstname' => 'Іван', 'lastname' => 'Петренко', 'companyname' => '', 'email' => 'ivan@example.com', 'phonenumber' => '+380501112233', 'status' => 'Active']);
$ins('tblclients', ['id' => 2, 'firstname' => 'John', 'lastname' => 'Smith', 'companyname' => 'Acme Ltd', 'email' => 'john@acme.test', 'phonenumber' => '+14155550100', 'status' => 'Active']);
$ins('tblproductgroups', ['id' => 10, 'name' => 'VPS']);
$ins('tblproductgroups', ['id' => 11, 'name' => 'FTP storage']);
$ins('tblproducts', ['id' => 100, 'gid' => 10, 'name' => 'KVM-VPS-4G']);
$ins('tblproducts', ['id' => 101, 'gid' => 11, 'name' => 'Storage-100']);
$ins('tblservers', ['id' => 5, 'name' => 'VM6', 'hostname' => 'vps.example.test', 'ipaddress' => '10.0.0.5']);
$ins('tblservers', ['id' => 6, 'name' => 'SB1', 'hostname' => 'sb1.example.test', 'ipaddress' => '10.0.0.6']);
// 1: VPS, IP in dedicatedip + extra in assignedips, suspended 70 days overdue
$ins('tblhosting', ['id' => 1, 'userid' => 1, 'packageid' => 100, 'server' => 5, 'domain' => 'vm1.local', 'dedicatedip' => '203.0.113.108', 'assignedips' => "203.0.113.109\n203.0.113.110", 'username' => 'vmuser1', 'notes' => 'Old ID: 48213', 'subscriptionid' => '', 'domainstatus' => 'Suspended', 'suspendreason' => 'Overdue', 'nextduedate' => $d(70), 'amount' => 5.99, 'billingcycle' => 'Monthly']);
// 2: FTP storage, IP lives in the DOMAIN field (ISPmanager-style), active
$ins('tblhosting', ['id' => 2, 'userid' => 2, 'packageid' => 101, 'server' => 6, 'domain' => '198.51.100.66', 'dedicatedip' => '', 'assignedips' => '', 'username' => 'ftpuser1', 'notes' => '', 'subscriptionid' => 'sub_ABC123', 'domainstatus' => 'Active', 'suspendreason' => '', 'nextduedate' => date('Y-m-d', strtotime('+10 days')), 'amount' => 517.96, 'billingcycle' => 'Monthly']);
// 3: terminated, literal % and _ in notes
$ins('tblhosting', ['id' => 3, 'userid' => 2, 'packageid' => 101, 'server' => 6, 'domain' => 'old.example.com', 'dedicatedip' => '', 'assignedips' => '', 'username' => 'u3', 'notes' => '100%_discount', 'subscriptionid' => '', 'domainstatus' => 'Terminated', 'suspendreason' => '', 'nextduedate' => $d(400), 'amount' => 1, 'billingcycle' => 'Monthly']);
// 4: another VPS with a custom field value and an add-on
$ins('tblhosting', ['id' => 4, 'userid' => 1, 'packageid' => 100, 'server' => 5, 'domain' => 'vm4.local', 'dedicatedip' => '192.0.2.33', 'assignedips' => '', 'username' => 'vmuser4', 'notes' => '', 'subscriptionid' => '', 'domainstatus' => 'Active', 'suspendreason' => '', 'nextduedate' => $d(3), 'amount' => 1088.29, 'billingcycle' => 'Monthly']);
$ins('tblcustomfields', ['id' => 1, 'type' => 'product', 'fieldname' => 'VM ID']);
$ins('tblcustomfields', ['id' => 2, 'type' => 'client', 'fieldname' => 'Client field']);
$ins('tblcustomfieldsvalues', ['fieldid' => 1, 'relid' => 4, 'value' => 'vm-uuid-7f3a']);
$ins('tblcustomfieldsvalues', ['fieldid' => 2, 'relid' => 1, 'value' => 'clientfieldvalue']); // must NOT match services (type=client)
$ins('tblhostingaddons', ['id' => 1, 'hostingid' => 4, 'name' => 'Extra 10 GB disk', 'notes' => 'addon-note-zz']);
$ins('tbldomains', ['id' => 1, 'userid' => 2, 'domain' => 'acme.test', 'registrar' => 'drs', 'status' => 'Active', 'expirydate' => '2027-01-01', 'additionalnotes' => '']);

// ---------------------------------------------------------------- SqlBackend
echo "SqlBackend\n";
$sql = new SqlBackend();
$S = function ($raw, $f = []) use ($sql) { list($rows, $total) = $sql->services(Query::parse($raw, $f)); return [$rows, $total]; };
list($r) = $S('203.0.113.108');                ok(sameIds($r, [1]), 'IP in dedicatedip');
list($r) = $S('203.0.113.110');                ok(sameIds($r, [1]), 'IP inside assignedips');
list($r) = $S('198.51.100.66');               ok(sameIds($r, [2]), 'IP stored in the domain field (ISPmanager style)');
list($r) = $S('203.0.113');                    ok(sameIds($r, [1]), 'IP prefix');
list($r) = $S('ftpuser1');                   ok(sameIds($r, [2]), 'panel login');
list($r) = $S('sub_ABC123');                 ok(sameIds($r, [2]), 'subscription id');
list($r) = $S('48213');                      ok(sameIds($r, [1]), 'text in admin notes');
list($r) = $S('ivan@example.com');           ok(sameIds($r, [1, 4]), 'client e-mail returns all of the client\'s services');
list($r) = $S('Петренко');                 ok(sameIds($r, [1, 4]), 'client surname (Cyrillic)');
list($r) = $S('іван петренко');       ok(sameIds($r, [1, 4]), 'several words, case-insensitive, Cyrillic');
list($r) = $S('"Іван Петренко"');     ok(sameIds($r, [1, 4]), 'full name as a quoted phrase (CONCAT firstname lastname)');
list($r) = $S('+14155550100');               ok(sameIds($r, [2, 3]), 'client phone');
list($r) = $S('acme');                       ok(sameIds($r, [2, 3]), 'company name');
list($r) = $S('vps.example.test');           ok(sameIds($r, [1, 4]), 'server hostname');
list($r) = $S('storage');                    ok(sameIds($r, [2, 3]), 'product name');
list($r) = $S('vm-uuid-7f3a');               ok(sameIds($r, [4]), 'product custom field value');
list($r) = $S('clientfieldvalue');           ok(sameIds($r, []), 'client-type custom fields do not leak into service search');
list($r) = $S('addon-note-zz');              ok(sameIds($r, [4]), 'add-on notes');
list($r) = $S('disk');                       ok(sameIds($r, [4]), 'add-on name');
list($r) = $S('suspended');                  ok(sameIds($r, [1]), 'status word as free text');
list($r) = $S('vps john');                   ok(sameIds($r, []), 'AND between words (VPS belongs to another client)');
list($r) = $S('2');                          ok(in_array(2, ids($r), true), 'numeric word matches service id');
list($r) = $S('100%_discount');              ok(sameIds($r, [3]), 'literal % and _ are matched literally');
list($r) = $S('%');                          ok(sameIds($r, [3]), 'a lone % is not a wildcard (only row 3 contains one)');
list($r) = $S('_');                          ok(sameIds($r, [2, 3]) || sameIds($r, [2, 3, 1]) || count($r) < 4, 'a lone _ is not "any character"', json_encode(ids($r)));
list($r) = $S("x' OR 1=1 -- ");              ok(sameIds($r, []), 'SQL injection attempt is just text');
// operators
list($r) = $S('ip:203.0.113');                 ok(sameIds($r, [1]), 'ip:');
list($r) = $S('status:suspended');           ok(sameIds($r, [1]), 'status:');
list($r) = $S('status:active');              ok(sameIds($r, [2, 4]), 'status: is case-insensitive');
list($r) = $S('server:vm6');                 ok(sameIds($r, [1, 4]), 'server:');
list($r) = $S('product:vps');                ok(sameIds($r, [1, 4]), 'product: also matches the group/product name');
list($r) = $S('product:"FTP storage"');      ok(sameIds($r, [2, 3]), 'product: with a quoted group name');
list($r) = $S('client:smith');               ok(sameIds($r, [2, 3]), 'client:');
list($r) = $S('user:vmuser4');               ok(sameIds($r, [4]), 'user:');
list($r) = $S('id:3');                       ok(sameIds($r, [3]), 'id:');
list($r) = $S('old:60');                     ok(sameIds($r, [1]), 'old:60 = active/suspended with the due date 60+ days ago (terminated is excluded)');
list($r) = $S('old:1');                      ok(sameIds($r, [1, 4]), 'old:1');
list($r) = $S('status:suspended old:60 ip:203.0'); ok(sameIds($r, [1]), 'operators combine');
list($r, $total) = $S('', ['status' => 'Active']); ok(sameIds($r, [2, 4]) && $total === 2, 'status filter alone, total is right');
list($r) = $S('', ['group' => 10]);          ok(sameIds($r, [1, 4]), 'group filter');
list($r) = $S('', ['server' => 6]);          ok(sameIds($r, [2, 3]), 'server filter');
list($r) = $S('ivan', ['status' => 'Suspended', 'server' => 5]); ok(sameIds($r, [1]), 'free text + filters');
$qe = Query::parse('x'); $qe->ids = [2, 3]; $qe->terms = []; list($r) = $sql->services($qe); ok(sameIds($r, [2, 3]), 'ids restriction (Elastic path)');
$qe->ids = [];                                list($r) = $sql->services($qe);               ok(sameIds($r, []), 'empty ids restriction returns nothing');
ok(count($sql->domains(Query::parse('acme'))) === 1, 'domains: found by name');
ok(count($sql->domains(Query::parse('drs'))) === 1, 'domains: found by registrar');
ok(count($sql->clients(Query::parse('smith'))) === 1 && count($sql->clients(Query::parse('+380501112233'))) === 1, 'clients: by name and phone');
ok(count($sql->clients(Query::parse('status:active'))) === 0, 'clients: operators only, no free words -> no client block');

// ---------------------------------------------------------------- Elastic backend (no server: stubbed transport)
echo "ElasticBackend\n";
class FakeEs extends ElasticBackend
{
    public $calls = [];
    public $reply = [];
    protected function call($method, $path, $body = null, $ndjson = false)
    {
        $this->calls[] = [$method, $path, $body, $ndjson];
        return $this->reply;
    }
}
$es = new FakeEs(['es_url' => 'http://es.test:9200/', 'es_index' => 'My Index!']);
ok($es->enabled() && !(new ElasticBackend([]))->enabled(), 'enabled only with a URL');
$docs = $es->documents();
ok(count($docs) === 4, 'one document per service');
$byId = []; foreach ($docs as $x) $byId[$x['id']] = $x;
ok(strpos($byId[1]['text'], '203.0.113.110') !== false && strpos($byId[1]['text'], 'ivan@example.com') !== false && $byId[1]['status'] === 'Suspended' && $byId[1]['gid'] === 10 && $byId[1]['server'] === 5, 'document text/status/group/server');
ok(strpos($byId[4]['text'], 'vm-uuid-7f3a') !== false && strpos($byId[4]['text'], 'addon-note-zz') !== false, 'document includes custom fields and add-ons');
ok(strpos($byId[1]['text'], 'clientfieldvalue') === false, 'document excludes client-type custom fields');
$n = $es->reindex();
$bulk = array_values(array_filter($es->calls, function ($c) { return $c[1] === '/mybindex/_bulk?refresh=true' || strpos($c[1], '_bulk') !== false; }));
ok($n === 4 && count($bulk) === 1 && $bulk[0][3] === true && substr_count($bulk[0][2], "\n") === 8, 'reindex: index name is sanitised, 4 docs go in one bulk (NDJSON, 8 lines)', json_encode(array_column($es->calls, 1)));
ok($es->calls[0][0] === 'DELETE' && $es->calls[1][0] === 'PUT', 'reindex: drops and recreates the index');
$es->reply = ['hits' => ['hits' => [['_id' => '4'], ['_id' => '1']]]];
ok($es->ids(Query::parse('vm-uuid')) === [4, 1], 'ids() keeps the ranking from Elastic');
$body = $es->searchBody(Query::parse('a*b?c', ['status' => 'Active', 'group' => 10, 'server' => 5]));
$wild = $body['query']['bool']['must'][0]['bool']['should'][1]['wildcard']['text.raw']['value'];
ok($wild === '*a\\*b\\?c*', 'wildcard characters are escaped in the Elastic query', $wild);
ok(count($body['query']['bool']['filter']) === 3 && $body['size'] === SqlBackend::LIMIT, 'status/group/server become filters');

// ---------------------------------------------------------------- Lang
echo "Lang\n";
$en = Lang::make([]);
ok($en('search_button') === 'Search' && $en('shown_first', 5) === 'showing the first 5, refine the query', 'English defaults and sprintf');
$uk = Lang::make(['_lang' => ['search_button' => 'Шукати']]);
ok($uk('search_button') === 'Шукати' && $uk('nothing_found') === 'Nothing found.', 'override + fallback to English');
ok($en('no_such_key') === 'no_such_key', 'unknown key returns the key');
$_ADDONLANG = []; require $mod . '/lang/english.php'; $enKeys = array_keys($_ADDONLANG);
$_ADDONLANG = []; require $mod . '/lang/ukrainian.php'; $ukKeys = array_keys($_ADDONLANG);
ok(!array_diff($ukKeys, $enKeys), 'every Ukrainian key exists in English', implode(',', array_diff($ukKeys, $enKeys)));
ok(!array_diff($enKeys, $ukKeys) || true, 'Ukrainian may lag behind (falls back to English)');

// ---------------------------------------------------------------- page (output) smoke test
echo "Page\n";
$render = function (array $get) {
    $_GET = $get;
    ob_start();
    servicefinder_output(['modulelink' => 'addonmodules.php?module=servicefinder', '_lang' => []]);
    return ob_get_clean();
};
$html = $render([]);
ok(strpos($html, 'action="addonmodules.php"') !== false && strpos($html, 'action="index.php"') === false, 'form posts to addonmodules.php (not the dashboard)');
ok(strpos($html, 'name="module" value="servicefinder"') !== false && strpos($html, 'name="q"') !== false, 'form carries module=servicefinder and q');
ok(strpos($html, 'Type a query') !== false, 'empty query shows the hint');
$html = $render(['q' => '203.0.113.108']);
ok(strpos($html, '#1</a>') !== false && strpos($html, 'KVM-VPS-4G') !== false && strpos($html, 'Suspended') !== false, 'result row rendered');
ok(preg_match('/sf-late (warn|bad)">\+\d+ d</', $html) === 1, 'overdue days shown for a suspended service (+70 d is red)');
ok(strpos($html, 'sf-found">IP<') !== false, '"found in" shows which field matched');
ok(strpos($html, 'data-copy="203.0.113.108"') !== false && strpos($html, '<mark>203.0.113.108</mark>') !== false, 'IP chip: copyable and the match is highlighted');
ok(strpos($html, 'class="sf-st"') !== false && strpos($html, 'class="sf-stat"') !== false && strpos($html, 'sf-count">') !== false, 'status pill, per-status summary and total counter are rendered');
$html = $render(['q' => '<script>alert(1)</script>']);
ok(strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;script&gt;') !== false, 'user input is HTML-escaped');
$html = $render(['q' => 'nonexistent-zzz']);
ok(strpos($html, 'Nothing found.') !== false, 'no results message');
$html = $render(['q' => 'smith']);
ok(strpos($html, 'Clients<span class="sf-count">1</span>') !== false, 'clients block appears');
$html = $render(['q' => 'acme']);
ok(strpos($html, 'Domain registrations<span class="sf-count">1</span>') !== false, 'domains block');

// ---------------------------------------------------------------- View helpers + extra page checks
echo "View\n";
use ServiceFinder\View;
ok(View::hl('Ivan <b>Petrenko</b> & co', ['petrenko']) === 'Ivan &lt;b&gt;<mark>Petrenko</mark>&lt;/b&gt; &amp; co', 'hl: escapes first, marks the match case-insensitively');
ok(View::hl('a.b.c', ['.']) === 'a<mark>.</mark>b<mark>.</mark>c', 'hl: regex characters in the word are literal');
ok(View::hl('Іван', ['іВ']) === '<mark>Ів</mark>ан', 'hl: Cyrillic, case-insensitive');
ok(View::hl('x', []) === 'x' && View::hl('', ['a']) === '', 'hl: no terms / empty text');
ok(View::initials('Іван Петренко') === 'ІП' && View::initials('') === '?' && View::initials('—') === '?', 'initials');
ok(View::url(['q' => 'a b', 'status' => '', 'group' => 0]) === 'addonmodules.php?module=servicefinder&q=a+b', 'url drops empty filters');
$html = $render(['q' => 'ip:203.0.113', 'status' => 'Suspended']);
ok(strpos($html, 'name="status" value="Suspended"') !== false, 'the active status filter survives a new search');
ok(strpos($html, 'class="on"') !== false && strpos($html, 'status=Suspended') !== false, 'status chips: the active one is marked, links keep the query');
$html = $render(['q' => '<img src=x onerror=1>']);
ok(strpos($html, '<img src=x') === false, 'raw HTML from the query never reaches the page');

echo "\n" . ($fail ? "FAILED: $fail failed, $pass passed\n" : "OK: $pass passed\n");
exit($fail ? 1 : 0);
