# Pesquisador para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

Quatro ferramentas num só plugin, em *Ferramentas → Pesquisador*: **busca textual**, **relatório de chamados com SLA**, **console SQL** e **banco de dados**. Ele reúne os antigos plugins "sql" e "consulta".

## O que o plugin faz

### Busca textual
- Procura em **chamados, problemas e mudanças**, em tudo: título, descrição, acompanhamentos, soluções, tarefas, validações e nomes de anexos.
- **Sintaxe:**
  - termos soltos, que precisam todos aparecer;
  - `"frase exata"`;
  - `-termo` ou `-"frase"` para excluir;
  - `#123` para achar pelo número.
- Ignora acentos e maiúsculas.
- **Índice próprio** (FULLTEXT) numa tabela do plugin:
  - atualizado na hora pelos hooks do GLPI;
  - construído e revisado em lotes pela ação automática, que também pega o que outros plugins gravaram direto no banco.
- **Respeita a visibilidade:** entidades ativas, lixeira, direitos de ver todos ou só os próprios, e direito de ver itens privados.
- Os filtros ficam no endereço da página, então dá para compartilhar o link e voltar a ele.

### Relatório de chamados
- **Filtros:** período, datas, status, tipo, prioridade, categoria, entidades, atores, grupos e **situação do SLA**.
- **31 colunas** à escolha, com paginação e ordenação no servidor.
- **SLA** calculado pelos prazos do próprio GLPI, com **contadores clicáveis**.
- **Relatórios salvos** por pessoa.
- **Exportação CSV ou XLSX** completa.

### Console SQL
- Separa e **classifica os comandos** (leitura ou alteração).
- Roda numa **conexão própria somente leitura** por padrão, com **tempo limite** e limite de linhas.
- **Comandos de alteração** só para administradores, com a opção ligada na configuração e **confirmação** na tela. Todos ficam registrados.
- **Histórico**, **consultas salvas**, lista de tabelas e colunas.
- Exportação do resultado em CSV, XLSX ou JSON.

### Banco de dados
- **Lista de tabelas**, com **estrutura** (colunas, índices, CREATE) e **dados**: paginação, ordenação e filtro por coluna.
- **Salvar tabelas inteiras**, como o "Exportar" do phpMyAdmin:
  - formatos SQL (estrutura e/ou dados), CSV, JSON ou XLSX;
  - sem compressão, **gzip** ou **zip**;
  - download direto ou arquivo **guardado no servidor**;
  - os dados são lidos em fluxo, sem carregar a tabela inteira na memória.

## Configuração

- **Acesso por módulo**, por perfis e usuários. Os administradores sempre têm acesso.
- **Busca:** limite por tipo, itens por página, busca parcial padrão e **reindexação** em lotes com barra de progresso.
- **Relatório:** colunas padrão e limite do XLSX.
- **Console:** liberar comandos de alteração, limite de linhas e tempo limite.
- **Retenção** do histórico e dos arquivos exportados.

---

## Download e instalação

1. Baixe o arquivo `pesquisador-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/pesquisador/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/pesquisador
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install pesquisador -u <usuário administrador>
   php bin/console plugin:activate pesquisador
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/pesquisador` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install pesquisador -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).