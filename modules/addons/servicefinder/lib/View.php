<?php

namespace ServiceFinder;

/** HTML helpers for the admin page. Everything user-controlled goes through h() / hl(); the CSS is scoped to .sf. */
class View
{
    public static function h($v)
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    /** escape $text and wrap every occurrence of the search words in <mark> */
    public static function hl($text, array $terms)
    {
        $text = (string)$text;
        $terms = array_values(array_filter(array_unique($terms), 'strlen'));
        if (!$terms || $text === '') return self::h($text);
        usort($terms, function ($a, $b) { return strlen($b) - strlen($a); });
        $re = '/(' . implode('|', array_map(function ($t) { return preg_quote($t, '/'); }, $terms)) . ')/iu';
        $parts = preg_split($re, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return self::h($text);
        $out = '';
        foreach ($parts as $i => $p) $out .= $i % 2 ? '<mark>' . self::h($p) . '</mark>' : self::h($p);
        return $out;
    }

    public static function url(array $params)
    {
        return 'addonmodules.php?' . http_build_query(array_filter(['module' => 'servicefinder'] + $params, function ($v) { return $v !== '' && $v !== 0 && $v !== null; }));
    }

    public static function initials($name)
    {
        $name = trim((string)$name);
        if ($name === '' || $name === '—') return '?';
        $w = preg_split('/\s+/u', $name);
        $a = mb_strtoupper(mb_substr($w[0], 0, 1));
        $b = isset($w[1]) ? mb_strtoupper(mb_substr($w[1], 0, 1)) : '';
        return $a . $b;
    }

    public static function hue($seed)
    {
        return crc32((string)$seed) % 360;
    }

    public static function css()
    {
        return <<<'CSS'
<style>
.sf{--sf-ink:#111827;--sf-muted:#6b7280;--sf-line:#e5e7eb;--sf-soft:#f3f4f6;--sf-card:#fff;--sf-accent:#2563eb;--sf-accent2:#7c3aed;font-size:13.5px;color:var(--sf-ink)}
.sf *{box-sizing:border-box}
.sf-hero{background:linear-gradient(135deg,#eef2ff 0%,#f5f3ff 60%,#fdf2f8 100%);border:1px solid #e0e7ff;border-radius:16px;padding:18px 18px 12px;margin:4px 0 16px}
.sf-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:stretch}
.sf-input-wrap{position:relative;flex:1 1 360px;min-width:220px}
.sf-input-wrap svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:20px;height:20px;color:#6366f1;pointer-events:none}
.sf-input{width:100%;height:50px;padding:0 16px 0 44px;border:1px solid #c7d2fe;border-radius:12px;font-size:16px;background:#fff;box-shadow:0 1px 2px rgba(17,24,39,.06),0 4px 14px rgba(99,102,241,.10);outline:none;transition:box-shadow .15s,border-color .15s}
.sf-input:focus{border-color:#6366f1;box-shadow:0 0 0 4px rgba(99,102,241,.18),0 4px 14px rgba(99,102,241,.12)}
.sf-select{height:50px;padding:0 30px 0 12px;border:1px solid #c7d2fe;border-radius:12px;background:#fff;font-size:13.5px;color:var(--sf-ink);max-width:190px}
.sf-btn{height:50px;padding:0 26px;border:0;border-radius:12px;font-weight:600;font-size:14.5px;color:#fff;background:linear-gradient(135deg,var(--sf-accent),var(--sf-accent2));box-shadow:0 4px 12px rgba(79,70,229,.35);cursor:pointer;transition:transform .08s,box-shadow .15s}
.sf-btn:hover{box-shadow:0 6px 16px rgba(79,70,229,.45)}.sf-btn:active{transform:translateY(1px)}
.sf-row2{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px}
.sf-seg a,.sf-chip{display:inline-block;padding:5px 12px;border-radius:999px;font-size:12.5px;text-decoration:none;border:1px solid #c7d2fe;background:rgba(255,255,255,.75);color:#3730a3;transition:background .12s}
.sf-seg a:hover,.sf-chip:hover{background:#fff;text-decoration:none;color:#312e81}
.sf-seg a.on{background:#4f46e5;border-color:#4f46e5;color:#fff}
.sf-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--sf-muted);margin-right:2px}
.sf-help{margin:10px 2px 0;font-size:12px;color:var(--sf-muted)}
.sf-help summary{cursor:pointer;user-select:none}
.sf-help code{background:#eef2ff;color:#3730a3;border-radius:6px;padding:1px 6px}
.sf-sum{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:2px 0 10px}
.sf-sum h4{margin:0 8px 0 0;font-size:16px;font-weight:700}
.sf-count{display:inline-block;min-width:24px;text-align:center;padding:1px 9px;border-radius:999px;background:#eef2ff;color:#3730a3;font-weight:700;font-size:12px;margin-left:6px}
.sf-stat{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;background:var(--sf-soft);font-size:12px;color:#374151}
.sf-dot{width:8px;height:8px;border-radius:50%;display:inline-block;background:#9ca3af}
.sf-card{background:var(--sf-card);border:1px solid var(--sf-line);border-radius:14px;box-shadow:0 1px 2px rgba(17,24,39,.04);overflow:hidden;margin-bottom:18px}
.sf-table{width:100%;border-collapse:collapse}
.sf-table th{background:#f9fafb;color:var(--sf-muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;padding:10px 14px;text-align:left;border-bottom:1px solid var(--sf-line);white-space:nowrap}
.sf-table td{padding:11px 14px;border-bottom:1px solid #f1f2f4;vertical-align:top}
.sf-table tr:last-child td{border-bottom:0}
.sf-table tbody tr{transition:background .1s}.sf-table tbody tr:hover{background:#fafaff}
.sf-table tbody tr td:first-child{box-shadow:inset 3px 0 0 var(--st,#d1d5db)}
.sf-id{font-weight:700;color:#4f46e5;text-decoration:none}.sf-id:hover{text-decoration:underline}
.sf-person{display:flex;gap:10px;align-items:center}
.sf-av{flex:0 0 32px;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;color:#fff}
.sf-person a{font-weight:600;color:var(--sf-ink);text-decoration:none}.sf-person a:hover{color:var(--sf-accent)}
.sf-sub{color:var(--sf-muted);font-size:12px}
.sf-pill{display:inline-block;padding:1px 8px;border-radius:6px;background:var(--sf-soft);color:#4b5563;font-size:11px;margin-top:3px}
.sf-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}
.sf-ip{display:inline-block;margin:2px 4px 0 0;padding:1px 8px;border-radius:6px;background:#ecfeff;color:#0e7490;border:1px solid #cffafe;cursor:copy;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.sf-ip:hover{background:#cffafe}.sf-ip.copied{background:#dcfce7;border-color:#bbf7d0;color:#166534}
.sf-user{color:var(--sf-muted);font-size:12px}
.sf-st{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600}
.sf-late{display:inline-block;margin-left:6px;padding:0 7px;border-radius:999px;font-size:11px;font-weight:700}
.sf-late.warn{background:#fef3c7;color:#92400e}.sf-late.bad{background:#fee2e2;color:#991b1b}
.sf-found{display:inline-block;margin:0 4px 3px 0;padding:0 7px;border-radius:6px;background:#fef9c3;color:#854d0e;font-size:11px}
.sf mark{background:#fde68a;color:inherit;border-radius:3px;padding:0 1px}
.sf-empty{padding:34px 20px;text-align:center;color:var(--sf-muted)}
.sf-empty b{display:block;font-size:15px;color:var(--sf-ink);margin-bottom:4px}
.sf-lists{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}
.sf-lists h5{margin:0;padding:11px 14px;font-size:13px;font-weight:700;border-bottom:1px solid var(--sf-line);background:#f9fafb}
.sf-li{padding:9px 14px;border-bottom:1px solid #f1f2f4}.sf-li:last-child{border-bottom:0}
.sf-alert{padding:10px 14px;border-radius:10px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;margin-bottom:12px}
@media (max-width:900px){.sf-hide-s{display:none}.sf-select{max-width:none;flex:1 1 140px}.sf-btn{flex:1 1 100%}.sf-table th,.sf-table td{padding:9px 10px}}
</style>
CSS;
    }

    public static function js()
    {
        return <<<'JS'
<script>
(function(){
  var root=document.querySelector('.sf'); if(!root) return;
  root.addEventListener('click',function(e){
    var el=e.target.closest('.sf-ip'); if(!el) return;
    var t=el.getAttribute('data-copy')||el.textContent;
    var done=function(){el.classList.add('copied');setTimeout(function(){el.classList.remove('copied')},900)};
    if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(done)}
    else{var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();try{document.execCommand('copy');done()}catch(x){}document.body.removeChild(a)}
  });
  document.addEventListener('keydown',function(e){
    if(e.key==='/'&&!/input|textarea|select/i.test((document.activeElement||{}).tagName||'')){var i=root.querySelector('.sf-input');if(i){e.preventDefault();i.focus();i.select()}}
  });
})();
</script>
JS;
    }

    public static function statusColor($status)
    {
        $map = ['Active' => ['#16a34a', '#dcfce7', '#166534'], 'Suspended' => ['#d97706', '#fef3c7', '#92400e'], 'Terminated' => ['#6b7280', '#f3f4f6', '#374151'],
            'Cancelled' => ['#9ca3af', '#f3f4f6', '#4b5563'], 'Pending' => ['#2563eb', '#dbeafe', '#1e40af'], 'Fraud' => ['#dc2626', '#fee2e2', '#991b1b'], 'Completed' => ['#7c3aed', '#ede9fe', '#5b21b6']];
        return $map[$status] ?? ['#9ca3af', '#f3f4f6', '#374151'];
    }
}
