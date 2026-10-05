<?php

/**
 * Plugin Pesquisador - console SQL (substitui o antigo plugin "sql").
 * Separa os comandos, classifica cada um (leitura ou alteração), executa numa conexão própria
 * (só leitura por padrão, com tempo limite), registra o histórico e guarda consultas salvas.
 * Comandos de alteração só rodam para administradores, com a opção ligada na configuração e
 * confirmação na tela; todos ficam registrados.
 */
class PluginPesquisadorConsole
{
    public const HISTORICO = 'glpi_plugin_pesquisador_historico';
    public const CONSULTAS = 'glpi_plugin_pesquisador_consultas';
    public const MAX_COMANDOS = 25;
    public const MAX_TAMANHO = 1048576;

    private const LEITURA = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH', 'ANALYZE', 'CHECKSUM'];

    // =====================================================================
    // Análise
    // =====================================================================

    /** Comandos separados por ";" fora de textos, identificadores e comentários */
    public static function dividir(string $sql): array
    {
        $comandos = [];
        $atual = '';
        $n = strlen($sql);
        $aspa = '';
        for ($i = 0; $i < $n; $i++) {
            $ch = $sql[$i];
            $prox = $i + 1 < $n ? $sql[$i + 1] : '';
            if ($aspa !== '') {
                $atual .= $ch;
                if ($ch === '\\' && $aspa !== '`') {
                    $atual .= $prox;
                    $i++;
                } elseif ($ch === $aspa) {
                    if ($prox === $aspa) {
                        $atual .= $prox;
                        $i++;
                    } else {
                        $aspa = '';
                    }
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $aspa = $ch;
                $atual .= $ch;
            } elseif (($ch === '-' && $prox === '-' && ($i + 2 >= $n || ctype_space($sql[$i + 2]))) || $ch === '#') {
                $fim = strpos($sql, "\n", $i);
                $fim = $fim === false ? $n : $fim;
                $atual .= ' ';
                $i = $fim - 1;
            } elseif ($ch === '/' && $prox === '*') {
                $fim = strpos($sql, '*/', $i + 2);
                $fim = $fim === false ? $n : $fim + 2;
                $atual .= ' ';
                $i = $fim - 1;
            } elseif ($ch === ';') {
                if (trim($atual) !== '') {
                    $comandos[] = trim($atual);
                }
                $atual = '';
            } else {
                $atual .= $ch;
            }
        }
        if (trim($atual) !== '') {
            $comandos[] = trim($atual);
        }
        return $comandos;
    }

    /** Texto sem o conteúdo de strings (para procurar palavras-chave com segurança) */
    private static function semTextos(string $sql): string
    {
        return (string) preg_replace(["/'(?:[^'\\\\]|\\\\.|'')*'/s", '/"(?:[^"\\\\]|\\\\.|"")*"/s', '/`(?:[^`]|``)*`/'], ["''", '""', '``'], $sql);
    }

    /** 'leitura' ou 'escrita' (tudo o que não é consulta pura é alteração) */
    public static function classificar(string $comando): string
    {
        $limpo = ltrim(self::semTextos($comando), " \t\r\n(");
        $palavra = strtoupper((string) preg_replace('/^([A-Za-z]+).*$/s', '$1', $limpo));
        if (!in_array($palavra, self::LEITURA, true)) {
            return 'escrita';
        }
        // Gravação em arquivo no servidor nunca é leitura
        if (preg_match('/\b(INTO\s+(OUT|DUMP)FILE|LOAD_FILE)\b/i', $limpo)) {
            return 'escrita';
        }
        // ANALYZE só é leitura como ANALYZE SELECT (ANALYZE TABLE/UPDATE altera)
        if ($palavra === 'ANALYZE') {
            return preg_match('/^ANALYZE\s+(FORMAT\s*=\s*\w+\s+)?SELECT\b/i', $limpo) ? 'leitura' : 'escrita';
        }
        // SELECT/WITH com comando de alteração embutido (WITH ... DELETE etc.); REPLACE(...) e INSERT(...) são funções de texto
        if ($palavra === 'SELECT' || $palavra === 'WITH') {
            $semTravas = (string) preg_replace('/\b(FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b/i', ' ', $limpo);
            if (preg_match('/\b(UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|RENAME)\b/i', $semTravas)
                || preg_match('/\b(INSERT|REPLACE)\b(?!\s*\()/i', $semTravas)) {
                return 'escrita';
            }
        }
        return 'leitura';
    }

    // =====================================================================
    // Execução
    // =====================================================================

    /**
     * Executa o texto do editor. Retorna:
     *   ['sucesso', 'mensagem', 'resultados' => [...], 'confirmar' => bool, 'escritas' => [...]]
     * $confirmado precisa ser true para comandos de alteração.
     */
    public static function executar(string $sql, bool $confirmado): array
    {
        $C = PluginPesquisadorConfig::class;
        if (strlen($sql) > self::MAX_TAMANHO) {
            return ['sucesso' => false, 'mensagem' => 'O texto passa de 1 MB.', 'resultados' => []];
        }
        $comandos = self::dividir($sql);
        if (!$comandos) {
            return ['sucesso' => false, 'mensagem' => 'Digite um comando SQL.', 'resultados' => []];
        }
        if (count($comandos) > self::MAX_COMANDOS) {
            return ['sucesso' => false, 'mensagem' => 'Execute no máximo ' . self::MAX_COMANDOS . ' comandos por vez.', 'resultados' => []];
        }
        $tipos = array_map([self::class, 'classificar'], $comandos);
        $escritas = array_values(array_filter($comandos, fn($c, $i) => $tipos[$i] === 'escrita', ARRAY_FILTER_USE_BOTH));
        if ($escritas) {
            if (!$C::ehAdmin()) {
                return ['sucesso' => false, 'mensagem' => 'Seu acesso ao console é só de leitura: use SELECT, SHOW, DESCRIBE ou EXPLAIN.', 'resultados' => []];
            }
            if (!$C::podeEscrever()) {
                return ['sucesso' => false, 'mensagem' => 'Comandos que alteram o banco estão desligados. Um administrador pode liberá-los em Configuração > SQL e banco.', 'resultados' => []];
            }
            if (!$confirmado) {
                return ['sucesso' => false, 'confirmar' => true, 'escritas' => array_map(fn($c) => mb_strimwidth($c, 0, 300, '…'), $escritas), 'mensagem' => 'Confirme os comandos que alteram o banco.', 'resultados' => []];
            }
        }

        $limite = $C::inteiro('sql_limite_linhas', 50, 10000);
        $c = PluginPesquisadorBanco::conexao(!$escritas);
        $resultados = [];
        $erro = '';
        $totalLinhas = 0;
        $inicioGeral = microtime(true);
        foreach ($comandos as $i => $comando) {
            $inicio = microtime(true);
            $res = $c->query($comando);
            $ms = (int) round((microtime(true) - $inicio) * 1000);
            $item = ['consulta' => mb_strimwidth($comando, 0, 500, '…'), 'tipo' => $tipos[$i], 'tempo_ms' => $ms];
            if ($res === false) {
                $erro = $c->error !== '' ? '#' . $c->errno . ' - ' . $c->error : 'Falha ao executar.';
                $item['erro'] = $erro;
                $resultados[] = $item;
                break;
            }
            if ($res instanceof mysqli_result) {
                $campos = $res->fetch_fields();
                $linhas = [];
                $n = 0;
                while ($r = $res->fetch_row()) {
                    if ($n < $limite) {
                        $linha = [];
                        foreach ($r as $k => $v) {
                            $linha[] = PluginPesquisadorBanco::valor($v, $campos[$k] ?? null, 2000);
                        }
                        $linhas[] = $linha;
                    }
                    $n++;
                }
                $res->free();
                $item += [
                    'colunas' => array_map(fn($f) => ['nome' => $f->name, 'numero' => in_array((int) $f->type, PluginPesquisadorBanco::TIPOS_NUMERO, true)], $campos),
                    'linhas'  => $linhas,
                    'total'   => $n,
                    'cortado' => $n > $limite,
                    'limite'  => $limite,
                ];
                $totalLinhas += $n;
            } else {
                $item['afetadas'] = (int) $c->affected_rows;
                $item['insert_id'] = (int) $c->insert_id;
                $avisos = (int) $c->warning_count;
                $item['avisos'] = $avisos;
                $totalLinhas += max(0, (int) $c->affected_rows);
            }
            $resultados[] = $item;
        }
        $ms = (int) round((microtime(true) - $inicioGeral) * 1000);
        self::registrar($sql, $escritas ? 'escrita' : 'leitura', $erro === '', $totalLinhas, $ms, $erro);
        return [
            'sucesso'    => $erro === '',
            'mensagem'   => $erro === '' ? count($comandos) . ' comando(s) executado(s) em ' . number_format($ms / 1000, 3, ',', '.') . ' s.' : 'Erro: ' . $erro,
            'resultados' => $resultados,
            'tempo_ms'   => $ms,
        ];
    }

    private static function registrar(string $sql, string $tipo, bool $ok, int $linhas, int $ms, string $erro): void
    {
        global $DB;
        $DB->insert(self::HISTORICO, [
            'users_id'      => (int) Session::getLoginUserID(),
            'consulta'      => mb_substr($sql, 0, 65000),
            'tipo'          => $tipo,
            'sucesso'       => (int) $ok,
            'linhas'        => $linhas,
            'tempo_ms'      => $ms,
            'erro'          => mb_substr($erro, 0, 2000),
            'ip'            => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        if ($tipo === 'escrita') {
            Toolbox::logInFile('pesquisador-sql', sprintf("%s (%d) %s %s\n%s\n", getUserName((int) Session::getLoginUserID()), (int) Session::getLoginUserID(), $ok ? 'OK' : 'ERRO', $erro, mb_substr($sql, 0, 5000)));
        }
    }

    /**
     * Um único comando de leitura, percorrido linha a linha (exportação do resultado do console).
     * $porLinha recebe (array $linha, array $campos). Retorna as colunas.
     */
    public static function percorrer(string $sql, callable $porLinha): array
    {
        $comandos = self::dividir($sql);
        if (count($comandos) !== 1 || self::classificar($comandos[0]) !== 'leitura') {
            throw new RuntimeException('Só é possível exportar um único comando de consulta (SELECT, SHOW...).');
        }
        $c = PluginPesquisadorBanco::conexaoExportacao();
        $res = $c->query($comandos[0], MYSQLI_USE_RESULT);
        if (!$res instanceof mysqli_result) {
            throw new RuntimeException($c->error !== '' ? $c->error : 'O comando não devolveu linhas.');
        }
        $campos = $res->fetch_fields();
        while ($r = $res->fetch_row()) {
            $porLinha($r, $campos);
        }
        $res->free();
        self::registrar($sql, 'leitura', true, 0, 0, '');
        return array_map(fn($f) => $f->name, $campos);
    }

    // =====================================================================
    // Histórico e consultas salvas
    // =====================================================================

    public static function historico(int $uid, int $limite = 50, bool $todos = false): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['FROM' => self::HISTORICO, 'WHERE' => $todos ? [] : ['users_id' => $uid], 'ORDER' => 'id DESC', 'LIMIT' => $limite]) as $r) {
            $lista[] = [
                'id'      => (int) $r['id'],
                'usuario' => (string) getUserName((int) $r['users_id']),
                'sql'     => (string) $r['consulta'],
                'tipo'    => (string) $r['tipo'],
                'sucesso' => (bool) $r['sucesso'],
                'linhas'  => (int) $r['linhas'],
                'tempo'   => (int) $r['tempo_ms'],
                'erro'    => (string) $r['erro'],
                'data'    => Html::convDateTime((string) $r['date_creation']),
            ];
        }
        return $lista;
    }

