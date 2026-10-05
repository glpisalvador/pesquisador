<?php

/**
 * Plugin Pesquisador - banco de dados (Ferramentas > Pesquisador > Banco de dados).
 * Sem ?tabela: lista de tabelas, salvar tabelas inteiras e arquivos guardados.
 * Com ?tabela=nome: estrutura, dados e salvar só essa tabela.
 */

Session::checkLoginUser();

$C = PluginPesquisadorConfig::class;
$B = PluginPesquisadorBanco::class;
$X = PluginPesquisadorExportador::class;
$e = [$C, 'e'];
if (!$C::acesso('banco')) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$tabela = is_string($_GET['tabela'] ?? null) ? (string) $_GET['tabela'] : '';
$todas = $B::tabelas();
if ($tabela !== '' && !isset($todas[$tabela])) {
    Session::addMessageAfterRedirect('Tabela não encontrada.', false, ERROR);
    Html::redirect($C::url('banco.php'));
}

$C::cabecalho('banco', $tabela !== '' ? $tabela : '');
echo $C::assets(['sql']);

$n = fn($v) => number_format((float) $v, 0, ',', '.');
$opcoesSelect = function (array $lista, string $atual) use ($e): string {
    $h = '';
    foreach ($lista as $v => $r) {
        $h .= '<option value="' . $e($v) . '"' . ((string) $v === $atual ? ' selected' : '') . '>' . $e($r) . '</option>';
    }
    return $h;
};

/** Formulário de salvamento (na lista e no detalhe) */
$formSalvar = function (array $tabelas, bool $unica) use ($e, $X, $opcoesSelect, $C): string {
    $h = '<form class="pesquisador-salvar-tabelas" data-pesquisador-salvar-tabelas>';
    if ($unica) {
        $h .= '<input type="hidden" name="tabelas[]" value="' . $e($tabelas[0]) . '">';
    }
    $h .= '<div class="pesquisador-filtros">'
        . '<div class="pesquisador-grupo"><span class="pesquisador-rotulo">Formato</span><select name="formato" class="form-select form-select-sm" data-pesquisador-formato>' . $opcoesSelect($X::FORMATOS, 'sql') . '</select></div>'
        . '<div class="pesquisador-grupo" data-so-sql><span class="pesquisador-rotulo">Conteúdo</span><select name="conteudo" class="form-select form-select-sm">' . $opcoesSelect($X::CONTEUDOS, 'ambos') . '</select></div>'
        . '<div class="pesquisador-grupo" data-sem-xlsx><span class="pesquisador-rotulo">Compressão</span><select name="compressao" class="form-select form-select-sm">' . $opcoesSelect($X::COMPRESSOES, 'nenhuma') . '</select></div>'
        . '<div class="pesquisador-grupo" data-so-sql><span class="pesquisador-rotulo">Linhas por INSERT</span><input type="number" name="lote" class="form-control form-control-sm pesquisador-numero" min="1" max="1000" value="100"></div>'
        . '<label class="pesquisador-opcao" data-so-sql><input type="checkbox" class="pesquisador-check" name="drop" value="1" checked> Incluir DROP TABLE IF EXISTS</label>'
        . '</div><div class="pesquisador-filtros">'
        . '<div class="pesquisador-grupo"><span class="pesquisador-rotulo">Nome do arquivo</span><input type="text" name="nome" class="form-control form-control-sm" maxlength="120" placeholder="Automático (tabela ou banco + data)"></div>'
        . '<div class="pesquisador-grupo"><span class="pesquisador-rotulo">Destino</span>'
        . '<label class="pesquisador-opcao"><input type="radio" class="pesquisador-check pesquisador-radio" name="destino" value="download" checked> Baixar agora</label>'
        . '<label class="pesquisador-opcao"><input type="radio" class="pesquisador-check pesquisador-radio" name="destino" value="servidor"> Guardar no servidor</label></div>'
        . '<div class="pesquisador-acoes ms-auto"><span class="pesquisador-pequeno" data-pesquisador-resumo-salvar></span>'
        . '<button type="submit" class="btn btn-sm pesquisador-btn-principal"><i class="ti ti-download"></i><span>' . ($unica ? 'Salvar tabela' : 'Salvar tabelas') . '</span></button></div>'
        . '</div><p class="pesquisador-explicacao"><i class="ti ti-info-circle"></i><span>CSV gera um arquivo por tabela (várias tabelas vão num zip). XLSX vale até '
        . number_format($X::LIMITE_XLSX, 0, ',', '.') . ' linhas. Arquivos guardados no servidor ficam em <code>files/_plugins/pesquisador/exportacoes</code>'
        . ($C::inteiro('exportacoes_dias', 0, 3650) > 0 ? ' e são apagados após ' . $C::inteiro('exportacoes_dias', 0, 3650) . ' dias' : '') . '.</span></p></form>';
    return $h;
};

