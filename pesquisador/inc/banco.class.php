<?php

/**
 * Plugin Pesquisador - banco de dados: conexão própria (isolada da conexão do GLPI), lista de tabelas,
 * estrutura (colunas, índices, CREATE) e navegação nos dados com paginação, ordenação e filtro.
 */
class PluginPesquisadorBanco
{
    /** Tipos binários do mysqli (o conjunto de caracteres 63 indica conteúdo binário) */
    private const TIPOS_TEXTO = [MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING];
    public const TIPOS_NUMERO = [
        MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL, MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG,
        MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_INT24, MYSQLI_TYPE_YEAR,
    ];

    private static array $conexoes = [];

    public static function nomeBanco(): string
    {
        global $DB;
        return (string) $DB->dbdefault;
    }

    public static function ehMariadb(mysqli $c): bool
    {
        return stripos((string) $c->server_info, 'mariadb') !== false;
    }

    /**
     * Conexão própria com as credenciais do GLPI. $leitura: transações só leitura.
     * O tempo limite por comando vem da configuração (MariaDB: max_statement_time; MySQL: só SELECT).
     */
    public static function conexao(bool $leitura = true): mysqli
    {
        global $DB;
        $chave = $leitura ? 'leitura' : 'escrita';
        if (isset(self::$conexoes[$chave])) {
            return self::$conexoes[$chave];
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $c = mysqli_init();
        $c->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        if (!empty($DB->dbssl)) {
            $c->ssl_set($DB->dbsslkey ?? null, $DB->dbsslcert ?? null, $DB->dbsslca ?? null, $DB->dbsslcapath ?? null, $DB->dbsslcacipher ?? null);
        }
        $host = is_array($DB->dbhost) ? (string) reset($DB->dbhost) : (string) $DB->dbhost;
        $partes = explode(':', $host);
        $senha = rawurldecode((string) $DB->dbpassword);
        if (count($partes) < 2) {
            $ok = @$c->real_connect($host, $DB->dbuser, $senha, $DB->dbdefault);
        } elseif ((int) $partes[1] > 0) {
            $ok = @$c->real_connect($partes[0], $DB->dbuser, $senha, $DB->dbdefault, (int) $partes[1]);
        } else {
            $ok = @$c->real_connect($partes[0], $DB->dbuser, $senha, $DB->dbdefault, (int) ini_get('mysqli.default_port'), $partes[1]);
        }
        if (!$ok) {
            throw new RuntimeException('Não foi possível conectar ao banco: ' . $c->connect_error);
        }
        $c->set_charset('utf8mb4');
        @$c->query("SET SESSION time_zone = '" . date('P') . "'");
        @$c->query("SET SESSION sql_mode = (SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");
        $segundos = PluginPesquisadorConfig::inteiro('sql_tempo_limite', 5, 3600);
        if (self::ehMariadb($c)) {
            @$c->query('SET SESSION max_statement_time = ' . $segundos);
        } else {
            @$c->query('SET SESSION MAX_EXECUTION_TIME = ' . ($segundos * 1000));
        }
        if ($leitura) {
            @$c->query('SET SESSION TRANSACTION READ ONLY');
        }
        return self::$conexoes[$chave] = $c;
    }

    /** Conexão sem tempo limite (salvar tabelas inteiras pode demorar) */
    public static function conexaoExportacao(): mysqli
    {
        $c = self::conexao(true);
        if (self::ehMariadb($c)) {
            @$c->query('SET SESSION max_statement_time = 0');
        } else {
            @$c->query('SET SESSION MAX_EXECUTION_TIME = 0');
        }
        return $c;
    }

    public static function nome(string $identificador): string
    {
        return '`' . str_replace('`', '``', $identificador) . '`';
    }

    // =====================================================================
    // Tabelas
    // =====================================================================

    /** Tabelas e visões do banco do GLPI: nome => dados */
    public static function tabelas(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['TABLE_NAME', 'TABLE_TYPE', 'ENGINE', 'TABLE_ROWS', 'DATA_LENGTH', 'INDEX_LENGTH', 'DATA_FREE', 'AUTO_INCREMENT', 'TABLE_COLLATION', 'CREATE_TIME', 'UPDATE_TIME', 'TABLE_COMMENT'],
            'FROM'   => 'information_schema.TABLES',
            'WHERE'  => ['TABLE_SCHEMA' => self::nomeBanco()],
            'ORDER'  => 'TABLE_NAME',
        ]) as $r) {
            $nome = (string) $r['TABLE_NAME'];
            $lista[$nome] = [
                'nome'       => $nome,
                'visao'      => $r['TABLE_TYPE'] === 'VIEW',
                'engine'     => (string) $r['ENGINE'],
                'linhas'     => (int) $r['TABLE_ROWS'],
                'dados'      => (int) $r['DATA_LENGTH'],
                'indices'    => (int) $r['INDEX_LENGTH'],
                'tamanho'    => (int) $r['DATA_LENGTH'] + (int) $r['INDEX_LENGTH'],
                'livre'      => (int) $r['DATA_FREE'],
                'auto'       => $r['AUTO_INCREMENT'] === null ? null : (int) $r['AUTO_INCREMENT'],
                'collation'  => (string) $r['TABLE_COLLATION'],
                'criada'     => (string) $r['CREATE_TIME'],
                'atualizada' => (string) $r['UPDATE_TIME'],
                'comentario' => (string) $r['TABLE_COMMENT'],
            ];
        }
        return $lista;
    }

    /** Tabela existente (evita injeção em nomes) */
    public static function existe(string $tabela): bool
    {
        global $DB;
        return $tabela !== '' && countElementsInTable('information_schema.TABLES', ['TABLE_SCHEMA' => self::nomeBanco(), 'TABLE_NAME' => $tabela]) > 0;
    }

    /** Filtra uma lista de nomes, mantendo só tabelas existentes (na ordem do banco) */
    public static function validas(array $nomes): array
    {
        $todas = array_keys(self::tabelas());
        return array_values(array_intersect($todas, array_map('strval', $nomes)));
    }

    public static function colunas(string $tabela): array
    {
        $c = self::conexao(true);
        $lista = [];
        $res = $c->query('SHOW FULL COLUMNS FROM ' . self::nome($tabela));
        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) {
                $lista[] = [
                    'nome'       => (string) $r['Field'],
                    'tipo'       => (string) $r['Type'],
                    'nulo'       => $r['Null'] === 'YES',
                    'chave'      => (string) $r['Key'],
                    'padrao'     => $r['Default'],
                    'extra'      => (string) $r['Extra'],
                    'collation'  => (string) ($r['Collation'] ?? ''),
                    'comentario' => (string) ($r['Comment'] ?? ''),
                ];
            }
            $res->free();
        }
        return $lista;
    }

    public static function indices(string $tabela): array
    {
        $c = self::conexao(true);
        $lista = [];
        $res = $c->query('SHOW INDEX FROM ' . self::nome($tabela));
        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) {
                $k = (string) $r['Key_name'];
                $lista[$k] ??= ['nome' => $k, 'unico' => (int) $r['Non_unique'] === 0, 'tipo' => (string) $r['Index_type'], 'colunas' => [], 'cardinalidade' => (int) $r['Cardinality']];
                $lista[$k]['colunas'][] = (string) $r['Column_name'] . ($r['Sub_part'] ? '(' . (int) $r['Sub_part'] . ')' : '');
            }
            $res->free();
        }
        return array_values($lista);
    }

    public static function criacao(string $tabela, bool $visao = false): string
    {
        $c = self::conexao(true);
        $res = $c->query(($visao ? 'SHOW CREATE VIEW ' : 'SHOW CREATE TABLE ') . self::nome($tabela));
        if (!$res instanceof mysqli_result) {
            return '';
        }
        $r = $res->fetch_row();
        $res->free();
        return (string) ($r[1] ?? '');
    }

    // =====================================================================
    // Dados
    // =====================================================================

    /** Valor de uma célula pronto para JSON (binários e textos longos resumidos) */
    public static function valor($v, ?object $campo, int $max = 300)
    {
        if ($v === null) {
            return null;
        }
        if ($campo && (int) $campo->charsetnr === 63 && in_array((int) $campo->type, self::TIPOS_TEXTO, true)) {
            $n = strlen((string) $v);
            return $n <= 32 ? '0x' . strtoupper(bin2hex((string) $v)) : '[binário ' . PluginPesquisadorConfig::tamanho($n) . ']';
        }
        $v = (string) $v;
        if ($max > 0 && mb_strlen($v) > $max) {
            return mb_substr($v, 0, $max) . '…';
        }
        return $v;
    }

    /**
     * Página de dados de uma tabela. $filtros: coluna => texto (LIKE %texto%, ou "NULL"/"=valor").
     */
    public static function dados(string $tabela, int $pagina, int $porPagina, string $ordem, string $direcao, array $filtros): array
    {
        $c = self::conexao(true);
        $colunas = array_column(self::colunas($tabela), 'nome');
        $where = [];
        foreach ($filtros as $col => $txt) {
            $txt = trim((string) $txt);
            if ($txt === '' || !in_array($col, $colunas, true)) {
                continue;
            }
            if (strtoupper($txt) === 'NULL') {
                $where[] = self::nome($col) . ' IS NULL';
            } elseif (str_starts_with($txt, '=')) {
                $where[] = self::nome($col) . " = '" . $c->real_escape_string(substr($txt, 1)) . "'";
            } else {
                $where[] = 'CAST(' . self::nome($col) . " AS CHAR) LIKE '%" . $c->real_escape_string(addcslashes($txt, '%_\\')) . "%'";
            }
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = 0;
        $res = $c->query('SELECT COUNT(*) FROM ' . self::nome($tabela) . $sqlWhere);
        if ($res instanceof mysqli_result) {
            $total = (int) $res->fetch_row()[0];
            $res->free();
        } else {
            throw new RuntimeException($c->error);
        }
        $porPagina = max(10, min(500, $porPagina));
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $sqlOrdem = in_array($ordem, $colunas, true) ? ' ORDER BY ' . self::nome($ordem) . ($direcao === 'desc' ? ' DESC' : ' ASC') : '';
        $sql = 'SELECT * FROM ' . self::nome($tabela) . $sqlWhere . $sqlOrdem . ' LIMIT ' . (($pagina - 1) * $porPagina) . ', ' . $porPagina;
        $res = $c->query($sql);
        if (!$res instanceof mysqli_result) {
            throw new RuntimeException($c->error);
        }
        $campos = $res->fetch_fields();
        $linhas = [];
        while ($r = $res->fetch_row()) {
            $linha = [];
            foreach ($r as $i => $v) {
                $linha[] = self::valor($v, $campos[$i] ?? null);
            }
            $linhas[] = $linha;
        }
        $res->free();
        return [
            'colunas' => array_map(fn($f) => $f->name, $campos),
            'linhas'  => $linhas,
            'total'   => $total,
            'pagina'  => $pagina,
            'paginas' => $paginas,
            'sql'     => $sql,
        ];
    }
}
