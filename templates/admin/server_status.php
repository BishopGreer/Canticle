<?php
$hb = function(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . ' ' . $units[$i];
};
$csrf = \Canticle\Core\Session::csrfToken();

$oc  = $cacheInfo['opcache'];
$apc = $cacheInfo['apcu'];
$rp  = $cacheInfo['realpath'];
$ws  = $cacheInfo['webserver'];
$ses = $cacheInfo['session'];

function statusBadge(bool $ok, string $yes = 'Yes', string $no = 'No'): string {
    return $ok
        ? '<span class="badge-pill badge-success">' . $yes . '</span>'
        : '<span class="badge-pill badge-danger">'  . $no  . '</span>';
}
function warnBadge(bool $warn, string $label): string {
    return $warn
        ? '<span class="badge-pill badge-warning">' . $label . '</span>'
        : '<span class="badge-pill badge-success">OK</span>';
}
?>

<h1>Server Status</h1>

<!-- ── System Information ───────────────────────────────────────── -->
<h2>System information</h2>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem">

  <div class="stat-card">
    <div class="lbl" style="margin-bottom:.3rem">Canticle</div>
    <div style="font-size:1.3rem;font-weight:700;color:var(--accent)">v<?= htmlspecialchars($sysInfo['canticle_version']) ?></div>
  </div>

  <div class="stat-card">
    <div class="lbl" style="margin-bottom:.3rem">PHP</div>
    <div style="font-size:1.1rem;font-weight:600"><?= htmlspecialchars($sysInfo['php_version']) ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:.2rem">
      SAPI: <code><?= htmlspecialchars($cacheInfo['sapi']) ?></code>
    </div>
  </div>

  <div class="stat-card">
    <div class="lbl" style="margin-bottom:.3rem">Web server</div>
    <div style="font-size:.95rem;font-weight:600"><?= htmlspecialchars($ws['software']) ?></div>
  </div>

  <div class="stat-card">
    <div class="lbl" style="margin-bottom:.3rem">MariaDB / MySQL</div>
    <div style="font-size:1rem;font-weight:600"><?= htmlspecialchars($sysInfo['db_version']) ?></div>
  </div>

  <div class="stat-card">
    <div class="lbl" style="margin-bottom:.3rem">Disk space</div>
    <div style="font-size:1rem;font-weight:600"><?= htmlspecialchars($sysInfo['disk_free']) ?> free</div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:.2rem">of <?= htmlspecialchars($sysInfo['disk_total']) ?></div>
  </div>

  <div class="stat-card">
    <div class="lbl" style="margin-bottom:.3rem">PHP limits</div>
    <div style="font-size:.85rem;line-height:1.7">
      Memory: <strong><?= htmlspecialchars($sysInfo['memory_limit']) ?></strong><br>
      Upload: <strong><?= htmlspecialchars($sysInfo['upload_max']) ?></strong><br>
      Max exec: <strong><?= htmlspecialchars($sysInfo['max_exec']) ?></strong>
    </div>
  </div>

</div>

<!-- Directories & extensions -->
<div class="admin-form" style="padding:1rem 1.25rem;margin-bottom:2rem">
  <div style="display:flex;flex-wrap:wrap;gap:1.5rem">
    <?php foreach ($sysInfo['dirs'] as $dir => $ok): ?>
      <div style="display:flex;align-items:center;gap:.4rem;font-size:.9rem">
        <span style="color:<?= $ok ? 'var(--success)' : 'var(--danger)' ?>"><?= $ok ? '✓' : '✗' ?></span>
        <code><?= htmlspecialchars($dir) ?>/</code>
        <span style="color:var(--muted)"><?= $ok ? 'writable' : 'NOT writable' ?></span>
      </div>
    <?php endforeach; ?>
    <?php foreach ($sysInfo['extensions'] as $ext => $ok): ?>
      <div style="display:flex;align-items:center;gap:.4rem;font-size:.9rem">
        <span style="color:<?= $ok ? 'var(--success)' : 'var(--danger)' ?>"><?= $ok ? '✓' : '✗' ?></span>
        <code><?= htmlspecialchars($ext) ?></code>
        <span style="color:var(--muted)"><?= $ok ? 'loaded' : 'MISSING' ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>


