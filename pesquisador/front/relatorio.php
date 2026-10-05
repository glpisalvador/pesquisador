<?php

/**
 * Plugin Pesquisador - relatório de chamados (Ferramentas > Pesquisador > Relatório de chamados).
 * Os filtros ficam na URL: o relatório pode ser compartilhado e o botão voltar funciona.
 */

Session::checkLoginUser();

$C = PluginPesquisadorConfig::class;
$R = PluginPesquisadorRelatorio::class;
$e = [$C, 'e'];
if (!$C::acesso('relatorio')) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$f = $R::normalizar($_GET);
$opcoes = $R::opcoes();
$porPagina = max(10, min(500, (int) ($_GET['por_pagina'] ?? $C::getConfig('por_pagina'))));

$C::cabecalho('relatorio');
echo $C::assets(['relatorio']);

$select = function (string $nome, array $lista, string $atual) use ($e): string {
    $h = '<select name="' . $e($nome) . '" class="form-select form-select-sm">';
    foreach ($lista as $v => $rotulo) {
        $h .= '<option value="' . $e($v) . '"' . ((string) $v === $atual ? ' selected' : '') . '>' . $e($rotulo) . '</option>';
    }
    return $h . '</select>';
};
$ms = function (string $nome, string $rotulo, string $vazio) use ($C, $opcoes, $f): string {
    return '<div class="pesquisador-campo"><label>' . $C::e($rotulo) . '</label>' . $C::multiselect($nome, $opcoes[$nome], $f[$nome], $vazio) . '</div>';
};

echo '<div class="pesquisador-pagina" data-pesquisador-relatorio data-ajax="' . $e($C::url('ajax.php')) . '" data-exportar="' . $e($C::url('exportar.php')) . '" data-token="' . $e($C::tokenCsrf()) . '">';

if (!isset($C::tiposPermitidos()['Ticket'])) {
    echo '<div class="pesquisador-alerta pesquisador-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>Seu perfil não pode ver chamados, então o relatório fica vazio.</span></div>';
} elseif ($C::tiposPermitidos()['Ticket'] === 'ator') {
    echo '<div class="pesquisador-alerta pesquisador-alerta-info"><i class="ti ti-info-circle"></i><span>Seu perfil vê só os chamados em que você ou um grupo seu participa.</span></div>';
}

echo '<form class="card pesquisador-card" data-pesquisador-rel-form autocomplete="off">';
echo '<div class="card-header"><h5><i class="ti ti-filter"></i> Filtros</h5>'
    . '<div class="pesquisador-cab-acoes">'
    . '<div class="pesquisador-salvos" data-pesquisador-salvos><select class="form-select form-select-sm" data-pesquisador-salvo-escolher><option value="">Relatórios salvos...</option></select>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-salvo-salvar title="Salvar os filtros e colunas atuais"><i class="ti ti-device-floppy"></i><span>Salvar</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-danger" data-pesquisador-salvo-excluir title="Excluir o relatório escolhido" hidden><i class="ti ti-trash"></i></button></div>'
    . '</div></div>';
echo '<div class="card-body">';

echo '<div class="pesquisador-filtros">';
echo '<div class="pesquisador-grupo"><span class="pesquisador-rotulo">Período</span>' . $select('periodo', $R::PERIODOS, $f['periodo']) . '</div>';
echo '<div class="pesquisador-grupo"><span class="pesquisador-rotulo">pela data de</span>' . $select('campo_data', $R::CAMPOS_DATA, $f['campo_data']) . '</div>';
echo '<div class="pesquisador-grupo" data-pesquisador-datas' . ($f['periodo'] === 'datas' ? '' : ' hidden') . '><span class="pesquisador-rotulo">de</span>'
    . Html::showDateField('de', ['value' => substr($f['de'], 0, 10), 'display' => false, 'maybeempty' => true])
    . '<span class="pesquisador-rotulo">até</span>'
    . Html::showDateField('ate', ['value' => substr($f['ate'], 0, 10), 'display' => false, 'maybeempty' => true]) . '</div>';
echo '<div class="pesquisador-grupo pesquisador-grupo-busca"><span class="pesquisador-rotulo">Busca</span><input type="search" name="busca" class="form-control form-control-sm" value="' . $e($f['busca']) . '" placeholder="Número, título ou entidade"></div>';
echo '</div>';

echo '<div class="pesquisador-grade-filtros">';
echo $ms('status', 'Status', 'Todos');
echo $ms('tipos', 'Tipo', 'Todos');
echo $ms('prioridades', 'Prioridade', 'Todas');
echo $ms('categorias', 'Categoria', 'Todas');
echo '<div class="pesquisador-campo"><label class="pesquisador-rotulo-linha">Entidade'
    . '<span class="pesquisador-opcao pesquisador-opcao-mini" title="Inclui as entidades filhas das escolhidas"><input class="pesquisador-check" type="checkbox" id="pesquisador-sub" name="subentidades" value="1"' . ($f['subentidades'] ? ' checked' : '') . '>'
    . '<span>com subentidades</span></span></label>'
    . $C::multiselect('entidades', $opcoes['entidades'], $f['entidades'], 'Todas as ativas') . '</div>';
