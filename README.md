# Controle de Materiais

Aplicacao Laravel para arquivamento e consulta de movimentacoes de materiais.

## Requisitos

- PHP 8.2 ou superior com extensoes exigidas pelo Laravel e Composer.
- PDO_MYSQL.
- Extensoes PHP exigidas pelo PhpSpreadsheet (`zip`, `xml`, `xmlreader`,
  `xmlwriter`, `gd`, `mbstring`, `fileinfo` e dependencias relacionadas).
- MySQL 8.0.16+ ou MariaDB 10.2.1+ para suporte efetivo a constraints CHECK.
- Apache ou Nginx; Docker e opcional.

XAMPP nao e requisito. A aplicacao pode ser hospedada em qualquer ambiente PHP
que ofereca Composer, PDO_MYSQL e acesso ao banco MySQL/MariaDB.

## Configuracao local ou no servidor

1. Instale as dependencias: `composer install`.
2. Copie `.env.example` para `.env` e gere a chave: `php artisan key:generate`.
3. Configure `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` e os
   dados de conexao (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
   `DB_PASSWORD`). Em HTTPS, configure tambem `SESSION_SECURE_COOKIE=true`;
   mantenha `SESSION_HTTP_ONLY=true` e `SESSION_SAME_SITE=lax`. Nao versionar o
   `.env` nem colocar segredos em arquivos servidos publicamente.
4. Configure Apache para servir somente `public/`, com `mod_rewrite` e
   `AllowOverride All`; no Nginx, use `public/` como `root`, fallback para
   `/index.php?$query_string` e PHP-FPM.
   No XAMPP com `htdocs` padrao, acesse
   `http://localhost/Dev95/public/`; em instalacao permanente, prefira um
   VirtualHost cujo document root seja `C:\Xampp\htdocs\Dev95\public`.
5. Mantenha `.env`, `vendor/` e o restante do codigo fora do document root.
   Garanta permissao de escrita para PHP apenas em `storage/` e
   `bootstrap/cache/`. Os XLSX originais ficam em `storage/app/private/`;
   inclua esse diretorio nos backups junto com o banco.
6. Execute as migracoes apenas em um banco vazio ou preparado para esta
   aplicacao: `php artisan migrate --force`.
7. Em producao, instale dependencias sem ferramentas de desenvolvimento,
   usando `composer install --no-dev --optimize-autoloader`; gere os assets
   com `npm ci` e `npm run build`, e execute
   `php artisan config:cache`, `php artisan route:cache` e
   `php artisan view:cache` durante a publicacao.

Nao execute `migrate:fresh` em bases com dados. Use um banco separado para os
testes de migracao e nunca compartilhe credenciais de producao com os testes.
Use uma conta SQL exclusiva para a aplicacao, com apenas as permissoes
necessarias. Defina uma rotina de backup do banco e teste periodicamente a
restauracao em uma base separada; nao use comandos que recriem ou limpem a base
de producao como procedimento de recuperacao.
O dump `database/schema/mysql-schema.sql` e uma referencia para reconstruir o
schema em banco descartavel; ele contem `DROP TABLE IF EXISTS`. Nao o execute
em banco existente ou com snapshots. Para instalacao e atualizacao, use
`php artisan migrate --force`.

### Checklist de seguranca para producao

- HTTPS e redirecionamento de HTTP para HTTPS configurados no servidor web ou proxy;
  habilite HSTS nesse ponto somente depois de confirmar HTTPS em todos os
  hosts abrangidos.
- `APP_DEBUG=false`, `APP_ENV=production` e `SESSION_SECURE_COOKIE=true`;
  permissao de escrita limitada a `storage/` e `bootstrap/cache/`.
- O aplicativo adiciona `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy` e `Permissions-Policy` nas respostas HTTP.
- O upload web e os originais arquivados usam o disco privado `local`.
  Mantenha `storage/app/private/` fora do document root e com permissao de
  escrita restrita ao PHP.
- Autenticacao de usuario ainda nao foi implementada. Em `APP_ENV=local`, o
  dashboard e as APIs aceitam acesso sem login somente por loopback
  (`127.0.0.0/8` ou `::1`), sem cabecalhos de proxy encaminhados. Acesse por
  `http://localhost/Dev95/public/` no XAMPP padrao, ou use
  `php artisan serve --host=127.0.0.1` e abra `http://127.0.0.1:8000/`.
- Em qualquer outro ambiente, telas e APIs exigem sessao autenticada. Nunca
  use `APP_ENV=local` como substituto de autenticacao em um servidor publico.

## Datas e fusos

Instantes sao persistidos em UTC em `DATETIME(6)`. A aplicacao converte horarios
para America/Sao_Paulo na exibicao e nas respostas de API.

