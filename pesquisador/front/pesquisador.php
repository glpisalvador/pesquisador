<?php

/**
 * Plugin Pesquisador - busca textual (Ferramentas > Pesquisador > Busca).
 * Os filtros ficam na URL: a busca pode ser compartilhada e o botão voltar funciona.
 */

Session::checkLoginUser();

$C = PluginPesquisadorConfig::class;
$e = [$C, 'e'];
if (!$C::temAcesso()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$permitidos = $C::tiposPermitidos();
$G = fn(string $k, string $padrao = '') => is_string($_GET[$k] ?? null) ? (string) $_GET[$k] : $padrao;
$GL = fn(string $k, array $padrao) => isset($_GET[$k]) ? array_map('strval', (array) $_GET[$k]) : $padrao;

$q = $G('q');
$tipos = array_intersect($GL('tipos', array_keys($permitidos)), array_keys($permitidos));
$fontes = array_intersect($GL('fontes', array_keys($C::FONTES)), array_keys($C::FONTES));
$situacao = $G('situacao', 'todos');
$ordem = $G('ordem', 'relevancia');
$porPagina = (int) $G('por_pagina', (string) $C::inteiro('por_pagina', 10, 200));
$parcial = isset($_GET['q']) ? !empty($_GET['parcial']) : $C::getConfig('parcial_padrao') === '1';
$situacaoIndice = PluginPesquisadorIndexador::situacao();

$C::cabecalho('busca', 'Pesquisador');
echo $C::assets();

$opcoes = function (array $lista, string $atual) use ($e): string {
    $h = '';
    foreach ($lista as $valor => $rotulo) {
        $h .= '<option value="' . $e($valor) . '"' . ((string) $valor === $atual ? ' selected' : '') . '>' . $e($rotulo) . '</option>';
    }
    return $h;
};

echo '<div class="pesquisador-pagina" data-pesquisador data-ajax="' . $e($C::url('ajax.php')) . '" data-situacao-indice="' . $e(json_encode($situacaoIndice)) . '">';

if (!$permitidos) {
    echo '<div class="pesquisador-alerta pesquisador-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>Seu perfil não pode ver chamados, problemas nem mudanças, então não há o que pesquisar.</span></div>';
}
if (!$situacaoIndice['completo']) {
    echo '<div class="pesquisador-alerta pesquisador-alerta-info" data-pesquisador-indice><i class="ti ti-loader"></i><span>O índice de busca está sendo construído (<strong data-pct>' . (int) $situacaoIndice['pct'] . '%</strong>). '
        . 'Enquanto isso, os resultados podem ficar incompletos.'
        . ($C::ehAdmin() ? ' <a href="' . $e($C::url('config.form.php')) . '">Acelerar na configuração</a>.' : '') . '</span></div>';
}

echo '<form class="card pesquisador-card" data-pesquisador-form autocomplete="off">';
echo '<div class="card-body">';
echo '<div class="pesquisador-caixa"><div class="input-group">'
    . '<span class="input-group-text"><i class="ti ti-search"></i></span>'
    . '<input type="search" name="q" class="form-control" value="' . $e($q) . '" placeholder="Ex.: impressora financeiro &quot;sem papel&quot; -teste #1234" autofocus>'
    . '<button type="submit" class="btn pesquisador-btn-principal"><i class="ti ti-search"></i><span>Pesquisar</span></button>'
    . '</div>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-ajuda title="Como pesquisar"><i class="ti ti-help-circle"></i></button></div>';

echo '<div class="pesquisador-ajuda" hidden data-pesquisador-ajuda-texto>'
    . '<div><code>impressora financeiro</code> todos os termos, em qualquer parte do item</div>'
    . '<div><code>"sem papel"</code> frase exata</div>'
    . '<div><code>-teste</code> exclui itens com o termo</div>'
    . '<div><code>#1234</code> ou <code>1234</code> pelo número do item</div>'
    . '<div><code>impress</code> encontra o começo das palavras (impressora, impressão); ative <em>Busca parcial</em> para achar trechos no meio (pressora)</div>'
    . '<div>Acentos e maiúsculas não importam.</div></div>';

echo '<div class="pesquisador-filtros">';
echo '<div class="pesquisador-grupo"><span class="pesquisador-rotulo">Itens</span>';
foreach ($C::TIPOS as $tipo => $def) {
    if (!isset($permitidos[$tipo])) {
        continue;
    }
    echo '<label class="pesquisador-opcao"><input type="checkbox" class="pesquisador-check" name="tipos[]" value="' . $e($tipo) . '"' . (in_array($tipo, $tipos, true) ? ' checked' : '') . '><i class="' . $e($def['icone']) . '"></i> ' . $e($def['plural'])
        . ($permitidos[$tipo] === 'ator' ? ' <small class="text-muted" title="Seu perfil vê só os itens em que você ou um grupo seu participa">(meus)</small>' : '') . '</label>';
}
echo '</div>';
echo '<div class="pesquisador-grupo"><span class="pesquisador-rotulo">Situação</span><select name="situacao" class="form-select form-select-sm">'
    . $opcoes(['todos' => 'Todos', 'abertos' => 'Em aberto', 'encerrados' => 'Solucionados e fechados'], $situacao) . '</select></div>';
echo '<div class="pesquisador-grupo"><span class="pesquisador-rotulo">Abertos de</span>'
    . Html::showDateField('de', ['value' => $G('de'), 'display' => false, 'maybeempty' => true])
    . '<span class="pesquisador-rotulo">até</span>'
    . Html::showDateField('ate', ['value' => $G('ate'), 'display' => false, 'maybeempty' => true]) . '</div>';
echo '<div class="pesquisador-grupo"><span class="pesquisador-rotulo">Ordenar</span><select name="ordem" class="form-select form-select-sm">'
    . $opcoes(['relevancia' => 'Mais relevantes', 'recentes' => 'Mais recentes', 'antigos' => 'Mais antigos'], $ordem) . '</select></div>';
echo '<div class="pesquisador-grupo"><div class="form-check form-switch pesquisador-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="pesquisador-parcial" name="parcial" value="1"' . ($parcial ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="pesquisador-parcial" title="Encontra o termo em qualquer trecho da palavra; é mais lenta">Busca parcial</label></div></div>';
echo '</div>';

echo '<div class="pesquisador-filtros pesquisador-fontes"><span class="pesquisador-rotulo">Procurar em</span>';
foreach ($C::FONTES as $fonte => [$rotulo, $icone]) {
    echo '<label class="pesquisador-opcao"><input type="checkbox" class="pesquisador-check" name="fontes[]" value="' . $e($fonte) . '"' . (in_array($fonte, $fontes, true) ? ' checked' : '') . '><i class="' . $e($icone) . '"></i> ' . $e($rotulo) . '</label>';
}
echo '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-limpar><i class="ti ti-eraser"></i> Limpar filtros</button>';
echo '</div>';
echo '<input type="hidden" name="pagina" value="' . max(1, (int) $G('pagina', '1')) . '">';
echo '<input type="hidden" name="por_pagina" value="' . max(10, min(200, $porPagina)) . '">';
echo '</div></form>';

echo '<div class="pesquisador-status" data-pesquisador-status></div>';
echo '<div data-pesquisador-resultados></div>';
echo '</div>';
Html::footer();
