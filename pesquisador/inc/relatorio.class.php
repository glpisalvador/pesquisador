<?php

use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QuerySubQuery;

/**
 * Plugin Pesquisador - relatório de chamados (reúne os antigos plugins "sql" e "consulta").
 * Filtros por período, datas, status, tipo, prioridade, categoria, entidades, atores, grupos e SLA;
 * situação do SLA pelos prazos do próprio GLPI; colunas escolhidas; paginação e ordenação no servidor;
 * contadores de SLA; exportação CSV/XLSX completa e relatórios salvos por pessoa.
 */
class PluginPesquisadorRelatorio
{
    public const SALVOS = 'glpi_plugin_pesquisador_relatorios';

    /** chave => [rótulo, ordenação (coluna ou apelido do SELECT; null = não ordena)] */
    public const COLUNAS = [
        'id'                => ['ID', 't.id'],
        'titulo'            => ['Título', 't.name'],
        'entidade'          => ['Entidade', 'entidade'],
        'descricao'         => ['Descrição', null],
        'requerente'        => ['Requerente', 'requerente'],
        'tecnico'           => ['Técnico', 'tecnico'],
        'grupo_atribuido'   => ['Grupo atribuído', 'grupo_atribuido'],
        'grupo_observador'  => ['Grupo observador', 'grupo_observador'],
        'categoria'         => ['Categoria', 'categoria'],
        'status'            => ['Status', 't.status'],
        'tipo'              => ['Tipo', 't.type'],
        'prioridade'        => ['Prioridade', 't.priority'],
        'urgencia'          => ['Urgência', 't.urgency'],
        'data'              => ['Abertura', 't.date'],
        'data_mod'          => ['Última atualização', 't.date_mod'],
        'atendimento'       => ['Início do atendimento', 't.takeintoaccountdate'],
        'solvedate'         => ['Solução', 't.solvedate'],
        'closedate'         => ['Fechamento', 't.closedate'],
        'sla_tto'           => ['SLA de atendimento', 'sla_tto_nome'],
        'sla_ttr'           => ['SLA de solução', 'sla_ttr_nome'],
        'tto'               => ['Prazo de atendimento + progresso', 't.time_to_own'],
        'ttr'               => ['Prazo de solução + progresso', 't.time_to_resolve'],
        'tto_situacao'      => ['Situação do atendimento', 'tto_sit'],
        'ttr_situacao'      => ['Situação da solução', 'ttr_sit'],
        'exc_adquirir'      => ['Prazo de atendimento excedido', 'tto_sit'],
        'exc_resolver'      => ['Prazo de solução excedido', 'ttr_sit'],
        'tempo_atendimento' => ['Tempo até o atendimento', 't.takeintoaccount_delay_stat'],
        'tempo_solucao'     => ['Tempo até a solução', 't.solve_delay_stat'],
        'tempo_fechamento'  => ['Tempo até o fechamento', 't.close_delay_stat'],
        'tempo_espera'      => ['Tempo em espera', 't.waiting_duration'],
        'acompanhamentos'   => ['Acompanhamentos', 'qtd_acompanhamentos'],
    ];

    public const PERIODOS = [
        ''        => 'Todo o período',
        'hora'    => 'Última hora',
        'dia'     => 'Últimas 24 horas',
        'semana'  => 'Última semana',
        '10dias'  => 'Últimos 10 dias',
        '15dias'  => 'Últimos 15 dias',
        '3semanas' => 'Últimas 3 semanas',
        'mes'     => 'Último mês',
        '3meses'  => 'Últimos 3 meses',
        '4meses'  => 'Últimos 4 meses',
        '6meses'  => 'Últimos 6 meses',
        '8meses'  => 'Últimos 8 meses',
        'ano'     => 'Último ano',
        'datas'   => 'Escolher datas',
    ];

