<?php
/**
 * HomeController.php - the public landing page (video hero, stats, sign-in CTA).
 */
declare(strict_types=1);

class HomeController
{
    public function index(): void
    {
        // The site root is the landing page for guests. Signed-in users (and the
        // installed PWA, whose start_url is the root) keep landing on the dashboard.
        if (current_user() !== null && !isset($_GET['page'])) {
            redirect(url('dashboard'));
        }

        // Each metric: display-font glyph, count-up target, suffix, decimals, label.
        $stats = [
            ['icon' => '<', 'target' => 120,   'suffix' => 'ms', 'decimals' => 0, 'label' => 'Inference Time'],
            ['icon' => '%', 'target' => 99.99, 'suffix' => '%',  'decimals' => 2, 'label' => 'Platform Uptime'],
            ['icon' => '*', 'target' => 24,    'suffix' => '/7', 'decimals' => 0, 'label' => 'Autonomous Runtime'],
            ['icon' => '#', 'target' => 2.4,   'suffix' => 'M',  'decimals' => 1, 'label' => 'Context Windows'],
        ];

        $navLinks = [
            ['label' => 'Home',         'href' => url('home'), 'active' => true],
            ['label' => 'Product',      'href' => '#',         'active' => false],
            ['label' => 'Case Studies', 'href' => '#',         'active' => false],
            ['label' => 'Contact',      'href' => '#',         'active' => false],
        ];

        view('home/index', [
            'stats'     => $stats,
            'navLinks'  => $navLinks,
            'signInUrl' => current_user() !== null ? url('dashboard') : url('auth', ['action' => 'login']),
            'startUrl'  => current_user() !== null ? url('dashboard') : url('auth', ['action' => 'register']),
        ]);
    }
}