echo $ms('requerentes', 'Requerente', 'Todos');
echo $ms('tecnicos', 'Técnico', 'Todos');
echo $ms('grupos_atribuidos', 'Grupo atribuído', 'Todos');
echo $ms('grupos_observadores', 'Grupo observador', 'Todos');
echo $ms('sla_tto', 'SLA de atendimento', 'Todos');
echo $ms('sla_ttr', 'SLA de solução', 'Todos');
echo '</div>';

// Colunas
$colunas = '';
$ordemColunas = array_merge($f['colunas'], array_diff(array_keys($R::COLUNAS), $f['colunas']));
foreach ($ordemColunas as $k) {
    $colunas .= '<label class="pesquisador-ms-opcao' . (in_array($k, $f['colunas'], true) ? ' selected' : '') . '" data-label="' . $e(mb_strtolower($R::COLUNAS[$k][0])) . '">'
        . '<input type="checkbox" class="pesquisador-check" name="colunas[]" value="' . $e($k) . '"' . (in_array($k, $f['colunas'], true) ? ' checked' : '') . '><span>' . $e($R::COLUNAS[$k][0]) . '</span></label>';
}
echo '<div class="pesquisador-rodape-filtros">'
    . '<div class="pesquisador-colunas" data-pesquisador-colunas><button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-colunas-abrir><i class="ti ti-columns"></i><span>Colunas (<span data-qtd>' . count($f['colunas']) . '</span>)</span></button>'
    . '<div class="pesquisador-colunas-lista" hidden><div class="pesquisador-ms-topo pesquisador-pequeno">As marcadas aparecem na ordem desta lista; desmarque para esconder.</div><div class="pesquisador-ms-opcoes">' . $colunas . '</div>'
    . '<div class="pesquisador-ms-topo"><button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-colunas-padrao><i class="ti ti-arrow-back-up"></i><span>Colunas padrão</span></button></div></div></div>'
    . '<div class="pesquisador-acoes">'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-rel-limpar><i class="ti ti-eraser"></i><span>Limpar filtros</span></button>'
    . '<button type="submit" class="btn btn-sm pesquisador-btn-principal"><i class="ti ti-player-play"></i><span>Gerar relatório</span></button>'
    . '</div></div>';

echo '<input type="hidden" name="situacao" value="' . $e($f['situacao']) . '">';
echo '<input type="hidden" name="ordem" value="' . $e($f['ordem']) . '">';
echo '<input type="hidden" name="direcao" value="' . $e($f['direcao']) . '">';
echo '<input type="hidden" name="pagina" value="' . max(1, (int) ($_GET['pagina'] ?? 1)) . '">';
echo '<input type="hidden" name="por_pagina" value="' . $porPagina . '">';
echo '<script type="application/json" data-pesquisador-colunas-padrao-lista>' . json_encode($R::colunasPadrao()) . '</script>';
echo '</div></form>';

// Contadores de SLA
$cards = [
    ['', 'ti ti-ticket', 'Chamados', 'total'],
    ['tto_ok', 'ti ti-clock-check', 'Atendimento no prazo', 'tto_ok'],
    ['tto_vencido', 'ti ti-clock-x', 'Atendimento vencido', 'tto_vencido'],
    ['tto_andamento', 'ti ti-clock', 'Atendimento em andamento', 'tto_andamento'],
    ['ttr_ok', 'ti ti-circle-check', 'Solução no prazo', 'ttr_ok'],
    ['ttr_vencido', 'ti ti-alert-octagon', 'Solução vencida', 'ttr_vencido'],
    ['ttr_andamento', 'ti ti-hourglass', 'Solução em andamento', 'ttr_andamento'],
];
echo '<div class="pesquisador-contadores" data-pesquisador-contadores>';
foreach ($cards as [$sit, $icone, $rotulo, $chave]) {
    $classe = $sit === '' ? 'total' : (str_ends_with($sit, '_ok') ? 'ok' : (str_ends_with($sit, '_vencido') ? 'vencido' : 'andamento'));
    echo '<button type="button" class="pesquisador-contador pesquisador-contador-' . $classe . '" data-situacao="' . $e($sit) . '" data-chave="' . $e($chave) . '" title="' . ($sit === '' ? 'Mostrar todos' : 'Mostrar só: ' . $e($rotulo)) . '">'
        . '<i class="' . $icone . '"></i><span class="pesquisador-contador-num">—</span><span class="pesquisador-contador-rotulo">' . $e($rotulo) . '</span></button>';
}
echo '</div>';

echo '<div class="pesquisador-status" data-pesquisador-rel-status></div>';
echo '<div data-pesquisador-rel-resultados></div>';
echo '</div>';
Html::footer();
