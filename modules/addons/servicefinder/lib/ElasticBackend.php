<?php

namespace ServiceFinder;

use WHMCS\Database\Capsule;

/**
 * Optional backend (settings: backend = elastic). The index holds one document per service with all searchable text in
 * "text"; the search returns service ids and the page loads the rows from SQL (so the table is always up to date).
 * Any error falls back to SqlBackend. Needs an Elasticsearch/OpenSearch reachable by HTTP; keep it off the billing host.
 */
class ElasticBackend
{
    private $url;
    private $index;
    private $auth;

    public function __construct(array $s)
    {
        $this->url = rtrim((string)($s['es_url'] ?? ''), '/');
        $this->index = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string)($s['es_index'] ?? 'whmcs_services'))) ?: 'whmcs_services';
        $user = (string)($s['es_user'] ?? '');
        $this->auth = $user !== '' ? $user . ':' . (string)($s['es_password'] ?? '') : '';
    }

    public function enabled()
    {
        return $this->url !== '';
    }

    protected function call($method, $path, $body = null, $ndjson = false)
    {
        $ch = curl_init($this->url . $path);
        $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . ($ndjson ? 'application/x-ndjson' : 'application/json')]];
        if ($this->auth !== '') $opt[CURLOPT_USERPWD] = $this->auth;
        if ($body !== null) $opt[CURLOPT_POSTFIELDS] = $ndjson ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch, $opt);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false || $code >= 300) throw new \RuntimeException("Elastic $method $path: HTTP $code $err " . substr((string)$out, 0, 160));
        return json_decode($out, true) ?: [];
    }

    /** one flat document per service; text = everything a person might type */
    public function documents()
    {
        $rows = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
            ->leftJoin('tblservers as s', 's.id', '=', 'h.server')
            ->get(['h.id', 'h.domain', 'h.dedicatedip', 'h.assignedips', 'h.username', 'h.notes', 'h.subscriptionid', 'h.domainstatus', 'h.suspendreason', 'p.gid',
                'p.name as product', 's.name as server', 's.hostname', 'h.server as server_id', 'c.firstname', 'c.lastname', 'c.companyname', 'c.email', 'c.phonenumber']);
        $custom = [];
        foreach (Capsule::table('tblcustomfieldsvalues as v')->join('tblcustomfields as f', function ($j) { $j->on('f.id', '=', 'v.fieldid')->where('f.type', '=', 'product'); })->get(['v.relid', 'v.value']) as $v) {
            $custom[$v->relid][] = $v->value;
        }
        foreach (Capsule::table('tblhostingaddons')->get(['hostingid', 'name', 'notes']) as $a) {
            $custom[$a->hostingid][] = $a->name . ' ' . $a->notes;
        }
        $docs = [];
        foreach ($rows as $r) {
            $text = implode(' ', array_filter([$r->id, $r->domain, $r->dedicatedip, $r->assignedips, $r->username, $r->notes, $r->subscriptionid, $r->suspendreason,
                $r->product, $r->server, $r->hostname, $r->firstname, $r->lastname, $r->companyname, $r->email, $r->phonenumber, implode(' ', $custom[$r->id] ?? [])], 'strlen'));
            $docs[] = ['id' => (int)$r->id, 'text' => $text, 'status' => $r->domainstatus, 'gid' => (int)$r->gid, 'server' => (int)$r->server_id];
        }
        return $docs;
    }

    /** full rebuild (a few hundred services: seconds) */
    public function reindex()
    {
        try { $this->call('DELETE', '/' . $this->index); } catch (\Throwable $e) { /* absent: fine */ }
        $this->call('PUT', '/' . $this->index, ['mappings' => ['properties' => [
            'text' => ['type' => 'text', 'analyzer' => 'standard', 'fields' => ['raw' => ['type' => 'wildcard']]],
            'status' => ['type' => 'keyword'], 'gid' => ['type' => 'integer'], 'server' => ['type' => 'integer']]]]);
        $docs = $this->documents();
        foreach (array_chunk($docs, 500) as $chunk) {
            $nd = '';
            foreach ($chunk as $d) $nd .= json_encode(['index' => ['_index' => $this->index, '_id' => $d['id']]]) . "\n" . json_encode($d, JSON_UNESCAPED_UNICODE) . "\n";
            $res = $this->call('POST', '/_bulk?refresh=true', $nd, true);
            if (!empty($res['errors'])) throw new \RuntimeException('Elastic bulk reported errors');
        }
        return count($docs);
    }

    /** the _search body for a parsed query (public so tests can check it) */
    public function searchBody(Query $q)
    {
        $must = [];
        foreach ($q->terms as $t) {
            $must[] = ['bool' => ['should' => [
                ['match_phrase_prefix' => ['text' => $t]],
                ['wildcard' => ['text.raw' => ['value' => '*' . str_replace(['\\', '*', '?'], ['\\\\', '\\*', '\\?'], strtolower($t)) . '*', 'case_insensitive' => true]]],
            ], 'minimum_should_match' => 1]];
        }
        $filter = [];
        if ($q->status !== '') $filter[] = ['term' => ['status' => $q->status]];
        if ($q->group) $filter[] = ['term' => ['gid' => $q->group]];
        if ($q->serverId) $filter[] = ['term' => ['server' => $q->serverId]];
        return ['size' => SqlBackend::LIMIT, '_source' => false,
            'query' => ['bool' => ['must' => $must ?: [['match_all' => (object)[]]], 'filter' => $filter]]];
    }

    /** @return int[] ids, best first */
    public function ids(Query $q)
    {
        $res = $this->call('POST', '/' . $this->index . '/_search', $this->searchBody($q));
        return array_map(function ($h) { return (int)$h['_id']; }, $res['hits']['hits'] ?? []);
    }
}
