<?php

/**
 * Plugin Pesquisador - GLPI 11 e 12
 * Busca textual em chamados, problemas e mudanças (índice próprio), relatório de chamados com SLA,
 * console SQL e banco de dados (estrutura, dados e salvamento de tabelas inteiras).
 * Reúne os antigos plugins "sql" e "consulta".
 */

define('PLUGIN_PESQUISADOR_VERSION', '3.0.0');
define('PLUGIN_PESQUISADOR_MIN_GLPI', '11.0.0');
define('PLUGIN_PESQUISADOR_MAX_GLPI', '12.99.99');

function plugin_init_pesquisador(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['pesquisador'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('pesquisador')) {
        return;
    }

    Plugin::registerClass('PluginPesquisadorConfig');
    Plugin::registerClass('PluginPesquisadorMenu');
    Plugin::registerClass('PluginPesquisadorIndexador');
    Plugin::registerClass('PluginPesquisadorExportador');

    $PLUGIN_HOOKS['config_page']['pesquisador'] = 'front/config.form.php';
    $PLUGIN_HOOKS['menu_toadd']['pesquisador'] = ['tools' => 'PluginPesquisadorMenu'];

    // Índice sempre atualizado: itens e tudo o que é escrito dentro deles
    $atualizar = ['PluginPesquisadorIndexador', 'aoSalvar'];
    $remover = ['PluginPesquisadorIndexador', 'aoRemover'];
    $tipos = ['Ticket', 'Problem', 'Change', 'ITILFollowup', 'ITILSolution', 'TicketTask', 'ProblemTask', 'ChangeTask', 'TicketValidation', 'ChangeValidation', 'Document_Item'];
    foreach ($tipos as $tipo) {
        $PLUGIN_HOOKS['item_add']['pesquisador'][$tipo] = $atualizar;
        $PLUGIN_HOOKS['item_update']['pesquisador'][$tipo] = $atualizar;
        $PLUGIN_HOOKS['item_purge']['pesquisador'][$tipo] = $remover;
    }
}

function plugin_version_pesquisador(): array
{
    return [
        'name'         => 'Pesquisador',
        'version'      => PLUGIN_PESQUISADOR_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PESQUISADOR_MIN_GLPI,
                'max' => PLUGIN_PESQUISADOR_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_pesquisador_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_PESQUISADOR_MIN_GLPI, '>=');
}

function plugin_pesquisador_check_config($verbose = false): bool
{
    return true;
}
