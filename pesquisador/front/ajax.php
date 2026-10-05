<?php

/**
 * Plugin Pesquisador - endpoint AJAX (sempre JSON).
 * Busca:     buscar, situacao (GET); reindexar_inicio, reindexar_lote (POST, administradores)
 * Relatório: rel_listar, rel_salvos (GET); rel_salvar, rel_excluir (POST)
 * Console:   sql_executar, sql_salvar, sql_excluir (POST); sql_historico, sql_consultas, sql_tabelas, sql_colunas (GET)
 * Banco:     banco_dados, banco_guardados (GET); banco_guardar, banco_excluir_guardado (POST)
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno: ' . $erro['message']]);
    }
});

$C = PluginPesquisadorConfig::class;
$I = PluginPesquisadorIndexador::class;
$R = PluginPesquisadorRelatorio::class;
$S = PluginPesquisadorConsole::class;
$B = PluginPesquisadorBanco::class;
$X = PluginPesquisadorExportador::class;

$post = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$responder = function (array $dados) use ($C, $post): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = $post ? $C::tokenCsrf() : '';
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$falhar = fn(string $mensagem, array $extra = []) => $responder(['success' => false, 'mensagem' => $mensagem] + $extra);

$uid = (int) Session::getLoginUserID();
if ($uid <= 0) {
    $falhar('Sessão expirada. Recarregue a página.');
}
$acao = (string) ($_REQUEST['action'] ?? '');
$exigir = function (string $modulo, bool $exigePost = false) use ($C, $post, $falhar): void {
    if ($exigePost && !$post) {
        $falhar('Requisição inválida.');
    }
    if (!$C::acesso($modulo)) {
        $falhar('Você não tem acesso a esta parte do Pesquisador.');
    }
};

try {
    switch ($acao) {
        // ------------------------------------------------------------ busca
        case 'buscar':
            $exigir('busca');
            $lista = fn(string $k) => array_values(array_filter(array_map('strval', (array) ($_GET[$k] ?? [])), fn($v) => $v !== ''));
            $busca = new PluginPesquisadorBusca((string) ($_GET['q'] ?? ''), [
                'tipos'    => isset($_GET['tipos']) ? $lista('tipos') : null,
                'fontes'   => isset($_GET['fontes']) ? $lista('fontes') : null,
                'situacao' => (string) ($_GET['situacao'] ?? ''),
                'de'       => (string) ($_GET['de'] ?? ''),
                'ate'      => (string) ($_GET['ate'] ?? ''),
                'ordem'    => (string) ($_GET['ordem'] ?? ''),
                'parcial'  => !empty($_GET['parcial']),
            ]);
            $resultado = $busca->executar((int) ($_GET['pagina'] ?? 1), (int) ($_GET['por_pagina'] ?? $C::inteiro('por_pagina', 10, 200)));
            $responder(['success' => $resultado['erro'] === null, 'mensagem' => (string) $resultado['erro']] + $resultado);

        case 'situacao':
            $exigir('busca');
            $responder(['success' => true] + $I::situacao());

        case 'reindexar_inicio':
        case 'reindexar_lote':
            if (!$post) {
                $falhar('Requisição inválida.');
            }
            if (!$C::ehAdmin()) {
                $falhar('Somente administradores podem reindexar.');
            }
            @set_time_limit(300);
            if ($acao === 'reindexar_inicio') {
                $I::iniciarReconstrucao();
            }
            $lote = $I::construirLote(400);
            $responder(['success' => true] + $lote + $I::situacao());

        // ------------------------------------------------------------ relatório
        case 'rel_listar':
            $exigir('relatorio');
            $f = $R::normalizar($_GET);
            $responder(['success' => true] + $R::listar($f, (int) ($_GET['pagina'] ?? 1), (int) ($_GET['por_pagina'] ?? 25)));

        case 'rel_salvos':
            $exigir('relatorio');
            $responder(['success' => true, 'salvos' => $R::salvos($uid)]);

        case 'rel_salvar':
            $exigir('relatorio', true);
            $filtros = json_decode((string) ($_POST['filtros'] ?? ''), true);
            $id = $R::salvar($uid, (string) ($_POST['nome'] ?? ''), is_array($filtros) ? $filtros : []);
            if ($id <= 0) {
                $falhar('Informe um nome para o relatório.');
            }
            $responder(['success' => true, 'mensagem' => 'Relatório salvo.', 'id' => $id, 'salvos' => $R::salvos($uid)]);

        case 'rel_excluir':
            $exigir('relatorio', true);
            if (!$R::excluir($uid, (int) ($_POST['id'] ?? 0))) {
                $falhar('Relatório não encontrado.');
            }
            $responder(['success' => true, 'mensagem' => 'Relatório excluído.', 'salvos' => $R::salvos($uid)]);

        // ------------------------------------------------------------ console
        case 'sql_executar':
            $exigir('console', true);
            @set_time_limit(0);
            $r = $S::executar((string) ($_POST['sql'] ?? ''), !empty($_POST['confirmado']));
            $responder(['success' => $r['sucesso']] + $r);

        case 'sql_historico':
            $exigir('console');
            $responder(['success' => true, 'historico' => $S::historico($uid, 100, $C::ehAdmin() && !empty($_GET['todos']))]);

        case 'sql_consultas':
            $exigir('console');
            $responder(['success' => true, 'consultas' => $S::consultas($uid)]);

        case 'sql_salvar':
            $exigir('console', true);
            $id = $S::salvar($uid, (int) ($_POST['id'] ?? 0), (string) ($_POST['nome'] ?? ''), (string) ($_POST['sql'] ?? ''), !empty($_POST['compartilhada']));
            if ($id <= 0) {
                $falhar('Informe o nome e o comando para salvar.');
            }
            $responder(['success' => true, 'mensagem' => 'Consulta salva.', 'id' => $id, 'consultas' => $S::consultas($uid)]);

        case 'sql_excluir':
            $exigir('console', true);
            if (!$S::excluir($uid, (int) ($_POST['id'] ?? 0))) {
                $falhar('Você só pode excluir as suas consultas.');
            }
            $responder(['success' => true, 'mensagem' => 'Consulta excluída.', 'consultas' => $S::consultas($uid)]);

        case 'sql_tabelas':
            $exigir('console');
            $lista = [];
            foreach ($B::tabelas() as $t) {
                $lista[] = ['nome' => $t['nome'], 'visao' => $t['visao'], 'linhas' => $t['linhas']];
            }
            $responder(['success' => true, 'tabelas' => $lista]);

        case 'sql_colunas':
            $exigir('console');
            $tabela = (string) ($_GET['tabela'] ?? '');
            if (!$B::existe($tabela)) {
                $falhar('Tabela não encontrada.');
            }
            $responder(['success' => true, 'colunas' => array_map(fn($c) => ['nome' => $c['nome'], 'tipo' => $c['tipo'], 'chave' => $c['chave']], $B::colunas($tabela))]);

        // ------------------------------------------------------------ banco
        case 'banco_dados':
            $exigir('banco');
            $tabela = (string) ($_GET['tabela'] ?? '');
            if (!$B::existe($tabela)) {
                $falhar('Tabela não encontrada.');
            }
            $filtros = [];
            foreach ((array) ($_GET['f'] ?? []) as $col => $txt) {
                if (is_string($col) && is_string($txt)) {
                    $filtros[$col] = $txt;
                }
            }
            $responder(['success' => true] + $B::dados(
                $tabela,
                (int) ($_GET['pagina'] ?? 1),
                (int) ($_GET['por_pagina'] ?? 25),
                (string) ($_GET['ordem'] ?? ''),
                (string) ($_GET['direcao'] ?? 'asc'),
                $filtros
            ));

        case 'banco_guardados':
            $exigir('banco');
            $responder(['success' => true, 'arquivos' => $X::guardados(), 'baixar' => $C::url('exportar.php', ['tipo' => 'arquivo'])]);

        case 'banco_guardar':
            $exigir('banco', true);
            @set_time_limit(0);
            $o = $X::opcoes($_POST);
            $r = $X::gerar(array_map('strval', (array) ($_POST['tabelas'] ?? [])), $o);
            $g = $X::guardar($r, $o);
            $responder([
                'success'  => true,
                'mensagem' => 'Arquivo guardado no servidor: ' . $r['nome'] . ' (' . $C::tamanho($r['tamanho']) . ', ' . number_format($r['linhas'], 0, ',', '.') . ' linhas).',
                'arquivos' => $X::guardados(),
            ]);

        case 'banco_excluir_guardado':
            $exigir('banco', true);
            if (!$C::ehAdmin()) {
                $falhar('Só administradores excluem arquivos guardados.');
            }
            if (!$X::excluirGuardado((int) ($_POST['id'] ?? 0))) {
                $falhar('Arquivo não encontrado.');
            }
            $responder(['success' => true, 'mensagem' => 'Arquivo excluído.', 'arquivos' => $X::guardados()]);

        default:
            $falhar('Ação desconhecida.');
    }
} catch (\Glpi\Exception\RedirectException $e) {
    throw $e;
} catch (\Throwable $e) {
    Toolbox::logInFile('pesquisador', 'Erro no ajax (' . $acao . '): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    $falhar('Erro: ' . $e->getMessage());
}
