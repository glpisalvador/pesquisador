<?php

/**
 * Plugin Pesquisador - configurações, acesso por módulo, mapas de tipos/origens e utilitários
 */
class PluginPesquisadorConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_pesquisador_configs';

    /** Módulos: chave => [rótulo, ícone, página, chaves de perfis e usuários liberados] */
    public const MODULOS = [
        'busca'     => ['Busca', 'ti ti-search', 'pesquisador.php', 'allowed_profiles', 'allowed_users'],
        'relatorio' => ['Relatório de chamados', 'ti ti-report', 'relatorio.php', 'relatorio_perfis', 'relatorio_usuarios'],
        'console'   => ['Console SQL', 'ti ti-terminal-2', 'console.php', 'sql_perfis', 'sql_usuarios'],
        'banco'     => ['Banco de dados', 'ti ti-database', 'banco.php', 'sql_perfis', 'sql_usuarios'],
    ];

    /**
     * Itens pesquisados: tabelas, vínculos de atores (visibilidade) e tabelas de tarefas e validações.
     */
    public const TIPOS = [
        'Ticket' => [
            'rotulo'     => 'Chamado',
            'plural'     => 'Chamados',
            'icone'      => 'ti ti-alert-circle',
            'tabela'     => 'glpi_tickets',
            'fk'         => 'tickets_id',
            'tarefas'    => 'glpi_tickettasks',
            'validacoes' => 'glpi_ticketvalidations',
            'usuarios'   => 'glpi_tickets_users',
            'grupos'     => 'glpi_groups_tickets',
            'direito'    => 'ticket',
        ],
        'Problem' => [
            'rotulo'     => 'Problema',
            'plural'     => 'Problemas',
            'icone'      => 'ti ti-alert-triangle',
            'tabela'     => 'glpi_problems',
            'fk'         => 'problems_id',
            'tarefas'    => 'glpi_problemtasks',
            'validacoes' => '',
            'usuarios'   => 'glpi_problems_users',
            'grupos'     => 'glpi_groups_problems',
            'direito'    => 'problem',
        ],
        'Change' => [
            'rotulo'     => 'Mudança',
            'plural'     => 'Mudanças',
            'icone'      => 'ti ti-exchange',
            'tabela'     => 'glpi_changes',
            'fk'         => 'changes_id',
            'tarefas'    => 'glpi_changetasks',
            'validacoes' => 'glpi_changevalidations',
            'usuarios'   => 'glpi_changes_users',
            'grupos'     => 'glpi_changes_groups',
            'direito'    => 'change',
        ],
    ];

    /** Origens do texto: chave => [rótulo, ícone, peso na relevância] (categoria é consultada na hora) */
    public const FONTES = [
        'titulo'         => ['Título', 'ti ti-heading', 6],
        'categoria'      => ['Categoria', 'ti ti-category', 4],
        'descricao'      => ['Descrição', 'ti ti-file-text', 3],
        'solucao'        => ['Soluções', 'ti ti-circle-check', 2],
        'acompanhamento' => ['Acompanhamentos', 'ti ti-message', 1],
        'tarefa'         => ['Tarefas', 'ti ti-list-check', 1],
        'validacao'      => ['Validações', 'ti ti-thumb-up', 1],
        'anexo'          => ['Anexos (nome)', 'ti ti-paperclip', 1],
    ];

    public static function getTypeName($nb = 0): string
    {
        return 'Pesquisador';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            // Busca
            'allowed_profiles'    => [],
            'allowed_users'       => [],
            'limite_por_tipo'     => '500',
            'por_pagina'          => '25',
            'parcial_padrao'      => '0',
            'indice_cursor'       => [],
            'indice_completo'     => '0',
            'indice_inicio'       => '',
            'indice_revisao'      => '',
            // Relatório de chamados
            'relatorio_perfis'    => [],
            'relatorio_usuarios'  => [],
            'relatorio_colunas'   => ['id', 'titulo', 'entidade', 'requerente', 'tecnico', 'status', 'data', 'tto', 'ttr', 'tto_situacao', 'ttr_situacao'],
            'relatorio_limite_xlsx' => '50000',
            // Console SQL e banco de dados
            'sql_perfis'          => [],
            'sql_usuarios'        => [],
            'sql_escrita'         => '0',
            'sql_limite_linhas'   => '1000',
            'sql_tempo_limite'    => '60',
            'historico_dias'      => '90',
            'exportacoes_dias'    => '15',
        ];
    }

    private static ?array $pesquisadorCache = null;

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        if (self::$pesquisadorCache === null) {
            self::$pesquisadorCache = [];
            if ($DB->tableExists(self::TABELA)) {
                foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => self::TABELA]) as $row) {
                    self::$pesquisadorCache[$row['name']] = $row['value'];
                }
            }
        }
        if (array_key_exists($name, self::$pesquisadorCache)) {
            return self::$pesquisadorCache[$name];
        }
        if ($default === null) {
            $padrao = self::padroes()[$name] ?? null;
            return is_array($padrao) ? json_encode($padrao) : $padrao;
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            $ok = (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        } else {
            $ok = (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
        }
        self::$pesquisadorCache = null;
        return $ok;
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value)));
    }

    public static function inteiro(string $name, int $min, int $max): int
    {
        return max($min, min($max, (int) self::getConfig($name)));
    }

    // =====================================================================
    // Acesso
    // =====================================================================

    /** Administradores sempre; demais pelos perfis e usuários liberados no módulo */
    public static function acesso(string $modulo): bool
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0 || !isset(self::MODULOS[$modulo])) {
            return false;
        }
        if (self::ehAdmin()) {
            return true;
        }
        [, , , $perfis, $usuarios] = self::MODULOS[$modulo];
        $perfil = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        return in_array($perfil, array_map('intval', self::getArrayConfig($perfis)), true)
            || in_array($uid, array_map('intval', self::getArrayConfig($usuarios)), true);
    }

    /** Busca textual (nome mantido da 2.0) */
    public static function temAcesso(): bool
    {
        return self::acesso('busca');
    }

    /** Algum módulo liberado (menu) */
    public static function algumAcesso(): bool
    {
        foreach (array_keys(self::MODULOS) as $m) {
            if (self::acesso($m)) {
                return true;
            }
        }
        return false;
    }

    /** Comandos que alteram o banco: só administradores e só se liberados na configuração */
    public static function podeEscrever(): bool
    {
        return self::ehAdmin() && (string) self::getConfig('sql_escrita') === '1';
    }

    /**
     * Tipos que o usuário pode pesquisar: itemtype => 'todos' (vê tudo das entidades ativas)
     * ou 'ator' (só onde ele ou um grupo dele é ator).
     */
    public static function tiposPermitidos(): array
    {
        $tipos = [];
        $regras = [
            'Ticket'  => [Ticket::READALL, [Ticket::READMY, Ticket::READGROUP, Ticket::READASSIGN]],
            'Problem' => [Problem::READALL, [Problem::READMY]],
            'Change'  => [Change::READALL, [Change::READMY]],
        ];
        foreach ($regras as $tipo => [$todos, $proprios]) {
            $direito = self::TIPOS[$tipo]['direito'];
            if (Session::haveRight($direito, $todos)) {
                $tipos[$tipo] = 'todos';
            } elseif (Session::haveRightsOr($direito, $proprios)) {
                $tipos[$tipo] = 'ator';
            }
        }
        return $tipos;
    }

    /** Origens privadas que o usuário pode ler */
    public static function privadosPermitidos(): array
    {
        $fontes = [];
        if (Session::haveRight('followup', ITILFollowup::SEEPRIVATE)) {
            $fontes[] = 'acompanhamento';
            $fontes[] = 'anexo';
        }
        if (Session::haveRight('task', CommonITILTask::SEEPRIVATE)) {
            $fontes[] = 'tarefa';
        }
        return $fontes;
    }

    // =====================================================================
    // Listas
    // =====================================================================

    public static function listarPerfis(): array
    {
        global $DB;
        $lista = [];
        // glpi_profiles não tem is_deleted
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $r) {
            $lista[(int) $r['id']] = (string) $r['name'];
        }
        return $lista;
    }

    public static function listarUsuarios(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'firstname', 'realname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => ['firstname ASC', 'realname ASC', 'name ASC'],
        ]) as $u) {
            $nome = trim(trim((string) $u['firstname']) . ' ' . trim((string) $u['realname']));
            $lista[(int) $u['id']] = $nome !== '' ? $nome . ' (' . $u['name'] . ')' : (string) $u['name'];
        }
        return $lista;
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/pesquisador/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** URL de arquivo de public/ (o GLPI 11/12 serve public/ em /plugins/<nome>/), com versão e data do arquivo */
    public static function urlAsset(string $caminho): string
    {
        global $CFG_GLPI;
        $arquivo = dirname(__DIR__) . '/public/' . $caminho;
        return $CFG_GLPI['root_doc'] . '/plugins/pesquisador/' . $caminho . '?v=' . PLUGIN_PESQUISADOR_VERSION . '-' . (is_file($arquivo) ? filemtime($arquivo) : 0);
    }

    /** CSS e scripts: pesquisador.js (comum) sempre, depois os do módulo */
    public static function assets(array $scripts = []): string
    {
        $h = '<link rel="stylesheet" href="' . self::e(self::urlAsset('css/pesquisador.css')) . '">'
            . '<script src="' . self::e(self::urlAsset('js/pesquisador.js')) . '"></script>';
        foreach ($scripts as $s) {
            $h .= '<script src="' . self::e(self::urlAsset('js/' . $s . '.js')) . '"></script>';
        }
        return $h;
    }

    /** Abertura das páginas do plugin: cabeçalho nativo com breadcrumb e abas dos módulos */
    public static function cabecalho(string $modulo, string $titulo = ''): void
    {
        Html::header($titulo !== '' ? $titulo : self::MODULOS[$modulo][0], $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginPesquisadorMenu', $modulo);
        echo '<ul class="nav nav-tabs pesquisador-modulos">';
        foreach (self::MODULOS as $m => [$rotulo, $icone, $pagina]) {
            if (self::acesso($m)) {
                echo '<li class="nav-item"><a class="nav-link' . ($m === $modulo ? ' active' : '') . '" href="' . self::e(self::url($pagina)) . '"><i class="' . $icone . '"></i> ' . self::e($rotulo) . '</a></li>';
            }
        }
        if (self::ehAdmin()) {
            echo '<li class="nav-item ms-auto"><a class="nav-link' . ($modulo === 'config' ? ' active' : '') . '" href="' . self::e(self::url('config.form.php')) . '"><i class="ti ti-settings"></i> Configuração</a></li>';
        }
        echo '</ul>';
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    /** Multiselect com pesquisa, marcar todos e selecionados primeiro */
    public static function multiselect(string $name, array $opcoes, array $selecionados, string $placeholder = 'Selecione...'): string
    {
        $selecionados = array_map('strval', $selecionados);
        $marcados = [];
        $demais = [];
        foreach ($opcoes as $id => $rotulo) {
            if (in_array((string) $id, $selecionados, true)) {
                $marcados[$id] = $rotulo;
            } else {
                $demais[$id] = $rotulo;
            }
        }
        asort($marcados, SORT_NATURAL | SORT_FLAG_CASE);
        asort($demais, SORT_NATURAL | SORT_FLAG_CASE);

        $h = '<div class="pesquisador-ms" data-pesquisador-ms data-nome="' . self::e($name) . '" data-placeholder="' . self::e($placeholder) . '">';
        $h .= '<input type="hidden" name="' . self::e($name) . '[]" value="-1">';
        $h .= '<button type="button" class="pesquisador-ms-cabecalho form-select form-select-sm" data-pesquisador-ms-abrir><span class="pesquisador-ms-texto"></span></button>';
        $h .= '<div class="pesquisador-ms-dropdown" hidden>';
        $h .= '<div class="pesquisador-ms-topo"><input type="text" class="form-control form-control-sm pesquisador-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>';
        $h .= '<label class="pesquisador-ms-todos"><input type="checkbox" class="pesquisador-check" data-pesquisador-ms-todos> Marcar/desmarcar todos</label>';
        $h .= '<div class="pesquisador-ms-opcoes">';
        foreach ($marcados + $demais as $id => $rotulo) {
            $marcado = isset($marcados[$id]);
            $h .= '<label class="pesquisador-ms-opcao' . ($marcado ? ' selected' : '') . '" data-label="' . self::e(mb_strtolower((string) $rotulo)) . '">'
                . '<input type="checkbox" class="pesquisador-check" name="' . self::e($name) . '[]" value="' . self::e($id) . '"' . ($marcado ? ' checked' : '') . '>'
                . '<span>' . self::e($rotulo) . '</span></label>';
        }
        $h .= '</div></div><div class="pesquisador-ms-contador"></div></div>';
        return $h;
    }

    public static function idsPost(string $campo): array
    {
        $ids = array_map('intval', (array) ($_POST[$campo] ?? []));
        return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
    }

    /** Pasta de arquivos do plugin em files/_plugins/pesquisador (fora da web) */
    public static function pasta(string $sub = ''): string
    {
        $p = GLPI_PLUGIN_DOC_DIR . '/pesquisador' . ($sub !== '' ? '/' . trim($sub, '/') : '');
        if (!is_dir($p)) {
            @mkdir($p, 0755, true);
        }
        return $p;
    }

    public static function tamanho(float $bytes): string
    {
        $un = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($un) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, $i === 0 ? 0 : 1, ',', '.') . ' ' . $un[$i];
    }
}