    private const RECUOS = [
        'hora' => '-1 hour', 'dia' => '-1 day', 'semana' => '-1 week', '10dias' => '-10 days', '15dias' => '-15 days',
        '3semanas' => '-3 weeks', 'mes' => '-1 month', '3meses' => '-3 months', '4meses' => '-4 months',
        '6meses' => '-6 months', '8meses' => '-8 months', 'ano' => '-1 year',
    ];

    public const CAMPOS_DATA = [
        'date'                => 'Abertura',
        'takeintoaccountdate' => 'Início do atendimento',
        'solvedate'           => 'Solução',
        'closedate'           => 'Fechamento',
        'date_mod'            => 'Última atualização',
    ];

    public const SITUACOES = [
        'tto_ok'        => 'Atendimento no prazo',
        'tto_vencido'   => 'Atendimento vencido',
        'tto_andamento' => 'Atendimento em andamento',
        'ttr_ok'        => 'Solução no prazo',
        'ttr_vencido'   => 'Solução vencida',
        'ttr_andamento' => 'Solução em andamento',
    ];

    private const ROTULO_SITUACAO = ['ok' => 'No prazo', 'vencido' => 'Vencido', 'andamento' => 'Em andamento', 'sem' => 'Sem prazo'];

    private const LISTAS = ['status', 'tipos', 'prioridades', 'categorias', 'entidades', 'requerentes', 'tecnicos', 'grupos_atribuidos', 'grupos_observadores', 'sla_tto', 'sla_ttr'];

    private static function e($t): string
    {
        return PluginPesquisadorConfig::e($t);
    }

    // =====================================================================
    // Filtros
    // =====================================================================