    public static function consultas(int $uid): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['FROM' => self::CONSULTAS, 'WHERE' => ['OR' => ['users_id' => $uid, 'is_compartilhada' => 1]], 'ORDER' => 'nome']) as $r) {
            $lista[] = [
                'id'           => (int) $r['id'],
                'nome'         => (string) $r['nome'],
                'sql'          => (string) $r['consulta'],
                'compartilhada' => (bool) $r['is_compartilhada'],
                'minha'        => (int) $r['users_id'] === $uid,
                'autor'        => (string) getUserName((int) $r['users_id']),
                'data'         => Html::convDateTime((string) $r['date_mod']),
            ];
        }
        return $lista;
    }

    /** Cria ou atualiza (só a própria, ou qualquer uma para administradores). Retorna o id ou 0 */
    public static function salvar(int $uid, int $id, string $nome, string $sql, bool $compartilhada): int
    {
        global $DB;
        $nome = mb_substr(trim(strip_tags($nome)), 0, 150);
        if ($nome === '' || trim($sql) === '') {
            return 0;
        }
        $dados = ['nome' => $nome, 'consulta' => mb_substr($sql, 0, self::MAX_TAMANHO), 'is_compartilhada' => (int) $compartilhada];
        if ($id > 0) {
            $onde = PluginPesquisadorConfig::ehAdmin() ? ['id' => $id] : ['id' => $id, 'users_id' => $uid];
            if (countElementsInTable(self::CONSULTAS, $onde) === 0) {
                return 0;
            }
            $DB->update(self::CONSULTAS, $dados, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::CONSULTAS, $dados + ['users_id' => $uid, 'date_creation' => date('Y-m-d H:i:s')]);
        return (int) $DB->insertId();
    }

    public static function excluir(int $uid, int $id): bool
    {
        global $DB;
        $onde = PluginPesquisadorConfig::ehAdmin() ? ['id' => $id] : ['id' => $id, 'users_id' => $uid];
        if (countElementsInTable(self::CONSULTAS, $onde) === 0) {
            return false;
        }
        return (bool) $DB->delete(self::CONSULTAS, $onde);
    }
}
