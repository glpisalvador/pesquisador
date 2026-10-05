/* Plugin Pesquisador - console SQL (execução, confirmação de alterações, resultados, exportação,
 * histórico, consultas salvas, lista de tabelas) e banco de dados (lista, dados, estrutura,
 * salvar tabelas inteiras e arquivos guardados no servidor). */
(function () {
    'use strict';

    var P;
    var esc;

    /** Grade de resultado: valores nulos em cinza, números à direita, ordenação local */
    var grade = function (colunas, linhas) {
        var cab = colunas.map(function (c, i) {
            var nome = typeof c === 'string' ? c : c.nome;
            return '<th class="pesquisador-ordenavel" data-col="' + i + '" title="Ordenar">' + esc(nome) + '</th>';
        }).join('');
        var numeros = colunas.map(function (c) { return typeof c === 'object' && c.numero; });
        var corpo = linhas.map(function (l) {
            return '<tr>' + l.map(function (v, i) {
                if (v === null) {
                    return '<td class="pesquisador-nulo">NULL</td>';
                }
                return '<td' + (numeros[i] ? ' class="text-end"' : '') + '>' + esc(v) + '</td>';
            }).join('') + '</tr>';
        }).join('');
        return '<div class="table-responsive pesquisador-grade"><table class="table table-sm table-striped table-hover pesquisador-tabela pesquisador-tabela-dados mb-0" data-pesquisador-grade>'
            + '<thead class="sticky-top"><tr>' + cab + '</tr></thead><tbody>' + corpo + '</tbody></table></div>';
    };

    /** Ordenação local de uma grade (número, data ou texto) */
    var ordenarLocal = function (th) {
        var tabela = th.closest('table');
        var i = parseInt(th.dataset.col, 10);
        var dir = th.classList.contains('sorted-asc') ? 'desc' : 'asc';
        tabela.querySelectorAll('th').forEach(function (x) { x.classList.remove('sorted-asc', 'sorted-desc'); });
        th.classList.add('sorted-' + dir);
        var corpo = tabela.querySelector('tbody');
        var valor = function (tr) {
            var td = tr.children[i];
            var t = td ? td.textContent : '';
            if (td && td.classList.contains('pesquisador-nulo')) {
                return { n: true, v: -Infinity };
            }
            if (t !== '' && !isNaN(t)) {
                return { n: true, v: parseFloat(t) };
            }
            return { n: false, v: t.toLowerCase() };
        };
        Array.from(corpo.rows).sort(function (a, b) {
            var x = valor(a);
            var y = valor(b);
            var r = x.n && y.n ? x.v - y.v : String(x.v).localeCompare(String(y.v), 'pt-BR', { numeric: true });
            return dir === 'asc' ? r : -r;
        }).forEach(function (tr) { corpo.appendChild(tr); });
    };

    document.addEventListener('click', function (e) {
        var th = e.target.closest('[data-pesquisador-grade] th[data-col]');
        if (th) {
            ordenarLocal(th);
        }
    });

    // =================================================================== console

    var iniciarConsole = function () {
        var raiz = document.querySelector('[data-pesquisador-console]');
        if (!raiz) {
            return;
        }
        var editor = raiz.querySelector('[data-pesquisador-editor]');
        var status = raiz.querySelector('[data-pesquisador-console-status]');
        var area = raiz.querySelector('[data-pesquisador-console-resultados]');
        var confirmacao = raiz.querySelector('[data-pesquisador-confirmacao]');
        var exportar = raiz.querySelector('[data-pesquisador-exportar-resultado]');
        var btnExecutar = raiz.querySelector('[data-pesquisador-executar]');
        var ultimoSql = '';
        var consultas = [];
        var editando = 0;

        /** Inserir texto na posição do cursor */
        var inserir = function (texto) {
            var ini = editor.selectionStart;
            var fim = editor.selectionEnd;
            editor.value = editor.value.substring(0, ini) + texto + editor.value.substring(fim);
            editor.selectionStart = editor.selectionEnd = ini + texto.length;
            editor.focus();
        };

        var desenhar = function (r) {
            area.classList.remove('pesquisador-esmaecido');
            exportar.hidden = true;
            if (r.confirmar) {
                status.innerHTML = '';
                var lista = confirmacao.querySelector('[data-pesquisador-confirmacao-lista]');
                lista.innerHTML = (r.escritas || []).map(function (c) { return '<li><code>' + esc(c) + '</code></li>'; }).join('');
                confirmacao.querySelector('[data-pesquisador-confirmacao-ciente]').checked = false;
                confirmacao.querySelector('[data-pesquisador-confirmacao-executar]').disabled = true;
                confirmacao.hidden = false;
                return;
            }
            status.innerHTML = '<span class="' + (r.success ? '' : 'pesquisador-texto-erro') + '">' + esc(r.mensagem || '') + '</span>';
            var blocos = (r.resultados || []).map(function (res, i) {
                var cab = '<div class="pesquisador-resultado-cab"><code class="pesquisador-resultado-sql" title="' + esc(res.consulta) + '">' + esc(res.consulta) + '</code>'
                    + '<span class="pesquisador-pequeno">' + res.tempo_ms + ' ms</span></div>';
                if (res.erro) {
                    return '<div class="card pesquisador-card pesquisador-resultado">' + cab + '<div class="pesquisador-alerta pesquisador-alerta-perigo m-2"><i class="ti ti-alert-triangle"></i><span>' + esc(res.erro) + '</span></div></div>';
                }
                if (res.colunas) {
                    var info = P.numero(res.total) + ' linha(s)' + (res.cortado ? ' · mostrando as primeiras ' + P.numero(res.limite) + ' (exporte para ver todas)' : '');
                    return '<div class="card pesquisador-card pesquisador-resultado">' + cab
                        + (res.linhas.length ? grade(res.colunas, res.linhas) : '<div class="pesquisador-pequeno p-3">Nenhuma linha.</div>')
                        + '<div class="pesquisador-rodape"><span class="pesquisador-pequeno">' + info + '</span></div></div>';
                }
                return '<div class="card pesquisador-card pesquisador-resultado">' + cab + '<div class="pesquisador-alerta pesquisador-alerta-ok m-2"><i class="ti ti-circle-check"></i><span>'
                    + P.numero(res.afetadas) + ' linha(s) afetada(s)' + (res.insert_id ? ' · último id inserido ' + res.insert_id : '') + (res.avisos ? ' · ' + res.avisos + ' aviso(s)' : '') + '</span></div></div>';
            });
            area.innerHTML = blocos.join('');
            var unico = (r.resultados || []).length === 1 && r.resultados[0].colunas && !r.resultados[0].erro;
            exportar.hidden = !unico;
            if (r.success) {
                carregarHistorico();
            }
        };

        var executar = function (confirmado) {
            var sql = editor.value.trim();
            if (!sql) {
                P.aviso('Digite um comando SQL.', true);
                return;
            }
            ultimoSql = sql;
            confirmacao.hidden = true;
            status.innerHTML = '<span class="pesquisador-giro"></span> Executando...';
            area.classList.add('pesquisador-esmaecido');
            P.ocupado(btnExecutar, true, 'Executando...');
            P.pedir(raiz, 'sql_executar', { sql: sql, confirmado: confirmado ? 1 : 0 }, 'POST').then(function (r) {
                P.ocupado(btnExecutar, false);
                desenhar(r);
            });
        };

        btnExecutar.addEventListener('click', function () { executar(false); });
        raiz.querySelector('[data-pesquisador-limpar-editor]').addEventListener('click', function () {
            editor.value = '';
            editor.focus();
        });
        confirmacao.querySelector('[data-pesquisador-confirmacao-ciente]').addEventListener('change', function (e) {
            confirmacao.querySelector('[data-pesquisador-confirmacao-executar]').disabled = !e.target.checked;
        });
        confirmacao.querySelector('[data-pesquisador-confirmacao-cancelar]').addEventListener('click', function () {
            confirmacao.hidden = true;
        });
        confirmacao.querySelector('[data-pesquisador-confirmacao-executar]').addEventListener('click', function () {
            executar(true);
        });

        exportar.addEventListener('click', function (e) {
            var b = e.target.closest('[data-formato]');
            if (b && ultimoSql) {
                P.baixar(raiz.dataset.exportar, 'POST', { tipo: 'console', formato: b.dataset.formato, sql: ultimoSql }, b, raiz);
            }
        });

        // ------------------------------------------------------------ tabelas
        var arvore = raiz.querySelector('[data-pesquisador-arvore]');
        P.pedir(raiz, 'sql_tabelas', {}, 'GET').then(function (r) {
            if (!r.success) {
                arvore.innerHTML = '<div class="pesquisador-pequeno p-2">' + esc(r.mensagem) + '</div>';
                return;
            }
            arvore.innerHTML = r.tabelas.map(function (t) {
                return '<div class="pesquisador-arvore-item" data-nome="' + esc(t.nome.toLowerCase()) + '">'
                    + '<button type="button" class="pesquisador-arvore-seta" data-pesquisador-abrir-tabela="' + esc(t.nome) + '" title="Colunas"><i class="ti ti-chevron-right"></i></button>'
                    + '<a href="#" class="pesquisador-arvore-nome" data-pesquisador-inserir="' + esc(t.nome) + '" title="≈ ' + P.numero(t.linhas) + ' linhas">' + esc(t.nome) + '</a>'
                    + (t.visao ? '<span class="pesquisador-pequeno">visão</span>' : '') + '<div class="pesquisador-arvore-colunas" hidden></div></div>';
            }).join('');
        });
        raiz.querySelector('[data-pesquisador-tabelas-filtro]').addEventListener('input', function (e) {
            var termo = e.target.value.trim().toLowerCase();
            arvore.querySelectorAll('.pesquisador-arvore-item').forEach(function (i) { i.hidden = termo !== '' && i.dataset.nome.indexOf(termo) < 0; });
        });
        arvore.addEventListener('click', function (e) {
            var ins = e.target.closest('[data-pesquisador-inserir]');
            if (ins) {
                e.preventDefault();
                inserir(ins.dataset.pesquisadorInserir);
                return;
            }
            var seta = e.target.closest('[data-pesquisador-abrir-tabela]');
            if (!seta) {
                return;
            }
            var item = seta.closest('.pesquisador-arvore-item');
            var caixa = item.querySelector('.pesquisador-arvore-colunas');
            caixa.hidden = !caixa.hidden;
            seta.classList.toggle('aberta', !caixa.hidden);
            if (!caixa.hidden && !caixa.dataset.carregado) {
                caixa.innerHTML = '<span class="pesquisador-giro"></span>';
                P.pedir(raiz, 'sql_colunas', { tabela: seta.dataset.pesquisadorAbrirTabela }, 'GET').then(function (r) {
                    caixa.dataset.carregado = '1';
                    caixa.innerHTML = r.success ? r.colunas.map(function (c) {
                        return '<a href="#" class="pesquisador-arvore-coluna" data-pesquisador-inserir="' + esc(c.nome) + '" title="' + esc(c.tipo) + '">'
                            + (c.chave === 'PRI' ? '<i class="ti ti-key"></i>' : '') + esc(c.nome) + ' <small>' + esc(c.tipo) + '</small></a>';
                    }).join('') : esc(r.mensagem);
                });
            }
        });

        // ------------------------------------------------------------ consultas salvas e histórico
        var painelConsultas = raiz.querySelector('[data-pesquisador-subaba-painel="consultas"]');
        var painelHistorico = raiz.querySelector('[data-pesquisador-subaba-painel="historico"]');
        var todos = raiz.querySelector('[data-pesquisador-historico-todos]');

        var mostrarConsultas = function (lista) {
            consultas = lista || [];
            if (!consultas.length) {
                painelConsultas.innerHTML = '<div class="pesquisador-vazio-linha"><i class="ti ti-bookmark-off"></i> Nenhuma consulta salva. Escreva um comando e use <strong>Salvar consulta</strong>.</div>';
                return;
            }
            painelConsultas.innerHTML = '<div class="table-responsive"><table class="table table-sm table-hover pesquisador-tabela mb-0"><thead><tr><th>Nome</th><th>Comando</th><th>Autor</th><th>Atualizada</th><th></th></tr></thead><tbody>'
                + consultas.map(function (c) {
                    return '<tr><td class="text-nowrap"><strong>' + esc(c.nome) + '</strong>' + (c.compartilhada ? ' <span class="pesquisador-selo pesquisador-selo-info" title="Visível para quem usa o console">compartilhada</span>' : '') + '</td>'
                        + '<td><code class="pesquisador-sql-curto" title="' + esc(c.sql) + '">' + esc(c.sql) + '</code></td><td class="text-nowrap">' + esc(c.autor) + '</td><td class="text-nowrap">' + esc(c.data) + '</td>'
                        + '<td class="pesquisador-col-acoes"><span class="pesquisador-icones">'
                        + '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-usar="' + c.id + '" title="Colocar no editor"><i class="ti ti-arrow-up"></i></button>'
                        + '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-executar-salva="' + c.id + '" title="Executar"><i class="ti ti-player-play"></i></button>'
                        + (c.minha || raiz.dataset.admin === '1' ? '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-editar="' + c.id + '" title="Editar nome e comando"><i class="ti ti-edit"></i></button>'
                            + '<button type="button" class="btn btn-sm btn-ghost-danger" data-pesquisador-excluir-consulta="' + c.id + '" title="Excluir"><i class="ti ti-trash"></i><span></span></button>' : '')
                        + '</span></td></tr>';
                }).join('') + '</tbody></table></div>';
        };
        var carregarConsultas = function () {
            P.pedir(raiz, 'sql_consultas', {}, 'GET').then(function (r) {
                if (r.success) {
                    mostrarConsultas(r.consultas);
                }
            });
        };
        var carregarHistorico = function () {
            P.pedir(raiz, 'sql_historico', { todos: todos && todos.checked ? 1 : 0 }, 'GET').then(function (r) {
                if (!r.success) {
                    return;
                }
                if (!r.historico.length) {
                    painelHistorico.innerHTML = '<div class="pesquisador-vazio-linha"><i class="ti ti-history"></i> Nenhum comando executado ainda.</div>';
                    return;
                }
                painelHistorico.innerHTML = '<div class="table-responsive"><table class="table table-sm table-hover pesquisador-tabela mb-0"><thead><tr><th>Data</th>' + (todos && todos.checked ? '<th>Usuário</th>' : '') + '<th>Comando</th><th>Tipo</th><th class="text-end">Linhas</th><th class="text-end">Tempo</th><th></th></tr></thead><tbody>'
                    + r.historico.map(function (h) {
                        return '<tr><td class="text-nowrap">' + esc(h.data) + '</td>' + (todos && todos.checked ? '<td class="text-nowrap">' + esc(h.usuario) + '</td>' : '')
                            + '<td><code class="pesquisador-sql-curto" title="' + esc(h.sql) + '">' + esc(h.sql) + '</code>' + (h.erro ? '<div class="pesquisador-texto-erro pesquisador-pequeno">' + esc(h.erro) + '</div>' : '') + '</td>'
                            + '<td><span class="pesquisador-selo ' + (h.tipo === 'escrita' ? 'pesquisador-selo-aviso">alteração' : 'pesquisador-selo-neutro">leitura') + '</span>' + (h.sucesso ? '' : ' <span class="pesquisador-selo pesquisador-selo-erro">erro</span>') + '</td>'
                            + '<td class="text-end">' + P.numero(h.linhas) + '</td><td class="text-end text-nowrap">' + h.tempo + ' ms</td>'
                            + '<td class="pesquisador-col-acoes"><button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-reusar="' + h.id + '" title="Colocar no editor"><i class="ti ti-arrow-up"></i></button></td></tr>';
                    }).join('') + '</tbody></table></div>';
                painelHistorico.historico = r.historico;
            });
        };

        raiz.querySelectorAll('[data-pesquisador-subaba]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                raiz.querySelectorAll('[data-pesquisador-subaba]').forEach(function (x) { x.classList.toggle('active', x === a); });
                painelConsultas.hidden = a.dataset.pesquisadorSubaba !== 'consultas';
                painelHistorico.hidden = a.dataset.pesquisadorSubaba !== 'historico';
                var rotulo = raiz.querySelector('[data-pesquisador-historico-todos-rotulo]');
                if (rotulo) {
                    rotulo.hidden = painelHistorico.hidden;
                }
            });
        });
        if (todos) {
            todos.addEventListener('change', carregarHistorico);
        }

        var buscarConsulta = function (id) {
            return consultas.filter(function (c) { return String(c.id) === String(id); })[0];
        };
        painelConsultas.addEventListener('click', function (e) {
            var b = e.target.closest('button');
            if (!b) {
                return;
            }
            var c = buscarConsulta(b.dataset.pesquisadorUsar || b.dataset.pesquisadorExecutarSalva || b.dataset.pesquisadorEditar || b.dataset.pesquisadorExcluirConsulta);
            if (!c) {
                return;
            }
            if (b.dataset.pesquisadorUsar || b.dataset.pesquisadorExecutarSalva) {
                editor.value = c.sql;
                editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (b.dataset.pesquisadorExecutarSalva) {
                    executar(false);
                }
            } else if (b.dataset.pesquisadorEditar) {
                editor.value = c.sql;
                abrirSalvar(c);
            } else if (b.dataset.pesquisadorExcluirConsulta && P.confirmado(b, 'Excluir a consulta salva')) {
                P.pedir(raiz, 'sql_excluir', { id: c.id }, 'POST').then(function (r) {
                    P.aviso(r.mensagem || '', !r.success);
                    if (r.success) {
                        mostrarConsultas(r.consultas);
                    }
                });
            }
        });
        painelHistorico.addEventListener('click', function (e) {
            var b = e.target.closest('[data-pesquisador-reusar]');
            if (b && painelHistorico.historico) {
                var h = painelHistorico.historico.filter(function (x) { return String(x.id) === b.dataset.pesquisadorReusar; })[0];
                if (h) {
                    editor.value = h.sql;
                    editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });

        var formSalvar = raiz.querySelector('[data-pesquisador-salvar-form]');
        var abrirSalvar = function (c) {
            editando = c ? c.id : 0;
            formSalvar.querySelector('[data-pesquisador-consulta-nome]').value = c ? c.nome : '';
            formSalvar.querySelector('[data-pesquisador-consulta-compartilhar]').checked = c ? !!c.compartilhada : false;
            formSalvar.hidden = false;
            formSalvar.querySelector('[data-pesquisador-consulta-nome]').focus();
        };
        raiz.querySelector('[data-pesquisador-salvar-consulta]').addEventListener('click', function () {
            if (!editor.value.trim()) {
                P.aviso('Escreva o comando antes de salvar.', true);
                return;
            }
            abrirSalvar(null);
        });
        formSalvar.querySelector('[data-pesquisador-consulta-cancelar]').addEventListener('click', function () {
            formSalvar.hidden = true;
        });
        formSalvar.querySelector('[data-pesquisador-consulta-confirmar]').addEventListener('click', function () {
            P.pedir(raiz, 'sql_salvar', {
                id: editando,
                nome: formSalvar.querySelector('[data-pesquisador-consulta-nome]').value,
                sql: editor.value,
                compartilhada: formSalvar.querySelector('[data-pesquisador-consulta-compartilhar]').checked ? 1 : 0
            }, 'POST').then(function (r) {
                P.aviso(r.mensagem || '', !r.success);
                if (r.success) {
                    formSalvar.hidden = true;
                    mostrarConsultas(r.consultas);
                }
            });
        });

        carregarConsultas();
        carregarHistorico();
        if (editor.value.trim()) {
            executar(false);
        }
    };

    // =================================================================== banco de dados

    /** Formulário de salvamento: campos que dependem do formato, resumo e envio */
    var ligarSalvar = function (raiz, form, tabelasEscolhidas) {
        var formato = form.querySelector('[data-pesquisador-formato]');
        var ajustar = function () {
            form.querySelectorAll('[data-so-sql]').forEach(function (el) { el.hidden = formato.value !== 'sql'; });
            form.querySelectorAll('[data-sem-xlsx]').forEach(function (el) { el.hidden = formato.value === 'xlsx'; });
        };
        formato.addEventListener('change', ajustar);
        ajustar();
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var tabelas = tabelasEscolhidas();
            if (!tabelas.length) {
                P.aviso('Marque pelo menos uma tabela na lista.', true);
                return;
            }
            var dados = { tabelas: tabelas };
            new FormData(form).forEach(function (v, k) {
                if (k !== 'tabelas[]') {
                    dados[k] = v;
                }
            });
            var botao = form.querySelector('button[type="submit"]');
            if (dados.destino === 'servidor') {
                P.ocupado(botao, true, 'Gerando...');
                P.pedir(raiz, 'banco_guardar', dados, 'POST').then(function (r) {
                    P.ocupado(botao, false);
                    P.aviso(r.mensagem || '', !r.success);
                    if (r.success && raiz.mostrarGuardados) {
                        raiz.mostrarGuardados(r.arquivos);
                    }
                });
                return;
            }
            dados.tipo = 'banco';
            delete dados.destino;
            P.baixar(raiz.dataset.exportar, 'POST', dados, botao, raiz);
        });
    };

    var iniciarBanco = function () {
        var raiz = document.querySelector('[data-pesquisador-banco]');
        if (!raiz) {
            return;
        }
        if (raiz.dataset.tabela) {
            iniciarTabela(raiz);
            return;
        }
        var lista = raiz.querySelector('[data-pesquisador-lista-tabelas]');
        var resumo = raiz.querySelector('[data-pesquisador-selecao]');
        var linhas = Array.from(lista.querySelectorAll('tbody tr'));
        var marcadas = function () {
            return linhas.filter(function (tr) { return tr.querySelector('[data-pesquisador-tabela-check]').checked; });
        };
        var atualizarResumo = function () {
            var m = marcadas();
            var tamanho = m.reduce(function (s, tr) { return s + Number(tr.dataset.tamanho); }, 0);
            var qtd = m.reduce(function (s, tr) { return s + Number(tr.dataset.linhas); }, 0);
            var un = ['B', 'KB', 'MB', 'GB'];
            var i = 0;
            while (tamanho >= 1024 && i < un.length - 1) {
                tamanho /= 1024;
                i++;
            }
            var texto = m.length ? m.length + ' tabela(s) marcada(s) · ≈ ' + P.numero(qtd) + ' linhas · ' + tamanho.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' ' + un[i] : 'Nenhuma tabela marcada';
            resumo.textContent = texto;
            var r2 = raiz.querySelector('[data-pesquisador-resumo-salvar]');
            if (r2) {
                r2.textContent = m.length ? texto : '';
            }
            linhas.forEach(function (tr) { tr.classList.toggle('pesquisador-linha-marcada', tr.querySelector('[data-pesquisador-tabela-check]').checked); });
            var visiveis = linhas.filter(function (tr) { return !tr.hidden; });
            var n = visiveis.filter(function (tr) { return tr.querySelector('[data-pesquisador-tabela-check]').checked; }).length;
            var todas = lista.querySelector('[data-pesquisador-todas-tabelas]');
            todas.checked = visiveis.length > 0 && n === visiveis.length;
            todas.indeterminate = n > 0 && n < visiveis.length;
        };
        var tempo = null;
        raiz.querySelector('[data-pesquisador-filtro-tabelas]').addEventListener('input', function (e) {
            clearTimeout(tempo);
            tempo = setTimeout(function () {
                var termo = e.target.value.trim().toLowerCase();
                linhas.forEach(function (tr) { tr.hidden = termo !== '' && tr.dataset.search.indexOf(termo) < 0; });
                atualizarResumo();
            }, 300);
        });
        lista.addEventListener('change', function (e) {
            if (e.target.matches('[data-pesquisador-todas-tabelas]')) {
                linhas.forEach(function (tr) {
                    if (!tr.hidden) {
                        tr.querySelector('[data-pesquisador-tabela-check]').checked = e.target.checked;
                    }
                });
            }
            atualizarResumo();
        });
        raiz.querySelectorAll('[data-pesquisador-marcar]').forEach(function (b) {
            b.addEventListener('click', function () {
                var todasVisiveis = b.dataset.pesquisadorMarcar === 'visiveis';
                linhas.forEach(function (tr) {
                    var c = tr.querySelector('[data-pesquisador-tabela-check]');
                    if (todasVisiveis) {
                        if (!tr.hidden) {
                            c.checked = true;
                        }
                    } else {
                        c.checked = false;
                    }
                });
                atualizarResumo();
            });
        });
        // Ordenação da lista (nome, linhas, tamanho...)
        lista.querySelectorAll('th[data-sort]').forEach(function (th, idx) {
            th.classList.add('pesquisador-ordenavel');
            th.addEventListener('click', function () {
                var col = Array.from(th.parentNode.children).indexOf(th);
                var dir = th.classList.contains('sorted-asc') ? 'desc' : 'asc';
                lista.querySelectorAll('th').forEach(function (x) { x.classList.remove('sorted-asc', 'sorted-desc'); });
                th.classList.add('sorted-' + dir);
                var corpo = lista.querySelector('tbody');
                linhas.sort(function (a, b) {
                    var ca = a.children[col];
                    var cb = b.children[col];
                    var va = ca.dataset.ordem !== undefined ? ca.dataset.ordem : ca.textContent.trim().toLowerCase();
                    var vb = cb.dataset.ordem !== undefined ? cb.dataset.ordem : cb.textContent.trim().toLowerCase();
                    var r = th.dataset.sort === 'numero' ? Number(va) - Number(vb) : String(va).localeCompare(String(vb), 'pt-BR', { numeric: true });
                    return dir === 'asc' ? r : -r;
                }).forEach(function (tr) { corpo.appendChild(tr); });
            });
        });

        var form = raiz.querySelector('[data-pesquisador-salvar-tabelas]');
        ligarSalvar(raiz, form, function () {
            return marcadas().map(function (tr) { return tr.dataset.tabela; });
        });

        // Arquivos guardados no servidor
        var caixa = raiz.querySelector('[data-pesquisador-guardados]');
        var baixarUrl = '';
        raiz.mostrarGuardados = function (arquivos) {
            if (!arquivos.length) {
                caixa.innerHTML = '<div class="pesquisador-vazio-linha"><i class="ti ti-archive-off"></i> Nenhum arquivo guardado. Escolha <strong>Guardar no servidor</strong> ao salvar tabelas.</div>';
                return;
            }
            caixa.innerHTML = '<div class="table-responsive"><table class="table table-sm table-hover pesquisador-tabela mb-0"><thead><tr><th>Arquivo</th><th>Formato</th><th>Tabelas</th><th class="text-end">Linhas</th><th class="text-end">Tamanho</th><th>Gerado por</th><th>Data</th><th></th></tr></thead><tbody>'
                + arquivos.map(function (a) {
                    return '<tr' + (a.existe ? '' : ' class="pesquisador-esmaecido"') + '><td class="pesquisador-mono">' + esc(a.arquivo) + (a.existe ? '' : ' <span class="pesquisador-selo pesquisador-selo-erro">arquivo não encontrado</span>') + '</td>'
                        + '<td class="text-nowrap">' + esc(a.formato) + (a.compressao ? ' · ' + esc(a.compressao) : '') + '</td>'
                        + '<td title="' + esc(a.lista) + '">' + a.tabelas + '</td><td class="text-end">' + P.numero(a.linhas) + '</td><td class="text-end text-nowrap">' + esc(a.tamanho) + '</td>'
                        + '<td class="text-nowrap">' + esc(a.usuario) + '</td><td class="text-nowrap">' + esc(a.data) + '</td>'
                        + '<td class="pesquisador-col-acoes"><span class="pesquisador-icones">'
                        + (a.existe ? '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pesquisador-baixar-guardado="' + a.id + '" title="Baixar"><i class="ti ti-download"></i><span></span></button>' : '')
                        + '<button type="button" class="btn btn-sm btn-ghost-danger" data-pesquisador-excluir-guardado="' + a.id + '" title="Excluir"><i class="ti ti-trash"></i><span></span></button></span></td></tr>';
                }).join('') + '</tbody></table></div>';
        };
        var carregar = function () {
            P.pedir(raiz, 'banco_guardados', {}, 'GET').then(function (r) {
                if (r.success) {
                    baixarUrl = r.baixar;
                    raiz.mostrarGuardados(r.arquivos);
                } else {
                    caixa.innerHTML = '<div class="pesquisador-vazio-linha">' + esc(r.mensagem) + '</div>';
                }
            });
        };
        raiz.querySelector('[data-pesquisador-recarregar-guardados]').addEventListener('click', carregar);
        caixa.addEventListener('click', function (e) {
            var b = e.target.closest('[data-pesquisador-baixar-guardado]');
            if (b) {
                P.baixar(baixarUrl + '&id=' + b.dataset.pesquisadorBaixarGuardado, 'GET', {}, b, raiz);
                return;
            }
            var x = e.target.closest('[data-pesquisador-excluir-guardado]');
            if (x && P.confirmado(x, 'Excluir o arquivo do servidor')) {
                P.pedir(raiz, 'banco_excluir_guardado', { id: x.dataset.pesquisadorExcluirGuardado }, 'POST').then(function (r) {
                    P.aviso(r.mensagem || '', !r.success);
                    if (r.success) {
                        raiz.mostrarGuardados(r.arquivos);
                    }
                });
            }
        });
        carregar();
        atualizarResumo();
    };

    /** Detalhe de uma tabela: dados paginados com filtros por coluna, estrutura e salvar */
    var iniciarTabela = function (raiz) {
        var tabela = raiz.dataset.tabela;
        var area = raiz.querySelector('[data-pesquisador-dados]');
        var estado = { pagina: 1, por_pagina: 25, ordem: '', direcao: 'asc', f: {} };
        var pedido = 0;

        raiz.querySelectorAll('[data-pesquisador-aba]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                raiz.querySelectorAll('[data-pesquisador-aba]').forEach(function (x) { x.classList.toggle('active', x === a); });
                raiz.querySelectorAll('[data-pesquisador-aba-painel]').forEach(function (p) { p.hidden = p.dataset.pesquisadorAbaPainel !== a.dataset.pesquisadorAba; });
            });
        });
        var copiar = raiz.querySelector('[data-pesquisador-copiar]');
        if (copiar) {
            copiar.addEventListener('click', function () {
                var texto = raiz.querySelector('[data-pesquisador-texto="criacao"]').textContent;
                var ok = function () { P.aviso('Copiado.'); };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(texto).then(ok);
                } else {
                    var t = document.createElement('textarea');
                    t.value = texto;
                    document.body.appendChild(t);
                    t.select();
                    document.execCommand('copy');
                    t.remove();
                    ok();
                }
            });
        }

        var carregar = function () {
            var meu = ++pedido;
            area.classList.add('pesquisador-esmaecido');
            var p = new URLSearchParams();
            p.set('tabela', tabela);
            ['pagina', 'por_pagina', 'ordem', 'direcao'].forEach(function (k) { p.set(k, estado[k]); });
            Object.keys(estado.f).forEach(function (k) {
                if (estado.f[k] !== '') {
                    p.set('f[' + k + ']', estado.f[k]);
                }
            });
            P.pedir(raiz, 'banco_dados', p, 'GET').then(function (r) {
                if (meu !== pedido) {
                    return;
                }
                area.classList.remove('pesquisador-esmaecido');
                if (!r.success) {
                    area.innerHTML = '<div class="pesquisador-alerta pesquisador-alerta-perigo m-2"><i class="ti ti-alert-triangle"></i><span>' + esc(r.mensagem) + '</span></div>';
                    return;
                }
                estado.pagina = r.pagina;
                var cab = r.colunas.map(function (c) {
                    return '<th class="pesquisador-ordenavel' + (estado.ordem === c ? ' sorted-' + estado.direcao : '') + '" data-ordenar="' + esc(c) + '">' + esc(c) + '</th>';
                }).join('');
                var filtros = r.colunas.map(function (c) {
                    return '<th class="pesquisador-filtro-coluna"><input type="search" class="form-control form-control-sm" data-filtro="' + esc(c) + '" value="' + esc(estado.f[c] || '') + '" placeholder="filtrar"></th>';
                }).join('');
                var corpo = r.linhas.map(function (l) {
                    return '<tr>' + l.map(function (v) {
                        return v === null ? '<td class="pesquisador-nulo">NULL</td>' : '<td>' + esc(v) + '</td>';
                    }).join('') + '</tr>';
                }).join('');
                var foco = document.activeElement && document.activeElement.dataset ? document.activeElement.dataset.filtro : null;
                area.innerHTML = '<div class="table-responsive pesquisador-grade"><table class="table table-sm table-striped table-hover pesquisador-tabela pesquisador-tabela-dados mb-0">'
                    + '<thead class="sticky-top"><tr>' + cab + '</tr><tr class="noHover">' + filtros + '</tr></thead><tbody>'
                    + (corpo || '<tr><td colspan="' + r.colunas.length + '" class="pesquisador-pequeno">Nenhuma linha.</td></tr>') + '</tbody></table></div>'
                    + '<div class="pesquisador-rodape"><span class="pesquisador-pequeno">' + P.numero(r.total) + ' linha(s) · página ' + r.pagina + ' de ' + r.paginas + '</span>'
                    + P.paginacao(r.pagina, r.paginas) + P.porPagina(estado.por_pagina, [25, 50, 100, 200, 500]) + '</div>';
                if (foco) {
                    var campo = area.querySelector('[data-filtro="' + foco.replace(/"/g, '\\"') + '"]');
                    if (campo) {
                        campo.focus();
                        campo.selectionStart = campo.selectionEnd = campo.value.length;
                    }
                }
            });
        };

        var tempo = null;
        area.addEventListener('input', function (e) {
            if (!e.target.dataset.filtro) {
                return;
            }
            clearTimeout(tempo);
            tempo = setTimeout(function () {
                estado.f[e.target.dataset.filtro] = e.target.value.trim();
                estado.pagina = 1;
                carregar();
            }, 400);
        });
        area.addEventListener('click', function (e) {
            var th = e.target.closest('[data-ordenar]');
            if (th) {
                estado.direcao = estado.ordem === th.dataset.ordenar && estado.direcao === 'asc' ? 'desc' : 'asc';
                estado.ordem = th.dataset.ordenar;
                carregar();
                return;
            }
            var a = e.target.closest('[data-pagina]');
            if (a) {
                e.preventDefault();
                if (!a.closest('.disabled')) {
                    estado.pagina = parseInt(a.dataset.pagina, 10);
                    carregar();
                }
            }
        });
        area.addEventListener('change', function (e) {
            if (e.target.matches('[data-pesquisador-por-pagina]')) {
                estado.por_pagina = parseInt(e.target.value, 10);
                estado.pagina = 1;
                carregar();
            }
        });

        var form = raiz.querySelector('[data-pesquisador-salvar-tabelas]');
        ligarSalvar(raiz, form, function () { return [tabela]; });
        carregar();
    };

    var iniciar = function () {
        P = window.Pesquisador;
        if (!P) {
            return;
        }
        esc = P.esc;
        iniciarConsole();
        iniciarBanco();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
