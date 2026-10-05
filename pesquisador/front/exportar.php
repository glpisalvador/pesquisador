<?php

/**
 * Plugin Pesquisador - downloads (o navegador baixa direto, em fluxo):
 *   tipo=relatorio (GET)  relatório de chamados em CSV ou XLSX, com os filtros e colunas da tela
 *   tipo=console   (POST) resultado completo de um comando de consulta em CSV, XLSX ou JSON
 *   tipo=banco     (POST) salvar tabelas inteiras (SQL, CSV, JSON, XLSX; gzip/zip)
 *   tipo=arquivo   (GET)  arquivo guardado no servidor
 * O cookie pesquisador_download avisa a tela que o arquivo começou a descer (ou o erro).
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}

Session::checkLoginUser();

$C = PluginPesquisadorConfig::class;
$X = PluginPesquisadorExportador::class;
$R = PluginPesquisadorRelatorio::class;

$aviso = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_REQUEST['aviso'] ?? ''));
$sinal = function (string $estado) use ($aviso): void {
    if ($aviso !== '' && !headers_sent()) {
        setrawcookie('pesquisador_download', rawurlencode($aviso . ':' . mb_substr($estado, 0, 300)), ['expires' => time() + 300, 'path' => '/', 'samesite' => 'Lax']);
    }
};
$falhar = function (string $mensagem, int $codigo = 400) use ($sinal): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $sinal('erro:' . $mensagem);
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    echo $mensagem;
    exit;
};

@set_time_limit(0);
@ini_set('memory_limit', '512M');
$tipo = (string) ($_REQUEST['tipo'] ?? '');
$post = strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

try {
    switch ($tipo) {
        case 'relatorio':
            if (!$C::acesso('relatorio')) {
                $falhar('Sem acesso ao relatório.', 403);
            }
            $f = $R::normalizar($_GET);
            $formato = ($_GET['formato'] ?? '') === 'xlsx' ? 'xlsx' : 'csv';
            $cabecalho = array_map(fn($c) => $R::COLUNAS[$c][0], $f['colunas']);
            $nome = 'relatorio_chamados_' . date('Ymd_His');
            if ($formato === 'csv') {
                $sinal('ok');
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $nome . '.csv"');
                header('Cache-Control: no-store');
                $saida = fopen('php://output', 'w');
                fwrite($saida, "\xEF\xBB\xBF" . $X::linhaCsv($cabecalho));
                $R::percorrer($f, function (array $r) use ($saida, $f, $R, $X) {
                    fwrite($saida, $X::linhaCsv(array_map(fn($c) => $R::celula($c, $r, false), $f['colunas'])));
                });
                fclose($saida);
                exit;
            }
            @ini_set('memory_limit', '1024M');
            $limite = $C::inteiro('relatorio_limite_xlsx', 1000, 500000);
            $linhas = [$cabecalho];
            $total = $R::percorrer($f, function (array $r) use (&$linhas, $f, $R) {
                $linhas[] = array_map(fn($c) => $R::celula($c, $r, false), $f['colunas']);
            }, $limite + 1);
            if ($total > $limite) {
                $falhar('O relatório tem mais de ' . number_format($limite, 0, ',', '.') . ' linhas: exporte em CSV ou use filtros.');
            }
            $planilha = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $aba = $planilha->getActiveSheet();
            $aba->setTitle('Chamados');
            $X::preencherAba($aba, $linhas);
            $caminho = GLPI_TMP_DIR . '/pesquisador_' . bin2hex(random_bytes(8)) . '.xlsx';
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($planilha))->save($caminho);
            $planilha->disconnectWorksheets();
            $sinal('ok');
            $X::enviar($caminho, $nome . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', true);
            // no break

        case 'console':
            if (!$post || !$C::acesso('console')) {
                $falhar('Sem acesso ao console.', 403);
            }
            $sql = (string) ($_POST['sql'] ?? '');
            $formato = in_array($_POST['formato'] ?? '', ['csv', 'xlsx', 'json'], true) ? (string) $_POST['formato'] : 'csv';
            $nome = 'consulta_' . date('Ymd_His');
            $caminho = GLPI_TMP_DIR . '/pesquisador_' . bin2hex(random_bytes(8)) . '.' . $formato;
            if ($formato === 'xlsx') {
                @ini_set('memory_limit', '1024M');
                $linhas = [];
                $colunas = PluginPesquisadorConsole::percorrer($sql, function (array $r) use (&$linhas) {
                    $linhas[] = array_map(fn($v) => $v === null || mb_check_encoding((string) $v, 'UTF-8') ? $v : '0x' . bin2hex((string) $v), $r);
                    if (count($linhas) > PluginPesquisadorExportador::LIMITE_XLSX) {
                        throw new RuntimeException('Mais de ' . number_format(PluginPesquisadorExportador::LIMITE_XLSX, 0, ',', '.') . ' linhas: exporte em CSV ou JSON.');
                    }
                });
                $planilha = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $aba = $planilha->getActiveSheet();
                $aba->setTitle('Consulta');
                $X::preencherAba($aba, array_merge([$colunas], $linhas));
                (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($planilha))->save($caminho);
                $planilha->disconnectWorksheets();
                $sinal('ok');
                $X::enviar($caminho, $nome . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', true);
            }
            $h = fopen($caminho, 'wb');
            $primeira = true;
            $colunas = PluginPesquisadorConsole::percorrer($sql, function (array $r, array $campos) use ($h, $formato, &$primeira, $X) {
                if ($primeira) {
                    fwrite($h, $formato === 'csv' ? "\xEF\xBB\xBF" . $X::linhaCsv(array_map(fn($f) => $f->name, $campos)) : "[\n");
                }
                if ($formato === 'csv') {
                    fwrite($h, $X::linhaCsv($r));
                } else {
                    $obj = [];
                    foreach ($r as $i => $v) {
                        $obj[$campos[$i]->name] = $v === null ? null : (mb_check_encoding((string) $v, 'UTF-8') ? (string) $v : '0x' . bin2hex((string) $v));
                    }
                    fwrite($h, ($primeira ? '  ' : ",\n  ") . json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                }
                $primeira = false;
            });
            if ($primeira) {
                fwrite($h, $formato === 'csv' ? "\xEF\xBB\xBF" . $X::linhaCsv($colunas) : '[');
            }
            if ($formato === 'json') {
                fwrite($h, "\n]\n");
            }
            fclose($h);
            $sinal('ok');
            $X::enviar($caminho, $nome . '.' . $formato, $formato === 'csv' ? 'text/csv; charset=utf-8' : 'application/json', true);
            // no break

        case 'banco':
            if (!$post || !$C::acesso('banco')) {
                $falhar('Sem acesso ao banco de dados.', 403);
            }
            $o = $X::opcoes($_POST);
            $r = $X::gerar(array_map('strval', (array) ($_POST['tabelas'] ?? [])), $o);
            $X::registrar($r, $o, 'download', $r['nome']);
            $sinal('ok');
            $X::enviar($r['caminho'], $r['nome'], $r['mime'], true);
            // no break

        case 'arquivo':
            if (!$C::acesso('banco')) {
                $falhar('Sem acesso ao banco de dados.', 403);
            }
            $arquivo = $X::caminhoGuardado((int) ($_GET['id'] ?? 0));
            if ($arquivo === null) {
                $falhar('Arquivo não encontrado.', 404);
            }
            [$caminho, $nome] = $arquivo;
            $mime = str_ends_with($nome, '.zip') ? 'application/zip' : (str_ends_with($nome, '.gz') ? 'application/gzip' : 'application/octet-stream');
            $sinal('ok');
            $X::enviar($caminho, preg_replace('/^\d{8}_\d{6}_[a-f0-9]{6}_/', '', $nome), $mime, false);
            // no break

        default:
            $falhar('Exportação desconhecida.');
    }
} catch (\Glpi\Exception\RedirectException $e) {
    throw $e;
} catch (\Throwable $e) {
    Toolbox::logInFile('pesquisador', 'Erro na exportação (' . $tipo . '): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    $falhar($e->getMessage());
}
