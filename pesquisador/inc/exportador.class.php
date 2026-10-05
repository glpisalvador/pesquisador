<?php

/**
 * Plugin Pesquisador - salvar tabelas inteiras (como o "Exportar" do phpMyAdmin) e exportar resultados.
 * SQL (estrutura e/ou dados, DROP TABLE, INSERTs agrupados), CSV, JSON ou XLSX; sem compressão, gzip
 * ou zip; download ou arquivo guardado no servidor (files/_plugins/pesquisador/exportacoes).
 * Os dados são lidos em fluxo (sem carregar a tabela na memória) e gravados num arquivo temporário.
 */
class PluginPesquisadorExportador extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_pesquisador_exportacoes';
    public const FORMATOS = ['sql' => 'SQL', 'csv' => 'CSV', 'json' => 'JSON', 'xlsx' => 'Excel (XLSX)'];
    public const COMPRESSOES = ['nenhuma' => 'Nenhuma', 'gzip' => 'gzip (.gz)', 'zip' => 'zip (.zip)'];
    public const CONTEUDOS = ['ambos' => 'Estrutura e dados', 'estrutura' => 'Só estrutura', 'dados' => 'Só dados'];
    public const LIMITE_XLSX = 200000;

    public static function getTypeName($nb = 0): string
    {
        return 'Pesquisador';
    }

    // =====================================================================
    // Opções
    // =====================================================================

    public static function opcoes(array $in): array
    {
        $o = [
            'formato'    => isset(self::FORMATOS[(string) ($in['formato'] ?? '')]) ? (string) $in['formato'] : 'sql',
            'compressao' => isset(self::COMPRESSOES[(string) ($in['compressao'] ?? '')]) ? (string) $in['compressao'] : 'nenhuma',
            'conteudo'   => isset(self::CONTEUDOS[(string) ($in['conteudo'] ?? '')]) ? (string) $in['conteudo'] : 'ambos',
            'drop'       => !empty($in['drop']),
            'lote'       => max(1, min(1000, (int) ($in['lote'] ?? 100))),
            'nome'       => trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($in['nome'] ?? '')), '_.'),
        ];
        if ($o['formato'] === 'xlsx') {
            $o['compressao'] = 'nenhuma';
        }
        return $o;
    }

    private static function temporario(string $sufixo): string
    {
        return GLPI_TMP_DIR . '/pesquisador_' . bin2hex(random_bytes(8)) . $sufixo;
    }

    /** Destino de escrita: arquivo comum ou gzip */
    private static function abrir(string $caminho, bool $gzip)
    {
        $h = $gzip ? gzopen($caminho, 'wb6') : fopen($caminho, 'wb');
        if (!$h) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário.');
        }
        return $h;
    }

    private static function escrever($h, bool $gzip, string $texto): void
    {
        $gzip ? gzwrite($h, $texto) : fwrite($h, $texto);
    }

    private static function fechar($h, bool $gzip): void
    {
        $gzip ? gzclose($h) : fclose($h);
    }

    // =====================================================================
    // Salvar tabelas
    // =====================================================================

    /**
     * Gera o arquivo. Retorna ['caminho' (temporário), 'nome', 'mime', 'linhas', 'tamanho', 'tempo_ms'].
     */
    public static function gerar(array $tabelas, array $o): array
    {
        $inicio = microtime(true);
        @set_time_limit(0);
        $todas = PluginPesquisadorBanco::tabelas();
        $tabelas = array_values(array_intersect(array_keys($todas), $tabelas));
        if (!$tabelas) {
            throw new RuntimeException('Escolha pelo menos uma tabela.');
        }
        $base = $o['nome'] !== '' ? $o['nome'] : (count($tabelas) === 1 ? $tabelas[0] : PluginPesquisadorBanco::nomeBanco()) . '_' . date('Ymd_His');
        $linhas = 0;
        $arquivos = [];

        switch ($o['formato']) {
            case 'sql':
                $gz = $o['compressao'] === 'gzip';
                $caminho = self::temporario('.sql' . ($gz ? '.gz' : ''));
                $linhas = self::sql($caminho, $gz, $tabelas, $todas, $o);
                $arquivos[] = [$caminho, $base . '.sql'];
                break;

            case 'json':
                $gz = $o['compressao'] === 'gzip';
                $caminho = self::temporario('.json' . ($gz ? '.gz' : ''));
                $linhas = self::json($caminho, $gz, $tabelas, $todas);
                $arquivos[] = [$caminho, $base . '.json'];
                break;

            case 'csv':
                $gz = $o['compressao'] === 'gzip' && count($tabelas) === 1;
                foreach ($tabelas as $t) {
                    $caminho = self::temporario('.csv' . ($gz ? '.gz' : ''));
                    $linhas += self::csvTabela($caminho, $gz, $t);
                    $arquivos[] = [$caminho, (count($tabelas) === 1 ? $base : $t) . '.csv'];
                }
                // Várias tabelas em CSV sempre vão num zip (um arquivo por tabela)
                if (count($tabelas) > 1) {
                    $o['compressao'] = 'zip';
                }
                break;

            case 'xlsx':
                $caminho = self::temporario('.xlsx');
                $linhas = self::xlsx($caminho, $tabelas, $todas);
                $arquivos[] = [$caminho, $base . '.xlsx'];
                break;
        }

        if ($o['compressao'] === 'zip') {
            $zip = self::temporario('.zip');
            $z = new ZipArchive();
            if ($z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Não foi possível criar o arquivo zip.');
            }
            foreach ($arquivos as [$caminho, $nome]) {
                $z->addFile($caminho, $nome);
            }
            $z->close();
            foreach ($arquivos as [$caminho]) {
                @unlink($caminho);
            }
            $final = [$zip, $base . '.zip', 'application/zip'];
        } else {
            [$caminho, $nome] = $arquivos[0];
            $mimes = ['sql' => 'application/sql', 'json' => 'application/json', 'csv' => 'text/csv', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
            $gzip = str_ends_with($caminho, '.gz');
            $final = [$caminho, $nome . ($gzip ? '.gz' : ''), $gzip ? 'application/gzip' : $mimes[$o['formato']] . ($o['formato'] === 'csv' || $o['formato'] === 'sql' ? '; charset=utf-8' : '')];
        }
        clearstatcache();
        return [
            'caminho'  => $final[0],
            'nome'     => $final[1],
            'mime'     => $final[2],
            'linhas'   => $linhas,
            'tamanho'  => (int) filesize($final[0]),
            'tabelas'  => $tabelas,
            'tempo_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ];
    }

    /** Valor SQL: NULL, número sem aspas, binário em hexadecimal, texto escapado */
    private static function valorSql(mysqli $c, $v, object $campo): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (in_array((int) $campo->type, PluginPesquisadorBanco::TIPOS_NUMERO, true) && is_numeric($v)) {
            return (string) $v;
        }
        if ((int) $campo->charsetnr === 63 && !in_array((int) $campo->type, [MYSQLI_TYPE_DATE, MYSQLI_TYPE_DATETIME, MYSQLI_TYPE_TIMESTAMP, MYSQLI_TYPE_TIME, MYSQLI_TYPE_BIT], true)) {
            return $v === '' ? "''" : '0x' . bin2hex((string) $v);
        }
        if ((int) $campo->type === MYSQLI_TYPE_BIT) {
            return "b'" . decbin((int) hexdec(bin2hex((string) $v))) . "'";
        }
        return "'" . $c->real_escape_string((string) $v) . "'";
    }

    private static function sql(string $caminho, bool $gz, array $tabelas, array $todas, array $o): int
    {
        $c = PluginPesquisadorBanco::conexaoExportacao();
        $h = self::abrir($caminho, $gz);
        $N = fn(string $n) => PluginPesquisadorBanco::nome($n);
        self::escrever($h, $gz, "-- Pesquisador (GLPI) - salvamento de tabelas\n-- Banco: " . PluginPesquisadorBanco::nomeBanco()
            . "\n-- Servidor: " . $c->server_info . "\n-- Gerado em: " . date('Y-m-d H:i:s') . ' por ' . getUserName((int) Session::getLoginUserID())
            . "\n-- Tabelas: " . count($tabelas) . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '" . date('P') . "';\n\n");
        $total = 0;
        $estrutura = $o['conteudo'] !== 'dados';
        $dados = $o['conteudo'] !== 'estrutura';
        // Visões depois das tabelas (dependem delas)
        usort($tabelas, fn($a, $b) => (int) $todas[$a]['visao'] <=> (int) $todas[$b]['visao']);
        foreach ($tabelas as $t) {
            $visao = $todas[$t]['visao'];
            self::escrever($h, $gz, "-- --------------------------------------------------------\n-- " . ($visao ? 'Visão' : 'Tabela') . ' ' . $N($t) . "\n-- --------------------------------------------------------\n\n");
            if ($estrutura) {
                if ($o['drop']) {
                    self::escrever($h, $gz, ($visao ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $N($t) . ";\n");
                }
                self::escrever($h, $gz, PluginPesquisadorBanco::criacao($t, $visao) . ";\n\n");
            }
            if (!$dados || $visao) {
                continue;
            }
            $res = $c->query('SELECT * FROM ' . $N($t), MYSQLI_USE_RESULT);
            if (!$res instanceof mysqli_result) {
                self::escrever($h, $gz, '-- Erro ao ler os dados: ' . str_replace("\n", ' ', $c->error) . "\n\n");
                continue;
            }
            $campos = $res->fetch_fields();
            $cabeca = 'INSERT INTO ' . $N($t) . ' (' . implode(', ', array_map(fn($f) => $N($f->name), $campos)) . ") VALUES\n";
            $bloco = [];
            while ($r = $res->fetch_row()) {
                $valores = [];
                foreach ($r as $i => $v) {
                    $valores[] = self::valorSql($c, $v, $campos[$i]);
                }
                $bloco[] = '(' . implode(', ', $valores) . ')';
                $total++;
                if (count($bloco) >= $o['lote']) {
                    self::escrever($h, $gz, $cabeca . implode(",\n", $bloco) . ";\n");
                    $bloco = [];
                }
            }
            if ($bloco) {
                self::escrever($h, $gz, $cabeca . implode(",\n", $bloco) . ";\n");
            }
            $res->free();
            self::escrever($h, $gz, "\n");
        }
        self::escrever($h, $gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
        self::fechar($h, $gz);
        return $total;
    }

    /** Texto de uma célula para CSV/JSON/XLSX (binários em hexadecimal) */
    private static function valorTexto($v, object $campo)
    {
        if ($v === null) {
            return null;
        }
        if ((int) $campo->charsetnr === 63 && !in_array((int) $campo->type, PluginPesquisadorBanco::TIPOS_NUMERO, true)
            && !in_array((int) $campo->type, [MYSQLI_TYPE_DATE, MYSQLI_TYPE_DATETIME, MYSQLI_TYPE_TIMESTAMP, MYSQLI_TYPE_TIME], true)) {
            return $v === '' ? '' : '0x' . bin2hex((string) $v);
        }
        return (string) $v;
    }

    public static function linhaCsv(array $valores): string
    {
        return implode(';', array_map(fn($v) => $v === null ? '' : '"' . str_replace('"', '""', (string) $v) . '"', $valores)) . "\r\n";
    }

    private static function csvTabela(string $caminho, bool $gz, string $tabela): int
    {
        $c = PluginPesquisadorBanco::conexaoExportacao();
        $h = self::abrir($caminho, $gz);
        $res = $c->query('SELECT * FROM ' . PluginPesquisadorBanco::nome($tabela), MYSQLI_USE_RESULT);
        if (!$res instanceof mysqli_result) {
            self::fechar($h, $gz);
            throw new RuntimeException('Erro ao ler ' . $tabela . ': ' . $c->error);
        }
        $campos = $res->fetch_fields();
        self::escrever($h, $gz, "\xEF\xBB\xBF" . self::linhaCsv(array_map(fn($f) => $f->name, $campos)));
        $n = 0;
        while ($r = $res->fetch_row()) {
            $linha = [];
            foreach ($r as $i => $v) {
                $linha[] = self::valorTexto($v, $campos[$i]);
            }
            self::escrever($h, $gz, self::linhaCsv($linha));
            $n++;
        }
        $res->free();
        self::fechar($h, $gz);
        return $n;
    }

    private static function json(string $caminho, bool $gz, array $tabelas, array $todas): int
    {
        $c = PluginPesquisadorBanco::conexaoExportacao();
        $h = self::abrir($caminho, $gz);
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        self::escrever($h, $gz, "{\n");
        $total = 0;
        foreach ($tabelas as $k => $t) {
            self::escrever($h, $gz, ($k > 0 ? ",\n" : '') . json_encode($t) . ": [");
            $res = $c->query('SELECT * FROM ' . PluginPesquisadorBanco::nome($t), MYSQLI_USE_RESULT);
            if ($res instanceof mysqli_result) {
                $campos = $res->fetch_fields();
                $primeira = true;
                while ($r = $res->fetch_row()) {
                    $obj = [];
                    foreach ($r as $i => $v) {
                        $obj[$campos[$i]->name] = self::valorTexto($v, $campos[$i]);
                    }
                    self::escrever($h, $gz, ($primeira ? "\n  " : ",\n  ") . json_encode($obj, $flags));
                    $primeira = false;
                    $total++;
                }
                $res->free();
            }
            self::escrever($h, $gz, "\n]");
        }
        self::escrever($h, $gz, "\n}\n");
        self::fechar($h, $gz);
        return $total;
    }

    private static function xlsx(string $caminho, array $tabelas, array $todas): int
    {
        $estimado = array_sum(array_map(fn($t) => (int) $todas[$t]['linhas'], $tabelas));
        if ($estimado > self::LIMITE_XLSX) {
            throw new RuntimeException('Para mais de ' . number_format(self::LIMITE_XLSX, 0, ',', '.') . ' linhas use SQL, CSV ou JSON (o Excel exige montar a planilha inteira na memória).');
        }
        @ini_set('memory_limit', '1024M');
        $c = PluginPesquisadorBanco::conexaoExportacao();
        $planilha = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $planilha->removeSheetByIndex(0);
        $usados = [];
        $total = 0;
        foreach ($tabelas as $t) {
            $nome = mb_substr((string) preg_replace('/[\\\\\/\?\*\[\]:]/', '_', $t), 0, 31);
            $base = $nome;
            for ($i = 2; isset($usados[mb_strtolower($nome)]); $i++) {
                $nome = mb_substr($base, 0, 28) . '~' . $i;
            }
            $usados[mb_strtolower($nome)] = true;
            $aba = $planilha->createSheet();
            $aba->setTitle($nome);
            $res = $c->query('SELECT * FROM ' . PluginPesquisadorBanco::nome($t), MYSQLI_USE_RESULT);
            if (!$res instanceof mysqli_result) {
                continue;
            }
            $campos = $res->fetch_fields();
            $dados = [array_map(fn($f) => $f->name, $campos)];
            while ($r = $res->fetch_row()) {
                $linha = [];
                foreach ($r as $i => $v) {
                    $linha[] = self::valorTexto($v, $campos[$i]);
                }
                $dados[] = $linha;
                $total++;
                if ($total > self::LIMITE_XLSX) {
                    $res->free();
                    throw new RuntimeException('Mais de ' . number_format(self::LIMITE_XLSX, 0, ',', '.') . ' linhas: use SQL, CSV ou JSON.');
                }
            }
            $res->free();
            self::preencherAba($aba, $dados);
        }
        if (!$planilha->getSheetCount()) {
            $planilha->createSheet();
        }
        $planilha->setActiveSheetIndex(0);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($planilha))->save($caminho);
        $planilha->disconnectWorksheets();
        return $total;
    }

    /** Linhas numa aba como texto (evita o Excel converter códigos e datas), cabeçalho em negrito */
    public static function preencherAba($aba, array $linhas): void
    {
        foreach ($linhas as $i => $linha) {
            foreach (array_values($linha) as $j => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $celula = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j + 1) . ($i + 1);
                if (is_int($v) || (is_string($v) && preg_match('/^-?[1-9]\d{0,14}$|^0$/', $v))) {
                    $aba->setCellValueExplicit($celula, (int) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                } else {
                    $aba->setCellValueExplicit($celula, mb_substr((string) $v, 0, 32000), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }
        if ($linhas) {
            $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, count($linhas[0])));
            $aba->getStyle('A1:' . $ultima . '1')->getFont()->setBold(true);
            $aba->freezePane('A2');
        }
    }

    // =====================================================================
    // Entrega e arquivos guardados
    // =====================================================================

    public static function registrar(array $r, array $o, string $destino, string $arquivo): int
    {
        global $DB;
        $DB->insert(self::TABELA, [
            'users_id'      => (int) Session::getLoginUserID(),
            'arquivo'       => mb_substr($arquivo, 0, 255),
            'formato'       => $o['formato'],
            'compressao'    => $o['compressao'],
            'destino'       => $destino,
            'tabelas'       => json_encode(array_values($r['tabelas'])),
            'qtd_tabelas'   => count($r['tabelas']),
            'linhas'        => (int) $r['linhas'],
            'tamanho'       => (int) $r['tamanho'],
            'tempo_ms'      => (int) $r['tempo_ms'],
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        return (int) $DB->insertId();
    }

    /** Guarda o arquivo gerado no servidor */
    public static function guardar(array $r, array $o): array
    {
        $pasta = PluginPesquisadorConfig::pasta('exportacoes');
        $nome = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $r['nome'];
        if (!@rename($r['caminho'], $pasta . '/' . $nome)) {
            if (!@copy($r['caminho'], $pasta . '/' . $nome)) {
                @unlink($r['caminho']);
                throw new RuntimeException('Não foi possível gravar o arquivo em ' . $pasta . '.');
            }
            @unlink($r['caminho']);
        }
        $id = self::registrar($r, $o, 'servidor', $nome);
        return ['id' => $id, 'arquivo' => $nome];
    }

    /** Envia um arquivo para o navegador e, se for temporário, apaga depois */
    public static function enviar(string $caminho, string $nome, string $mime, bool $apagar): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nome) . '"');
        header('Content-Length: ' . filesize($caminho));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($caminho);
        if ($apagar) {
            @unlink($caminho);
        }
        exit;
    }

    public static function guardados(): array
    {
        global $DB;
        $pasta = PluginPesquisadorConfig::pasta('exportacoes');
        $lista = [];
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['destino' => 'servidor'], 'ORDER' => 'id DESC', 'LIMIT' => 300]) as $r) {
            $existe = is_file($pasta . '/' . basename((string) $r['arquivo']));
            $tabelas = json_decode((string) $r['tabelas'], true) ?: [];
            $lista[] = [
                'id'       => (int) $r['id'],
                'arquivo'  => (string) $r['arquivo'],
                'formato'  => self::FORMATOS[$r['formato']] ?? $r['formato'],
                'compressao' => $r['compressao'] === 'nenhuma' ? '' : (string) $r['compressao'],
                'tabelas'  => (int) $r['qtd_tabelas'],
                'lista'    => implode(', ', array_slice($tabelas, 0, 8)) . (count($tabelas) > 8 ? '…' : ''),
                'linhas'   => (int) $r['linhas'],
                'tamanho'  => PluginPesquisadorConfig::tamanho((float) $r['tamanho']),
                'usuario'  => (string) getUserName((int) $r['users_id']),
                'data'     => Html::convDateTime((string) $r['date_creation']),
                'existe'   => $existe,
            ];
        }
        return $lista;
    }

    public static function caminhoGuardado(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id, 'destino' => 'servidor'], 'LIMIT' => 1]) as $r) {
            $caminho = PluginPesquisadorConfig::pasta('exportacoes') . '/' . basename((string) $r['arquivo']);
            if (is_file($caminho)) {
                return [$caminho, (string) $r['arquivo']];
            }
        }
        return null;
    }

    public static function excluirGuardado(int $id): bool
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id, 'destino' => 'servidor'], 'LIMIT' => 1]) as $r) {
            $caminho = PluginPesquisadorConfig::pasta('exportacoes') . '/' . basename((string) $r['arquivo']);
            if (is_file($caminho)) {
                @unlink($caminho);
            }
            $DB->update(self::TABELA, ['destino' => 'apagado'], ['id' => $id]);
            return true;
        }
        return false;
    }

    // =====================================================================
    // Tarefa automática de limpeza
    // =====================================================================

    public static function cronInfo($name)
    {
        if ($name === 'PesquisadorLimpar') {
            return ['description' => 'Pesquisador: apaga salvamentos de tabelas e histórico do console antigos'];
        }
        return [];
    }

    public static function cronPesquisadorLimpar(CronTask $task): int
    {
        global $DB;
        $C = PluginPesquisadorConfig::class;
        $n = 0;
        $dias = $C::inteiro('exportacoes_dias', 0, 3650);
        if ($dias > 0) {
            $limite = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'));
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::TABELA, 'WHERE' => ['destino' => 'servidor', 'date_creation' => ['<', $limite]]]) as $r) {
                $n += (int) self::excluirGuardado((int) $r['id']);
            }
        }
        $diasH = $C::inteiro('historico_dias', 0, 3650);
        if ($diasH > 0) {
            // Comandos que alteraram o banco ficam o dobro do tempo
            $DB->delete(PluginPesquisadorConsole::HISTORICO, ['tipo' => 'leitura', 'date_creation' => ['<', date('Y-m-d H:i:s', strtotime('-' . $diasH . ' days'))]]);
            $DB->delete(PluginPesquisadorConsole::HISTORICO, ['tipo' => 'escrita', 'date_creation' => ['<', date('Y-m-d H:i:s', strtotime('-' . ($diasH * 2) . ' days'))]]);
        }
        // Temporários esquecidos (download interrompido)
        foreach (glob(GLPI_TMP_DIR . '/pesquisador_*') ?: [] as $arquivo) {
            if (filemtime($arquivo) < time() - DAY_TIMESTAMP) {
                @unlink($arquivo);
            }
        }
        $task->addVolume($n);
        return 1;
    }
}