<!-- ── Cache Layers ─────────────────────────────────────────────── -->
<h2>Cache layers
  <span style="font-size:.8rem;font-weight:400;color:var(--muted);margin-left:.6rem">
    These can cause uploaded files to appear unchanged until flushed
  </span>
</h2>

<!-- Flush All -->
<form method="POST" action="/admin/server-status/flush" style="margin-bottom:1.5rem">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="cache_type" value="all">
  <button type="submit" class="btn-primary"
          onclick="return confirm('Flush all available caches now?')">
    ♻ Flush All Caches
  </button>
  <span style="color:var(--muted);font-size:.85rem;margin-left:.75rem">
    Clears OPcache, APCu, and realpath cache in one step.
  </span>
</form>


<!-- PHP-FPM Warning -->
<?php if ($cacheInfo['fpmWarning']): ?>
<div class="flash-error" style="margin-bottom:1.5rem;max-width:760px">
  <strong>PHP-FPM pool isolation warning</strong><br>
  Your server runs PHP via <strong>PHP-FPM</strong> with OPcache enabled and
  <code>opcache.validate_timestamps = Off</code>. When you click "Flush OPcache,"
  it only resets the cache inside the single FPM worker that handled your admin request.
  Other workers in the pool continue serving stale compiled bytecode until their TTL
  expires or you reload PHP-FPM.<br><br>
  <strong>Fix:</strong> SSH into the server and run:<br>
  <code style="display:block;margin-top:.4rem;padding:.4rem .6rem;background:rgba(0,0,0,.25);border-radius:4px">
    sudo systemctl reload php<?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?>-fpm
  </code>
  <span style="font-size:.82rem;color:var(--muted)">
    Or set <code>opcache.validate_timestamps = On</code> in php.ini so PHP always
    checks file modification times (slightly slower but eliminates stale cache issues).
  </span>
</div>
<?php elseif ($cacheInfo['isFpm']): ?>
<div class="admin-form" style="margin-bottom:1.5rem;max-width:760px;padding:.85rem 1.1rem;font-size:.9rem">
  <strong>PHP-FPM detected</strong> —
  OPcache has <code>validate_timestamps = On</code>, so PHP checks file modification
  times automatically. Stale cache should not be an issue.
</div>
<?php endif; ?>


<!-- ── OPcache ─────────────────────────────────────────────────── -->
<div class="admin-form" style="max-width:760px;padding:1.1rem 1.25rem;margin-bottom:1.25rem">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem">
    <h3 style="margin:0">PHP OPcache
      <span style="font-size:.8rem;font-weight:400;color:var(--muted);margin-left:.4rem">bytecode cache</span>
    </h3>
    <?php if ($oc['available']): ?>
      <?= statusBadge($oc['enabled'], 'Enabled', 'Disabled') ?>
    <?php else: ?>
      <span class="badge-pill badge-danger">Not installed</span>
    <?php endif; ?>
  </div>

  <?php if ($oc['available'] && $oc['enabled']): ?>
    <div style="display:flex;flex-wrap:wrap;gap:1.5rem;font-size:.9rem;margin-bottom:1rem">
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Memory used</div>
        <strong><?= $oc['pct'] ?>%</strong>
        <span style="color:var(--muted);font-size:.82rem">(<?= $hb($oc['used']) ?> / <?= $hb($oc['total']) ?>)</span>
      </div>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Cached scripts</div>
        <strong><?= number_format($oc['scripts']) ?></strong>
      </div>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Hits / Misses</div>
        <strong><?= number_format($oc['hits']) ?></strong> / <?= number_format($oc['misses']) ?>
      </div>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Revalidate freq</div>
        <strong><?= htmlspecialchars($oc['ttl']) ?>s</strong>
      </div>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Validate timestamps</div>
        <?= statusBadge($oc['validate'], 'On', 'Off') ?>
      </div>
    </div>

    <div style="padding-top:.85rem;border-top:1px solid var(--border)">
      <form method="POST" action="/admin/server-status/flush" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="cache_type" value="opcache">
        <button type="submit" class="btn btn-sm">♻ Flush OPcache</button>
      </form>
      <?php if ($cacheInfo['isFpm'] && !$oc['validate']): ?>
        <span style="color:var(--warning,#f59e0b);font-size:.82rem;margin-left:.6rem">
          ⚠ PHP-FPM: flush only affects this worker — other workers may still serve stale cache.
        </span>
      <?php else: ?>
        <span style="color:var(--muted);font-size:.82rem;margin-left:.6rem">
          Forces PHP to recompile all cached files on next request.
        </span>
      <?php endif; ?>
    </div>
  <?php elseif ($oc['available']): ?>
    <p style="color:var(--muted);font-size:.9rem;margin:0">
      OPcache is installed but disabled. Enable it in <code>php.ini</code> for better performance.
    </p>
  <?php else: ?>
    <p style="color:var(--muted);font-size:.9rem;margin:0">
      OPcache is not installed. Install the <code>php-opcache</code> package for better performance.
    </p>
  <?php endif; ?>
