<?php
/**
 * chatbot/settings.php - Setting & Integration, shared by Customer and Staff.
 *
 * Expects (from ChatbotController): $title, $audience, $sub, $assistant,
 * $integrations [[icon, name, text, state]] (state: true = connected, 'setup' = available
 * but not configured, false = coming soon), $chatUrl, $productCount (Customer only).
 * Saving assistant settings and connecting channels is the next integration
 * step, so the controls are shown but not yet editable.
 */
view('layouts/header', ['title' => $title]);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1><?= e($audience) ?> Setting &amp; Integration</h1>
        <p><?= e($sub) ?></p>
    </div>
    <div class="welcome-actions">
        <a class="primary-btn" href="<?= e($chatUrl) ?>">
            <i class="fa-regular fa-comments"></i> Open chat interface
        </a>
    </div>
</section>

<div class="page-body">

    <div class="flash flash--info" role="status">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Preview</strong>
            <span>These settings show what will be configurable. Saving them and connecting channels is the next integration step.</span>
        </div>
    </div>

    <div class="grid-2">

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Assistant</h2>
                    <p>How the <?= e(strtolower($audience)) ?> assistant introduces itself.</p>
                </div>
            </div>
            <div class="panel-body">
                <div class="field">
                    <label for="assistant_name">Assistant name</label>
                    <input id="assistant_name" type="text" value="<?= e($assistant['name']) ?>" disabled>
                </div>
                <div class="field">
                    <label for="assistant_greeting">Greeting message</label>
                    <textarea id="assistant_greeting" rows="3" disabled><?= e($assistant['greeting']) ?></textarea>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="assistant_tone">Tone</label>
                        <select id="assistant_tone" disabled>
                            <option>Friendly</option>
                            <option>Formal</option>
                            <option>Concise</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="assistant_language">Language</label>
                        <select id="assistant_language" disabled>
                            <option>English</option>
                            <option>Bahasa Melayu</option>
                            <option>中文</option>
                        </select>
                    </div>
                </div>

                <?php if ($productCount !== null): ?>
                    <div class="settings-link">
                        <span class="item-icon"><i class="fa-solid fa-box-open"></i></span>
                        <span>
                            <strong>Product knowledge</strong>
                            <small><?= (int) $productCount ?> product<?= $productCount === 1 ? '' : 's' ?> / service<?= $productCount === 1 ? '' : 's' ?> the assistant can answer about</small>
                        </span>
                        <a class="secondary-btn" href="<?= e(url('products')) ?>">Manage</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Integrations</h2>
                    <p>Channels where the assistant can be reached.</p>
                </div>
            </div>
            <div class="panel-body">
                <ul class="item-list">
                    <?php foreach ($integrations as [$icon, $name, $text, $connected]): ?>
                        <li>
                            <span class="item-icon"><i class="<?= e($icon) ?>"></i></span>
                            <span><strong><?= e($name) ?></strong><small><?= e($text) ?></small></span>
                            <?php if ($connected === true): ?>
                                <span class="badge badge--success"><i class="fa-solid fa-circle-check"></i> Connected</span>
                            <?php elseif ($connected === 'setup'): ?>
                                <span class="badge">Not set up</span>
                            <?php else: ?>
                                <span class="badge badge--muted">Coming soon</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

    </div>
</div>
<?php view('layouts/footer'); ?>
