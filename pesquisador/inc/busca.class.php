<?php

/**
 * Plugin Pesquisador - motor de busca.
 *
 * Sintaxe: termos soltos (todos precisam aparecer no item, em qualquer origem), "frase exata",
 * -termo ou -"frase" para excluir, #123 (ou só o número) para achar pelo ID.
 * Termos de 3+ letras usam o índice FULLTEXT (início de palavra); termos curtos, com pontuação,
 * frases e a busca parcial usam LIKE (qualquer trecho). A comparação ignora acentos e maiúsculas
 * (collation utf8mb4_unicode_ci). A visibilidade segue as entidades ativas, a lixeira, os direitos
 * de ver todos/próprios e o direito de ver privados.
 */
class PluginPesquisadorBusca
{
    /** Palavras ignoradas pelo FULLTEXT do InnoDB (com 3+ letras): vão por LIKE */
    private const STOPWORDS = ['about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];
    private const MAX_TERMOS = 10;

    private array $positivos = [];
    private array $negativos = [];
    private array $fontes;
    private array $tipos;
    private string $situacao;
    private string $de;
    private string $ate;
    private string $ordem;
    private bool $parcial;

    /**
     * $opcoes: tipos[], fontes[], situacao (todos|abertos|encerrados), de, ate (Y-m-d),
     * ordem (relevancia|recentes|antigos), parcial (bool)
     */
    public function __construct(string $consulta, array $opcoes = [])
    {
        $C = PluginPesquisadorConfig::class;
        $permitidos = $C::tiposPermitidos();
        $tipos = array_values(array_intersect((array) ($opcoes['tipos'] ?? array_keys($permitidos)), array_keys($permitidos)));
        $this->tipos = array_intersect_key($permitidos, array_flip($tipos));
        $fontes = array_values(array_intersect((array) ($opcoes['fontes'] ?? array_keys($C::FONTES)), array_keys($C::FONTES)));
        $this->fontes = $fontes ?: array_keys($C::FONTES);
        $this->situacao = in_array($opcoes['situacao'] ?? '', ['abertos', 'encerrados'], true) ? $opcoes['situacao'] : 'todos';
        $this->de = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($opcoes['de'] ?? '')) ? $opcoes['de'] : '';
        $this->ate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($opcoes['ate'] ?? '')) ? $opcoes['ate'] : '';
        $this->ordem = in_array($opcoes['ordem'] ?? '', ['recentes', 'antigos'], true) ? $opcoes['ordem'] : 'relevancia';
        $this->parcial = !empty($opcoes['parcial']);
        $this->interpretar($consulta);
    }

    // =====================================================================
    // Interpretação dos termos
    // =====================================================================

    private function interpretar(string $consulta): void
    {
        $consulta = mb_substr(trim(str_replace(['“', '”', '„'], '"', $consulta)), 0, 500);
        preg_match_all('/(-?)"([^"]*)"|(\S+)/u', $consulta, $m, PREG_SET_ORDER);
        $vistos = [];
        foreach ($m as $p) {
            $negativo = false;
            $frase = false;
            if (isset($p[3]) && $p[3] !== '') {
                $texto = $p[3];
                if (str_starts_with($texto, '-') && mb_strlen($texto) > 1) {
                    $negativo = true;
                    $texto = mb_substr($texto, 1);
                }
                // Pontuação nas pontas não faz parte do termo ("impressora," / "(rede)")
                $texto = preg_replace('/^[\p{P}\p{S}]+(?=[\p{L}\p{N}#])|(?<=[\p{L}\p{N}])[\p{P}\p{S}]+$/u', '', $texto);
            } else {
                $negativo = $p[1] === '-';
                $texto = trim((string) preg_replace('/\s+/u', ' ', $p[2]));
                $frase = str_contains($texto, ' ');
            }
            if ($texto === '' || $texto === '"') {
                continue;
            }
            $id = preg_match('/^#?(\d{1,10})$/', $texto, $n) ? (int) $n[1] : 0;
            if ($id > 0) {
                $texto = $n[1];
            } elseif (mb_strlen($texto) < 2) {
                continue;
            }
            $chave = ($negativo ? '-' : '+') . mb_strtolower($texto);
            if (isset($vistos[$chave])) {
                continue;
            }
            $vistos[$chave] = true;
            $termo = [
                'texto' => $texto,
                'id'    => $negativo ? 0 : $id,
                'ft'    => !$this->parcial && !$frase && preg_match('/^[\p{L}\p{N}_]{3,84}$/u', $texto) && !in_array(mb_strtolower($texto), self::STOPWORDS, true),
            ];
            if ($negativo) {
                $this->negativos[] = $termo;
            } elseif (count($this->positivos) < self::MAX_TERMOS) {
                $this->positivos[] = $termo;
            }
        }
    }

    public function termos(): array
    {
        return ['positivos' => array_column($this->positivos, 'texto'), 'negativos' => array_column($this->negativos, 'texto')];
    }

    // =====================================================================
    // SQL
    // =====================================================================

    private static function lista(array $valores): string
    {
        global $DB;
        return implode(',', array_map(fn($v) => is_int($v) ? (string) $v : $DB->quote((string) $v), $valores));
    }

    /** Condição de um termo sobre a coluna de texto do alias informado */
    private static function condicao(array $termo, string $alias): string
    {
        global $DB;
        if ($termo['ft']) {
            return 'MATCH(' . $alias . '.`texto`) AGAINST(' . $DB->quote('+' . $termo['texto'] . '*') . ' IN BOOLEAN MODE)';
        }
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $termo['texto']);
        return $alias . '.`texto` LIKE ' . $DB->quote('%' . $like . '%');
    }

    /** Linhas de texto que valem: origens escolhidas e privados só para quem pode ver */
    private function filtroLinha(string $alias): string
    {
        $textos = array_values(array_diff($this->fontes, ['categoria']));
        if (!$textos) {
            return '0';
        }
        $privados = array_values(array_intersect(PluginPesquisadorConfig::privadosPermitidos(), $textos));
        return '(' . $alias . '.`fonte` IN (' . self::lista($textos) . ') AND (' . $alias . '.`is_private` = 0'
            . ($privados ? ' OR ' . $alias . '.`fonte` IN (' . self::lista($privados) . ')' : '') . '))';
    }

    /** Categorias cujo nome completo contém o termo */
    private function categorias(array $termo): array
    {
        global $DB;
        static $cache = [];
        if (!in_array('categoria', $this->fontes, true)) {
            return [];
        }
        $chave = mb_strtolower($termo['texto']);
        if (!isset($cache[$chave])) {
            $cache[$chave] = [];
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $termo['texto']);
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_itilcategories', 'WHERE' => ['completename' => ['LIKE', '%' . $like . '%']], 'LIMIT' => 2000]) as $r) {
                $cache[$chave][] = (int) $r['id'];
            }
        }
        return $cache[$chave];
    }

    /** Restrições do item (entidades, lixeira, visibilidade, situação e período) sobre o alias t */
    private function restricoes(string $tipo): array
    {
        $C = PluginPesquisadorConfig::class;
        $def = $C::TIPOS[$tipo];
        $where = ['t.is_deleted' => 0, 't.entities_id' => array_map('intval', $_SESSION['glpiactiveentities'] ?? [0])];
        if ($this->tipos[$tipo] === 'ator') {
            $uid = (int) Session::getLoginUserID();
            $grupos = array_map('intval', $_SESSION['glpigroups'] ?? []);
            $sql = '(t.`users_id_recipient` = ' . $uid
                . ' OR EXISTS (SELECT 1 FROM `' . $def['usuarios'] . '` AS au WHERE au.`' . $def['fk'] . '` = t.`id` AND au.`users_id` = ' . $uid . ')';
            if ($grupos) {
                $sql .= ' OR EXISTS (SELECT 1 FROM `' . $def['grupos'] . '` AS ag WHERE ag.`' . $def['fk'] . '` = t.`id` AND ag.`groups_id` IN (' . implode(',', $grupos) . '))';
            }
            $where[] = new \Glpi\DBAL\QueryExpression($sql . ')');
        }
        if ($this->situacao !== 'todos') {
            $encerrados = array_values(array_unique(array_map('intval', array_merge($tipo::getSolvedStatusArray(), $tipo::getClosedStatusArray()))));
            $where[] = $this->situacao === 'encerrados' ? ['t.status' => $encerrados] : ['NOT' => ['t.status' => $encerrados]];
        }
        if ($this->de !== '') {
            $where[] = ['t.date' => ['>=', $this->de . ' 00:00:00']];
        }
        if ($this->ate !== '') {
            $where[] = ['t.date' => ['<=', $this->ate . ' 23:59:59']];
        }
        return $where;
    }

    /** Itens de um tipo que atendem a todos os termos: [id => [score, data, fontes[]]] (até $limite) */
    private function buscarTipo(string $tipo, int $limite): array
    {
        global $DB;
        $C = PluginPesquisadorConfig::class;
        $def = $C::TIPOS[$tipo];
        $Q = fn(string $sql) => new \Glpi\DBAL\QueryExpression($sql);
        $filtro = $this->filtroLinha('i');

        $pesos = 'CASE i.`fonte`';
        foreach ($C::FONTES as $f => [, , $peso]) {
            $pesos .= ' WHEN ' . $DB->quote($f) . ' THEN ' . (float) $peso;
        }
        $pesos .= ' ELSE 1 END';

        $linhaTermo = [];
        $itemTermo = [];
        $bonus = [];
        $todasCats = [];
        $todosIds = [];
        foreach ($this->positivos as $k => $termo) {
            $linhaTermo[$k] = '(' . $filtro . ' AND (' . self::condicao($termo, 'i') . ') > 0)';
            $partes = ['MAX(' . $linhaTermo[$k] . ') > 0'];
            $cats = $this->categorias($termo);
            if ($cats) {
                $partes[] = 'MAX(t.`itilcategories_id`) IN (' . implode(',', $cats) . ')';
                $bonus[] = '(MAX(t.`itilcategories_id`) IN (' . implode(',', $cats) . ')) * ' . (float) $C::FONTES['categoria'][2];
                $todasCats = array_merge($todasCats, $cats);
            }
            if ($termo['id'] > 0) {
                $partes[] = 'MAX(t.`id`) = ' . $termo['id'];
                $bonus[] = '(MAX(t.`id`) = ' . $termo['id'] . ') * 100';
                $todosIds[] = $termo['id'];
            }
            $itemTermo[$k] = '(' . implode(' OR ', $partes) . ')';
        }
        $qualquer = '(' . implode(' OR ', $linhaTermo) . ')';

        $where = ['i.itemtype' => $tipo] + $this->restricoes($tipo);
        $alcance = [$qualquer];
        if ($todasCats) {
            $alcance[] = 't.`itilcategories_id` IN (' . implode(',', array_unique($todasCats)) . ')';
        }
        if ($todosIds) {
            $alcance[] = 't.`id` IN (' . implode(',', array_unique($todosIds)) . ')';
        }
        $where[] = $Q('(' . implode(' OR ', $alcance) . ')');

        // Exclusões: nenhum texto visível do item nem a categoria podem conter o termo
        $filtroX = $this->filtroLinha('x');
        foreach ($this->negativos as $termo) {
            $where[] = $Q('NOT EXISTS (SELECT 1 FROM `' . PluginPesquisadorIndexador::TABELA . '` AS x WHERE x.`itemtype` = ' . $DB->quote($tipo)
                . ' AND x.`items_id` = t.`id` AND ' . $filtroX . ' AND (' . self::condicao($termo, 'x') . ') > 0)');
            if ($cats = $this->categorias($termo)) {
                $where[] = ['NOT' => ['t.itilcategories_id' => $cats]];
            }
        }

        $score = 'SUM(' . $pesos . ' * (' . implode(' + ', $linhaTermo) . '))' . ($bonus ? ' + ' . implode(' + ', $bonus) : '');
        $ordem = match ($this->ordem) {
            'recentes' => ['data DESC'],
            'antigos'  => ['data ASC'],
            default    => ['score DESC', 'data DESC'],
        };
        $itens = [];
        foreach ($DB->request([
            'SELECT'     => [
                'i.items_id AS id',
                $Q($score . ' AS score'),
                $Q('MAX(t.`date`) AS data'),
                $Q('MAX(t.`itilcategories_id`) AS cat'),
                $Q('GROUP_CONCAT(DISTINCT CASE WHEN ' . $qualquer . ' THEN i.`fonte` END) AS fontes'),
            ],
            'FROM'       => PluginPesquisadorIndexador::TABELA . ' AS i',
            'INNER JOIN' => [$def['tabela'] . ' AS t' => ['ON' => ['i' => 'items_id', 't' => 'id']]],
            'WHERE'      => $where,
            'GROUPBY'    => ['i.items_id'],
            'HAVING'     => [$Q(implode(' AND ', $itemTermo))],
            'ORDER'      => $ordem,
            'LIMIT'      => $limite,
        ]) as $r) {
            $fontes = array_filter(explode(',', (string) $r['fontes']));
            if ($todasCats && in_array((int) $r['cat'], $todasCats, true)) {
                $fontes[] = 'categoria';
            }
            $itens[(int) $r['id']] = ['score' => (float) $r['score'], 'data' => (string) $r['data'], 'fontes' => array_values(array_unique($fontes))];
        }
        return $itens;
    }

    // =====================================================================
    // Execução
    // =====================================================================

    /** Resultado paginado pronto para a tela */
    public function executar(int $pagina = 1, int $porPagina = 25): array
    {
        $inicio = microtime(true);
        $C = PluginPesquisadorConfig::class;
        $base = ['itens' => [], 'total' => 0, 'limite_atingido' => false, 'pagina' => 1, 'paginas' => 0, 'termos' => $this->termos(), 'erro' => null];
        if (!$this->tipos) {
            return ['erro' => 'Escolha ao menos um tipo de item que você pode ver.'] + $base;
        }
        if (!$this->positivos) {
            return ['erro' => $this->negativos ? 'Informe ao menos um termo para procurar (só exclusões não bastam).' : 'Digite ao menos um termo com 2 ou mais caracteres.'] + $base;
        }
        $limite = $C::inteiro('limite_por_tipo', 50, 5000);
        $todos = [];
        $atingido = false;
        foreach (array_keys($this->tipos) as $tipo) {
            $achados = $this->buscarTipo($tipo, $limite);
            $atingido = $atingido || count($achados) >= $limite;
            foreach ($achados as $id => $a) {
                $todos[] = ['itemtype' => $tipo, 'id' => $id] + $a;
            }
        }
        usort($todos, match ($this->ordem) {
            'recentes' => fn($a, $b) => strcmp($b['data'], $a['data']),
            'antigos'  => fn($a, $b) => strcmp($a['data'], $b['data']),
            default    => fn($a, $b) => [$b['score'], $b['data']] <=> [$a['score'], $a['data']],
        });
        $total = count($todos);
        $porPagina = max(10, min(200, $porPagina));
        $paginas = (int) ceil($total / $porPagina);
        $pagina = max(1, min($pagina, max(1, $paginas)));
        $pagina_itens = array_slice($todos, ($pagina - 1) * $porPagina, $porPagina);

        return [
            'itens'           => $this->detalhar($pagina_itens),
            'total'           => $total,
            'limite_atingido' => $atingido,
            'limite'          => $limite,
            'pagina'          => $pagina,
            'paginas'         => $paginas,
            'por_pagina'      => $porPagina,
            'termos'          => $this->termos(),
            'tempo'           => round(microtime(true) - $inicio, 2),
            'erro'            => null,
        ];
    }

    /** Dados de exibição e trecho destacado dos itens da página */
    private function detalhar(array $lista): array
    {
        global $DB;
        $C = PluginPesquisadorConfig::class;
        $porTipo = [];
        foreach ($lista as $l) {
            $porTipo[$l['itemtype']][] = $l['id'];
        }
        $dados = [];
        $textos = [];
        foreach ($porTipo as $tipo => $ids) {
            $def = $C::TIPOS[$tipo];
            foreach ($DB->request([
                'SELECT'    => ['t.id', 't.name', 't.status', 't.date', 't.date_mod', 'e.completename AS entidade', 'c.completename AS categoria'],
                'FROM'      => $def['tabela'] . ' AS t',
                'LEFT JOIN' => [
                    'glpi_entities AS e'       => ['ON' => ['t' => 'entities_id', 'e' => 'id']],
                    'glpi_itilcategories AS c' => ['ON' => ['t' => 'itilcategories_id', 'c' => 'id']],
                ],
                'WHERE'     => ['t.id' => $ids],
            ]) as $r) {
                $dados[$tipo][(int) $r['id']] = $r;
            }
            foreach ($DB->request([
                'SELECT' => ['items_id', 'fonte', 'texto'],
                'FROM'   => PluginPesquisadorIndexador::TABELA . ' AS i',
                'WHERE'  => ['i.itemtype' => $tipo, 'i.items_id' => $ids, new \Glpi\DBAL\QueryExpression($this->filtroLinha('i'))],
            ]) as $r) {
                $textos[$tipo][(int) $r['items_id']][(string) $r['fonte']][] = (string) $r['texto'];
            }
        }

        $saida = [];
        foreach ($lista as $l) {
            $tipo = $l['itemtype'];
            $r = $dados[$tipo][$l['id']] ?? null;
            if ($r === null) {
                continue;
            }
            $trecho = $this->trecho($textos[$tipo][$l['id']] ?? []);
            $saida[] = [
                'itemtype'   => $tipo,
                'tipo'       => $C::TIPOS[$tipo]['rotulo'],
                'icone'      => $C::TIPOS[$tipo]['icone'],
                'id'         => (int) $r['id'],
                'titulo'     => (string) $r['name'],
                'url'        => $tipo::getFormURLWithID((int) $r['id']),
                'status'     => $tipo::getStatus((int) $r['status']),
                'status_html' => $tipo::getStatusIcon((int) $r['status']),
                'entidade'   => (string) ($r['entidade'] ?? ''),
                'categoria'  => (string) ($r['categoria'] ?? ''),
                'abertura'   => $r['date'] ? Html::convDateTime((string) $r['date']) : '',
                'atualizado' => $r['date_mod'] ? Html::convDateTime((string) $r['date_mod']) : '',
                'fontes'     => array_values(array_map(fn($f) => ['chave' => $f, 'rotulo' => $C::FONTES[$f][0] ?? $f, 'icone' => $C::FONTES[$f][1] ?? 'ti ti-point'], array_intersect(array_keys($C::FONTES), $l['fontes']))),
                'trecho'     => $trecho['html'],
                'trecho_fonte' => $trecho['fonte'] !== '' ? ($C::FONTES[$trecho['fonte']][0] ?? '') : '',
            ];
        }
        return $saida;
    }

    // =====================================================================
    // Trecho com destaque (ignora acentos e maiúsculas)
    // =====================================================================

    /** Caracteres do texto e a versão "base" de cada um (sem acento, minúscula), um para um */
    private static function caracteres(string $texto): array
    {
        $orig = preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        static $mapa = [];
        $base = [];
        foreach ($orig as $c) {
            if (!isset($mapa[$c])) {
                $n = class_exists('Normalizer') ? (string) Normalizer::normalize($c, Normalizer::FORM_D) : $c;
                $n = mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', $n));
                $mapa[$c] = mb_strlen($n) === 1 ? $n : (mb_strlen(mb_strtolower($c)) === 1 ? mb_strtolower($c) : $c);
            }
            $base[] = $mapa[$c];
        }
        return [$orig, $base];
    }

    private static function base(string $texto): string
    {
        return implode('', self::caracteres($texto)[1]);
    }

    /** ['html' => trecho com <mark>, 'fonte' => origem] da melhor origem que contém um termo */
    private function trecho(array $porFonte): array
    {
        $C = PluginPesquisadorConfig::class;
        $termos = array_values(array_filter(array_unique(array_map(fn($t) => self::base($t['texto']), $this->positivos)), fn($t) => $t !== ''));
        foreach (array_keys($C::FONTES) as $fonte) {
            foreach ($porFonte[$fonte] ?? [] as $texto) {
                $texto = mb_substr($texto, 0, 30000);
                [$orig, $base] = self::caracteres($texto);
                $alvo = implode('', $base);
                $faixas = [];
                foreach ($termos as $t) {
                    $len = mb_strlen($t);
                    $pos = 0;
                    while (($p = mb_strpos($alvo, $t, $pos)) !== false) {
                        $faixas[] = [$p, $p + $len];
                        $pos = $p + $len;
                        if (count($faixas) > 200) {
                            break 2;
                        }
                    }
                }
                if (!$faixas) {
                    continue;
                }
                usort($faixas, fn($a, $b) => $a[0] <=> $b[0]);
                $primeira = $faixas[0][0];
                $total = count($orig);
                $ini = max(0, $primeira - 80);
                $fim = min($total, $primeira + 200);
                // Começa e termina em fronteira de palavra
                while ($ini > 0 && $ini > $primeira - 110 && !preg_match('/\s/u', $orig[$ini - 1])) {
                    $ini--;
                }
                while ($fim < $total && $fim < $primeira + 230 && !preg_match('/\s/u', $orig[$fim])) {
                    $fim++;
                }
                $html = $ini > 0 ? '… ' : '';
                $cursor = $ini;
                foreach ($faixas as [$a, $b]) {
                    if ($b <= $cursor || $a >= $fim) {
                        continue;
                    }
                    $a = max($a, $cursor);
                    $b = min($b, $fim);
                    $html .= $C::e(implode('', array_slice($orig, $cursor, $a - $cursor))) . '<mark>' . $C::e(implode('', array_slice($orig, $a, $b - $a))) . '</mark>';
                    $cursor = $b;
                }
                $html .= $C::e(implode('', array_slice($orig, $cursor, $fim - $cursor))) . ($fim < $total ? ' …' : '');
                return ['html' => $html, 'fonte' => $fonte];
            }
        }
        // Achado só pela categoria ou pelo ID: mostra o começo da descrição
        $descricao = (string) ($porFonte['descricao'][0] ?? '');
        return ['html' => $C::e(mb_strimwidth($descricao, 0, 220, ' …')), 'fonte' => $descricao !== '' ? 'descricao' : ''];
    }
}
