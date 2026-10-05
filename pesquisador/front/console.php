<?php

/**
 * Plugin Pesquisador - console SQL (Ferramentas > Pesquisador > Console SQL).
 * O editor é texto puro de propósito: SQL não pode passar pelo editor rico.
 */

Session::checkLoginUser();

$C = PluginPesquisadorConfig::class;
$e = [$C, 'e'];
if (!$C::acesso('console')) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$sqlInicial = is_string($_GET['sql'] ?? null) ? (string) $_GET['sql'] : '';
if ($sqlInicial === '' && is_string($_GET['tabela'] ?? null) && PluginPesquisadorBanco::existe((string) $_GET['tabela'])) {
    $sqlInicial = 'SELECT * FROM ' . PluginPesquisadorBanco::nome((string) $_GET['tabela']) . ' LIMIT 100;';
}

$C::cabecalho('console');
echo $C::assets(['sql']);

$escrita = $C::podeEscrever();
echo '<div class="pesquisador-pagina" data-pesquisador-console data-ajax="' . $e($C::url('ajax.php')) . '" data-exportar="' . $e($C::url('exportar.php')) . '" data-token="' . $e($C::tokenCsrf()) . '" data-admin="' . (int) $C::ehAdmin() . '">';

echo '<div class="pesquisador-console">';

// ------------------------------------------------------------ tabelas (ajuda para escrever)
echo '<aside class="card pesquisador-card pesquisador-console-lateral"><div class="card-header"><h5><i class="ti ti-table"></i> Tabelas</h5></div><div class="card-body p-0">'
    . '<div class="pesquisador-lateral-busca"><input type="search" class="form-control form-control-sm" placeholder="Filtrar tabelas..." data-pesquisador-tabelas-filtro></div>'
    . '<div class="pesquisador-arvore" data-pesquisador-arvore><div class="pesquisador-pequeno p-2"><span class="pesquisador-giro"></span> Carregando...</div></div>'
    . '<div class="pesquisador-pequeno pesquisador-lateral-dica"><i class="ti ti-info-circle"></i> Clique no nome para inserir no editor; na seta para ver as colunas.</div>'
    . '</div></aside>';

echo '<div class="pesquisador-console-principal">';
echo '<div class="card pesquisador-card"><div class="card-header"><h5><i class="ti ti-terminal-2"></i> Comando SQL</h5>'
    . '<span class="pesquisador-selo ' . ($escrita ? 'pesquisador-selo-aviso" title="Comandos que alteram o banco pedem confirmação e ficam registrados"><i class="ti ti-pencil"></i> Alterações liberadas' : 'pesquisador-selo-info" title="Só SELECT, SHOW, DESCRIBE e EXPLAIN"><i class="ti ti-lock"></i> Somente leitura') . '</span></div>'
    . '<div class="card-body">'
    . '<textarea class="form-control pesquisador-editor" rows="10" spellcheck="false" data-pesquisador-editor placeholder="SELECT id, name FROM glpi_tickets ORDER BY id DESC LIMIT 20;">' . $e($sqlInicial) . '</textarea>'
    . '<div class="pesquisador-confirmacao" data-pesquisador-confirmacao hidden><div class="pesquisador-alerta pesquisador-alerta-perigo"><i class="ti ti-alert-triangle"></i><div>'
    . '<strong>Estes comandos alteram o banco de dados e não podem ser desfeitos:</strong><ol data-pesquisador-confirmacao-lista></ol>'
    . '<label class="pesquisador-opcao"><input type="checkbox" class="pesquisador-check" data-pesquisador-confirmacao-ciente> Entendo o risco e quero executar</label>'
    . '<div class="pesquisador-acoes mt-2"><button type="button" class="btn btn-sm pesquisador-btn-cancelar" data-pesquisador-confirmacao-cancelar><i class="ti ti-x"></i><span>Cancelar</span></button>'
    . '<button type="button" class="btn btn-sm btn-danger" data-pesquisador-confirmacao-executar disabled><i class="ti ti-player-play"></i><span>Executar mesmo assim</span></button></div></div></div></div>'
    . '<div class="pesquisador-rodape-filtros">'
    . '<div class="pesquisador-acoes">'
    . '<button type="button" class="btn btn-sm pesquisador-btn-principal" data-pesquisador-executar><i class="ti ti-player-play"></i><span>Executar</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-limpar-editor><i class="ti ti-eraser"></i><span>Limpar</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-salvar-consulta><i class="ti ti-device-floppy"></i><span>Salvar consulta</span></button>'
    . '</div>'
    . '<div class="pesquisador-acoes" data-pesquisador-exportar-resultado hidden><span class="pesquisador-rotulo">Exportar resultado</span>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-formato="csv"><i class="ti ti-file-type-csv"></i><span>CSV</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-formato="xlsx"><i class="ti ti-file-spreadsheet"></i><span>XLSX</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-formato="json"><i class="ti ti-braces"></i><span>JSON</span></button></div>'
    . '</div>'
    . '<div class="pesquisador-salvar-consulta" data-pesquisador-salvar-form hidden>'
    . '<input type="text" class="form-control form-control-sm" maxlength="150" placeholder="Nome da consulta" data-pesquisador-consulta-nome>'
    . '<label class="pesquisador-opcao"><input type="checkbox" class="pesquisador-check" data-pesquisador-consulta-compartilhar> Compartilhar com quem usa o console</label>'
    . '<button type="button" class="btn btn-sm pesquisador-btn-principal" data-pesquisador-consulta-confirmar><i class="ti ti-check"></i><span>Salvar</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-consulta-cancelar><i class="ti ti-x"></i></button></div>'
    . '<p class="pesquisador-explicacao"><i class="ti ti-info-circle"></i><span>Vários comandos separados por ";" rodam em sequência. Cada resultado mostra até '
    . number_format($C::inteiro('sql_limite_linhas', 50, 10000), 0, ',', '.') . ' linhas (a exportação traz todas). Tempo limite por comando: ' . $C::inteiro('sql_tempo_limite', 5, 3600) . ' s.</span></p>'
    . '</div></div>';

echo '<div class="pesquisador-status" data-pesquisador-console-status></div>';
echo '<div data-pesquisador-console-resultados></div>';

echo '<div class="card pesquisador-card"><div class="card-header pesquisador-card-abas">'
    . '<ul class="nav nav-pills pesquisador-subabas"><li class="nav-item"><a href="#" class="nav-link active" data-pesquisador-subaba="consultas"><i class="ti ti-bookmark"></i> Consultas salvas</a></li>'
    . '<li class="nav-item"><a href="#" class="nav-link" data-pesquisador-subaba="historico"><i class="ti ti-history"></i> Histórico</a></li></ul>'
    . ($C::ehAdmin() ? '<label class="pesquisador-opcao ms-auto" data-pesquisador-historico-todos-rotulo hidden><input type="checkbox" class="pesquisador-check" data-pesquisador-historico-todos> De todos os usuários</label>' : '')
    . '</div><div class="card-body p-0">'
    . '<div data-pesquisador-subaba-painel="consultas"></div><div data-pesquisador-subaba-painel="historico" hidden></div>'
    . '</div></div>';

echo '</div></div></div>';
Html::footer();