    /** Filtros limpos a partir de GET/POST ou de um relatório salvo */
    public static function normalizar(array $in): array
    {
        $f = [
            'periodo'      => isset(self::PERIODOS[(string) ($in['periodo'] ?? '')]) ? (string) ($in['periodo'] ?? '') : '',
            'campo_data'   => isset(self::CAMPOS_DATA[(string) ($in['campo_data'] ?? '')]) ? (string) $in['campo_data'] : 'date',
            'de'           => self::data((string) ($in['de'] ?? ''), false),
            'ate'          => self::data((string) ($in['ate'] ?? ''), true),
            'subentidades' => !isset($in['subentidades']) || !empty($in['subentidades']),
            'busca'        => mb_substr(trim((string) ($in['busca'] ?? '')), 0, 200),
            'situacao'     => isset(self::SITUACOES[(string) ($in['situacao'] ?? '')]) ? (string) $in['situacao'] : '',
            'ordem'        => isset(self::COLUNAS[(string) ($in['ordem'] ?? '')]) && self::COLUNAS[(string) $in['ordem']][1] !== null ? (string) $in['ordem'] : 'id',
            'direcao'      => strtolower((string) ($in['direcao'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        ];
        foreach (self::LISTAS as $l) {
            // "-1" e "" vêm do campo vazio do multiselect; a entidade raiz é 0
            $valores = array_filter(array_map('strval', (array) ($in[$l] ?? [])), fn($v) => preg_match('/^\d+$/', $v) === 1);
            $f[$l] = array_values(array_unique(array_filter(array_map('intval', $valores), fn($v) => $l === 'entidades' ? $v >= 0 : $v > 0)));
        }
        $colunas = array_values(array_intersect(array_map('strval', (array) ($in['colunas'] ?? [])), array_keys(self::COLUNAS)));
        $f['colunas'] = $colunas ?: self::colunasPadrao();
        return $f;
    }

    public static function colunasPadrao(): array
    {
        $c = array_values(array_intersect(PluginPesquisadorConfig::getArrayConfig('relatorio_colunas'), array_keys(self::COLUNAS)));
        return $c ?: ['id', 'titulo', 'entidade', 'status', 'data'];
    }

    private static function data(string $v, bool $fim): string
    {
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v . ($fim ? ' 23:59:59' : ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $v)) {
            return strlen($v) === 16 ? $v . ($fim ? ':59' : ':00') : $v;
        }
        return '';
    }

    /** Situação do SLA em SQL ('ok', 'vencido', 'andamento', 'sem'), com o "agora" do PHP */
    private static function exprSituacao(string $tipo): string
    {
        global $DB;
        $agora = $DB->quoteValue(date('Y-m-d H:i:s'));
        if ($tipo === 'tto') {
            return "(CASE WHEN `t`.`time_to_own` IS NULL THEN 'sem'"
                . " WHEN `t`.`takeintoaccountdate` IS NOT NULL THEN IF(`t`.`takeintoaccountdate` <= `t`.`time_to_own`, 'ok', 'vencido')"
                . " WHEN $agora > `t`.`time_to_own` THEN 'vencido' ELSE 'andamento' END)";
        }
        return "(CASE WHEN `t`.`time_to_resolve` IS NULL THEN 'sem'"
            . " WHEN `t`.`solvedate` IS NOT NULL THEN IF(`t`.`solvedate` <= `t`.`time_to_resolve`, 'ok', 'vencido')"
            . " WHEN `t`.`closedate` IS NOT NULL THEN IF(`t`.`closedate` <= `t`.`time_to_resolve`, 'ok', 'vencido')"
            . " WHEN $agora > `t`.`time_to_resolve` THEN 'vencido' ELSE 'andamento' END)";
    }

    /** Entidades onde o relatório procura: ativas da sessão, limitadas pelas escolhidas (com filhas) */
    private static function entidadesAlvo(array $f): array
    {
        $ativas = array_map('intval', $_SESSION['glpiactiveentities'] ?? []);
        if (!$f['entidades']) {
            return $ativas;
        }
        $alvo = [];
        foreach ($f['entidades'] as $id) {
            $alvo = array_merge($alvo, $f['subentidades'] ? array_map('intval', getSonsOf('glpi_entities', $id)) : [$id]);
        }
        return array_values(array_intersect(array_unique($alvo), $ativas));
    }

    private static function sub(string $tabela, string $campo, array $where): QuerySubQuery
    {
        return new QuerySubQuery(['SELECT' => $campo, 'FROM' => $tabela, 'WHERE' => $where]);
    }

    /** WHERE completo (com ou sem o filtro de situação do SLA, usado nos contadores) */
    private static function where(array $f, bool $comSituacao = true): array
    {
        global $DB;
        $where = ['t.is_deleted' => 0];
        $entidades = self::entidadesAlvo($f);
        $where['t.entities_id'] = $entidades ?: [-1];

        // Perfis que só veem os próprios chamados
        $permitido = PluginPesquisadorConfig::tiposPermitidos()['Ticket'] ?? '';
        if ($permitido === 'ator') {
            $uid = (int) Session::getLoginUserID();
            $grupos = array_map('intval', $_SESSION['glpigroups'] ?? []);
            $ou = [
                't.users_id_recipient' => $uid,
                ['t.id' => self::sub('glpi_tickets_users', 'tickets_id', ['users_id' => $uid])],
            ];
            if ($grupos) {
                $ou[] = ['t.id' => self::sub('glpi_groups_tickets', 'tickets_id', ['groups_id' => $grupos])];
            }
            $where[] = ['OR' => $ou];
        } elseif ($permitido === '') {
            $where['t.id'] = -1;
        }

        $campo = 't.' . $f['campo_data'];
        $de = $f['de'];
        $ate = $f['ate'];
        if (isset(self::RECUOS[$f['periodo']])) {
            $de = date('Y-m-d H:i:s', strtotime(self::RECUOS[$f['periodo']]));
            $ate = '';
        } elseif ($f['periodo'] !== 'datas') {
            $de = '';
            $ate = '';
        }
        if ($de !== '') {
            $where[] = [$campo => ['>=', $de]];
        }
        if ($ate !== '') {
            $where[] = [$campo => ['<=', $ate]];
        }

        foreach (['status' => 't.status', 'tipos' => 't.type', 'prioridades' => 't.priority', 'categorias' => 't.itilcategories_id', 'sla_tto' => 't.slas_id_tto', 'sla_ttr' => 't.slas_id_ttr'] as $chave => $coluna) {
            if ($f[$chave]) {
                $where[$coluna] = $f[$chave];
            }
        }
        $atores = [
            'requerentes'         => ['glpi_tickets_users', 'users_id', CommonITILActor::REQUESTER],
            'tecnicos'            => ['glpi_tickets_users', 'users_id', CommonITILActor::ASSIGN],
            'grupos_atribuidos'   => ['glpi_groups_tickets', 'groups_id', CommonITILActor::ASSIGN],
            'grupos_observadores' => ['glpi_groups_tickets', 'groups_id', CommonITILActor::OBSERVER],
        ];
        foreach ($atores as $chave => [$tabela, $coluna, $papel]) {
            if ($f[$chave]) {
                $where[] = ['t.id' => self::sub($tabela, 'tickets_id', ['type' => $papel, $coluna => $f[$chave]])];
            }
        }

        if ($f['busca'] !== '') {
            $termo = ltrim($f['busca'], '#');
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['busca']) . '%';
            $ou = [
                't.name'        => ['LIKE', $like],
                'e.completename' => ['LIKE', $like],
            ];
            if (ctype_digit($termo)) {
                $ou['t.id'] = (int) $termo;
            }
            $where[] = ['OR' => $ou];
        }

        if ($comSituacao && $f['situacao'] !== '') {
            [$tipo, $valor] = explode('_', $f['situacao'], 2);
            $where[] = new QueryExpression(self::exprSituacao($tipo) . ' = ' . $DB->quoteValue($valor));
        }
        return $where;
    }

    private static function joins(): array
    {
        return [
            'glpi_entities AS e'       => ['ON' => ['e' => 'id', 't' => 'entities_id']],
            'glpi_itilcategories AS c' => ['ON' => ['c' => 'id', 't' => 'itilcategories_id']],
            'glpi_slas AS stto'        => ['ON' => ['stto' => 'id', 't' => 'slas_id_tto']],
            'glpi_slas AS sttr'        => ['ON' => ['sttr' => 'id', 't' => 'slas_id_ttr']],
        ];
    }

    private static function nomes(string $vinculo, int $papel): string
    {
        $nome = "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname,''),' ',COALESCE(u.realname,''))),''), u.name)";
        if ($vinculo === 'usuarios') {
            return "(SELECT GROUP_CONCAT(DISTINCT COALESCE($nome, tu.alternative_email) ORDER BY 1 SEPARATOR ', ') FROM glpi_tickets_users tu"
                . " LEFT JOIN glpi_users u ON u.id = tu.users_id WHERE tu.tickets_id = t.id AND tu.type = $papel)";
        }
        return "(SELECT GROUP_CONCAT(DISTINCT g.completename ORDER BY 1 SEPARATOR ', ') FROM glpi_groups_tickets gt"
            . " JOIN glpi_groups g ON g.id = gt.groups_id WHERE gt.tickets_id = t.id AND gt.type = $papel)";
    }

    private static function select(): array
    {
        return [
            't.id', 't.name', 't.content', 't.status', 't.type', 't.priority', 't.urgency', 't.date', 't.date_mod',
            't.takeintoaccountdate', 't.solvedate', 't.closedate', 't.time_to_own', 't.time_to_resolve',
            't.takeintoaccount_delay_stat', 't.solve_delay_stat', 't.close_delay_stat', 't.waiting_duration',
            'e.completename AS entidade', 'c.completename AS categoria', 'stto.name AS sla_tto_nome', 'sttr.name AS sla_ttr_nome',
            new QueryExpression(self::exprSituacao('tto') . ' AS `tto_sit`'),
            new QueryExpression(self::exprSituacao('ttr') . ' AS `ttr_sit`'),
            new QueryExpression(self::nomes('usuarios', CommonITILActor::REQUESTER) . ' AS `requerente`'),
            new QueryExpression(self::nomes('usuarios', CommonITILActor::ASSIGN) . ' AS `tecnico`'),
            new QueryExpression(self::nomes('grupos', CommonITILActor::ASSIGN) . ' AS `grupo_atribuido`'),
            new QueryExpression(self::nomes('grupos', CommonITILActor::OBSERVER) . ' AS `grupo_observador`'),
            new QueryExpression("(SELECT COUNT(*) FROM glpi_itilfollowups f WHERE f.itemtype = 'Ticket' AND f.items_id = t.id) AS `qtd_acompanhamentos`"),
        ];
    }

    private static function ordem(array $f): array
    {
        $campo = self::COLUNAS[$f['ordem']][1] ?? 't.id';
        $dir = $f['direcao'] === 'asc' ? 'ASC' : 'DESC';
        return $campo === 't.id' ? ["$campo $dir"] : ["$campo $dir", 't.id DESC'];
    }

    // =====================================================================
    // Consulta
    // =====================================================================

    public static function listar(array $f, int $pagina, int $porPagina): array
    {
        global $DB;
        $inicio = microtime(true);
        $porPagina = max(10, min(500, $porPagina));
        $base = ['FROM' => 'glpi_tickets AS t', 'LEFT JOIN' => self::joins(), 'WHERE' => self::where($f)];
        $total = 0;
        foreach ($DB->request($base + ['COUNT' => 'total']) as $r) {
            $total = (int) $r['total'];
        }
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $linhas = [];
        foreach ($DB->request($base + ['SELECT' => self::select(), 'ORDER' => self::ordem($f), 'START' => ($pagina - 1) * $porPagina, 'LIMIT' => $porPagina]) as $row) {
            $celulas = [];
            foreach ($f['colunas'] as $col) {
                $celulas[$col] = self::celula($col, $row, true);
            }
            $linhas[] = ['id' => (int) $row['id'], 'url' => Ticket::getFormURLWithID((int) $row['id']), 'celulas' => $celulas];
        }
        $colunas = [];
        foreach ($f['colunas'] as $col) {
            $colunas[] = ['chave' => $col, 'rotulo' => self::COLUNAS[$col][0], 'ordenavel' => self::COLUNAS[$col][1] !== null];
        }
        return [
            'total'    => $total,
            'pagina'   => $pagina,
            'paginas'  => $paginas,
            'colunas'  => $colunas,
            'linhas'   => $linhas,
            'contagem' => self::contadores($f),
            'tempo'    => max(0.01, round(microtime(true) - $inicio, 2)),
        ];
    }

    /** Contadores de SLA do conjunto filtrado (sem o filtro de situação, para todos continuarem clicáveis) */
    public static function contadores(array $f): array
    {
        global $DB;
        $tto = self::exprSituacao('tto');
        $ttr = self::exprSituacao('ttr');
        $select = [new QueryExpression('COUNT(*) AS `total`')];
        foreach (['ok', 'vencido', 'andamento'] as $s) {
            $select[] = new QueryExpression("SUM($tto = '$s') AS `tto_$s`");
            $select[] = new QueryExpression("SUM($ttr = '$s') AS `ttr_$s`");
        }
        $r = $DB->request(['SELECT' => $select, 'FROM' => 'glpi_tickets AS t', 'LEFT JOIN' => self::joins(), 'WHERE' => self::where($f, false)])->current() ?: [];
        return array_map('intval', $r);
    }

    /** Todas as linhas (exportação), em blocos para não carregar tudo de uma vez */
    public static function percorrer(array $f, callable $porLinha, int $limite = 0): int
    {
        global $DB;
        $n = 0;
        $bloco = 2000;
        for ($inicio = 0;; $inicio += $bloco) {
            $quantos = 0;
            foreach ($DB->request(['SELECT' => self::select(), 'FROM' => 'glpi_tickets AS t', 'LEFT JOIN' => self::joins(), 'WHERE' => self::where($f), 'ORDER' => self::ordem($f), 'START' => $inicio, 'LIMIT' => $bloco]) as $row) {
                $quantos++;
                $porLinha($row);
                $n++;
                if ($limite > 0 && $n >= $limite) {
                    return $n;
                }
            }
            if ($quantos < $bloco) {
                return $n;
            }
        }
    }

    // =====================================================================
    // Células
    // =====================================================================

    private static function progresso(?string $inicio, ?string $limite, ?string $fim): int
    {
        $i = strtotime((string) $inicio);
        $l = strtotime((string) $limite);
        $f = strtotime((string) $fim);
        if (!$i || !$l || $l - $i <= 0) {
            return 100;
        }
        return (int) round(max(0, min(100, ($f - $i) / ($l - $i) * 100)));
    }

    private static function barra(?string $prazo, int $pct, string $situacao): string
    {
        $cor = match (true) {
            $situacao === 'ok'      => 'rgba(25, 135, 84, 0.55)',
            $situacao === 'vencido' => 'rgba(220, 53, 69, 0.55)',
            $pct >= 80              => 'rgba(255, 193, 7, 0.7)',
            default                 => 'rgba(23, 162, 184, 0.5)',
        };
        return '<div class="pesquisador-sla"><div class="pesquisador-sla-data">' . self::e(Html::convDateTime((string) $prazo)) . '</div>'
            . '<div class="pesquisador-sla-barra"><div class="pesquisador-sla-fill" style="width:' . $pct . '%;background:' . $cor . ';"></div>'
            . '<span>' . $pct . '%</span></div></div>';
    }

    private static function selo(string $situacao): string
    {
        if ($situacao === 'sem') {
            return '<span class="pesquisador-pequeno">Sem prazo</span>';
        }
        return '<span class="pesquisador-selo pesquisador-situacao-' . self::e($situacao) . '">' . self::e(self::ROTULO_SITUACAO[$situacao] ?? $situacao) . '</span>';
    }

    private static function texto(?string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</div>', '</li>'], [' <br', ' </p>', ' </div>', ' </li>'], (string) $html)), ENT_QUOTES, 'UTF-8')));
    }

    /** Valor de uma coluna: HTML para a tela ou texto para exportação */
    public static function celula(string $col, array $r, bool $html)
    {
        $e = fn($v) => $html ? self::e($v) : (string) $v;
        $data = fn($v) => empty($v) ? '' : $e(Html::convDateTime((string) $v));
        $tempo = fn($s) => (int) $s > 0 ? $e(Html::timestampToString((int) $s, false)) : '';
        switch ($col) {
            case 'id':
                return $html ? '<a href="' . self::e(Ticket::getFormURLWithID((int) $r['id'])) . '">' . (int) $r['id'] . '</a>' : (int) $r['id'];
            case 'titulo':
                return $html ? '<a href="' . self::e(Ticket::getFormURLWithID((int) $r['id'])) . '" class="pesquisador-titulo-rel">' . self::e($r['name']) . '</a>' : (string) $r['name'];
            case 'descricao':
                $t = self::texto($r['content']);
                return $html ? '<span class="pesquisador-cortado" title="' . self::e(mb_strimwidth($t, 0, 1000, '…')) . '">' . self::e(mb_strimwidth($t, 0, 160, '…')) . '</span>' : $t;
            case 'entidade':
            case 'requerente':
            case 'tecnico':
            case 'grupo_atribuido':
            case 'grupo_observador':
            case 'categoria':
                $v = str_replace('&#62;', '>', (string) ($r[$col] ?? ''));
                return $html && $v !== '' ? '<span class="pesquisador-cortado" title="' . self::e($v) . '">' . self::e($v) . '</span>' : $e($v);
            case 'status':
                $nome = Ticket::getStatus((int) $r['status']);
                return $html ? Ticket::getStatusIcon((int) $r['status']) . ' ' . self::e($nome) : $nome;
            case 'tipo':
                return $e(Ticket::getTicketTypeName((int) $r['type']));
            case 'prioridade':
                return $e(CommonITILObject::getPriorityName((int) $r['priority']));
            case 'urgencia':
                return $e(CommonITILObject::getUrgencyName((int) $r['urgency']));
            case 'data':
                return $data($r['date']);
            case 'data_mod':
                return $data($r['date_mod']);
            case 'atendimento':
                return $data($r['takeintoaccountdate']);
            case 'solvedate':
                return $data($r['solvedate']);
            case 'closedate':
                return $data($r['closedate']);
            case 'sla_tto':
                return $e($r['sla_tto_nome'] ?? '');
            case 'sla_ttr':
                return $e($r['sla_ttr_nome'] ?? '');
            case 'tto':
            case 'ttr':
                $prazo = $col === 'tto' ? $r['time_to_own'] : $r['time_to_resolve'];
                if (empty($prazo)) {
                    return '';
                }
                $fim = $col === 'tto' ? ($r['takeintoaccountdate'] ?: date('Y-m-d H:i:s')) : ($r['solvedate'] ?: ($r['closedate'] ?: date('Y-m-d H:i:s')));
                $pct = self::progresso($r['date'], $prazo, $fim);
                $sit = (string) $r[$col . '_sit'];
                return $html ? self::barra($prazo, $pct, $sit) : Html::convDateTime((string) $prazo) . ' (' . $pct . '%)';
            case 'tto_situacao':
            case 'ttr_situacao':
                $sit = (string) $r[substr($col, 0, 3) . '_sit'];
                return $html ? self::selo($sit) : (self::ROTULO_SITUACAO[$sit] ?? $sit);
            case 'exc_adquirir':
            case 'exc_resolver':
                $sit = (string) $r[$col === 'exc_adquirir' ? 'tto_sit' : 'ttr_sit'];
                if ($sit === 'sem') {
                    return '';
                }
                $sim = $sit === 'vencido';
                return $html ? '<span class="pesquisador-selo pesquisador-situacao-' . ($sim ? 'vencido' : 'ok') . '">' . ($sim ? 'Sim' : 'Não') . '</span>' : ($sim ? 'Sim' : 'Não');
            case 'tempo_atendimento':
                return $tempo($r['takeintoaccount_delay_stat']);
            case 'tempo_solucao':
                return $tempo($r['solve_delay_stat']);
            case 'tempo_fechamento':
                return $tempo($r['close_delay_stat']);
            case 'tempo_espera':
                return $tempo($r['waiting_duration']);
            case 'acompanhamentos':
                return (int) $r['qtd_acompanhamentos'];
        }
        return '';
    }

    // =====================================================================
    // Opções dos filtros
    // =====================================================================

    public static function opcoes(): array
    {
        global $DB;
        $ativas = array_map('intval', $_SESSION['glpiactiveentities'] ?? []);
        $nomeUsuario = function (array $r): string {
            $n = trim(trim((string) $r['firstname']) . ' ' . trim((string) $r['realname']));
            return $n !== '' ? $n : (string) $r['name'];
        };
        $usuarios = function (int $papel) use ($DB, $nomeUsuario): array {
            $lista = [];
            foreach ($DB->request([
                'SELECT'     => ['u.id', 'u.name', 'u.firstname', 'u.realname'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_users AS u',
                'INNER JOIN' => ['glpi_tickets_users AS tu' => ['ON' => ['tu' => 'users_id', 'u' => 'id']]],
                'WHERE'      => ['tu.type' => $papel, 'u.is_deleted' => 0],
            ]) as $r) {
                $lista[(int) $r['id']] = $nomeUsuario($r);
            }
            return $lista;
        };
        $grupos = function (int $papel) use ($DB): array {
            $lista = [];
            // glpi_groups não tem is_deleted
            foreach ($DB->request([
                'SELECT'     => ['g.id', 'g.completename'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_groups AS g',
                'INNER JOIN' => ['glpi_groups_tickets AS gt' => ['ON' => ['gt' => 'groups_id', 'g' => 'id']]],
                'WHERE'      => ['gt.type' => $papel],
            ]) as $r) {
                $lista[(int) $r['id']] = str_replace('&#62;', '>', (string) $r['completename']);
            }
            return $lista;
        };

        // Entidades: só as de primeiro nível abaixo da raiz (as filhas entram por "incluir subentidades")
        $entidades = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename', 'entities_id'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $ativas ?: [-1]], 'ORDER' => 'completename']) as $r) {
            if ((int) $r['id'] === 0 || (int) $r['entities_id'] === 0) {
                $entidades[(int) $r['id']] = str_replace('&#62;', '>', (string) $r['completename']);
            }
        }
        if (!$entidades) {
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $ativas ?: [-1]]]) as $r) {
                $entidades[(int) $r['id']] = str_replace('&#62;', '>', (string) $r['completename']);
            }
        }

        $categorias = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_itilcategories', 'ORDER' => 'completename']) as $r) {
            $categorias[(int) $r['id']] = str_replace('&#62;', '>', (string) $r['completename']);
        }
        $slas = [SLM::TTO => [], SLM::TTR => []];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'type'], 'FROM' => 'glpi_slas', 'ORDER' => 'name']) as $r) {
            $slas[(int) $r['type']][(int) $r['id']] = (string) $r['name'];
        }
        $prioridades = [];
        foreach ([6, 5, 4, 3, 2, 1] as $p) {
            $prioridades[$p] = CommonITILObject::getPriorityName($p);
        }
        return [
            'status'              => Ticket::getAllStatusArray(),
            'tipos'               => Ticket::getTypes(),
            'prioridades'         => $prioridades,
            'categorias'          => $categorias,
            'entidades'           => $entidades,
            'requerentes'         => $usuarios(CommonITILActor::REQUESTER),
            'tecnicos'            => $usuarios(CommonITILActor::ASSIGN),
            'grupos_atribuidos'   => $grupos(CommonITILActor::ASSIGN),
            'grupos_observadores' => $grupos(CommonITILActor::OBSERVER),
            'sla_tto'             => $slas[SLM::TTO],
            'sla_ttr'             => $slas[SLM::TTR],
        ];
    }

    // =====================================================================
    // Relatórios salvos
    // =====================================================================

    public static function salvos(int $uid): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['FROM' => self::SALVOS, 'WHERE' => ['users_id' => $uid], 'ORDER' => 'nome']) as $r) {
            $lista[] = ['id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'filtros' => json_decode((string) $r['filtros'], true) ?: []];
        }
        return $lista;
    }

    public static function salvar(int $uid, string $nome, array $filtros): int
    {
        global $DB;
        $nome = mb_substr(trim(strip_tags($nome)), 0, 100);
        if ($nome === '') {
            return 0;
        }
        $dados = json_encode(self::normalizar($filtros), JSON_UNESCAPED_UNICODE);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::SALVOS, 'WHERE' => ['users_id' => $uid, 'nome' => $nome]]) as $r) {
            $DB->update(self::SALVOS, ['filtros' => $dados], ['id' => (int) $r['id']]);
            return (int) $r['id'];
        }
        $DB->insert(self::SALVOS, ['users_id' => $uid, 'nome' => $nome, 'filtros' => $dados]);
        return (int) $DB->insertId();
    }

    public static function excluir(int $uid, int $id): bool
    {
        global $DB;
        if (countElementsInTable(self::SALVOS, ['id' => $id, 'users_id' => $uid]) === 0) {
            return false;
        }
        return (bool) $DB->delete(self::SALVOS, ['id' => $id, 'users_id' => $uid]);
    }
}
