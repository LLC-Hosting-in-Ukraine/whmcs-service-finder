<?php

namespace ServiceFinder;

/**
 * Parsed search request. Free words are matched in ANY field (AND between words); operators narrow it:
 *   status:suspended  ip:203.0.113  server:vm6  product:storage  client:ivanov  user:ftpuser1  id:2090  old:60
 * "old:N" = next due date was N or more days ago. Words in "quotes" are one phrase.
 */
class Query
{
    const OPS = ['status', 'ip', 'server', 'product', 'client', 'user', 'id', 'old'];

    public $raw = '';
    public $terms = [];
    public $ops = [];
    public $status = '';
    public $group = 0;
    public $serverId = 0;
    /** set by the Elastic backend: restrict to these service ids (free words are then not re-applied) */
    public $ids = null;

    public static function parse($raw, array $filters = [])
    {
        $q = new self();
        $q->raw = trim((string)$raw);
        $q->status = (string)($filters['status'] ?? '');
        $q->group = (int)($filters['group'] ?? 0);
        $q->serverId = (int)($filters['server'] ?? 0);
        // tokens: key:"quoted value" | "quoted phrase" | plain
        preg_match_all('/(?:([A-Za-z]+):)?"([^"]+)"|(\S+)/u', $q->raw, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            if (isset($x[3]) && $x[3] !== '') {
                $key = ''; $val = $x[3];
                if (preg_match('/^([A-Za-z]+):(.+)$/u', $val, $kv) && in_array(strtolower($kv[1]), self::OPS, true)) { $key = strtolower($kv[1]); $val = $kv[2]; }
            } else {
                $key = strtolower($x[1] ?? ''); $val = $x[2];
                if ($key !== '' && !in_array($key, self::OPS, true)) { $val = $x[1] . ':' . $val; $key = ''; }
            }
            $val = trim($val, '"');
            if ($val === '') continue;
            if ($key !== '') $q->ops[$key][] = $val; else $q->terms[] = $val;
        }
        return $q;
    }

    public function isEmpty()
    {
        return !$this->terms && !$this->ops && $this->status === '' && !$this->group && !$this->serverId && $this->ids === null;
    }

    public static function like($s)
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
    }
}