## Desenvolvimento e validacao

- `php artisan test`
- `composer validate --no-check-publish`
- `php artisan serve` somente para desenvolvimento local.
- Validar uma planilha sem gravar: `php artisan materials:import caminho\arquivo.xlsx --dry-run`
- Importar para o snapshot do dia (fuso America/Sao_Paulo):
  `php artisan materials:import caminho\arquivo.xlsx --date=AAAA-MM-DD`

O upload web aceita apenas `.xlsx`; arquivos com macros (`.xlsm`) nao sao
aceitos nem executados. A validacao e a reconciliacao acontecem antes de gravar
o snapshot; o arquivo original e mantido no disco privado. Snapshots anteriores
ao upload web podem nao ter o binario original armazenado. O comando CLI
`materials:import` continua disponivel, incluindo `--dry-run`.
Em `APP_ENV=local`, o upload sem login e permitido apenas por loopback. Em
outros ambientes, a rota exige sessao autenticada; como o sistema ainda nao
oferece fluxo de login, nao exponha o upload publicamente antes de integrar
autenticacao real.
O limite padrao de arquivo e 10 MiB e pode ser alterado por
`MATERIALS_IMPORT_MAX_KILOBYTES`.
Configure tambem `upload_max_filesize` e `post_max_size` no PHP para aceitar o
limite escolhido (com `post_max_size` ligeiramente maior que
`upload_max_filesize`).

## API do candlestick

Os endpoints sao protegidos por `LocalDashboardAccess`: sem sessao, somente
loopback em `APP_ENV=local`; nos demais ambientes, e exigida autenticacao:

- `GET /api/dashboard/candlestick`: aceita `metric` (`cost`, `records`,
  `distinct_work_orders` ou `scrap_m2`), `period` (`current_month`,
  `previous_month` ou `last_30_days`), `year` + `month`, ou `start_date` +
  `end_date`; filtros opcionais `cost_center`, `movement_type` e `unit`.
- `GET /api/snapshots?date=AAAA-MM-DD`: lista as versoes do dia com horario
  local, arquivo, linhas e custo para o painel de detalhe.
- `GET /api/dashboard/summary`: KPIs calculados pelo ultimo snapshot do periodo
  com os filtros ativos.
- `GET /api/dashboard/snapshots`: lista paginada dos snapshots no periodo.
- `GET /api/dashboard/options`: centros de custo, tipos de movimento e UMs
  disponiveis para os filtros.
- `GET /api/dashboard/units`: metricas por UM, evolucao por snapshot diario e
  composicao por centro de custo.

A resposta do candlestick inclui `date`, `open`, `high`, `low`, `close`,
`variation`, `variation_percent`, `update_count`, `derived` e `doji`.
As metricas por snapshot sao pre-agregadas para as combinacoes de centro de
custo, tipo de movimento e unidade. `quantity_total` permanece nulo quando a
agregacao nao seleciona uma UM; M e MT nunca sao combinados. Dias sem snapshot
nao geram candle. A abertura derivada de snapshot unico usa o fechamento
anterior; sem fechamento anterior, o candle e doji. O `grouping_mask` usa os
bits 1/2/4 para centro/tipo/UM; sobras usam a revisao manual mais recente.

## Painel web

O caminho `/` redireciona diretamente para o Painel de materiais. A navegacao
do header permite abrir o Dashboard. No desenvolvimento local, o loopback pode
acessar o dashboard e as APIs sem login; em outros ambientes e necessaria uma
sessao autenticada.
No XAMPP, acessar `http://localhost/Dev95` redireciona para
`http://localhost/Dev95/public/materials`; a raiz do projeto bloqueia o acesso
direto aos arquivos internos e deixa a pasta `public` atender a aplicacao.
O frontend usa Vite e empacota o Apache
ECharts localmente, sem dependencia de CDN em runtime.
Para preparar os assets em desenvolvimento ou durante a publicacao, instale
Node.js LTS e execute `npm install` seguido de `npm run build`. O servidor PHP
de producao pode servir os assets ja gerados em `public/build`.

Os filtros de periodo, centro, tipo, UM, metrica e paleta ficam na URL para
compartilhamento. KPIs mostram o snapshot mais recente no intervalo; o
candlestick mostra apenas dias com snapshot e o clique abre os arquivos e
versoes daquele dia. O painel permite baixar a ultima versao do dia ou
escolher uma versao especifica.

Os graficos por UM usam o snapshot mais recente do intervalo para barras e
composicao por centro. A linha usa a ultima versao de cada dia com snapshot,
sem somar versoes nem dias; dias sem snapshot nao recebem ponto. Clicar em uma
UM filtra os demais graficos. Cada codigo de UM permanece separado, inclusive
M e MT.

