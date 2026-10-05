<?php

/**
 * Plugin Pesquisador - índice de busca.
 *
 * Cada item (chamado, problema, mudança) vira várias linhas em glpi_plugin_pesquisador_textos:
 * título, descrição e cada acompanhamento, solução, tarefa, validação e nome de anexo, sempre em
 * texto puro (sem HTML nem imagens embutidas). O índice FULLTEXT fica só nessa tabela do plugin.
 * Os hooks atualizam o item na hora; a tarefa automática constrói o índice em lotes e depois revisa
 * o que mudou por fora dos hooks (gravações diretas no banco por outros plugins).
 */
class PluginPesquisadorIndexador extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_pesquisador_textos';
    public const LOTE_CRON = 1000;
    public const MAX_TEXTO = 1000000;

    /** Subitens: classe => [itemtype do pai (null = campo itemtype), campo do id do pai] */
    private const SUBITENS = [
        'ITILFollowup'     => [null, 'items_id'],
        'ITILSolution'     => [null, 'items_id'],
        'Document_Item'    => [null, 'items_id'],
        'TicketTask'       => ['Ticket', 'tickets_id'],
        'ProblemTask'      => ['Problem', 'problems_id'],
        'ChangeTask'       => ['Change', 'changes_id'],
        'TicketValidation' => ['Ticket', 'tickets_id'],
        'ChangeValidation' => ['Change', 'changes_id'],
    ];

    public static function getTypeName($nb = 0): string
    {
        return 'Índice do Pesquisador';
    }

    // =====================================================================
    // Texto
    // =====================================================================

    /** HTML do GLPI -> texto puro de uma linha (imagens base64 e tags saem; entidades viram caracteres) */
    public static function limparTexto(?string $html): string
    {
        $t = (string) $html;
        if ($t === '') {
            return '';
        }
        $t = mb_scrub($t, 'UTF-8');
        // Conteúdo antigo (GLPI 9/10) pode estar com o HTML codificado
        for ($i = 0; $i < 2 && preg_match('/&(lt|gt|amp|quot|#\d+|#x[0-9a-f]+|[a-z]+);/i', $t); $i++) {
            $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $t = preg_replace('#data:[a-z0-9/+.\-]+;base64,[a-z0-9+/=\s]+#i', ' ', $t);
        $t = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $t);
        // Toda tag separa palavras, menos as de formatação dentro da palavra (<b>im</b>pressora)
        $t = preg_replace('#<(?!/?(b|strong|i|em|u|s|strike|sub|sup|font|mark|code)\b)[^>]*>#i', ' ', $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);
        $t = trim((string) preg_replace('/\s+/u', ' ', $t));
        return mb_substr($t, 0, self::MAX_TEXTO);
    }

    // =====================================================================
    // Indexação
    // =====================================================================

    /** Reindexa os itens informados (apaga e regrava as linhas deles). Retorna quantos existem. */
    public static function indexar(string $itemtype, array $ids): int
    {
        global $DB;
        $def = PluginPesquisadorConfig::TIPOS[$itemtype] ?? null;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if ($def === null || !$ids) {
            return 0;
        }
        $linhas = [];
        $add = function (int $item, string $fonte, int $fonteId, $texto, bool $privado = false) use (&$linhas) {
            $texto = self::limparTexto(is_string($texto) ? $texto : (string) $texto);
            if ($texto !== '') {
                $linhas[] = ['items_id' => $item, 'fonte' => $fonte, 'fonte_id' => $fonteId, 'is_private' => $privado ? 1 : 0, 'texto' => $texto];
            }
        };

        $existentes = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'content'], 'FROM' => $def['tabela'], 'WHERE' => ['id' => $ids]]) as $r) {
            $id = (int) $r['id'];
            $existentes[] = $id;
            $add($id, 'titulo', 0, $r['name']);
            $add($id, 'descricao', 0, $r['content']);
        }
        if ($existentes) {
            foreach ($DB->request(['SELECT' => ['id', 'items_id', 'content', 'is_private'], 'FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $existentes]]) as $r) {
                $add((int) $r['items_id'], 'acompanhamento', (int) $r['id'], $r['content'], (int) $r['is_private'] === 1);
            }
            foreach ($DB->request(['SELECT' => ['id', 'items_id', 'content'], 'FROM' => 'glpi_itilsolutions', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $existentes]]) as $r) {
                $add((int) $r['items_id'], 'solucao', (int) $r['id'], $r['content']);
            }
            if ($def['tarefas'] !== '' && $DB->tableExists($def['tarefas'])) {
                foreach ($DB->request(['SELECT' => ['id', $def['fk'], 'content', 'is_private'], 'FROM' => $def['tarefas'], 'WHERE' => [$def['fk'] => $existentes]]) as $r) {
                    $add((int) $r[$def['fk']], 'tarefa', (int) $r['id'], $r['content'], (int) $r['is_private'] === 1);
                }
            }
            if ($def['validacoes'] !== '' && $DB->tableExists($def['validacoes'])) {
                foreach ($DB->request(['SELECT' => ['id', $def['fk'], 'comment_submission', 'comment_validation'], 'FROM' => $def['validacoes'], 'WHERE' => [$def['fk'] => $existentes]]) as $r) {
                    $add((int) $r[$def['fk']], 'validacao', (int) $r['id'], (string) $r['comment_submission'] . ' <br> ' . (string) $r['comment_validation']);
                }
            }
            $privadoDoc = $DB->fieldExists('glpi_documents_items', 'is_private');
            foreach ($DB->request([
                'SELECT'     => array_merge(['di.id', 'di.items_id', 'd.name', 'd.filename'], $privadoDoc ? ['di.is_private'] : []),
                'FROM'       => 'glpi_documents_items AS di',
                'INNER JOIN' => ['glpi_documents AS d' => ['ON' => ['di' => 'documents_id', 'd' => 'id']]],
                'WHERE'      => ['di.itemtype' => $itemtype, 'di.items_id' => $existentes, 'd.is_deleted' => 0],
            ]) as $r) {
                $nome = trim((string) $r['name']);
                $arquivo = trim((string) $r['filename']);
                $add((int) $r['items_id'], 'anexo', (int) $r['id'], $nome . ($arquivo !== '' && $arquivo !== $nome ? ' ' . $arquivo : ''), $privadoDoc && (int) $r['is_private'] === 1);
            }
        }

        $DB->delete(self::TABELA, ['itemtype' => $itemtype, 'items_id' => $ids]);
        foreach ($linhas as $l) {
            $DB->insert(self::TABELA, $l + ['itemtype' => $itemtype]);
        }
        return count($existentes);
    }

    public static function remover(string $itemtype, int $id): void
    {
        global $DB;
        $DB->delete(self::TABELA, ['itemtype' => $itemtype, 'items_id' => $id]);
    }

    /** [itemtype, id] do item pesquisável afetado por $item, ou null */
    private static function alvo(CommonDBTM $item): ?array
    {
        $classe = $item::class;
        if (isset(PluginPesquisadorConfig::TIPOS[$classe])) {
            return [$classe, (int) $item->getID()];
        }
        if (!isset(self::SUBITENS[$classe])) {
            return null;
        }
        [$tipo, $campo] = self::SUBITENS[$classe];
        $tipo ??= (string) ($item->fields['itemtype'] ?? '');
        $id = (int) ($item->fields[$campo] ?? 0);
        return isset(PluginPesquisadorConfig::TIPOS[$tipo]) && $id > 0 ? [$tipo, $id] : null;
    }

    /** Hooks item_add e item_update */
    public static function aoSalvar(CommonDBTM $item): void
    {
        try {
            $alvo = self::alvo($item);
            if ($alvo === null) {
                return;
            }
            // Item principal alterado sem mexer em título/descrição: nada a reindexar
            if (isset(PluginPesquisadorConfig::TIPOS[$item::class]) && !empty($item->updates)
                && !array_intersect(['name', 'content'], (array) $item->updates)) {
                return;
            }
            self::indexar($alvo[0], [$alvo[1]]);
        } catch (\Throwable $e) {
            Toolbox::logInFile('pesquisador', 'Falha ao indexar ' . $item::class . ' #' . $item->getID() . ': ' . $e->getMessage() . "\n");
        }
    }

    /** Hook item_purge */
    public static function aoRemover(CommonDBTM $item): void
    {
        try {
            $alvo = self::alvo($item);
            if ($alvo === null) {
                return;
            }
            if (isset(PluginPesquisadorConfig::TIPOS[$item::class])) {
                self::remover($alvo[0], $alvo[1]);
            } else {
                self::indexar($alvo[0], [$alvo[1]]);
            }
        } catch (\Throwable $e) {
            Toolbox::logInFile('pesquisador', 'Falha ao atualizar o índice (' . $item::class . '): ' . $e->getMessage() . "\n");
        }
    }

    // =====================================================================
    // Construção completa (em lotes) e revisão
    // =====================================================================

    public static function iniciarReconstrucao(): void
    {
        global $DB;
        $DB->doQuery('TRUNCATE TABLE `' . self::TABELA . '`');
        $C = PluginPesquisadorConfig::class;
        $C::setConfig('indice_cursor', json_encode(new stdClass()));
        $C::setConfig('indice_completo', '0');
        $C::setConfig('indice_inicio', date('Y-m-d H:i:s'));
        $C::setConfig('indice_revisao', '');
    }

    /** Indexa até $max itens a partir de onde parou. Retorna ['feito' => n, 'concluido' => bool] */
    public static function construirLote(int $max): array
    {
        global $DB;
        $C = PluginPesquisadorConfig::class;
        $cursor = json_decode((string) $C::getConfig('indice_cursor'), true) ?: [];
        $feito = 0;
        foreach (PluginPesquisadorConfig::TIPOS as $tipo => $def) {
            if (!empty($cursor[$tipo . '_ok'])) {
                continue;
            }
            $restante = $max - $feito;
            if ($restante <= 0) {
                break;
            }
            $ids = [];
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $def['tabela'], 'WHERE' => ['id' => ['>', (int) ($cursor[$tipo] ?? 0)]], 'ORDER' => 'id ASC', 'LIMIT' => $restante]) as $r) {
                $ids[] = (int) $r['id'];
            }
            foreach (array_chunk($ids, 200) as $parte) {
                self::indexar($tipo, $parte);
            }
            $feito += count($ids);
            if ($ids) {
                $cursor[$tipo] = max($ids);
            }
            if (count($ids) < $restante) {
                $cursor[$tipo . '_ok'] = 1;
            }
        }
        $C::setConfig('indice_cursor', json_encode($cursor));
        $concluido = true;
        foreach (array_keys(PluginPesquisadorConfig::TIPOS) as $tipo) {
            $concluido = $concluido && !empty($cursor[$tipo . '_ok']);
        }
        if ($concluido && $C::getConfig('indice_completo') !== '1') {
            $C::setConfig('indice_completo', '1');
            // A revisão seguinte pega o que mudou durante a construção
            $C::setConfig('indice_revisao', (string) ($C::getConfig('indice_inicio') ?: date('Y-m-d H:i:s')));
        }
        return ['feito' => $feito, 'concluido' => $concluido];
    }

    /** Reindexa o que foi alterado desde a última revisão e limpa linhas de itens que não existem mais */
    public static function revisar(): int
    {
        global $DB;
        $C = PluginPesquisadorConfig::class;
        $desde = (string) $C::getConfig('indice_revisao');
        // Margem de segurança: reindexar duas vezes não faz mal; perder uma alteração faz
        $agora = date('Y-m-d H:i:s', time() - 5 * MINUTE_TIMESTAMP);
        if ($desde === '') {
            $C::setConfig('indice_revisao', $agora);
            return 0;
        }
        $pais = [];
        $coletar = function (string $tabela, string $tipoCampo, string $idCampo, ?string $tipoFixo) use ($DB, $desde, &$pais) {
            if (!$DB->tableExists($tabela) || !$DB->fieldExists($tabela, 'date_mod')) {
                return;
            }
            $select = $tipoFixo === null ? [$tipoCampo, $idCampo] : [$idCampo];
            foreach ($DB->request(['SELECT' => $select, 'DISTINCT' => true, 'FROM' => $tabela, 'WHERE' => ['date_mod' => ['>=', $desde]], 'LIMIT' => 20000]) as $r) {
                $tipo = $tipoFixo ?? (string) $r[$tipoCampo];
                if (isset(PluginPesquisadorConfig::TIPOS[$tipo])) {
                    $pais[$tipo][(int) $r[$idCampo]] = true;
                }
            }
        };
        foreach (PluginPesquisadorConfig::TIPOS as $tipo => $def) {
            $coletar($def['tabela'], '', 'id', $tipo);
            if ($def['tarefas'] !== '') {
                $coletar($def['tarefas'], '', $def['fk'], $tipo);
            }
            if ($def['validacoes'] !== '') {
                $coletar($def['validacoes'], '', $def['fk'], $tipo);
            }
        }
        $coletar('glpi_itilfollowups', 'itemtype', 'items_id', null);
        $coletar('glpi_itilsolutions', 'itemtype', 'items_id', null);
        $coletar('glpi_documents_items', 'itemtype', 'items_id', null);

        $total = 0;
        foreach ($pais as $tipo => $ids) {
            foreach (array_chunk(array_keys($ids), 200) as $parte) {
                self::indexar($tipo, $parte);
                $total += count($parte);
            }
        }
        // Itens apagados por fora do GLPI
        foreach (PluginPesquisadorConfig::TIPOS as $tipo => $def) {
            $orfaos = [];
            foreach ($DB->request([
                'SELECT'    => ['i.items_id'],
                'DISTINCT'  => true,
                'FROM'      => self::TABELA . ' AS i',
                'LEFT JOIN' => [$def['tabela'] . ' AS t' => ['ON' => ['i' => 'items_id', 't' => 'id']]],
                'WHERE'     => ['i.itemtype' => $tipo, 't.id' => null],
                'LIMIT'     => 5000,
            ]) as $r) {
                $orfaos[] = (int) $r['items_id'];
            }
            if ($orfaos) {
                $DB->delete(self::TABELA, ['itemtype' => $tipo, 'items_id' => $orfaos]);
            }
        }
        $C::setConfig('indice_revisao', $agora);
        return $total;
    }

    /** Situação do índice para a configuração e a página de busca */
    public static function situacao(): array
    {
        global $DB;
        $C = PluginPesquisadorConfig::class;
        $cursor = json_decode((string) $C::getConfig('indice_cursor'), true) ?: [];
        $completo = $C::getConfig('indice_completo') === '1';
        $tipos = [];
        $feitos = 0;
        $totais = 0;
        foreach (PluginPesquisadorConfig::TIPOS as $tipo => $def) {
            $total = (int) ($DB->request(['COUNT' => 'n', 'FROM' => $def['tabela']])->current()['n'] ?? 0);
            if ($completo || !empty($cursor[$tipo . '_ok'])) {
                $feito = $total;
            } else {
                $feito = (int) ($DB->request(['COUNT' => 'n', 'FROM' => $def['tabela'], 'WHERE' => ['id' => ['<=', (int) ($cursor[$tipo] ?? 0)]]])->current()['n'] ?? 0);
            }
            $tipos[$tipo] = ['rotulo' => $def['plural'], 'total' => $total, 'feito' => $feito];
            $feitos += $feito;
            $totais += $total;
        }
        return [
            'completo' => $completo,
            'tipos'    => $tipos,
            'feito'    => $feitos,
            'total'    => $totais,
            'pct'      => $totais > 0 ? (int) floor($feitos / $totais * 100) : 100,
            'textos'   => (int) ($DB->request(['COUNT' => 'n', 'FROM' => self::TABELA])->current()['n'] ?? 0),
            'revisao'  => (string) $C::getConfig('indice_revisao'),
        ];
    }

    // =====================================================================
    // Tarefa automática
    // =====================================================================

    public static function cronInfo($name): array
    {
        return ['description' => 'Pesquisador: constrói e revisa o índice de busca'];
    }

    public static function cronPesquisadorIndexar(CronTask $task): int
    {
        if (PluginPesquisadorConfig::getConfig('indice_completo') !== '1') {
            $r = self::construirLote(self::LOTE_CRON);
            $task->addVolume($r['feito']);
            $task->log($r['concluido'] ? 'Índice concluído' : 'Construção do índice em andamento');
            return 1;
        }
        $n = self::revisar();
        $task->addVolume($n);
        return $n > 0 ? 1 : 0;
    }
}
