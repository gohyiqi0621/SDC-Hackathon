<?php
/**
 * system/settings.php - where each setting comes from (values never shown).
 *
 * Expects: $groups, $envFile, $sapi, $envCount, $appUrl, $loader.
 */
view('layouts/header', ['title' => 'Server settings check']);
$labels = [
    'secret'  => ['Replit Secret / environment', 'badge--success'],
    'file'    => ['.env file', 'badge--info'],
    'missing' => ['Missing', 'badge--danger'],
];
?>
<div class="page-body" style="padding-top:24px">
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Server settings check</h2>
                <p>Where this server reads each setting from. Values are never shown.</p>
            </div>
        </div>
        <div class="panel-body">
            <ul class="item-list">
                <li><span><strong>Settings loader</strong><small><?= e($loader) ?></small></span></li>
                <li><span><strong>.env file on this server</strong><small><?= $envFile ? 'Present - it only fills settings that have no Replit Secret. On Replit it is safer to delete it.' : 'Not present (good on Replit: everything comes from Secrets).' ?></small></span></li>
                <li><span><strong>Environment variables visible to PHP</strong><small><?= (int) $envCount ?> (PHP mode: <?= e($sapi) ?>)<?= $envCount < 5 ? ' - almost none: this PHP setup hides the environment, so Secrets cannot be read. Run the app with php -S (see .replit).' : '' ?></small></span></li>
                <li><span><strong>App address</strong><small><?= e($appUrl) ?></small></span></li>
            </ul>
        </div>
    </section>

    <?php foreach ($groups as $group => $rows): ?>
        <section class="panel">
            <div class="panel-header"><div><h2><?= e($group) ?></h2></div></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Setting</th><th>Read from</th><th>Note</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $r): [$label, $class] = $labels[$r['state']]; ?>
                            <tr>
                                <td><code><?= e($r['key']) ?></code></td>
                                <td><span class="badge <?= e($r['state'] === 'missing' && $r['optional'] ? 'badge--muted' : $class) ?>"><?= e($r['state'] === 'missing' && $r['optional'] ? 'Not set (optional)' : $label) ?></span></td>
                                <td class="muted">
                                    <?php if ($r['state'] === 'missing' && $r['in_file'] === false): ?>
                                        Empty line in .env, and no Replit Secret with this exact name.
                                    <?php elseif ($r['state'] === 'missing' && !$r['optional']): ?>
                                        Add a Replit Secret named exactly <code><?= e($r['key']) ?></code>, then restart / redeploy.
                                    <?php elseif ($r['state'] === 'file' && $envFile): ?>
                                        From .env (no Replit Secret with this name).
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php view('layouts/footer'); ?>
