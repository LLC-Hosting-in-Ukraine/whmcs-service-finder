<?php

namespace ServiceFinder;

use WHMCS\Database\Capsule;

/** Default backend: LIKE over every field of a service (plain SQL, fast enough for thousands of rows). */
class SqlBackend
{
    const LIMIT = 300;

    /** columns that "any field" search looks at (all on tblhosting h / tblclients c / tblproducts p / tblservers s) */
    const FIELDS = [
        'h.domainstatus', 'h.domain', 'h.dedicatedip', 'h.assignedips', 'h.username', 'h.notes', 'h.subscriptionid', 'h.suspendreason',
        'c.firstname', 'c.lastname', 'c.companyname', 'c.email', 'c.phonenumber',
        'p.name', 's.name', 's.hostname', 's.ipaddress',
    ];

    /** @return array [rows, total] */
    public function services(Query $q)
    {
        // GROUP_CONCAT is cut at 1024 bytes by default on MySQL; the value is per session and harmless
        Capsule::connection()->statement('SET SESSION group_concat_max_len = 1048576');
        $db = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->leftJoin('tblproductgroups as g', 'g.id', '=', 'p.gid')
            ->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
            ->leftJoin('tblservers as s', 's.id', '=', 'h.server')
            // custom field values and add-ons are folded into one text per service (one pass, not one subquery per word)
            ->leftJoin(Capsule::raw('(SELECT v.relid, GROUP_CONCAT(v.value SEPARATOR \' \') AS txt FROM tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id = v.fieldid AND f.type = \'product\' GROUP BY v.relid) cf'), 'cf.relid', '=', 'h.id')
            ->leftJoin(Capsule::raw('(SELECT a.hostingid, GROUP_CONCAT(CONCAT_WS(\' \', a.name, a.notes) SEPARATOR \' \') AS txt FROM tblhostingaddons a GROUP BY a.hostingid) ad'), 'ad.hostingid', '=', 'h.id');

        if ($q->ids !== null) { $q->ids ? $db->whereIn('h.id', $q->ids) : $db->whereRaw('1=0'); }
        else foreach ($q->terms as $t) $this->anyField($db, $t);

        foreach ($q->ops as $op => $vals) {
            foreach ($vals as $v) {
                $like = Query::like($v);
                switch ($op) {
                    case 'status': $db->where('h.domainstatus', $this->statusName($v)); break;
                    case 'ip': $db->where(function ($w) use ($like) { $w->where('h.dedicatedip', 'like', $like)->orWhere('h.assignedips', 'like', $like)->orWhere('h.domain', 'like', $like); }); break;
                    case 'server': $db->where(function ($w) use ($like) { $w->where('s.name', 'like', $like)->orWhere('s.hostname', 'like', $like); }); break;
                    case 'product': $db->where(function ($w) use ($like) { $w->where('p.name', 'like', $like)->orWhere('g.name', 'like', $like); }); break;
                    case 'client': $db->where(function ($w) use ($like) { $w->where('c.firstname', 'like', $like)->orWhere('c.lastname', 'like', $like)->orWhere('c.companyname', 'like', $like)->orWhere('c.email', 'like', $like)->orWhereRaw("CONCAT(c.firstname,' ',c.lastname) LIKE ?", [$like]); }); break;
                    case 'user': $db->where('h.username', 'like', $like); break;
                    case 'id': if (ctype_digit($v)) $db->where('h.id', (int)$v); break;
                    case 'old': if (ctype_digit($v)) $db->whereIn('h.domainstatus', ['Active', 'Suspended'])->whereRaw('DATEDIFF(CURDATE(), h.nextduedate) >= ?', [(int)$v]); break;
                }
            }
        }
        if ($q->status !== '') $db->where('h.domainstatus', $q->status);
        if ($q->group) $db->where('p.gid', $q->group);
        if ($q->serverId) $db->where('h.server', $q->serverId);

        $total = (clone $db)->count();
        $rows = $db->orderBy('h.id', 'desc')->limit(self::LIMIT)->get([
            'h.id', 'h.userid', 'h.domainstatus', 'h.domain', 'h.dedicatedip', 'h.assignedips', 'h.username', 'h.notes', 'h.domainstatus',
            'h.nextduedate', 'h.amount', 'h.billingcycle', 'h.suspendreason', 'h.subscriptionid',
            'p.name as product', 'g.name as grp', 's.name as server', 's.hostname as server_host',
            'c.firstname', 'c.lastname', 'c.companyname', 'c.email', 'c.phonenumber',
        ])->all();
        return [$rows, $total];
    }

    /** one word must be found in any field, in a custom field value, or in an add-on of the service */
    private function anyField($db, $t)
    {
        $like = Query::like($t);
        $db->where(function ($w) use ($t, $like) {
            foreach (self::FIELDS as $f) $w->orWhere($f, 'like', $like);
            $w->orWhereRaw("CONCAT(c.firstname,' ',c.lastname) LIKE ?", [$like]);
            $w->orWhere('cf.txt', 'like', $like)->orWhere('ad.txt', 'like', $like);
            if (ctype_digit($t)) { $w->orWhere('h.id', (int)$t)->orWhere('h.userid', (int)$t); }
        });
    }

    private function statusName($v)
    {
        $map = ['active' => 'Active', 'suspended' => 'Suspended', 'terminated' => 'Terminated', 'cancelled' => 'Cancelled', 'canceled' => 'Cancelled',
            'pending' => 'Pending', 'fraud' => 'Fraud', 'completed' => 'Completed'];
        return $map[strtolower($v)] ?? $v;
    }

    /** domains (registrations) matching the free words */
    public function domains(Query $q, $limit = 50)
    {
        if (!$q->terms) return [];
        $db = Capsule::table('tbldomains as d')->leftJoin('tblclients as c', 'c.id', '=', 'd.userid');
        foreach ($q->terms as $t) {
            $like = Query::like($t);
            $db->where(function ($w) use ($like, $t) {
                $w->where('d.domain', 'like', $like)->orWhere('d.registrar', 'like', $like)->orWhere('d.additionalnotes', 'like', $like)
                    ->orWhere('c.email', 'like', $like)->orWhereRaw("CONCAT(c.firstname,' ',c.lastname) LIKE ?", [$like]);
                if (ctype_digit($t)) $w->orWhere('d.id', (int)$t);
            });
        }
        return $db->orderBy('d.id', 'desc')->limit($limit)->get(['d.id', 'd.userid', 'd.domain', 'd.registrar', 'd.status', 'd.expirydate', 'c.firstname', 'c.lastname'])->all();
    }

    /** clients matching the free words (name, company, e-mail, phone, id) */
    public function clients(Query $q, $limit = 30)
    {
        if (!$q->terms) return [];
        $db = Capsule::table('tblclients as c');
        foreach ($q->terms as $t) {
            $like = Query::like($t);
            $db->where(function ($w) use ($like, $t) {
                $w->where('c.firstname', 'like', $like)->orWhere('c.lastname', 'like', $like)->orWhere('c.companyname', 'like', $like)
                    ->orWhere('c.email', 'like', $like)->orWhere('c.phonenumber', 'like', $like)->orWhereRaw("CONCAT(c.firstname,' ',c.lastname) LIKE ?", [$like]);
                if (ctype_digit($t)) $w->orWhere('c.id', (int)$t);
            });
        }
        return $db->orderByDesc('c.id')->limit($limit)->get(['c.id', 'c.firstname', 'c.lastname', 'c.companyname', 'c.email', 'c.phonenumber', 'c.status'])->all();
    }
}