</div>


<!-- ── APCu ───────────────────────────────────────────────────── -->
<div class="admin-form" style="max-width:760px;padding:1.1rem 1.25rem;margin-bottom:1.25rem">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem">
    <h3 style="margin:0">APCu
      <span style="font-size:.8rem;font-weight:400;color:var(--muted);margin-left:.4rem">user data cache</span>
    </h3>
    <?php if ($apc['available']): ?>
      <?= statusBadge($apc['enabled'], 'Enabled', 'Disabled') ?>
    <?php else: ?>
      <span class="badge-pill" style="background:var(--surface-2);color:var(--muted)">Not installed</span>
    <?php endif; ?>
  </div>

  <?php if ($apc['available'] && $apc['enabled']): ?>
    <div style="display:flex;flex-wrap:wrap;gap:1.5rem;font-size:.9rem;margin-bottom:1rem">
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Entries</div>
        <strong><?= number_format($apc['entries']) ?></strong>
      </div>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Hits / Misses</div>
        <strong><?= number_format($apc['hits']) ?></strong> / <?= number_format($apc['misses']) ?>
      </div>
      <?php if ($apc['mem_size'] > 0): ?>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Memory available</div>
        <strong><?= $hb($apc['mem_avail']) ?></strong>
        <span style="color:var(--muted);font-size:.82rem">of <?= $hb($apc['mem_size']) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <div style="padding-top:.85rem;border-top:1px solid var(--border)">
      <form method="POST" action="/admin/server-status/flush" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="cache_type" value="apcu">
        <button type="submit" class="btn btn-sm">♻ Flush APCu</button>
      </form>
      <span style="color:var(--muted);font-size:.82rem;margin-left:.6rem">
        Clears all user data cached by APCu.
      </span>
    </div>
  <?php else: ?>
    <p style="color:var(--muted);font-size:.9rem;margin:0">
      APCu is <?= $apc['available'] ? 'installed but disabled' : 'not installed' ?>.
      Canticle does not require APCu, but if it is present it can cache PHP data between requests.
    </p>
  <?php endif; ?>
</div>


<!-- ── Realpath Cache ──────────────────────────────────────────── -->
<div class="admin-form" style="max-width:760px;padding:1.1rem 1.25rem;margin-bottom:1.25rem">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem">
    <h3 style="margin:0">PHP Realpath Cache
      <span style="font-size:.8rem;font-weight:400;color:var(--muted);margin-left:.4rem">filesystem path cache</span>
    </h3>
    <?= statusBadge($rp['available'], 'Active', 'Unavailable') ?>
  </div>

  <?php if ($rp['available']): ?>
    <div style="display:flex;flex-wrap:wrap;gap:1.5rem;font-size:.9rem;margin-bottom:1rem">
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Entries cached</div>
        <strong><?= number_format($rp['entries']) ?></strong>
      </div>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Memory used</div>
        <strong><?= $hb($rp['size']) ?></strong>
        <span style="color:var(--muted);font-size:.82rem">of <?= htmlspecialchars($rp['max_size']) ?></span>
      </div>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">TTL</div>
        <strong><?= $rp['ttl'] ?>s</strong>
      </div>
    </div>
    <p style="font-size:.85rem;color:var(--muted);margin:0 0 .85rem">
      PHP caches filesystem paths to avoid repeated disk lookups. If you upload a new file to the server,
      PHP may still resolve old paths until this cache expires or is cleared.
    </p>
    <div style="padding-top:.85rem;border-top:1px solid var(--border)">
      <form method="POST" action="/admin/server-status/flush" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="cache_type" value="realpath">
        <button type="submit" class="btn btn-sm">♻ Flush Realpath Cache</button>
      </form>
      <span style="color:var(--muted);font-size:.82rem;margin-left:.6rem">
        Runs <code>clearstatcache(true)</code>.
      </span>
    </div>
  <?php endif; ?>
