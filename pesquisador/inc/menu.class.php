<?php

/**
 * Plugin Pesquisador - item no menu Ferramentas, com um submenu por módulo (breadcrumb nativo)
 */
class PluginPesquisadorMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Pesquisador';
    }

    public static function getMenuName(): string
    {
        return 'Pesquisador';
    }

    public static function getIcon(): string
    {
        return 'ti ti-search';
    }

    public static function canView(): bool
    {
        return PluginPesquisadorConfig::algumAcesso();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getMenuContent()
    {
        $C = PluginPesquisadorConfig::class;
        if (!self::canView()) {
            return false;
        }
        $opcoes = [];
        $inicio = '';
        foreach ($C::MODULOS as $m => [$rotulo, $icone, $pagina]) {
            if (!$C::acesso($m)) {
                continue;
            }
            $url = $C::url($pagina);
            $inicio = $inicio ?: $url;
            $opcoes[$m] = [
                'title' => $rotulo,
                'page'  => $url,
                'icon'  => $icone,
                'links' => ['search' => $url],
            ];
        }
        if ($C::ehAdmin()) {
            $opcoes['config'] = [
                'title' => 'Configuração',
                'page'  => $C::url('config.form.php'),
                'icon'  => 'ti ti-settings',
            ];
        }
        return [
            'title'   => self::getMenuName(),
            'page'    => $inicio,
            'icon'    => self::getIcon(),
            'links'   => ['search' => $inicio],
            'options' => $opcoes,
        ];
    }
}
