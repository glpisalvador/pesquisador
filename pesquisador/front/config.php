<?php

/**
 * Plugin Pesquisador - atalho para a configuração
 */

Session::checkLoginUser();
Html::redirect(PluginPesquisadorConfig::url('config.form.php'));