echo '<div class="pesquisador-pagina" data-pesquisador-banco data-ajax="' . $e($C::url('ajax.php')) . '" data-exportar="' . $e($C::url('exportar.php')) . '" data-token="' . $e($C::tokenCsrf()) . '"'
    . ($tabela !== '' ? ' data-tabela="' . $e($tabela) . '"' : '') . '>';

if ($tabela === '') {
    // ------------------------------------------------------------ lista
    $totalTamanho = array_sum(array_column($todas, 'tamanho'));
    $totalLinhas = array_sum(array_column($todas, 'linhas'));
    echo '<div class="pesquisador-resumo-banco">'
        . '<span><i class="ti ti-database"></i> <strong>' . $e($B::nomeBanco()) . '</strong></span>'
        . '<span>' . $n(count($todas)) . ' tabelas</span><span>≈ ' . $n($totalLinhas) . ' linhas</span><span>' . $e($C::tamanho($totalTamanho)) . '</span></div>';

    $linhas = '';
    foreach ($todas as $t) {
        $linhas .= '<tr data-linha data-search="' . $e(mb_strtolower($t['nome'] . ' ' . $t['comentario'])) . '" data-tabela="' . $e($t['nome']) . '" data-tamanho="' . (int) $t['tamanho'] . '" data-linhas="' . (int) $t['linhas'] . '">'
            . '<td class="pesquisador-col-check"><input type="checkbox" class="pesquisador-check" name="tabelas[]" value="' . $e($t['nome']) . '" data-pesquisador-tabela-check></td>'
            . '<td><a href="' . $e($C::url('banco.php', ['tabela' => $t['nome']])) . '" class="pesquisador-mono">' . $e($t['nome']) . '</a>'
            . ($t['visao'] ? ' <span class="pesquisador-selo pesquisador-selo-info">visão</span>' : '') . '</td>'
            . '<td class="text-end" data-ordem="' . (int) $t['linhas'] . '">' . ($t['visao'] ? '—' : '≈ ' . $n($t['linhas'])) . '</td>'
            . '<td class="text-end" data-ordem="' . (int) $t['tamanho'] . '">' . $e($C::tamanho($t['tamanho'])) . '</td>'
            . '<td>' . $e($t['engine']) . '</td><td>' . $e($t['collation']) . '</td>'
            . '<td class="text-nowrap" data-ordem="' . $e($t['atualizada']) . '">' . $e($t['atualizada'] !== '' ? Html::convDateTime($t['atualizada']) : '') . '</td>'
            . '<td class="pesquisador-col-acoes"><span class="pesquisador-icones">'
            . '<a class="btn btn-sm btn-ghost-secondary" href="' . $e($C::url('banco.php', ['tabela' => $t['nome']])) . '" title="Estrutura e dados"><i class="ti ti-eye"></i></a>'
            . ($C::acesso('console') ? '<a class="btn btn-sm btn-ghost-secondary" href="' . $e($C::url('console.php', ['tabela' => $t['nome']])) . '" title="Abrir no console"><i class="ti ti-terminal-2"></i></a>' : '')
            . '</span></td></tr>';
    }
    echo '<div class="card pesquisador-card"><div class="card-header"><h5><i class="ti ti-table"></i> Tabelas</h5>'
        . '<div class="pesquisador-cab-acoes"><input type="search" class="form-control form-control-sm pesquisador-busca-tabela" placeholder="Filtrar por nome (ex.: glpi_plugin_)" data-pesquisador-filtro-tabelas>'
        . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-marcar="visiveis" title="Marca as tabelas que aparecem com o filtro"><i class="ti ti-checks"></i><span>Marcar visíveis</span></button>'
        . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-marcar="nenhuma"><i class="ti ti-square"></i><span>Desmarcar</span></button></div></div>'
        . '<div class="card-body p-0"><div class="table-responsive pesquisador-tabela-caixa"><table class="table table-sm table-hover pesquisador-tabela pesquisador-tabela-ordenavel mb-0" data-pesquisador-lista-tabelas>'
        . '<thead class="sticky-top"><tr><th class="pesquisador-col-check"><input type="checkbox" class="pesquisador-check" data-pesquisador-todas-tabelas title="Marcar/desmarcar as visíveis"></th>'
        . '<th data-sort="texto">Tabela</th><th data-sort="numero" class="text-end">Linhas</th><th data-sort="numero" class="text-end">Tamanho</th><th data-sort="texto">Engine</th><th data-sort="texto">Collation</th><th data-sort="texto">Atualizada</th><th class="no-export"></th></tr></thead>'
        . '<tbody>' . $linhas . '</tbody></table></div>'
        . '<div class="pesquisador-rodape"><span class="pesquisador-pequeno" data-pesquisador-selecao>Nenhuma tabela marcada</span><span class="pesquisador-pequeno">As contagens de linhas são estimativas do servidor (InnoDB).</span></div>'
        . '</div></div>';

    echo '<div class="card pesquisador-card"><div class="card-header"><h5><i class="ti ti-device-floppy"></i> Salvar tabelas inteiras</h5></div><div class="card-body">'
        . $formSalvar([], false) . '</div></div>';

    echo '<div class="card pesquisador-card"><div class="card-header"><h5><i class="ti ti-archive"></i> Arquivos guardados no servidor</h5>'
        . '<div class="pesquisador-cab-acoes"><button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-recarregar-guardados><i class="ti ti-refresh"></i><span>Atualizar</span></button></div></div>'
        . '<div class="card-body p-0" data-pesquisador-guardados><div class="pesquisador-pequeno p-3"><span class="pesquisador-giro"></span> Carregando...</div></div></div>';
} else {
    // ------------------------------------------------------------ detalhe
    $t = $todas[$tabela];
    $colunas = $B::colunas($tabela);
    $indices = $t['visao'] ? [] : $B::indices($tabela);
    echo '<div class="pesquisador-resumo-banco">'
        . '<a href="' . $e($C::url('banco.php')) . '" class="btn btn-sm btn-ghost-secondary"><i class="ti ti-arrow-left"></i><span>Todas as tabelas</span></a>'
        . '<span><i class="ti ti-table"></i> <strong class="pesquisador-mono">' . $e($tabela) . '</strong>' . ($t['visao'] ? ' <span class="pesquisador-selo pesquisador-selo-info">visão</span>' : '') . '</span>'
        . ($t['visao'] ? '' : '<span>≈ ' . $n($t['linhas']) . ' linhas</span><span>dados ' . $e($C::tamanho($t['dados'])) . ' · índices ' . $e($C::tamanho($t['indices'])) . '</span>')
        . '<span>' . $e($t['engine']) . '</span><span>' . $e($t['collation']) . '</span>'
        . ($t['auto'] !== null ? '<span>próximo id ' . $n($t['auto']) . '</span>' : '')
        . ($C::acesso('console') ? '<a class="btn btn-sm btn-ghost-secondary ms-auto" href="' . $e($C::url('console.php', ['tabela' => $tabela])) . '"><i class="ti ti-terminal-2"></i><span>Abrir no console</span></a>' : '')
        . '</div>';

    echo '<ul class="nav nav-tabs pesquisador-abas-tabela">'
        . '<li class="nav-item"><a href="#" class="nav-link active" data-pesquisador-aba="dados"><i class="ti ti-list"></i> Dados</a></li>'
        . '<li class="nav-item"><a href="#" class="nav-link" data-pesquisador-aba="estrutura"><i class="ti ti-columns"></i> Estrutura</a></li>'
        . '<li class="nav-item"><a href="#" class="nav-link" data-pesquisador-aba="salvar"><i class="ti ti-device-floppy"></i> Salvar</a></li></ul>';

    // Dados
    echo '<div data-pesquisador-aba-painel="dados"><div class="card pesquisador-card"><div class="card-body p-0" data-pesquisador-dados>'
        . '<div class="pesquisador-pequeno p-3"><span class="pesquisador-giro"></span> Carregando...</div></div></div>'
        . '<p class="pesquisador-explicacao mt-2"><i class="ti ti-info-circle"></i><span>Filtros por coluna: texto procura em qualquer parte, <code>=valor</code> procura o valor exato e <code>NULL</code> procura vazios. Textos longos e binários aparecem resumidos.</span></p></div>';

    // Estrutura
    $lc = '';
    foreach ($colunas as $i => $c) {
        $lc .= '<tr><td class="text-end pesquisador-pequeno">' . ($i + 1) . '</td><td class="pesquisador-mono"><strong>' . $e($c['nome']) . '</strong></td><td class="pesquisador-mono">' . $e($c['tipo']) . '</td>'
            . '<td>' . ($c['nulo'] ? 'Sim' : 'Não') . '</td><td class="pesquisador-mono">' . ($c['padrao'] === null ? '<span class="pesquisador-pequeno">' . ($c['nulo'] ? 'NULL' : '—') . '</span>' : $e($c['padrao'])) . '</td>'
            . '<td>' . ($c['chave'] === 'PRI' ? '<span class="pesquisador-selo pesquisador-selo-aviso">primária</span>' : ($c['chave'] === 'UNI' ? '<span class="pesquisador-selo pesquisador-selo-info">única</span>' : ($c['chave'] === 'MUL' ? '<span class="pesquisador-selo pesquisador-selo-neutro">índice</span>' : ''))) . '</td>'
            . '<td class="pesquisador-pequeno">' . $e($c['extra']) . '</td><td class="pesquisador-pequeno">' . $e($c['collation']) . '</td><td class="pesquisador-pequeno">' . $e($c['comentario']) . '</td></tr>';
    }
    $li = '';
    foreach ($indices as $i) {
        $li .= '<tr><td class="pesquisador-mono"><strong>' . $e($i['nome']) . '</strong></td><td>' . ($i['nome'] === 'PRIMARY' ? 'Primária' : ($i['unico'] ? 'Única' : 'Comum')) . '</td><td>' . $e($i['tipo']) . '</td>'
            . '<td class="pesquisador-mono">' . $e(implode(', ', $i['colunas'])) . '</td><td class="text-end">' . $n($i['cardinalidade']) . '</td></tr>';
    }
    echo '<div data-pesquisador-aba-painel="estrutura" hidden>'
        . '<div class="card pesquisador-card"><div class="card-header"><h5><i class="ti ti-columns"></i> Colunas (' . count($colunas) . ')</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-striped table-hover pesquisador-tabela mb-0">'
        . '<thead><tr><th>#</th><th>Nome</th><th>Tipo</th><th>Nulo</th><th>Padrão</th><th>Chave</th><th>Extra</th><th>Collation</th><th>Comentário</th></tr></thead><tbody>' . $lc . '</tbody></table></div></div></div>'
        . ($indices ? '<div class="card pesquisador-card mt-3"><div class="card-header"><h5><i class="ti ti-key"></i> Índices (' . count($indices) . ')</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-striped table-hover pesquisador-tabela mb-0">'
            . '<thead><tr><th>Nome</th><th>Tipo</th><th>Método</th><th>Colunas</th><th class="text-end">Cardinalidade</th></tr></thead><tbody>' . $li . '</tbody></table></div></div></div>' : '')
        . '<div class="card pesquisador-card mt-3"><div class="card-header"><h5><i class="ti ti-code"></i> ' . ($t['visao'] ? 'CREATE VIEW' : 'CREATE TABLE') . '</h5>'
        . '<div class="pesquisador-cab-acoes"><button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-copiar="criacao"><i class="ti ti-copy"></i><span>Copiar</span></button></div></div>'
        . '<div class="card-body"><pre class="pesquisador-codigo" data-pesquisador-texto="criacao">' . $e($B::criacao($tabela, $t['visao'])) . '</pre></div></div>'
        . '</div>';

    // Salvar
    echo '<div data-pesquisador-aba-painel="salvar" hidden><div class="card pesquisador-card"><div class="card-header"><h5><i class="ti ti-device-floppy"></i> Salvar a tabela inteira</h5></div><div class="card-body">'
        . $formSalvar([$tabela], true) . '</div></div></div>';
}

echo '</div>';
Html::footer();
