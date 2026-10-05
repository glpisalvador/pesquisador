<?php

/**
 * Plugin Pesquisador - configuração (marketplace e menu). Cada aba tem formulários próprios que fazem
 * POST para esta mesma página; a reindexação roda por AJAX em lotes, com barra de progresso.
 */

Session::checkLoginUser();

$C = PluginPesquisadorConfig::class;
$I = PluginPesquisadorIndexador::class;
$R = PluginPesquisadorRelatorio::class;
$e = [$C, 'e'];

if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$abas = [
    'busca'     => ['ti ti-search', 'Busca'],
    'relatorio' => ['ti ti-report', 'Relatório de chamados'],
    'sql'       => ['ti ti-database', 'SQL e banco'],
];
$aba = (string) ($_POST['aba'] ?? $_GET['aba'] ?? 'busca');
if (!isset($abas[$aba])) {
    $aba = 'busca';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_action'])) {
    $numero = fn(string $campo, int $min, int $max, int $padrao) => (string) max($min, min($max, (int) ($_POST[$campo] ?? $padrao)));
    switch ((string) $_POST['save_action']) {
        case 'salvar_acesso':
            $C::setArrayConfig('allowed_profiles', $C::idsPost('allowed_profiles'));
            $C::setArrayConfig('allowed_users', $C::idsPost('allowed_users'));
            Session::addMessageAfterRedirect('Acesso à busca salvo.', false, INFO);
            break;

        case 'salvar_opcoes':
            $C::setConfig('limite_por_tipo', $numero('limite_por_tipo', 50, 5000, 500));
            $C::setConfig('por_pagina', (string) (in_array((int) ($_POST['por_pagina'] ?? 25), [25, 50, 100, 200], true) ? (int) $_POST['por_pagina'] : 25));
            $C::setConfig('parcial_padrao', !empty($_POST['parcial_padrao']) ? '1' : '0');
            Session::addMessageAfterRedirect('Opções da busca salvas.', false, INFO);
            break;

        case 'salvar_relatorio':
            $C::setArrayConfig('relatorio_perfis', $C::idsPost('relatorio_perfis'));
            $C::setArrayConfig('relatorio_usuarios', $C::idsPost('relatorio_usuarios'));
            $colunas = array_values(array_intersect(array_map('strval', (array) ($_POST['relatorio_colunas'] ?? [])), array_keys($R::COLUNAS)));
            $C::setArrayConfig('relatorio_colunas', $colunas ?: $C::padroes()['relatorio_colunas']);
            $C::setConfig('relatorio_limite_xlsx', $numero('relatorio_limite_xlsx', 1000, 500000, 50000));
            Session::addMessageAfterRedirect('Configuração do relatório salva.', false, INFO);
            break;

        case 'salvar_sql':
            $C::setArrayConfig('sql_perfis', $C::idsPost('sql_perfis'));
            $C::setArrayConfig('sql_usuarios', $C::idsPost('sql_usuarios'));
            $C::setConfig('sql_escrita', !empty($_POST['sql_escrita']) ? '1' : '0');
            $C::setConfig('sql_limite_linhas', $numero('sql_limite_linhas', 50, 10000, 1000));
            $C::setConfig('sql_tempo_limite', $numero('sql_tempo_limite', 5, 3600, 60));
            $C::setConfig('historico_dias', $numero('historico_dias', 0, 3650, 90));
            $C::setConfig('exportacoes_dias', $numero('exportacoes_dias', 0, 3650, 15));
            Session::addMessageAfterRedirect('Configuração do console e do banco salva.', false, INFO);
            break;
    }
}

$C::cabecalho('config', 'Pesquisador');
echo $C::assets();

$form = fn(string $acao) => '<form method="post" action="' . $e($C::url('config.form.php')) . '" class="pesquisador-form"><input type="hidden" name="save_action" value="' . $e($acao) . '"><input type="hidden" name="aba" value="' . $e($aba) . '" data-pesquisador-aba-atual>';
$salvar = '<div class="pesquisador-rodape-form"><button type="submit" class="btn btn-sm pesquisador-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar</span></button></div>';
$card = fn(string $icone, string $titulo, string $corpo) => '<div class="card pesquisador-card"><div class="card-header"><h5><i class="' . $icone . '"></i> ' . $e($titulo) . '</h5></div><div class="card-body">' . $corpo . '</div></div>';
$explicacao = fn(string $texto) => '<p class="pesquisador-explicacao"><i class="ti ti-info-circle"></i><span>' . $texto . '</span></p>';
$numeroCampo = fn(string $nome, string $rotulo, int $min, int $max, string $dica = '') => '<div class="pesquisador-campo"><label for="pq-' . $nome . '">' . $e($rotulo) . '</label>'
    . '<input type="number" id="pq-' . $nome . '" class="form-control form-control-sm" name="' . $nome . '" min="' . $min . '" max="' . $max . '" value="' . (int) $C::getConfig($nome) . '">' . ($dica !== '' ? '<small>' . $dica . '</small>' : '') . '</div>';
