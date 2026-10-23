<?php
/**
 * partials/setup_checklist.php - "Get your assistant ready" checklist.
 *
 * Expects: $steps (SetupStatus::customer() / ::staff()), $tryUrl, $tryLabel.
 * Required steps count towards the progress bar; optional ones say so.
 */
$progress = \Src\Services\SetupStatus::progress($steps);
$complete = $progress['done'] === $progress['total'];
$percent  = $progress['total'] > 0 ? (int) round($progress['done'] * 100 / $progress['total']) : 100;
?>
<section class="setup-check<?= $complete ? ' is-complete' : '' ?>" aria-labelledby="setup-check-title">
    <div class="setup-check__head">
        <div>
            <h2 id="setup-check-title">
                <?php if ($complete): ?>
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Ready to go
                <?php else: ?>
                    <i class="fa-solid fa-list-check" aria-hidden="true"></i> Get your assistant ready
                <?php endif; ?>
            </h2>
            <p><?= $complete ? 'Everything needed is set up. Try it, or fine-tune the settings below.' : $progress['done'] . ' of ' . $progress['total'] . ' required steps done.' ?></p>
        </div>
        <a class="primary-btn setup-check__try" href="<?= e($tryUrl) ?>">
            <i class="fa-regular fa-comments" aria-hidden="true"></i> <?= e($tryLabel) ?>
        </a>
    </div>
    <div class="setup-check__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $percent ?>"
         aria-label="Setup progress"><span style="width: <?= $percent ?>%"></span></div>

    <ol class="setup-steps">
        <?php foreach ($steps as $i => $step): ?>
            <li class="setup-step<?= $step['done'] ? ' is-done' : '' ?><?= $step['optional'] ? ' is-optional' : '' ?>">
                <span class="setup-step__mark" aria-hidden="true">
                    <?= $step['done'] ? '<i class="fa-solid fa-check"></i>' : (int) ($i + 1) ?>
                </span>
                <span class="setup-step__text">
                    <strong><?= e($step['label']) ?><?php if ($step['optional']): ?> <em>Optional</em><?php endif; ?></strong>
                    <small><?= e($step['hint']) ?></small>
                </span>
                <?php if (!$step['done']): ?>
                    <a class="setup-step__fix" href="<?= e($step['fix_url']) ?>"><?= e($step['fix_label']) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                <?php else: ?>
                    <a class="setup-step__edit" href="<?= e($step['fix_url']) ?>">Edit</a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
