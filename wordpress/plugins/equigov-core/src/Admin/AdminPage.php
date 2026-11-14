<?php

declare(strict_types=1);

namespace EquiGov\Core\Admin;

use EquiGov\Core\Auth\Roles;

/**
 * Página "EquiGov" no painel: casca HTML + app JavaScript que consome a API REST do plugin.
 * Autenticação por cookie + nonce wp_rest; as permissões reais são checadas no servidor,
 * os "can*" abaixo só escondem botões que o usuário não poderia usar.
 */
final class AdminPage
{
    private const SLUG = 'equigov';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void
    {
        add_menu_page('EquiGov', 'EquiGov', Roles::VIEW_DIAGNOSES, self::SLUG, [$this, 'render'], 'dashicons-chart-area', 3);
    }

    public function render(): void
    {
        echo '<div class="wrap equigov">';
        echo '<h1 class="screen-reader-text">EquiGov</h1>';
        echo '<div id="equigov-live" class="screen-reader-text" aria-live="polite" role="status"></div>';
        echo '<div id="equigov-notice" aria-live="assertive"></div>';
        echo '<main id="equigov-app" tabindex="-1"><p>Carregando…</p></main>';
        echo '<noscript><p>' . esc_html__('Esta tela precisa de JavaScript.', 'equigov-core') . '</p></noscript>';
        echo '</div>';
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }
        $base = plugins_url('assets/', EQUIGOV_CORE_FILE);
        wp_enqueue_style('equigov-admin', $base . 'admin.css', [], EQUIGOV_CORE_VERSION);
        wp_enqueue_script('equigov-admin', $base . 'admin.js', [], EQUIGOV_CORE_VERSION, true);

        $user = wp_get_current_user();
        wp_add_inline_script('equigov-admin', 'window.EQUIGOV = ' . wp_json_encode([
            'root'       => esc_url_raw(rest_url('equigov/v1')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'userId'     => $user->ID,
            'userName'   => $user->display_name,
            'canAnswer'  => current_user_can(Roles::ANSWER_DIAGNOSES),
            'canManage'  => current_user_can(Roles::MANAGE_DIAGNOSES),
            'canReview'  => current_user_can(Roles::REVIEW_ACTION_PLANS),
            'canApprove' => current_user_can(Roles::APPROVE_ACTION_PLANS),
        ]) . ';', 'before');
    }
}
