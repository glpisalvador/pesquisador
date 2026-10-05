/* Plugin Pesquisador - funções comuns (window.Pesquisador), multiselect, downloads, busca textual
 * e página de configuração (abas e reindexação em lotes). */
(function () {
    'use strict';

    if (window.Pesquisador) {
        return;
    }

    // ------------------------------------------------------------------ utilitários

    var esc = function (t) {
        return String(t === null || t === undefined ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    };

    var lerJson = function (texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = String(texto).match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try {
                    return JSON.parse(m[0]);
                } catch (e2) { /* segue */ }
            }
        }
        return { success: false, mensagem: 'Resposta inválida do servidor.' };
    };

    var aviso = function (mensagem, erro) {
        var fn = erro ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function') {
            fn(mensagem);
        }
    };

    var numero = function (n) {
        return Number(n || 0).toLocaleString('pt-BR');
    };

    /** Token CSRF (só existe no GLPI 11): o da página, renovado a cada resposta, ou o da meta tag */
    var token = function (raiz) {
        if (raiz && raiz.dataset.token) {
            return raiz.dataset.token;
        }
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        return meta ? meta.getAttribute('content') : '';
    };

    var pedir = function (raiz, acao, dados, metodo) {
        var url = raiz.dataset.ajax;
        var opcoes = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (metodo === 'POST') {
            var fd = new FormData();
            fd.append('action', acao);
            Object.keys(dados || {}).forEach(function (k) {
                var v = dados[k];
                if (Array.isArray(v)) {
                    v.forEach(function (x) { fd.append(k + '[]', x); });
                } else if (v !== undefined && v !== null) {
                    fd.append(k, v);
                }
            });
            var t = token(raiz);
            if (t) {
                fd.append('_glpi_csrf_token', t);
                opcoes.headers['X-Glpi-Csrf-Token'] = t;
            }
            opcoes.method = 'POST';
            opcoes.body = fd;
        } else {
            var p = dados instanceof URLSearchParams ? dados : new URLSearchParams();
            if (!(dados instanceof URLSearchParams)) {
                Object.keys(dados || {}).forEach(function (k) {
                    var v = dados[k];
                    if (Array.isArray(v)) {
                        v.forEach(function (x) { p.append(k + '[]', x); });
                    } else if (v !== undefined && v !== null) {
                        p.set(k, v);
                    }
                });
            }
            p.set('action', acao);
            url += '?' + p.toString();
        }
        return fetch(url, opcoes)
            .then(function (r) { return r.text(); })
            .then(function (texto) {
                var r = lerJson(texto);
                if (r.new_token && raiz.dataset.token !== undefined) {
                    raiz.dataset.token = r.new_token;
                }
                return r;
            })
            .catch(function () {
                return { success: false, mensagem: 'Falha de comunicação com o servidor.' };
            });
    };

    var ocupado = function (botao, sim, texto) {
        if (!botao) {
            return;
        }
        var span = botao.querySelector('span');
        if (sim) {
            botao.disabled = true;
            if (span) {
                botao.dataset.textoOriginal = span.textContent;
                span.textContent = texto || 'Aguarde...';
            }
        } else {
            botao.disabled = false;
            if (span && botao.dataset.textoOriginal !== undefined) {
                span.textContent = botao.dataset.textoOriginal;
            }
        }
    };

    /**
     * Download sem sair da página: GET num iframe oculto ou POST de um formulário para ele.
     * O servidor grava o cookie pesquisador_download=<aviso>:ok|erro:mensagem quando começa a enviar.
     */
    var baixar = function (url, metodo, dados, botao, raiz) {
        var nomeAviso = 'd' + Date.now() + Math.floor(Math.random() * 1000);
        var quadro = document.querySelector('iframe[name="pesquisador-download"]');
        if (!quadro) {
            quadro = document.createElement('iframe');
            quadro.name = 'pesquisador-download';
            quadro.hidden = true;
            document.body.appendChild(quadro);
        }
        ocupado(botao, true, 'Gerando...');
        if (metodo === 'POST') {
            var form = document.createElement('form');
            form.method = 'post';
            form.action = url;
            form.target = 'pesquisador-download';
            form.hidden = true;
            var campo = function (n, v) {
                var i = document.createElement('input');
                i.type = 'hidden';
                i.name = n;
                i.value = v;
                form.appendChild(i);
            };
            Object.keys(dados).forEach(function (k) {
                if (Array.isArray(dados[k])) {
                    dados[k].forEach(function (x) { campo(k + '[]', x); });
                } else {
                    campo(k, dados[k]);
                }
            });
            campo('aviso', nomeAviso);
            var t = token(raiz);
            if (t) {
                campo('_glpi_csrf_token', t);
            }
            document.body.appendChild(form);
            form.submit();
            form.remove();
        } else {
            quadro.src = url + (url.indexOf('?') < 0 ? '?' : '&') + 'aviso=' + nomeAviso;
        }
        var inicio = Date.now();
        var vigia = setInterval(function () {
            var bruto = (document.cookie.match(/(?:^|; )pesquisador_download=([^;]*)/) || [])[1] || '';
            var valor = '';
            try {
                valor = decodeURIComponent(bruto);
            } catch (e) {
                valor = '';
            }
            var meu = valor.indexOf(nomeAviso + ':') === 0;
            if (!meu && Date.now() - inicio < 30 * 60 * 1000) {
                return;
            }
            clearInterval(vigia);
            document.cookie = 'pesquisador_download=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
            ocupado(botao, false);
            var estado = meu ? valor.substring(nomeAviso.length + 1) : '';
            if (estado.indexOf('erro:') === 0) {
                aviso(estado.substring(5), true);
            } else if (estado === 'ok') {
                aviso('Arquivo gerado; o download vai começar.');
            }
        }, 700);
    };

    /** Confirmação dentro da página (sem confirm do navegador): o segundo clique em até 4 s confirma */
    var confirmado = function (botao, texto) {
        if (botao.dataset.armado === '1') {
            botao.dataset.armado = '';
            botao.classList.remove('pesquisador-confirmando');
            return true;
        }
        botao.dataset.armado = '1';
        botao.classList.add('pesquisador-confirmando');
        botao.title = texto || 'Clique de novo para confirmar';
        var span = botao.querySelector('span');
        var original = span ? span.textContent : '';
        if (span) {
            span.textContent = 'Confirmar?';
        }
        setTimeout(function () {
            botao.dataset.armado = '';
            botao.classList.remove('pesquisador-confirmando');
            if (span) {
                span.textContent = original;
            }
        }, 4000);
        return false;
    };

    /** Paginação no padrão do GLPI (5 botões numéricos e reticências) */
    var paginacao = function (pagina, paginas) {
        if (paginas <= 1) {
            return '';
        }
        var botao = function (pag, rotulo, ativo, desabilitado) {
            return '<li class="page-item' + (ativo ? ' active' : '') + (desabilitado ? ' disabled' : '') + '"><a class="page-link" href="#" data-pagina="' + pag + '">' + rotulo + '</a></li>';
        };
        var h = '<ul class="pagination pagination-sm mb-0">' + botao(pagina - 1, '<i class="ti ti-chevron-left"></i>', false, pagina <= 1);
        var ini = Math.max(1, pagina - 2);
        var fim = Math.min(paginas, ini + 4);
        ini = Math.max(1, fim - 4);
        if (ini > 1) {
            h += botao(1, '1', false, false) + (ini > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
        }
        for (var i = ini; i <= fim; i++) {
            h += botao(i, String(i), i === pagina, false);
        }
        if (fim < paginas) {
            h += (fim < paginas - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') + botao(paginas, String(paginas), false, false);
        }
        return h + botao(pagina + 1, '<i class="ti ti-chevron-right"></i>', false, pagina >= paginas) + '</ul>';
    };

    var porPagina = function (atual, opcoes) {
        return '<label class="pesquisador-pequeno d-inline-flex align-items-center gap-2 mb-0">Por página <select class="form-select form-select-sm" data-pesquisador-por-pagina>'
            + (opcoes || [25, 50, 100, 200]).map(function (n) { return '<option' + (String(n) === String(atual) ? ' selected' : '') + '>' + n + '</option>'; }).join('')
            + '</select></label>';
    };

    var definirData = function (form, nome, valor) {
        var el = form.querySelector('[name="' + nome + '"]');
        if (!el) {
            return;
        }
        var fp = el._flatpickr || (el.closest('.flatpickr') || {})._flatpickr;
        if (fp) {
            if (valor) {
                fp.setDate(valor, false);
            } else {
                fp.clear();
            }
        } else {
            el.value = valor || '';
        }
    };

    // ------------------------------------------------------------------ multiselect (delegado: vale para a página toda)

    var opcoesMs = function (ms) {
        return Array.from(ms.querySelectorAll('.pesquisador-ms-opcao'));
    };

    var atualizarMs = function (ms) {
        var lista = opcoesMs(ms);
        var marcadas = lista.filter(function (o) { return o.querySelector('input').checked; });
        var texto = ms.querySelector('.pesquisador-ms-texto');
        if (!marcadas.length) {
            texto.textContent = ms.dataset.placeholder || 'Selecione...';
        } else if (marcadas.length <= 2) {
            texto.textContent = marcadas.map(function (o) { return o.querySelector('span').textContent; }).join(', ');
        } else {
            texto.textContent = marcadas.length + ' selecionado(s)';
        }
        ms.classList.toggle('pesquisador-ms-ativo', marcadas.length > 0);
        ms.querySelector('.pesquisador-ms-contador').textContent = marcadas.length + ' de ' + lista.length + ' selecionado(s)';
        var visiveis = lista.filter(function (o) { return !o.hidden; });
        var n = visiveis.filter(function (o) { return o.querySelector('input').checked; }).length;
        var todos = ms.querySelector('[data-pesquisador-ms-todos]');
        todos.checked = visiveis.length > 0 && n === visiveis.length;
        todos.indeterminate = n > 0 && n < visiveis.length;
    };

    var reordenarMs = function (ms) {
        var caixa = ms.querySelector('.pesquisador-ms-opcoes');
        opcoesMs(ms).sort(function (a, b) {
            var ca = a.querySelector('input').checked ? 0 : 1;
            var cb = b.querySelector('input').checked ? 0 : 1;
            return ca !== cb ? ca - cb : a.dataset.label.localeCompare(b.dataset.label, 'pt-BR', { numeric: true });
        }).forEach(function (o) { caixa.appendChild(o); });
    };

    var filtrarMs = function (ms) {
        var termo = ms.querySelector('.pesquisador-ms-busca').value.trim().toLowerCase();
        opcoesMs(ms).forEach(function (o) { o.hidden = termo !== '' && o.dataset.label.indexOf(termo) < 0; });
        atualizarMs(ms);
    };

    /** Marca exatamente os valores informados */
    var definirMs = function (ms, valores) {
        var lista = (valores || []).map(String);
        opcoesMs(ms).forEach(function (o) {
            var c = o.querySelector('input');
            c.checked = lista.indexOf(c.value) >= 0;
            o.classList.toggle('selected', c.checked);
        });
        reordenarMs(ms);
        atualizarMs(ms);
    };

    var valoresMs = function (ms) {
        return opcoesMs(ms).map(function (o) { return o.querySelector('input'); }).filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    };

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('[data-pesquisador-ms-abrir]');
        document.querySelectorAll('[data-pesquisador-ms]').forEach(function (ms) {
            var drop = ms.querySelector('.pesquisador-ms-dropdown');
            if (abrir && ms.contains(abrir)) {
                drop.hidden = !drop.hidden;
                if (!drop.hidden) {
                    ms.querySelector('.pesquisador-ms-busca').focus();
                }
            } else if (!ms.contains(e.target)) {
                drop.hidden = true;
            }
        });
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('.pesquisador-ms-busca')) {
            filtrarMs(e.target.closest('[data-pesquisador-ms]'));
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('.pesquisador-ms-busca')) {
            e.preventDefault();
        }
    });

    document.addEventListener('change', function (e) {
        var ms = e.target.closest('[data-pesquisador-ms]');
        if (!ms) {
            return;
        }
        if (e.target.matches('[data-pesquisador-ms-todos]')) {
            opcoesMs(ms).forEach(function (o) {
                if (!o.hidden) {
                    o.querySelector('input').checked = e.target.checked;
                    o.classList.toggle('selected', e.target.checked);
                }
            });
        } else if (e.target.closest('.pesquisador-ms-opcao')) {
            e.target.closest('.pesquisador-ms-opcao').classList.toggle('selected', e.target.checked);
            var busca = ms.querySelector('.pesquisador-ms-busca');
            if (busca.value) {
                busca.value = '';
                filtrarMs(ms);
            }
        } else {
            return;
        }
        reordenarMs(ms);
        atualizarMs(ms);
    });

    var iniciarMs = function () {
        document.querySelectorAll('[data-pesquisador-ms]').forEach(atualizarMs);
    };

    window.Pesquisador = {
        esc: esc,
        lerJson: lerJson,
        aviso: aviso,
        numero: numero,
        pedir: pedir,
        ocupado: ocupado,
        baixar: baixar,
        confirmado: confirmado,
        paginacao: paginacao,
        porPagina: porPagina,
        definirData: definirData,
        definirMs: definirMs,
        valoresMs: valoresMs,
        atualizarMs: atualizarMs
    };

    // ------------------------------------------------------------------ busca textual

    var iniciarBusca = function () {
        var raiz = document.querySelector('[data-pesquisador]');
        if (!raiz) {
            return;
        }
        var form = raiz.querySelector('[data-pesquisador-form]');
        var status = raiz.querySelector('[data-pesquisador-status]');
        var area = raiz.querySelector('[data-pesquisador-resultados]');
        var pedido = 0;
        var campo = function (nome) {
            return form.querySelector('[name="' + nome + '"]');
        };

        /** Parâmetros atuais do formulário (só o que não é padrão vai para a URL) */
        var parametros = function () {
            var p = new URLSearchParams();
            var fd = new FormData(form);
            p.set('q', (fd.get('q') || '').trim());
            ['tipos', 'fontes'].forEach(function (nome) {
                var marcados = fd.getAll(nome + '[]');
                if (marcados.length !== form.querySelectorAll('input[name="' + nome + '[]"]').length) {
                    if (!marcados.length) {
                        p.append(nome + '[]', '');
                    }
                    marcados.forEach(function (v) { p.append(nome + '[]', v); });
                }
            });
            [['situacao', 'todos'], ['ordem', 'relevancia'], ['de', ''], ['ate', '']].forEach(function (par) {
                var v = (fd.get(par[0]) || '').trim();
                if (v !== '' && v !== par[1]) {
                    p.set(par[0], v);
                }
            });
            if (fd.get('parcial')) {
                p.set('parcial', '1');
            }
            var pagina = parseInt(fd.get('pagina'), 10) || 1;
            if (pagina > 1) {
                p.set('pagina', String(pagina));
            }
            p.set('por_pagina', fd.get('por_pagina') || '25');
            return p;
        };

        /** Preenche o formulário a partir da URL (voltar/avançar do navegador) */
        var preencher = function (p) {
            campo('q').value = p.get('q') || '';
            ['tipos', 'fontes'].forEach(function (nome) {
                var lista = p.getAll(nome + '[]');
                form.querySelectorAll('input[name="' + nome + '[]"]').forEach(function (c) {
                    c.checked = !p.has(nome + '[]') || lista.indexOf(c.value) >= 0;
                });
            });
            campo('situacao').value = p.get('situacao') || 'todos';
            campo('ordem').value = p.get('ordem') || 'relevancia';
            campo('parcial').checked = p.get('parcial') === '1';
            campo('pagina').value = p.get('pagina') || '1';
            if (p.get('por_pagina')) {
                campo('por_pagina').value = p.get('por_pagina');
            }
            definirData(form, 'de', p.get('de'));
            definirData(form, 'ate', p.get('ate'));
        };

        var desenhar = function (r) {
            area.classList.remove('pesquisador-esmaecido');
            if (!r.success) {
                status.innerHTML = '';
                area.innerHTML = '<div class="pesquisador-alerta pesquisador-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>' + esc(r.mensagem || 'Não foi possível pesquisar.') + '</span></div>';
                return;
            }
            var termos = (r.termos.positivos || []).map(function (t) { return '<span class="pesquisador-termo">' + esc(t) + '</span>'; }).join(' ')
                + (r.termos.negativos || []).map(function (t) { return ' <span class="pesquisador-termo pesquisador-termo-fora">-' + esc(t) + '</span>'; }).join('');
            status.innerHTML = '<span><strong>' + numero(r.total) + '</strong> ' + (r.total === 1 ? 'item encontrado' : 'itens encontrados') + ' em ' + String(r.tempo).replace('.', ',') + ' s</span>'
                + '<span class="pesquisador-termos">' + termos + '</span>'
                + (r.limite_atingido ? '<span class="pesquisador-limite"><i class="ti ti-info-circle"></i> Mostrando os ' + numero(r.limite) + ' mais relevantes de cada tipo; acrescente termos ou filtros para refinar.</span>' : '');
            if (!r.itens.length) {
                area.innerHTML = '<div class="card pesquisador-card"><div class="card-body pesquisador-vazio"><i class="ti ti-mood-empty"></i><div><strong>Nenhum item encontrado.</strong>'
                    + '<p class="mb-0">Confira a grafia, retire termos ou filtros' + (campo('parcial').checked ? '' : ', ou ative a <em>Busca parcial</em> para achar trechos no meio das palavras') + '.</p></div></div></div>';
                return;
            }
            var linhas = r.itens.map(function (i) {
                var fontes = i.fontes.map(function (f) {
                    return '<span class="pesquisador-fonte"><i class="' + esc(f.icone) + '"></i>' + esc(f.rotulo) + '</span>';
                }).join('');
                return '<tr>'
                    + '<td class="text-nowrap"><span class="pesquisador-tipo"><i class="' + esc(i.icone) + '"></i>' + esc(i.tipo) + '</span></td>'
                    + '<td class="text-nowrap"><a href="' + esc(i.url) + '">' + i.id + '</a></td>'
                    + '<td class="pesquisador-col-titulo"><a href="' + esc(i.url) + '" class="pesquisador-titulo">' + esc(i.titulo) + '</a>'
                    + (i.trecho ? '<div class="pesquisador-trecho">' + (i.trecho_fonte ? '<span class="pesquisador-trecho-fonte">' + esc(i.trecho_fonte) + ':</span> ' : '') + i.trecho + '</div>' : '')
                    + (fontes ? '<div class="pesquisador-fontes-achadas">' + fontes + '</div>' : '') + '</td>'
                    + '<td class="text-nowrap">' + i.status_html + ' ' + esc(i.status) + '</td>'
                    + '<td>' + esc(i.entidade) + '</td>'
                    + '<td>' + esc(i.categoria) + '</td>'
                    + '<td class="text-nowrap">' + esc(i.abertura) + '</td>'
                    + '<td class="text-nowrap">' + esc(i.atualizado) + '</td></tr>';
            }).join('');
            area.innerHTML = '<div class="card pesquisador-card"><div class="table-responsive"><table class="table table-sm table-hover pesquisador-tabela">'
                + '<thead><tr><th>Tipo</th><th>ID</th><th>Título e trecho encontrado</th><th>Status</th><th>Entidade</th><th>Categoria</th><th>Abertura</th><th>Última atualização</th></tr></thead>'
                + '<tbody>' + linhas + '</tbody></table></div>'
                + '<div class="pesquisador-rodape"><span class="pesquisador-pequeno">Página ' + r.pagina + ' de ' + Math.max(1, r.paginas) + '</span>'
                + paginacao(r.pagina, r.paginas) + porPagina(campo('por_pagina').value) + '</div></div>';
        };

        var pesquisar = function (registrar) {
            var p = parametros();
            if (!p.get('q')) {
                status.innerHTML = '';
                area.innerHTML = '';
                return;
            }
            if (registrar) {
                var url = window.location.pathname + '?' + p.toString();
                if (registrar === 'push') {
                    window.history.pushState(null, '', url);
                } else {
                    window.history.replaceState(null, '', url);
                }
            }
            var meu = ++pedido;
            status.innerHTML = '<span class="pesquisador-giro"></span> Pesquisando...';
            area.classList.add('pesquisador-esmaecido');
            pedir(raiz, 'buscar', p, 'GET').then(function (r) {
                if (meu === pedido) {
                    desenhar(r);
                }
            });
        };

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            campo('pagina').value = '1';
            pesquisar('push');
        });

        area.addEventListener('click', function (e) {
            var a = e.target.closest('[data-pagina]');
            if (!a) {
                return;
            }
            e.preventDefault();
            if (a.closest('.disabled')) {
                return;
            }
            campo('pagina').value = a.dataset.pagina;
            pesquisar('push');
            raiz.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        area.addEventListener('change', function (e) {
            if (e.target.matches('[data-pesquisador-por-pagina]')) {
                campo('por_pagina').value = e.target.value;
                campo('pagina').value = '1';
                pesquisar('push');
            }
        });

        // Filtros refazem a busca na hora (se já houver termos)
        form.addEventListener('change', function (e) {
            if (e.target.name === 'q') {
                return;
            }
            if (campo('q').value.trim()) {
                campo('pagina').value = '1';
                pesquisar('replace');
            }
        });

        raiz.querySelector('[data-pesquisador-ajuda]').addEventListener('click', function () {
            var t = raiz.querySelector('[data-pesquisador-ajuda-texto]');
            t.hidden = !t.hidden;
        });

        raiz.querySelector('[data-pesquisador-limpar]').addEventListener('click', function () {
            form.querySelectorAll('input[name="tipos[]"], input[name="fontes[]"]').forEach(function (c) { c.checked = true; });
            campo('situacao').value = 'todos';
            campo('ordem').value = 'relevancia';
            campo('parcial').checked = false;
            definirData(form, 'de', '');
            definirData(form, 'ate', '');
            campo('pagina').value = '1';
            pesquisar('replace');
        });

        window.addEventListener('popstate', function () {
            preencher(new URLSearchParams(window.location.search));
            pesquisar(false);
        });

        // Construção do índice em andamento: acompanha até terminar
        var avisoIndice = raiz.querySelector('[data-pesquisador-indice]');
        if (avisoIndice) {
            var acompanhar = setInterval(function () {
                pedir(raiz, 'situacao', {}, 'GET').then(function (s) {
                    if (!s.success) {
                        return;
                    }
                    avisoIndice.querySelector('[data-pct]').textContent = s.pct + '%';
                    if (s.completo) {
                        clearInterval(acompanhar);
                        avisoIndice.remove();
                    }
                });
            }, 15000);
        }

        if (campo('q').value.trim()) {
            pesquisar(false);
        }
    };

    // ------------------------------------------------------------------ configuração

    var iniciarConfig = function () {
        var raiz = document.querySelector('[data-pesquisador-config]');
        if (!raiz) {
            return;
        }
        raiz.querySelectorAll('[data-aba]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                raiz.querySelectorAll('[data-aba]').forEach(function (x) { x.classList.toggle('active', x === a); });
                raiz.querySelectorAll('[data-aba-painel]').forEach(function (p) { p.hidden = p.dataset.abaPainel !== a.dataset.aba; });
                raiz.querySelectorAll('[data-pesquisador-aba-atual]').forEach(function (h) { h.value = a.dataset.aba; });
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('aba', a.dataset.aba);
                    window.history.replaceState(null, '', url.toString());
                } catch (err) { /* navegador antigo */ }
            });
        });

        var botao = raiz.querySelector('[data-reindexar]');
        if (!botao) {
            return;
        }
        var barra = raiz.querySelector('[data-barra]');
        var pct = raiz.querySelector('[data-pct]');
        var estado = raiz.querySelector('[data-estado]');
        var mostrar = function (s) {
            barra.style.width = s.pct + '%';
            pct.textContent = s.pct + '%';
            raiz.querySelector('[data-textos]').textContent = numero(s.textos);
            var celulas = raiz.querySelectorAll('[data-feito]');
            Object.keys(s.tipos).forEach(function (k, i) {
                if (celulas[i]) {
                    celulas[i].textContent = numero(s.tipos[k].feito);
                }
            });
            estado.innerHTML = s.completo ? '<span class="pesquisador-selo pesquisador-selo-ok">Índice completo</span>' : '<span class="pesquisador-selo pesquisador-selo-info">Em construção</span>';
        };
        var lote = function (acao) {
            pedir(raiz, acao, {}, 'POST').then(function (r) {
                if (!r.success) {
                    botao.disabled = false;
                    aviso(r.mensagem || 'Falha na reindexação.', true);
                    return;
                }
                mostrar(r);
                if (r.concluido) {
                    botao.disabled = false;
                    botao.querySelector('span').textContent = 'Reindexar tudo';
                    aviso('Índice reconstruído.');
                    return;
                }
                lote('reindexar_lote');
            });
        };
        botao.addEventListener('click', function () {
            if (!confirmado(botao, 'O índice será apagado e reconstruído agora')) {
                return;
            }
            botao.disabled = true;
            botao.querySelector('span').textContent = 'Reindexando...';
            lote('reindexar_inicio');
        });
    };

    var iniciar = function () {
        iniciarMs();
        iniciarBusca();
        iniciarConfig();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
