<?php

/**
 * Plugin Pesquisador - instalação e desinstalação
 */

function plugin_pesquisador_install(): bool
{
    global $DB;

    $opcoes = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    if (!$DB->tableExists('glpi_plugin_pesquisador_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_pesquisador_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` text NULL,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }

    // Índice próprio: um texto (já sem HTML) por origem de cada item
    if (!$DB->tableExists('glpi_plugin_pesquisador_textos')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_pesquisador_textos` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(30) NOT NULL,
            `items_id` int unsigned NOT NULL,
            `fonte` varchar(20) NOT NULL,
            `fonte_id` int unsigned NOT NULL DEFAULT 0,
            `is_private` tinyint(1) NOT NULL DEFAULT 0,
            `texto` longtext NULL,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `origem` (`itemtype`, `items_id`, `fonte`, `fonte_id`),
            KEY `item` (`itemtype`, `items_id`),
            FULLTEXT KEY `texto` (`texto`)
        ) $opcoes");
    }

    // Relatórios de chamados salvos por pessoa (filtros e colunas)
    if (!$DB->tableExists('glpi_plugin_pesquisador_relatorios')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_pesquisador_relatorios` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `nome` varchar(100) NOT NULL DEFAULT '',
            `filtros` text NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `usuario_nome` (`users_id`, `nome`)
        ) $opcoes");
    }

    // Consultas SQL salvas (pessoais ou compartilhadas)
    if (!$DB->tableExists('glpi_plugin_pesquisador_consultas')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_pesquisador_consultas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `nome` varchar(150) NOT NULL DEFAULT '',
            `consulta` longtext NULL,
            `is_compartilhada` tinyint(1) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `is_compartilhada` (`is_compartilhada`)
        ) $opcoes");
    }

    // Histórico do console (e registro de todo comando que altera o banco)
    if (!$DB->tableExists('glpi_plugin_pesquisador_historico')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_pesquisador_historico` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `consulta` longtext NULL,
            `tipo` varchar(10) NOT NULL DEFAULT 'leitura',
            `sucesso` tinyint(1) NOT NULL DEFAULT 0,
            `linhas` bigint NOT NULL DEFAULT 0,
            `tempo_ms` int unsigned NOT NULL DEFAULT 0,
            `erro` text NULL,
            `ip` varchar(45) NOT NULL DEFAULT '',
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `tipo` (`tipo`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }

    // Salvamentos de tabelas (downloads e arquivos guardados no servidor)
    if (!$DB->tableExists('glpi_plugin_pesquisador_exportacoes')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_pesquisador_exportacoes` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `arquivo` varchar(255) NOT NULL DEFAULT '',
            `formato` varchar(10) NOT NULL DEFAULT '',
            `compressao` varchar(10) NOT NULL DEFAULT '',
            `destino` varchar(10) NOT NULL DEFAULT 'download',
            `tabelas` longtext NULL,
            `qtd_tabelas` int unsigned NOT NULL DEFAULT 0,
            `linhas` bigint NOT NULL DEFAULT 0,
            `tamanho` bigint NOT NULL DEFAULT 0,
            `tempo_ms` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `destino` (`destino`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }

    // A versão 1.0 criava índices FULLTEXT nas tabelas do GLPI: são removidos (o núcleo volta ao esquema original)
    $antigos = [
        'glpi_tickets'           => 'pesquisador_ft_nomecontent',
        'glpi_itilfollowups'     => 'pesquisador_ft_content',
        'glpi_itilsolutions'     => 'pesquisador_ft_content',
        'glpi_ticketvalidations' => 'pesquisador_ft_comentarios',
    ];
    foreach ($antigos as $tabela => $indice) {
        if ($DB->tableExists($tabela) && $DB->numrows($DB->doQuery("SHOW INDEX FROM `$tabela` WHERE Key_name = " . $DB->quote($indice))) > 0) {
            $DB->doQuery("ALTER TABLE `$tabela` DROP INDEX `$indice`");
        }
    }

    // Configurações que faltarem (as existentes não mudam)
    require_once __DIR__ . '/inc/config.class.php';
    foreach (PluginPesquisadorConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_pesquisador_configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert('glpi_plugin_pesquisador_configs', ['name' => $nome, 'value' => is_array($valor) ? json_encode($valor) : $valor]);
        }
    }

    // Quem usava o plugin "consulta" continua com acesso ao relatório
    if ($DB->tableExists('glpi_plugin_consulta_configs')) {
        foreach (['allowed_profiles' => 'relatorio_perfis', 'allowed_users' => 'relatorio_usuarios'] as $antigo => $novo) {
            foreach ($DB->request(['FROM' => 'glpi_plugin_consulta_configs', 'WHERE' => ['name' => $antigo]]) as $r) {
                $ids = array_values(array_filter(array_map('intval', json_decode((string) $r['value'], true) ?: [])));
                $atuais = array_map('intval', json_decode((string) PluginPesquisadorConfig::getConfig($novo), true) ?: []);
                if ($ids && !$atuais) {
                    $DB->update('glpi_plugin_pesquisador_configs', ['value' => json_encode($ids)], ['name' => $novo]);
                }
            }
        }
    }

    PluginPesquisadorConfig::pasta('exportacoes');
    $protecao = GLPI_PLUGIN_DOC_DIR . '/pesquisador/.htaccess';
    if (!is_file($protecao)) {
        @file_put_contents($protecao, "Order Deny,Allow\nDeny from all\n");
    }

    // Construção do índice e revisão periódica; limpeza de histórico e arquivos antigos
    CronTask::register('PluginPesquisadorIndexador', 'PesquisadorIndexar', 5 * MINUTE_TIMESTAMP, [
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'Pesquisador: constrói e mantém o índice de busca',
    ]);
    CronTask::register('PluginPesquisadorExportador', 'PesquisadorLimpar', DAY_TIMESTAMP, [
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'Pesquisador: apaga salvamentos de tabelas e histórico do console antigos',
    ]);

    return true;
}

function plugin_pesquisador_uninstall(): bool
{
    // As tabelas e os arquivos do plugin são mantidos (regra do projeto); só as tarefas automáticas saem
    CronTask::unregister('pesquisador');
    return true;
}