$perfis = $C::listarPerfis();
$usuarios = $C::listarUsuarios();
$acesso = fn(string $p, string $u) => '<div class="pesquisador-grade-2"><div class="pesquisador-campo"><label>Perfis</label>' . $C::multiselect($p, $perfis, $C::getArrayConfig($p), 'Nenhum perfil') . '</div>'
    . '<div class="pesquisador-campo"><label>Usuários</label>' . $C::multiselect($u, $usuarios, $C::getArrayConfig($u), 'Nenhum usuário') . '</div></div>';

$s = $I::situacao();
echo '<div class="pesquisador-config" data-pesquisador-config data-ajax="' . $e($C::url('ajax.php')) . '" data-token="' . $e($C::tokenCsrf()) . '">';
echo '<ul class="nav nav-pills pesquisador-subabas">';
foreach ($abas as $k => [$icone, $rotulo]) {
    echo '<li class="nav-item"><a href="#" class="nav-link' . ($k === $aba ? ' active' : '') . '" data-aba="' . $k . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
}
echo '</ul>';

// ================================================================ Busca
echo '<div class="pesquisador-config-painel" data-aba-painel="busca"' . ($aba === 'busca' ? '' : ' hidden') . '>';
$linhas = '';
foreach ($s['tipos'] as $t) {
    $linhas .= '<tr><td>' . $e($t['rotulo']) . '</td><td class="text-end" data-feito>' . number_format($t['feito'], 0, ',', '.') . '</td><td class="text-end">' . number_format($t['total'], 0, ',', '.') . '</td></tr>';
}
$corpo = $explicacao('O índice guarda o texto puro de cada chamado, problema e mudança (título, descrição, acompanhamentos, soluções, tarefas, validações e nomes de anexos) numa tabela do plugin, sem alterar as tabelas do GLPI. Ele é atualizado na hora pelas gravações do GLPI e revisado a cada 5 minutos pela tarefa automática "PesquisadorIndexar". Reindexe só se suspeitar de diferenças.')
    . '<div class="pesquisador-indice">'
    . '<div class="pesquisador-progresso"><div class="pesquisador-progresso-barra" data-barra style="width:' . (int) $s['pct'] . '%"></div><span data-pct>' . (int) $s['pct'] . '%</span></div>'
    . '<div class="pesquisador-indice-info"><span data-estado><span class="pesquisador-selo ' . ($s['completo'] ? 'pesquisador-selo-ok">Índice completo' : 'pesquisador-selo-info">Em construção') . '</span></span>'
    . '<span class="pesquisador-pequeno"><span data-textos>' . number_format($s['textos'], 0, ',', '.') . '</span> textos indexados'
    . ($s['revisao'] !== '' ? ' · última revisão ' . $e(Html::convDateTime($s['revisao'])) : '') . '</span></div>'
    . '<table class="table table-sm pesquisador-tabela mb-0"><thead><tr><th>Item</th><th class="text-end">Indexados</th><th class="text-end">Total</th></tr></thead><tbody>' . $linhas . '</tbody></table>'
    . '<div class="pesquisador-rodape-form"><button type="button" class="btn btn-sm pesquisador-btn-principal" data-reindexar><i class="ti ti-refresh"></i><span>Reindexar tudo</span></button></div>'
    . '</div>';
echo $card('ti ti-database-search', 'Índice de busca', $corpo);

echo $form('salvar_acesso');
echo $card('ti ti-shield-lock', 'Quem pode usar a busca', $explicacao('Administradores sempre têm acesso. Cada pessoa só encontra o que já pode ver no GLPI: as entidades ativas, os itens permitidos pelo perfil (todos, ou só aqueles de que participa) e os textos privados apenas com o direito de vê-los.')
    . $acesso('allowed_profiles', 'allowed_users') . $salvar);
echo Html::closeForm(false);

