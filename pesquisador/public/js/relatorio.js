/* Plugin Pesquisador - relatório de chamados: filtros na URL, tabela paginada e ordenável no servidor,
 * contadores de SLA clicáveis, escolha de colunas, relatórios salvos e exportação CSV/XLSX. */
(function () {
    'use strict';

    var iniciar = function () {
        var raiz = document.querySelector('[data-pesquisador-relatorio]');
        if (!raiz || !window.Pesquisador) {
            return;
        }
        var P = window.Pesquisador;
        var esc = P.esc;
        var form = raiz.querySelector('[data-pesquisador-rel-form]');
        var status = raiz.querySelector('[data-pesquisador-rel-status]');
        var area = raiz.querySelector('[data-pesquisador-rel-resultados]');
        var contadores = raiz.querySelector('[data-pesquisador-contadores]');
        var listas = ['status', 'tipos', 'prioridades', 'categorias', 'entidades', 'requerentes', 'tecnicos', 'grupos_atribuidos', 'grupos_observadores', 'sla_tto', 'sla_ttr'];
        var salvos = [];
        var pedido = 0;

        var campo = function (nome) {
            return form.querySelector('[name="' + nome + '"]');
        };
        var ms = function (nome) {
            return form.querySelector('[data-pesquisador-ms][data-nome="' + nome + '"]');
        };
        var colunasMarcadas = function () {
            return Array.from(form.querySelectorAll('input[name="colunas[]"]:checked')).map(function (c) { return c.value; });
        };

        /** Filtros atuais como objeto (para salvar) e como parâmetros (URL e AJAX) */
        var filtros = function () {
            var f = {
                periodo: campo('periodo').value,
                campo_data: campo('campo_data').value,
                de: campo('de') ? campo('de').value : '',
                ate: campo('ate') ? campo('ate').value : '',
                busca: campo('busca').value.trim(),
                subentidades: campo('subentidades').checked ? 1 : 0,
                situacao: campo('situacao').value,
                ordem: campo('ordem').value,
                direcao: campo('direcao').value,
                colunas: colunasMarcadas()
            };
            listas.forEach(function (l) { f[l] = P.valoresMs(ms(l)); });
            return f;
        };
        var parametros = function () {
            var f = filtros();
            var p = new URLSearchParams();
            Object.keys(f).forEach(function (k) {
                if (Array.isArray(f[k])) {
                    f[k].forEach(function (v) { p.append(k + '[]', v); });
                } else if (f[k] !== '' && !(k === 'subentidades' && f[k] === 1) && !(k === 'campo_data' && f[k] === 'date')
                    && !(k === 'ordem' && f[k] === 'id') && !(k === 'direcao' && f[k] === 'desc')) {
                    p.set(k, f[k]);
                }
            });
            if (!f.subentidades) {
                p.set('subentidades', '0');
            }
            if (f.periodo !== 'datas') {
                p.delete('de');
                p.delete('ate');
            }
            var pagina = parseInt(campo('pagina').value, 10) || 1;
            if (pagina > 1) {
                p.set('pagina', String(pagina));
            }
            p.set('por_pagina', campo('por_pagina').value);
            return p;
        };

        var aplicar = function (f) {
            campo('periodo').value = f.periodo || '';
            campo('campo_data').value = f.campo_data || 'date';
            P.definirData(form, 'de', (f.de || '').substring(0, 10));
            P.definirData(form, 'ate', (f.ate || '').substring(0, 10));
            campo('busca').value = f.busca || '';
            campo('subentidades').checked = f.subentidades === undefined ? true : !!Number(f.subentidades);
            campo('situacao').value = f.situacao || '';
            campo('ordem').value = f.ordem || 'id';
            campo('direcao').value = f.direcao || 'desc';
            listas.forEach(function (l) { P.definirMs(ms(l), f[l] || []); });
            if (f.colunas && f.colunas.length) {
                definirColunas(f.colunas);
            }
            mostrarDatas();
        };

        var mostrarDatas = function () {
            raiz.querySelector('[data-pesquisador-datas]').hidden = campo('periodo').value !== 'datas';
        };

        // ------------------------------------------------------------ resultado

        var contar = function (c) {
            contadores.querySelectorAll('[data-chave]').forEach(function (b) {
                b.querySelector('.pesquisador-contador-num').textContent = P.numero(c[b.dataset.chave] || 0);
                b.classList.toggle('active', b.dataset.situacao === campo('situacao').value && (b.dataset.situacao !== '' || campo('situacao').value === ''));
            });
        };

        var desenhar = function (r) {
            area.classList.remove('pesquisador-esmaecido');
            if (!r.success) {
                status.innerHTML = '';
                area.innerHTML = '<div class="pesquisador-alerta pesquisador-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>' + esc(r.mensagem || 'Não foi possível gerar o relatório.') + '</span></div>';
                return;
            }
            contar(r.contagem || {});
            var sit = campo('situacao').value;
            var rotuloSit = sit ? contadores.querySelector('[data-situacao="' + sit + '"] .pesquisador-contador-rotulo') : null;
            status.innerHTML = '<span><strong>' + P.numero(r.total) + '</strong> ' + (r.total === 1 ? 'chamado' : 'chamados') + ' em ' + String(r.tempo).replace('.', ',') + ' s</span>'
                + (rotuloSit ? '<span class="pesquisador-termo">' + esc(rotuloSit.textContent) + ' <a href="#" data-pesquisador-sem-situacao title="Mostrar todos"><i class="ti ti-x"></i></a></span>' : '')
                + '<span class="pesquisador-acoes ms-auto"><button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-exportar="csv"' + (r.total ? '' : ' disabled') + '><i class="ti ti-file-type-csv"></i><span>Exportar CSV</span></button>'
                + '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-exportar="xlsx"' + (r.total ? '' : ' disabled') + '><i class="ti ti-file-spreadsheet"></i><span>Exportar XLSX</span></button></span>';
            if (!r.linhas.length) {
                area.innerHTML = '<div class="card pesquisador-card"><div class="card-body pesquisador-vazio"><i class="ti ti-mood-empty"></i><div><strong>Nenhum chamado com esses filtros.</strong><p class="mb-0">Amplie o período ou retire filtros.</p></div></div></div>';
                return;
            }
            var ordem = campo('ordem').value;
            var dir = campo('direcao').value;
            var cab = r.colunas.map(function (c) {
                if (!c.ordenavel) {
                    return '<th>' + esc(c.rotulo) + '</th>';
                }
                var ativo = c.chave === ordem;
                return '<th class="pesquisador-ordenavel' + (ativo ? ' sorted-' + dir : '') + '" data-ordenar="' + esc(c.chave) + '" title="Ordenar">' + esc(c.rotulo) + '</th>';
            }).join('');
            var linhas = r.linhas.map(function (l) {
                return '<tr>' + r.colunas.map(function (c) {
                    return '<td class="pesquisador-rel-' + esc(c.chave) + '">' + (l.celulas[c.chave] === null || l.celulas[c.chave] === undefined ? '' : l.celulas[c.chave]) + '</td>';
                }).join('') + '</tr>';
            }).join('');
            area.innerHTML = '<div class="card pesquisador-card"><div class="table-responsive pesquisador-rel-caixa"><table class="table table-sm table-hover pesquisador-tabela pesquisador-rel-tabela">'
                + '<thead><tr>' + cab + '</tr></thead><tbody>' + linhas + '</tbody></table></div>'
                + '<div class="pesquisador-rodape"><span class="pesquisador-pequeno">Página ' + r.pagina + ' de ' + r.paginas + '</span>'
                + P.paginacao(r.pagina, r.paginas) + P.porPagina(campo('por_pagina').value, [25, 50, 100, 200, 500]) + '</div></div>';
        };

        var gerar = function (registrar) {
            var p = parametros();
            if (registrar) {
                var url = window.location.pathname + '?' + p.toString();
                if (registrar === 'push') {
                    window.history.pushState(null, '', url);
                } else {
                    window.history.replaceState(null, '', url);
                }
            }
            var meu = ++pedido;
            status.innerHTML = '<span class="pesquisador-giro"></span> Gerando relatório...';
            area.classList.add('pesquisador-esmaecido');
            P.pedir(raiz, 'rel_listar', p, 'GET').then(function (r) {
                if (meu === pedido) {
                    desenhar(r);
                }
            });
        };

        var reiniciarPagina = function () {
            campo('pagina').value = '1';
        };

        // ------------------------------------------------------------ colunas

        var caixaColunas = raiz.querySelector('[data-pesquisador-colunas]');
        var listaColunas = caixaColunas.querySelector('.pesquisador-colunas-lista');
        var definirColunas = function (lista) {
            var opcoes = listaColunas.querySelector('.pesquisador-ms-opcoes');
            var todas = Array.from(opcoes.querySelectorAll('.pesquisador-ms-opcao'));
            todas.forEach(function (o) {
                var c = o.querySelector('input');
                c.checked = lista.indexOf(c.value) >= 0;
                o.classList.toggle('selected', c.checked);
            });
            todas.sort(function (a, b) {
                var ia = lista.indexOf(a.querySelector('input').value);
                var ib = lista.indexOf(b.querySelector('input').value);
                return (ia < 0 ? 999 : ia) - (ib < 0 ? 999 : ib);
            }).forEach(function (o) { opcoes.appendChild(o); });
            caixaColunas.querySelector('[data-qtd]').textContent = String(lista.length);
        };
        caixaColunas.querySelector('[data-pesquisador-colunas-abrir]').addEventListener('click', function () {
            listaColunas.hidden = !listaColunas.hidden;
        });
        document.addEventListener('click', function (e) {
            if (!caixaColunas.contains(e.target)) {
                listaColunas.hidden = true;
            }
        });
        listaColunas.addEventListener('change', function (e) {
            if (e.target.name === 'colunas[]') {
                e.target.closest('.pesquisador-ms-opcao').classList.toggle('selected', e.target.checked);
                if (!colunasMarcadas().length) {
                    e.target.checked = true;
                    e.target.closest('.pesquisador-ms-opcao').classList.add('selected');
                    P.aviso('Deixe pelo menos uma coluna.', true);
                }
                // Coluna marcada vai para o fim das visíveis
                definirColunas(colunasMarcadas().filter(function (v) { return v !== e.target.value; }).concat(e.target.checked ? [e.target.value] : []));
                gerar('replace');
            }
        });
        caixaColunas.querySelector('[data-pesquisador-colunas-padrao]').addEventListener('click', function () {
            definirColunas(JSON.parse(raiz.querySelector('[data-pesquisador-colunas-padrao-lista]').textContent));
            gerar('replace');
        });

        // ------------------------------------------------------------ relatórios salvos

        var escolher = raiz.querySelector('[data-pesquisador-salvo-escolher]');
        var btnExcluir = raiz.querySelector('[data-pesquisador-salvo-excluir]');
        var mostrarSalvos = function (lista, selecionado) {
            salvos = lista || [];
            escolher.innerHTML = '<option value="">' + (salvos.length ? 'Relatórios salvos (' + salvos.length + ')' : 'Nenhum relatório salvo') + '</option>'
                + salvos.map(function (s) { return '<option value="' + s.id + '"' + (String(s.id) === String(selecionado) ? ' selected' : '') + '>' + esc(s.nome) + '</option>'; }).join('');
            btnExcluir.hidden = !escolher.value;
        };
        escolher.addEventListener('change', function () {
            btnExcluir.hidden = !escolher.value;
            var s = salvos.filter(function (x) { return String(x.id) === escolher.value; })[0];
            if (s) {
                aplicar(s.filtros);
                reiniciarPagina();
                gerar('push');
            }
        });
        raiz.querySelector('[data-pesquisador-salvo-salvar]').addEventListener('click', function (e) {
            var botao = e.currentTarget;
            var atual = salvos.filter(function (x) { return String(x.id) === escolher.value; })[0];
            var caixa = raiz.querySelector('[data-pesquisador-salvo-nome]');
            if (!caixa) {
                caixa = document.createElement('span');
                caixa.className = 'pesquisador-salvo-nome';
                caixa.setAttribute('data-pesquisador-salvo-nome', '');
                caixa.innerHTML = '<input type="text" class="form-control form-control-sm" maxlength="100" placeholder="Nome do relatório">'
                    + '<button type="button" class="btn btn-sm pesquisador-btn-principal" data-ok><i class="ti ti-check"></i></button>'
                    + '<button type="button" class="btn btn-sm btn-ghost-secondary" data-nao><i class="ti ti-x"></i></button>';
                botao.parentNode.insertBefore(caixa, botao);
                caixa.querySelector('[data-nao]').addEventListener('click', function () { caixa.remove(); botao.hidden = false; });
                var enviar = function () {
                    var nome = caixa.querySelector('input').value.trim();
                    if (!nome) {
                        P.aviso('Informe um nome para o relatório.', true);
                        return;
                    }
                    P.pedir(raiz, 'rel_salvar', { nome: nome, filtros: JSON.stringify(filtros()) }, 'POST').then(function (r) {
                        P.aviso(r.mensagem || '', !r.success);
                        if (r.success) {
                            caixa.remove();
                            botao.hidden = false;
                            mostrarSalvos(r.salvos, r.id);
                        }
                    });
                };
                caixa.querySelector('[data-ok]').addEventListener('click', enviar);
                caixa.querySelector('input').addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        enviar();
                    }
                });
            }
            botao.hidden = true;
            caixa.querySelector('input').value = atual ? atual.nome : '';
            caixa.querySelector('input').focus();
        });
        btnExcluir.addEventListener('click', function () {
            if (!escolher.value || !P.confirmado(btnExcluir, 'Excluir o relatório salvo')) {
                return;
            }
            P.pedir(raiz, 'rel_excluir', { id: escolher.value }, 'POST').then(function (r) {
                P.aviso(r.mensagem || '', !r.success);
                if (r.success) {
                    mostrarSalvos(r.salvos, '');
                }
            });
        });
        P.pedir(raiz, 'rel_salvos', {}, 'GET').then(function (r) {
            if (r.success) {
                mostrarSalvos(r.salvos, '');
            }
        });

        // ------------------------------------------------------------ eventos

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            reiniciarPagina();
            gerar('push');
        });
        campo('periodo').addEventListener('change', mostrarDatas);

        contadores.addEventListener('click', function (e) {
            var b = e.target.closest('[data-situacao]');
            if (!b) {
                return;
            }
            campo('situacao').value = campo('situacao').value === b.dataset.situacao ? '' : b.dataset.situacao;
            reiniciarPagina();
            gerar('push');
        });

        status.addEventListener('click', function (e) {
            if (e.target.closest('[data-pesquisador-sem-situacao]')) {
                e.preventDefault();
                campo('situacao').value = '';
                reiniciarPagina();
                gerar('push');
                return;
            }
            var exp = e.target.closest('[data-pesquisador-exportar]');
            if (exp) {
                var p = parametros();
                p.delete('pagina');
                p.delete('por_pagina');
                p.set('tipo', 'relatorio');
                p.set('formato', exp.dataset.pesquisadorExportar);
                P.baixar(raiz.dataset.exportar + '?' + p.toString(), 'GET', {}, exp, raiz);
            }
        });

        area.addEventListener('click', function (e) {
            var th = e.target.closest('[data-ordenar]');
            if (th) {
                if (campo('ordem').value === th.dataset.ordenar) {
                    campo('direcao').value = campo('direcao').value === 'asc' ? 'desc' : 'asc';
                } else {
                    campo('ordem').value = th.dataset.ordenar;
                    campo('direcao').value = 'asc';
                }
                reiniciarPagina();
                gerar('replace');
                return;
            }
            var a = e.target.closest('[data-pagina]');
            if (a) {
                e.preventDefault();
                if (!a.closest('.disabled')) {
                    campo('pagina').value = a.dataset.pagina;
                    gerar('push');
                    raiz.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });

        area.addEventListener('change', function (e) {
            if (e.target.matches('[data-pesquisador-por-pagina]')) {
                campo('por_pagina').value = e.target.value;
                reiniciarPagina();
                gerar('push');
            }
        });

        raiz.querySelector('[data-pesquisador-rel-limpar]').addEventListener('click', function () {
            aplicar({ periodo: '', campo_data: 'date', subentidades: 1, colunas: colunasMarcadas() });
            escolher.value = '';
            btnExcluir.hidden = true;
            reiniciarPagina();
            gerar('push');
        });

        window.addEventListener('popstate', function () {
            window.location.reload();
        });

        mostrarDatas();
        gerar(false);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