## Exportacao Excel (M6)

`GET /api/snapshots/{id}/export` gera um XLSX para o snapshot escolhido. A rota
e protegida por autenticacao e aceita `cost_center`, `movement_type` e `unit`;
os dados e as sobras sao limitados a esses filtros. No painel, a acao principal
exporta a ultima versao do dia e cada versao listada tambem pode ser exportada
individualmente. A exportacao nao altera snapshots nem revisoes de sobra.

O arquivo tem as abas `Dashboard`, `Dados`, `Aproveitamento Chapa`, `Resumo`,
`Leia-me` e `Listas`; cria as tabelas `DadosMovimentacoes` e `ApontamentoSobra`,
dois graficos e formulas vivas (`SUMIFS`/`COUNTIFS`). Codigos e identificadores
sao texto, as quantidades permanecem associadas a sua UM e a coluna de sobra
usa a revisao manual mais recente. Correcoes de materiais tambem usam a ultima
revisao registrada; a linha originalmente importada permanece preservada.

Checklist de equivalencia verificado:

- [x] Abas, cabecalhos de dados/sobras e nomes das duas tabelas do modelo.
- [x] KPIs, resumos por centro/tipo, formulas, listas e dois graficos.
- [x] Convencao visual: filtros amarelos, entrada manual azul, vinculos verdes
  e formulas pretas.
- [x] XLSX reaberto com PhpSpreadsheet depois de gravado; tabelas, formulas e
  graficos continuam presentes.
- [ ] Fidelidade visual pixel a pixel: nao garantida; o layout e recriado com
  estilos equivalentes, nao copiado do modelo.

Foi testado carregar e salvar o modelo `FINAL_FIL_ATUALIZADO` com
PhpSpreadsheet, mas o arquivo resultante apresentou referencias de desenho
invalidas e nao reabriu. Por isso, o exportador gera a pasta de trabalho do
zero, sem depender de um caminho local do XAMPP/Downloads. O arquivo modelo
original permanece somente leitura.

## Consulta, correcoes e apontamento de sobra

Em `/materials`, o arquivo de origem pode ser pesquisado junto com a data.
Cada snapshot oferece uma tabela de materiais paginada e recolhivel na propria
listagem, exportacao, download do original e exclusao definitiva. A exclusao exige confirmacao no navegador e
remove em transacao o snapshot, linhas importadas, revisoes de movimento,
grupos/apontamentos de sobra e metricas. O XLSX e preparado fora da transacao
SQL e removido apos o commit; uma falha de limpeza e registrada e informada.

Expandir a consulta de um snapshot apresenta todos os campos importados. Uma
correcao acrescenta uma revisao auditavel com operador e horario UTC; nunca
altera a linha importada. Consulta, exportacao, metricas e dashboards usam a
revisao mais recente, enquanto o custo importado no arquivo de snapshots
continua como referencia imutavel. A identidade de um grupo de chapa alterada
por correcao nao transfere a sobra antiga: o grupo anterior fica inativo e o
novo inicia sem apontamento.

`/materials/scrap-pointing` lista somente grupos elegiveis: UM `M2`, descricao
contendo `CHAPA` sem diferenciar caixa e sem `GRADE` ou `DEGRAU`. A sobra e
informada manualmente com o nome do operador; cada envio cria uma revisao com
data/hora. O limite e a soma com sinal das quantidades `M2` elegiveis da
identidade do grupo, limitada inferiormente a zero. Os filtros por arquivo
usam correspondencia parcial parametrizada; como o padrao `%texto%` nao
aproveita indice B-tree, nenhum indice foi adicionado para essa busca.

A migration `2026_11_13_000001_create_snapshot_movement_revisions_and_activate_scraps.php`
adiciona a tabela de revisoes e o estado de elegibilidade dos grupos. Execute
`php artisan migrate` no banco de aplicacao antes de usar esses fluxos. O
`down()` remove esse schema e, portanto, tambem elimina as revisoes criadas;
faca backup antes de reverter apos a entrada de dados.

## Estado

O M1 configura Laravel e o esquema MySQL/MariaDB. O M2 adiciona validacao,
reconciliacao e arquivamento por linha de comando. O M3 calcula metricas por
snapshot e oferece APIs protegidas para candlestick e detalhe do dia. O M4
adiciona o dashboard protegido, o M5 adiciona os graficos por UM e o M6
adiciona a exportacao Excel. O M7 conclui o hardening operacional e a
documentacao de publicacao; a integracao de autenticacao continua pendente e
e requisito antes de exposicao publica.