</div>


<!-- ── Web Server / Reverse Proxy Cache ───────────────────────── -->
<div class="admin-form" style="max-width:760px;padding:1.1rem 1.25rem;margin-bottom:1.25rem">
  <h3 style="margin:0 0 .85rem">Web Server Cache</h3>

  <div style="display:flex;flex-wrap:wrap;gap:1.5rem;font-size:.9rem;margin-bottom:1rem">
    <div>
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Software</div>
      <strong><?= htmlspecialchars($ws['software']) ?></strong>
    </div>

    <?php if ($ws['is_apache']): ?>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">mod_cache</div>
        <?= statusBadge($ws['mod_cache'], 'Active ⚠', 'Not loaded') ?>
      </div>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">mod_expires</div>
        <?= statusBadge($ws['mod_expires'], 'Active', 'Not loaded') ?>
      </div>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">mod_deflate</div>
        <?= statusBadge($ws['mod_deflate'], 'Active', 'Not loaded') ?>
      </div>
    <?php elseif ($ws['is_litespeed']): ?>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">LiteSpeed Cache</div>
        <span class="badge-pill badge-warning">Present — may cache HTML pages</span>
      </div>
    <?php elseif ($ws['is_nginx']): ?>
      <div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Nginx</div>
        <span class="badge-pill" style="background:var(--surface-2);color:var(--muted)">
          FastCGI cache depends on server config
        </span>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($ws['mod_cache']): ?>
    <div class="flash-error" style="margin-bottom:.75rem">
      <strong>Apache mod_cache is active.</strong> This caches full HTML page responses on the server.
      Uploaded files may not take effect until the cache expires or is cleared.
      To clear it, SSH to the server and run: <code>sudo service apache2 restart</code>
      or flush the cache directory configured in your Apache <code>CacheRoot</code>.
    </div>
  <?php elseif ($ws['is_litespeed']): ?>
    <div style="font-size:.85rem;color:var(--muted)">
      If LiteSpeed Cache is caching pages, purge it from the LiteSpeed WebAdmin console
      or by adding <code>X-LiteSpeed-Cache-Control: no-cache</code> to your response headers.
    </div>
  <?php else: ?>
    <p style="font-size:.85rem;color:var(--muted);margin:0">
      No full-page server cache detected. If file uploads are not taking effect, the
      most likely cause is PHP OPcache or PHP-FPM worker pool isolation (see above).
    </p>
  <?php endif; ?>
</div>


<!-- ── Session Cache ──────────────────────────────────────────── -->
<div class="admin-form" style="max-width:760px;padding:1.1rem 1.25rem;margin-bottom:2rem">
  <h3 style="margin:0 0 .85rem">PHP Session</h3>
  <div style="display:flex;flex-wrap:wrap;gap:1.5rem;font-size:.9rem">
    <div>
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Handler</div>
      <code><?= htmlspecialchars($ses['handler']) ?></code>
    </div>
    <div>
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Lifetime</div>
      <strong><?= $ses['lifetime'] ?>s</strong>
      <span style="color:var(--muted);font-size:.82rem">(<?= round($ses['lifetime'] / 60) ?> min)</span>
    </div>
    <?php if ($ses['handler'] === 'files' && $ses['path']): ?>
    <div>
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Save path</div>
      <code style="font-size:.82rem"><?= htmlspecialchars($ses['path']) ?></code>
    </div>
    <?php endif; ?>
  </div>
  <p style="font-size:.85rem;color:var(--muted);margin:.75rem 0 0">
    Sessions are used for admin authentication. They do not cache page content.
  </p>
</div>