echo $form('salvar_opcoes');
$porPagina = (int) $C::getConfig('por_pagina');
$op = '';
foreach ([25, 50, 100, 200] as $n) {
    $op .= '<option value="' . $n . '"' . ($n === $porPagina ? ' selected' : '') . '>' . $n . '</option>';
}
$corpo = '<div class="pesquisador-linha">'
    . $numeroCampo('limite_por_tipo', 'Máximo de itens por tipo', 50, 5000, 'Os mais relevantes de cada tipo (50 a 5.000).')
    . '<div class="pesquisador-campo"><label for="pq-pagina">Resultados por página</label><select id="pq-pagina" class="form-select form-select-sm" name="por_pagina">' . $op . '</select><small>Vale também para o relatório.</small></div>'
    . '<div class="pesquisador-campo"><label>&nbsp;</label><div class="form-check form-switch pesquisador-switch"><input class="form-check-input" type="checkbox" role="switch" id="pq-parcial" name="parcial_padrao" value="1"' . ($C::getConfig('parcial_padrao') === '1' ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="pq-parcial">Busca parcial ligada por padrão</label></div><small>Acha trechos no meio das palavras; é mais lenta em bases grandes.</small></div>'
    . '</div>';
echo $card('ti ti-adjustments', 'Opções da busca', $corpo . $salvar);
echo Html::closeForm(false);
echo '</div>';

// ================================================================ Relatório
echo '<div class="pesquisador-config-painel" data-aba-painel="relatorio"' . ($aba === 'relatorio' ? '' : ' hidden') . '>';
echo $form('salvar_relatorio');
$colunasPadrao = $C::getArrayConfig('relatorio_colunas');
$colunas = [];
foreach ($R::COLUNAS as $k => [$rotulo]) {
    $colunas[$k] = $rotulo;
}
echo $card('ti ti-shield-lock', 'Quem pode usar o relatório', $explicacao('Administradores sempre têm acesso. O relatório mostra só chamados das entidades ativas da pessoa; perfis que não veem todos os chamados enxergam apenas aqueles de que participam.')
    . $acesso('relatorio_perfis', 'relatorio_usuarios'));
echo $card('ti ti-columns', 'Padrões', '<div class="pesquisador-grade-2"><div class="pesquisador-campo"><label>Colunas padrão</label>' . $C::multiselect('relatorio_colunas', $colunas, $colunasPadrao, 'Colunas padrão')
    . '<small>Usadas por quem ainda não escolheu as próprias colunas.</small></div>'
    . $numeroCampo('relatorio_limite_xlsx', 'Máximo de linhas no XLSX', 1000, 500000, 'Acima disso, use CSV (o Excel monta a planilha inteira na memória).') . '</div>' . $salvar);
echo Html::closeForm(false);
echo '</div>';

// ================================================================ SQL e banco
echo '<div class="pesquisador-config-painel" data-aba-painel="sql"' . ($aba === 'sql' ? '' : ' hidden') . '>';
echo $form('salvar_sql');
echo $card('ti ti-shield-lock', 'Quem pode usar o console e o banco de dados', $explicacao('Quem tem o direito de configuração do GLPI sempre tem acesso. Os perfis e usuários liberados aqui usam o console <strong>só para leitura</strong> (SELECT, SHOW, DESCRIBE, EXPLAIN) e podem ver e salvar qualquer tabela — inclusive dados sensíveis como e-mails e senhas criptografadas. Libere com cuidado.')
    . $acesso('sql_perfis', 'sql_usuarios'));
echo $card('ti ti-pencil', 'Comandos que alteram o banco', '<div class="pesquisador-alerta pesquisador-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>INSERT, UPDATE, DELETE, ALTER, DROP e outros comandos mudam o banco do GLPI diretamente, sem histórico do GLPI e sem como desfazer. Faça um salvamento das tabelas antes.</span></div>'
    . '<div class="form-check form-switch pesquisador-switch"><input class="form-check-input" type="checkbox" role="switch" id="pq-escrita" name="sql_escrita" value="1"' . ((string) $C::getConfig('sql_escrita') === '1' ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="pq-escrita">Permitir que administradores executem comandos de alteração</label></div>'
    . $explicacao('Mesmo ligado, cada execução pede confirmação na tela e fica registrada no histórico do console e no log <code>files/_log/pesquisador-sql.log</code>.'));
echo $card('ti ti-adjustments', 'Limites e retenção', '<div class="pesquisador-linha">'
    . $numeroCampo('sql_limite_linhas', 'Linhas mostradas por resultado', 50, 10000, 'A exportação do resultado traz todas.')
    . $numeroCampo('sql_tempo_limite', 'Tempo limite por comando (segundos)', 5, 3600, 'No MySQL vale só para SELECT; no MariaDB, para todos.')
    . $numeroCampo('historico_dias', 'Guardar histórico do console (dias)', 0, 3650, '0 = para sempre. Comandos de alteração ficam o dobro do tempo.')
    . $numeroCampo('exportacoes_dias', 'Guardar arquivos salvos no servidor (dias)', 0, 3650, '0 = para sempre. Limpeza diária pela tarefa "PesquisadorLimpar".')
    . '</div>' . $salvar);
echo Html::closeForm(false);
echo '</div>';

echo '</div>';
Html::footer();
