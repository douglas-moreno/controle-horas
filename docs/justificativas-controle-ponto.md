# Justificativas do controle de ponto (F1: estrutura e permissões; F2: tela e validação; F3: gravação e efeitos; F4: edição e cancelamento; F5.1: abono no espelho; F5.2: regra alternativa, tela e impressão; F5.3: sugestões de benefícios; F5.4: documentação e regressão; F6.1: histórico e marcação vinculada; F6.2a: estrutura dos snapshots do espelho; F6.2b: snapshots das justificativas em competência fechada; F6.2c: fechamento, reabertura e pontos de partida; F6.2d: consulta, impressão e divergência histórica; F6.3: mudança entre competências)

## 1. Objetivo e escopo

Registrar, na tela `points-edit` (botão de edição de cada dia na tela de horas extras), as ocorrências da jornada que o RH justifica: faltas, atrasos, saídas, esquecimentos de marcação etc. A justificativa é um registro independente das batidas, com efeito imediato e sem aprovação posterior.

- **F1** — catálogo de motivos, tabelas, histórico append-only e permissões.
- **F2** — `ManualPointRegistrar`, seção de justificativas na tela e validação no servidor.
- **F3** — gravação pelo `PointJustificationService`: idempotência, sobreposição, competência fechada, criação da batida manual com classificação pela sequência do dia e histórico.
- **F4** — edição e cancelamento, com controle otimista pela `version`, movimentação e remoção controladas da batida vinculada e histórico.
- **F5.1** — abono do atraso e conferência de ausências no espelho de ponto (`TimesheetJustifications`), calculados na leitura.
- **F5.2** — mesmo abono na regra alternativa e na comparação (D8); atraso bruto, abonado e líquido, motivo e período na tela de horas extras e na impressão (D9).
- **F5.3** — supressão de sugestões de ausência injustificada e conflito informativo nas sugestões de benefícios.
- **F6.1** — consulta somente leitura do histórico, marcação vinculada (inclusive removida) e aviso de justificativa movida.
- **F6.2a** — estrutura append-only de snapshots do espelho por funcionário e dia (`timesheet_snapshots`, `TimesheetSnapshotRecorder`). Entregue sem integração; integrada às justificativas na F6.2b, ao fechamento na F6.2c e à consulta e impressão na F6.2d.
- **F6.2b** — snapshots antes e depois de cada registro, edição e cancelamento de justificativa que atinge dia de competência fechada.
- **F6.2c** — ponto de partida do espelho no fechamento da competência, versões a cada novo fechamento, ponto de partida tardio e bloqueio da exclusão de funcionário e competência com snapshots.
- **F6.2d** — consulta e impressão somente leitura das versões históricas, snapshot de referência, aviso de divergência (tela do dia e impressão atual) e ordem de bloqueios competência → funcionário nas justificativas, validada por testes de concorrência real.
- **F6.3** — testes de ponta a ponta da mudança de data de justificativa entre datas e competências (fechadas pelo fluxo real), atomicidade por etapa, concorrência e impressão histórica com uma competência por folha.

Fora do escopo até aqui: banco de horas, documentos comprobatórios (política do RH pendente).

## 2. Permissões

Permissões individuais em `users.permissions` (JSON). Usuários existentes começam sem nenhuma.

| Permissão | Gate | Uso |
|---|---|---|
| `hr` | `manage-point-justifications` | Consultar, registrar, editar e cancelar justificativas; consultar e imprimir versões históricas do espelho (F6.2d) |
| `hr.closed_period` | `change-closed-period-justifications` | Operar em competência fechada; só vale junto com `hr` |

Concessão: `php artisan users:permissions email@empresa --grant=hr [--grant=hr.closed_period]`; retirada com `--revoke`.

A rota `points-edit` continua aberta: incluir e remover batida manual seguem as regras anteriores, exceto a batida vinculada a uma justificativa ativa, que não pode ser excluída por esse fluxo (seção 11). Só a seção de justificativas exige `hr`, verificada no servidor em cada método Livewire e no serviço.

## 3. Modelo de dados

- `point_justification_reasons` — catálogo (`code` único e estável; nome, categoria, `time_mode`, `requires_document`, `default_treatment`, `allowed_treatments`, `creates_manual_point`, `active`, `sort_order`).
- `point_justifications` — a justificativa. Nunca é apagada (cancelamento lógico). `manual_point_id` único (FK `points`, nullOnDelete); `request_uuid` único (idempotência); `version` (controle otimista, F4).
- `point_justification_histories` — append-only: ação, valores anteriores/novos, competência afetada, `closed_period`, responsável (FK nullOnDelete) e nome copiado.

## 4. Catálogo

25 motivos em 4 categorias (`PointJustificationReasonSeeder`, idempotente, nunca sobrescreve um motivo existente). Nenhum exige documento; `allowed_treatments` nulo (todos os tratamentos suportados) até o RH definir a matriz motivo × tratamento.

Criam batida manual (decisão da F3): **Esquecimento de marcação**, **Falha no relógio de ponto** e **Trabalho externo sem marcação**. Em catálogos semeados antes da F3, a migration `2026_11_20_001542_enable_manual_point_for_clock_failure_and_external_work_reasons` liga somente `creates_manual_point` desses motivos (pelo `code`), sem tocar outra coluna ou motivo; é idempotente. Ordem de implantação: `migrate` e depois o seeder — com o catálogo vazio a migration não altera nada e o seeder já cria os motivos com a opção ligada.

Tratamentos: abonar integralmente, abonar parcialmente (exige minutos), não abonar. **Compensar no banco de horas** existe no enum, mas é recusado (não há banco de horas).

## 5. Validação (`PointJustificationValidator`)

- Motivo existente e ativo (na edição, também o motivo atual da justificativa, ainda que desativado; seção 10); tratamento válido, suportado e permitido pelo motivo.
- Horários conforme `time_mode`:
  - dia inteiro: sem horários; duração = jornada prevista do dia (`config/timesheet.php`), nula sem jornada;
  - intervalo: início e fim `H:i`; fim posterior ao início, ou anterior/igual com "termina no dia seguinte" (até 24 h); duração calculada;
  - dia inteiro ou intervalo: escolha do RH;
  - marcação única: horário e tipo de marcação; sem fim e sem duração.
- Abono parcial: minutos entre 1 e a duração (1440 sem duração).
- Observação até 1000 caracteres.
- `request_uuid` UUID ainda não usado.
- Competência fechada: a `BenefitPeriod` fechada cuja janela (mês anterior à competência) contém a data exige `hr.closed_period`.

## 6. Gravação (`PointJustificationService::register`)

Tudo em uma transação; qualquer falha (inclusive no histórico) desfaz justificativa, batida e histórico.

1. Gate `manage-point-justifications`, antes de qualquer leitura.
2. `lockForUpdate` no funcionário: serializa os registros do mesmo funcionário (e a inclusão de batida pelo `ManualPointRegistrar`, que bloqueia a mesma linha).
3. Idempotência (seção 7).
4. Revalidação completa (seção 5).
5. Sobreposição (seção 8).
6. Competência relida com `sharedLock` imediatamente antes da gravação: se tiver sido fechada depois da validação, exige `hr.closed_period`; um fechamento concorrente espera a transação terminar.
7. Motivo com `creates_manual_point`: classificação (seção 9) e batida pelo `ManualPointRegistrar` (mesma transação).
8. Justificativa com `manual_point_id` e `created_by`.
9. Histórico `created` (valores gravados, motivo, funcionário, retrato da batida, posição na sequência, competência afetada, `closed_period`) e, havendo batida, `manual_point_created` com o retrato integral do ponto.

Não altera batidas existentes (importadas, NSR, `afd_import_id`, pontos mantidos ou anotados pelo RH) e não toca benefícios: nenhuma `BenefitAdjustment`, nenhuma execução de sugestões, nenhuma mudança em `BenefitPeriod`.

## 7. Idempotência

- O formulário gera um `request_uuid` (`#[Locked]`) a cada novo cadastro e o mantém entre envios com erro; após a gravação, fecha e gera outro.
- Identificador já gravado, mesmo funcionário e mesmo conteúdo (motivo, data, horários, virada, tipo, tratamento, minutos, observação normalizada): devolve o registro existente (`created = false`), sem nova batida nem novo histórico.
- Conteúdo diferente, outro funcionário ou dados inválidos: recusa com "Este identificador de operação já foi usado para outro lançamento", sem revelar o registro. A autorização é verificada antes.
- Corrida com o mesmo identificador: o bloqueio do funcionário serializa; entre funcionários distintos, o UNIQUE do banco recusa a segunda gravação e a exceção é convertida no mesmo tratamento de reenvio (sem erro 500).

## 8. Sobreposição

Recusada entre justificativas **ativas** do mesmo funcionário, em datas e horários reais, como intervalos `[início, fim)`:

| Justificativa | Período ocupado |
|---|---|
| Dia inteiro | `[data 00:00, data+1 00:00)` |
| Intervalo | `[data início, data fim)`, fim no dia seguinte com a virada |
| Marcação única | `[horário, horário + 1 min)` |

Assim: dia inteiro conflita com qualquer outra da data; intervalos com interseção são recusados e adjacentes aceitos; marcação dentro de intervalo é recusada (no fim do intervalo, aceita); duas marcações no mesmo minuto são recusadas; a ocorrência que atravessa a meia-noite é comparada com a data seguinte. Canceladas não contam. A duplicidade de batidas no mesmo minuto continua a cargo do `ManualPointRegistrar`.

## 9. Classificação pela sequência cronológica (`PunchSequence`)

A posição da nova batida entre todas as batidas do funcionário na data (importadas e manuais) define o lado: posição ímpar = entrada, par = saída. O tipo informado no formulário precisa corresponder (entrada ou fim do intervalo → ímpar; saída ou início do intervalo → par); a divergência é recusada com a posição calculada, sem escolha arbitrária. Recusados também: empate no mesmo minuto e batida existente sem horário válido.

A data de trabalho é a data civil da batida, como no espelho (`TimesheetMirror` agrupa por `points.date`): numa jornada que atravessa a meia-noite, a batida depois de 00:00 é classificada no dia seguinte. Nenhuma batida existente é alterada.

## 10. Edição (`PointJustificationService::update`)

O formulário de edição carrega os valores e a `version` lida (propriedades `#[Locked]`). Em uma transação:

1. Gate `manage-point-justifications`.
2. `lockForUpdate` no funcionário e depois na justificativa (mesma ordem do registro).
3. Cancelada: recusa. Versão diferente da recebida: recusa com "A justificativa foi alterada por outro usuário…", sem gravar nada.
4. Revalidação completa (seção 5), inclusive a nova data (`occurrence_date`, opcional). O motivo atual é aceito mesmo desativado, para salvar os demais campos sem trocá-lo; ele não é reativado nem alterado no catálogo. Trocar de motivo exige um motivo ativo; o motivo aceito é lido do banco (justificativa em edição), não do formulário. Novas justificativas aceitam somente motivos ativos.
5. Sem nenhuma diferença nos campos: nada é gravado (nem versão, nem histórico).
6. Sobreposição sem contar a própria justificativa.
7. Competências anterior e nova relidas com `sharedLock`; qualquer uma fechada exige `hr.closed_period`.
8. Batida vinculada:
   - motivo que cria batida e batida existente: reclassificada pela sequência da data (sem contar ela mesma) e, se a data ou o horário mudarem, movida pelo `ManualPointRegistrar::move` (manual, do funcionário, sem proteção do RH, sem duplicidade no minuto);
   - motivo que cria batida sem batida vinculada: criada como no registro;
   - novo motivo que não cria batida com batida vinculada: recusado (a remoção só acontece no cancelamento).
9. `version + 1`, `updated_by`.
10. Histórico `updated` (valores anteriores e novos, retratos da batida antes e depois, competências, campos alterados na descrição) e, conforme o caso, `manual_point_updated` (retratos antes e depois) ou `manual_point_created`.

## 11. Cancelamento (`PointJustificationService::cancel`)

Motivo obrigatório (até 1000 caracteres, sem espaços nas pontas). Em uma transação:

1. Gate; bloqueio do funcionário e da justificativa.
2. Já cancelada: recusa ("Esta justificativa já foi cancelada."). Versão divergente: recusa.
3. Competência relida com `sharedLock`; fechada exige `hr.closed_period`.
4. Batida vinculada protegida (mantida por decisão do RH ou anotada) ou não manual: **nada é cancelado**, com o motivo do bloqueio. A proteção não é alterada.
5. Retrato integral da batida.
6. `canceled_at`, `canceled_by`, `cancellation_reason`, `version + 1`; depois, remoção da batida pelo `ManualPointRegistrar::remove`. A justificativa nunca é apagada.
7. Histórico `canceled` (valores anteriores e novos, retrato da batida) e `manual_point_removed` (retrato).

Batida vinculada já removida por outro caminho (dados anteriores à F4) não impede o cancelamento.

**Exclusão manual de batida vinculada:** bloqueada no modelo `Point` (evento `deleting`: batida vinculada a justificativa ativa), valendo para a tela, para o `ManualPointRegistrar` e para qualquer exclusão pelo Eloquent; a tela também recusa com mensagem própria e mostra "Justificativa" no lugar do botão de exclusão. Por isso o cancelamento marca a justificativa como cancelada antes de remover a batida. Exclusões diretas pelo query builder ou SQL não passam pela proteção.

## 12. Efeito no espelho de ponto (F5.1, `TimesheetJustifications`)

Calculado a cada leitura do espelho (`TimesheetMirror::forEmployee` e impressão), a partir das justificativas **ativas**; nada é gravado. Criar, editar, mudar a data ou cancelar uma justificativa vale na próxima leitura, sem recálculo e sem possibilidade de abono em dobro. Uma consulta de justificativas (mais a do motivo) por espelho ou por impressão, para todos os funcionários.

**Abono do atraso** (somente em dia útil com atraso calculado, isto é, 2 ou 4 marcações):

- só os tratamentos "Abonar integralmente" e "Abonar parcialmente"; "Não abonar" mantém o atraso;
- trechos atrasados: entrada prevista → 1ª marcação e última marcação → saída prevista, em minutos de jornada (o intervalo previsto não conta); o retorno tardio do almoço não faz parte do atraso oficial e não é afetado;
- período da justificativa: dia inteiro, intervalo (a parte depois da meia-noite de uma justificativa do dia anterior vale no dia seguinte) ou nenhum (marcação única não abona atraso);
- integral: minutos do período dentro dos trechos atrasados; parcial: o menor entre os minutos informados e esse valor, primeiro na entrada e depois na saída;
- o abono da entrada não passa do atraso na entrada (já descontada a tolerância) e o da saída não passa da saída antecipada: sem saldo negativo e sem crédito.

Campos do dia: `delay_before_allowance_minutes` (bruto), `allowed_delay_minutes` (abonado), `delay_minutes` (líquido), `late_entry_minutes` e `early_leave_minutes` (brutos) e `justifications` (motivo, tratamento, período e minutos abonados de cada uma). Totais: os mesmos campos somados e `justified_days`.

**Conferência:** dia útil sem marcação coberto por justificativa ativa de **dia inteiro** sai da conferência (`justified`), com qualquer tratamento suportado; a situação continua "sem marcação" e o rótulo vira "Justificado — motivo" (com "(não abonado)" quando for o caso). Justificativa de intervalo em dia sem marcação não tira da conferência, e a justificativa não esconde outras situações (marcações incompletas, excedentes, fora da jornada).

Sem justificativas, o espelho é idêntico ao anterior. `TimesheetMirror::build()` sem justificativas (prévia das decisões do AFD) continua com o atraso bruto. Não há efeito em benefícios: o cálculo de benefícios não lê o espelho.

**Regra alternativa e comparação (F5.2, D8):** a chave `alternative` recebe o mesmo abono, pela mesma função, sobre o atraso alternativo (entrada prevista → 1ª marcação considerada; última → saída prevista), com `delay_before_allowance_minutes`, `allowed_delay_minutes` e `delay_minutes` líquido, e o dia útil sem marcação justificado por dia inteiro também sai da conferência. A comparação carrega as justificativas em uma consulta e as aplica aos dois lados; ausência justificada não é divergência. O abono pode igualar dias em que as regras diferiam só no atraso (ex.: tolerância), porque os dois atrasos ficam zerados.

**Apresentação (F5.2, D9):** o texto de cada justificativa é montado uma vez no espelho (`justification_notes`: "Abonado — motivo, período: HH:MM", "Não abonado — motivo, período" ou "Justificativa — motivo, período") e exibido igual na tela e na impressão.

- Tela de horas extras: no card de atraso, "Atraso líquido" com o bruto e o abonado do período; na coluna Atraso do dia, bruto, abonado e líquido quando há abono; os textos das justificativas na coluna Ocorrência.
- Impressão: coluna Atraso com o líquido (e "bruto/abon." em letra menor quando há abono); textos das justificativas na coluna "Justificativa da irregularidade"; na linha de totais, "Atraso bruto · abonado · líquido"; legenda explicando o atraso líquido.
- Horas extras não mudam: o abono só atua sobre o atraso.

## 12.1. Sugestões de benefícios (F5.3, `TimesheetAdjustmentSuggester`)

- **Critério** (`TimesheetJustifications::suppressesAbsenceSuggestion`): justificativa ativa de dia inteiro, na própria data de ocorrência, com abono integral ou parcial. "Não abonar", intervalo, marcação única, canceladas, outro funcionário, outra data e a parte depois da meia-noite de uma justificativa noturna não contam.
- **Carga:** `analyze()` lê as justificativas de todos os funcionários acompanhados com `forEmployees()` (uma consulta mais o motivo), usada pela geração e pelos conflitos.
- **Geração:** dia útil sem batida coberto pelo critério não gera ausência injustificada e conta em `skipped_justified`. A verificação vem depois de `skipped_existing` (`dedupe_key`) e de `skipped_covered` (ajuste não rejeitado cobrindo o dia), que mantêm a semântica anterior; cada dia entra em um único contador. Sugestões de trabalho (sábado, domingo, feriado) não mudam. A cauda da competência anterior (compensações) não é suprimida.
- **Conflito `absence_with_justification`** (informativo, calculado a cada consulta): sugestão do ponto de ausência injustificada não rejeitada (pendente ou confirmada, inclusive compensação) em dia coberto pelo critério. A mensagem traz funcionário, data, motivo, tratamento, número da justificativa e ajuste afetado. Nenhum ajuste é rejeitado, alterado ou excluído; o conflito some quando a justificativa é cancelada. Ausências manuais e sugestões com o motivo alterado na revisão não entram.
- **Execuções:** migration `2026_11_20_232926_add_skipped_justified_to_benefit_suggestion_runs_table` (`unsigned int`, default 0, depois de `skipped_covered`; `down()` remove a coluna). Execuções anteriores ficam com 0. O contador aparece no resumo da geração, na notificação e na "Última execução".
- Fora do escopo: `BenefitAdjustmentRegistrar`, `BenefitPeriodCalculator`, períodos e valores de benefícios.

## 12.2. Histórico e marcação vinculada (F6.1, `PointJustificationHistoryPresenter`)

- **Acesso:** botão "Histórico" em cada justificativa (ativa ou cancelada) da tela `points-edit`. `showJustificationHistory()` exige `manage-point-justifications` (sem permissão nova; competência fechada não exige `hr.closed_period` para leitura) e só abre justificativa do funcionário da tela. A justificativa aberta fica em propriedade `#[Locked]`. Nada é gravado na consulta.
- **Linha do tempo:** registros na ordem de gravação, com ação, data/hora, usuário (o nome copiado em `performed_by_name`, que sobrevive à exclusão do usuário), competência, indicação de competência fechada e descrição. Campos exibidos somente da lista conhecida (motivo, data, horários, término no dia seguinte, tipo de marcação, tratamento, minutos abonados, observação), como texto escapado, nunca o JSON bruto. O registro mostra os campos preenchidos; a edição, só os alterados, com antes e depois; o cancelamento, o motivo.
- **Nome do motivo:** o do retrato gravado no histórico (registro e edição gravam o motivo), nunca substituído pelo nome atual do cadastro. Sem retrato (dados anteriores ao histórico completo), o nome atual aparece marcado como "nome atual do cadastro".
- **Marcação vinculada:** a atual, quando existe (data, hora, tipo, proteção pelo RH); removida no cancelamento, pelos dados do histórico, com data e usuário da remoção; ausente sem registro de remoção (apagada fora do fluxo), com o aviso e o último retrato.
- **Justificativa movida:** a data antiga avisa "a justificativa #N … foi movida para dd/mm/aaaa" (com link para a nova data e acesso ao histórico), a partir do retrato anterior da edição. Some quando a justificativa volta para a data. Isolado por funcionário e só para o RH.
- **Correção histórica do AFD (investigação):** quarentena remove somente pontos `importado` do importador legado (sem REP/NSR, conferidos por hash); reversão de recuperação e revogação de decisão removem somente os pontos `importado` que elas mesmas inseriram, também conferidos por hash. Nenhuma das rotinas remove batida manual; a marcação vinculada é sempre `manual`. As rotinas apagam pelo query builder (sem `Point::deleting`), então a garantia vem dessas conferências, não do evento do model.

## 12.3. Snapshots do espelho (F6.2a, `TimesheetSnapshotRecorder`)

O espelho continua calculado na leitura. O snapshot guarda um dia do funcionário como foi apresentado, para consultar a versão histórica sem recalcular com o código atual. Cada dia do espelho é calculado de forma independente (os totais são somas dos dias), por isso o registro é por funcionário e dia; isso também resolve a diferença entre o período 26→25 do espelho e a competência mensal.

**Tabela `timesheet_snapshots`** (migration `2026_11_21_032611_create_timesheet_snapshots_table`):

| Coluna | Conteúdo |
|---|---|
| `employee_id`, `date` | Funcionário e dia do espelho |
| `sequence` | Versão do dia do funcionário (1, 2, 3...); único com funcionário e data |
| `source` | Origem (abaixo) |
| `competence` | Competência da data (mês seguinte), para consulta |
| `benefit_period_id` | Competência de benefícios, quando informada |
| `point_justification_history_id` | Histórico da justificativa ligado, quando aplicável |
| `operation_uuid` | Agrupa os snapshots de uma operação (antes e depois, várias datas) |
| `schema_version`, `rule_version`, `rules_hash` | Versão da estrutura, rótulo da regra e SHA-256 do código das classes de cálculo |
| `inputs`, `inputs_hash` | Entradas do cálculo e o hash da parte `calculation` |
| `result`, `result_hash` | Dia como o `TimesheetMirror` apresentou (enums pelo valor) e o hash |
| `record_hash` | Hash das colunas gravadas, para detectar alteração feita fora da aplicação |
| `reason`, `created_by`, `created_by_name`, `created_at` | Motivo opcional, usuário (anulado se removido; nome copiado) e data/hora |

Índices: único (`employee_id`, `date`, `sequence`); (`employee_id`, `date`, `source`); (`competence`, `employee_id`); `operation_uuid`. Funcionário, competência e histórico com `restrictOnDelete`.

**Entradas (`inputs`):**
- `calculation` (entra no `inputs_hash`): PIS do funcionário (as batidas são selecionadas por ele: trocar o PIS no cadastro muda a origem dos dados, mesmo com batidas iguais), data, dia da semana, tipo do dia, feriado, jornada do dia da semana, tolerância, batidas (horário, tipo, anotação do RH), justificativas ativas que atingem o dia (inclusive a parte depois da meia-noite da véspera: data, horários, tratamento, minutos, nome e categoria do motivo), parâmetros da regra de horas extras e, somente quando ligada, da regra alternativa.
- `references` (fora do hash): funcionário (id, nome, PIS repetido para auditoria), ids das batidas com REP, NSR e importação, ids, versões e último histórico das justificativas, id e código do motivo.

**Hash:** SHA-256 de JSON canônico (chaves ordenadas, listas na ordem de cálculo, enums pelo valor), prefixado pela versão do esquema. Entradas equivalentes têm o mesmo hash, mesmo com ids diferentes (batida apagada e recriada no mesmo horário). O MySQL reordena as chaves do JSON gravado; o hash canônico não depende dessa ordem.

**Origens:**
- `closure_baseline` — ponto de partida do fechamento da competência (gravado na transação do fechamento, F6.2c). Reabertura e novo fechamento geram nova versão.
- `late_baseline` — ponto de partida tardio, para competência fechada antes da implantação, na primeira necessidade de registro. Não é o resultado original do fechamento: retrata o dia no momento em que foi gravado, depois de eventuais mudanças.
- `before_change` / `after_change` — antes e depois de uma alteração autorizada (F6.2b); exigem `operation_uuid`. O "antes" pode ser capturado antes da alteração (`capture()`) e gravado depois (`persist()`), já ligado ao histórico.
- `recalculation` — novo registro do dia fora de uma alteração.

**Regras de repetição:** fechamento, antes e depois são sempre gravados (cada um documenta um evento). `late_baseline` não é gravado no dia que já tem ponto de partida. `recalculation` não é gravado quando o último snapshot do dia tem as mesmas entradas, regras e resultado. Nas justificativas, a versão é calculada com o funcionário bloqueado. No fechamento, com a competência bloqueada (`FOR UPDATE`) e sem bloqueio explícito do funcionário (`lockEmployee: false`, seção 12.5): as justificativas que atingem esses dias esperam a competência, e só o próprio fechamento grava versões neles. O índice único é a última barreira. (Texto anterior à F6.2c dizia que a versão era sempre calculada com o funcionário bloqueado; substituído.)

**Imutabilidade:** os eventos do model recusam editar e apagar; o builder recusa `update`, `delete`, `forceDelete`, `upsert`, `increment` e `decrement` em massa. Triggers não foram usados: com binlog ligado o MySQL exige SUPER (ou `log_bin_trust_function_creators`) para criá-los, e o usuário da aplicação não tem — a migration falharia no meio. Alteração direta no banco não é impedida pela aplicação; `integrityProblems()` a detecta pelos hashes (`record_hash`, `inputs_hash`, `result_hash`). Impedir no banco depende de GRANT ou de triggers criados por quem administra o banco.

**Implantação (privilégios):** o usuário da aplicação deve ter somente `SELECT` e `INSERT` em `timesheet_snapshots`, sem `UPDATE` e `DELETE` (a aplicação nunca precisa deles). A migration é executada por um usuário com `CREATE`/`REFERENCES`; depois dela, por exemplo, `REVOKE UPDATE, DELETE ON <banco>.timesheet_snapshots FROM '<usuário da aplicação>'@'<host>'`, o que exige que os privilégios do usuário da aplicação estejam concedidos por tabela, e não para o banco inteiro (`<banco>.*`). Ajuste feito pelo administrador do banco; esta fase não altera privilégios.

**Rollback da migration (auditoria M1):** `down()` só remove a tabela vazia. Com snapshots gravados, `migrate:rollback`, `migrate:reset` e `migrate:refresh` são interrompidos com exceção ("Rollback recusado: timesheet_snapshots contém o histórico permanente...") antes de qualquer DDL; nada é apagado. A regra não depende do ambiente. Desenvolvimento e testes: `migrate:fresh` (e `RefreshDatabase`) apaga todas as tabelas sem chamar `down()` e continua funcionando. Produção: o histórico é permanente; removê-lo exige backup e decisão do administrador do banco, fora da aplicação. Num rollback de lote que inclua migrations posteriores, as posteriores já terão sido revertidas quando esta recusar.

**Divergência (`divergence()`):** compara o snapshot com as entradas, o código e o resultado atuais. Detecta mudança de batidas (inclusive pelas rotinas do AFD e por marcação manual), justificativas, nome do motivo, feriados, jornada, tolerância, regra alternativa e código de cálculo. Limitações: indica que houve mudança, não quantas nem quais versões intermediárias existiram (só as que passaram pelo gravador são preservadas); `rules_hash` muda com qualquer edição do código das classes de cálculo, inclusive sem efeito no resultado; configuração fora da lista acima não é coberta.

**Volume (medido nos testes):** cerca de 2,9 KB de dados por dia de funcionário com 4 batidas e 1 justificativa (3,6 KB com a regra alternativa ligada), sem contar índices e páginas do InnoDB. Um ponto de partida de competência para 100 funcionários ≈ 31 × 100 = 3.100 linhas (≈ 9 a 11 MB de dados). Retenção permanente (decisão do RH).

## 12.4. Snapshots das justificativas (F6.2b, `PointJustificationService`)

- **Quando:** registro, edição (inclusive mudança de data e de competência) e cancelamento que atingem dia de competência com `BenefitPeriod` fechado. Dias atingidos: a data da justificativa e, com término no dia seguinte, a data seguinte; na edição, as datas anteriores e as novas. Somente os dias de competência fechada geram snapshot; competência inexistente, aberta ou apurada não gera. Reenvio idempotente e edição sem mudança não geram.
- **Sequência, na transação da operação:** bloqueios e revalidação (inalterados) → competências fechadas relidas com bloqueio compartilhado → captura dos dias (`before`, ainda sem a alteração) → alteração (justificativa, marcação manual) → histórico → nova captura (`after`) → gravação de `before_change` e `after_change` por dia, com o mesmo `operation_uuid`, ligados ao registro principal do histórico (registrada, alterada ou cancelada) e ao período fechado de cada dia → confirmação. O "antes" é capturado antes de qualquer gravação, então representa o estado anterior mesmo gravado depois.
- **Falha:** qualquer exceção (na alteração, no histórico ou em qualquer snapshot) desfaz tudo: justificativa, marcação, histórico e snapshots.
- **Autorização:** inalterada. A permissão de competência fechada continua exigida pela competência da data da justificativa (e, na mudança de data, pelas duas). A data seguinte de uma justificativa noturna, quando em outra competência fechada, ganha snapshot sem nova exigência de permissão (regra anterior preservada).
- **Ponto de partida tardio (F6.2c):** dia de competência fechada sem `closure_baseline` nem `late_baseline` recebe `late_baseline` com o mesmo estado capturado para o `before_change`, gravado antes dele, com o mesmo `operation_uuid` e histórico, uma única vez por funcionário e dia (sequência: tardio, antes, depois).
- **Consulta e impressão:** seção 12.6 (F6.2d).

## 12.5. Fechamento, reabertura e exclusões (F6.2c)

- **Fechamento (`BenefitPeriodWorkflow::close`):** depois das conferências, do recálculo dos benefícios (inalterado) e da mudança de status, na mesma transação, `TimesheetSnapshotRecorder::recordClosureBaseline()` grava `closure_baseline` de todos os dias da janela da competência — o mês civil anterior (competência 10/2026 → 01/09 a 30/09), o mesmo critério da competência de uma data nas justificativas. Os espelhos de 26 a 25 que atravessam a janela (26/08–25/09 e 26/09–25/10) ficam com os dias de setembro registrados; os de agosto e outubro pertencem a outras competências. Funcionários abrangidos: sem rescisão antes do início da janela, ou com batida ou justificativa ativa (inclusive a da véspera que termina no dia seguinte) na janela; funcionários sem batidas entram com os dias sem marcação; fins de semana e feriados entram. Todos os snapshots do fechamento têm o mesmo `operation_uuid`, a competência, o usuário e o motivo "Fechamento nº N da competência MM/AAAA.". Qualquer falha desfaz o fechamento inteiro (status, recálculo, histórico de status e snapshots). Fechamento recusado pelas regras não grava snapshot.
- **Bloqueios:** o fechamento bloqueia a competência (`FOR UPDATE`) e grava sem bloquear explicitamente os funcionários (`persist(..., lockEmployee: false)`). Mas cada linha gravada com chave estrangeira para o funcionário (apuração e snapshots) recebe do InnoDB um bloqueio compartilhado na linha do funcionário, mantido até o fim da transação; a ordem real do fechamento é competência → funcionário. A F6.2d alinhou as justificativas a essa ordem (seção 12.6).
- **Reabertura:** descarta o snapshot de benefícios, como antes, e não toca nos snapshots do espelho. Entre a reabertura e o novo fechamento, a competência está aberta: alterações de justificativas não geram snapshot (só o hash acusará a diferença).
- **Novo fechamento:** grava nova versão de cada dia (`sequence` seguinte), com outro `operation_uuid` e o número do fechamento no motivo; as versões anteriores ficam intactas. Cada fechamento é identificado por `operation_uuid` e pelo motivo; cada dia, pela sequência.
- **`closure_baseline` × `late_baseline`:** o primeiro é o estado do dia no momento do fechamento, gravado na transação do fechamento. O segundo existe só para dia de competência fechada sem ponto de partida (fechada antes da implantação, ou funcionário fora do fechamento) e retrata o dia no momento da primeira operação que o exigiu — depois de eventuais mudanças desde o fechamento real. Nunca é apresentado como resultado original do fechamento.
- **Falhas de banco previsíveis (auditoria B5, `DatabaseFailure`):** no fechamento pela tela de apuração (`BenefitPeriodCalculation::close`) e na exclusão de funcionário (`EmployeeIndex::destroy`), espera de bloqueio esgotada (1205) ou deadlock (1213) e violação de integridade (SQLSTATE 23000, por exemplo um snapshot gravado por um fechamento concorrente com a exclusão) viram mensagem na tela. A transação já foi desfeita e a exceção vai para o log (`report()`). Outras falhas de banco não são capturadas: seguem como erro e são registradas pelo handler do framework. Regras e bloqueios inalterados.
- **Exclusão de funcionário:** recusada com mensagem própria quando há snapshots ("registros históricos do espelho de ponto..., preservados permanentemente. Informe a data de rescisão"). A chave estrangeira restritiva continua como última barreira.
- **Exclusão de competência:** `deletionBlocker()` acrescenta "A competência possui snapshots do espelho de ponto..." depois das regras anteriores (aberta, mais recente, sem apuração, sem ajustes), o que cobre a competência reaberta. A tela não oferece a exclusão e, chamada diretamente, mostra a mensagem. Não há mecanismo de exclusão ou limpeza de snapshots.
- **Alterações externas:** marcações manuais, rotinas do AFD, feriados, jornada, tolerância, regra alternativa e troca de PIS não gravam snapshot. A diferença é detectada pela comparação de hashes com o snapshot de referência do dia, escolhido pelo critério da seção 12.6 (que não é necessariamente a última versão gravada), sem preservar as versões intermediárias. (Até a F6.2c o texto falava em comparar com a última versão gravada; substituído pelo critério da F6.2d.)

## 12.6. Consulta, impressão e divergência histórica (F6.2d, `TimesheetSnapshotHistory`)

Tudo somente leitura: nenhuma consulta, aviso ou impressão grava snapshot ou altera registro. O estado atual, quando comparado, é lido por `TimesheetSnapshotRecorder::capture()`, que não persiste. Não há edição nem exclusão de snapshots.

- **Consulta (tela do dia, `points-edit`):** botão "Versões do Espelho" na seção de justificativas (gate `manage-point-justifications`; chamada direta sem a permissão é recusada com 403). Lista todas as versões do funcionário e do dia em ordem de `sequence`: origem (rótulo e cor próprios para fechamento e ponto de partida tardio), marca "Referência atual", falha de integridade, motivo, data e hora do registro, responsável (`created_by_name`, preservado mesmo com o usuário removido), competência e operação (`operation_uuid`). O detalhe mostra o resultado e as entradas **gravados** (marcações, extra, atraso, ocorrência, justificativas, jornada, tolerância, PIS, regra alternativa), a explicação da origem e, se o registro estiver íntegro, a comparação com o espelho atual. A versão consultada é procurada pelo funcionário e pelo dia da tela (propriedade `#[Locked]`); outra é recusada com notificação.
- **Impressão histórica (`reports.timesheet.snapshot-print`, `TimesheetSnapshotPrintController`, mesmo gate):** imprime os snapshots gravados juntos com o selecionado — mesma operação, mesma origem, mesmo funcionário e mesma competência (no fechamento, os dias da janela; numa alteração, os dias atingidos daquela competência); sem `operation_uuid`, só o selecionado. A restrição à competência veio da F6.3 (seção 12.7). Valores de `result` exatamente como armazenados; jornada e tolerância das entradas gravadas; nome e PIS do funcionário das referências gravadas; totais = soma dos minutos gravados dos dias (formatação só). Cabeçalho "VERSÃO HISTÓRICA DO ESPELHO DE PONTO — NÃO É O ESPELHO ATUAL", origem e explicação, motivo, data e responsável do registro, operação, versão da regra e do esquema, competência e dias. Por dia: versão, versões posteriores (sequência, origem, data) e "Situação atual" (confere/difere, com os campos), comparação só para consulta. Dia com falha de integridade sai marcado e sem comparação. Razão social e CNPJ vêm da configuração atual (identificação da empresa, não dado do cálculo). A impressão atual do espelho continua igual, só com o aviso abaixo.
- **Snapshot de referência** (estado aceito mais recente do dia; o último snapshot não serve sempre):
  1. A competência do dia precisa estar **fechada agora**. Aberta ou apurada (inclusive depois de reabertura) não tem referência: mudanças são esperadas.
  2. Só contam snapshots ligados a essa competência. Ponto de partida = baseline (`closure_baseline` ou `late_baseline`) de maior versão. Sem baseline (fechada antes da implantação, ou funcionário fora do fechamento), não há referência ("sem ponto de partida registrado").
  3. Referência = último snapshot a partir desse baseline que não seja `before_change`. Depois de alterações autorizadas, é o `after_change` mais recente; depois de reabertura e novo fechamento, o novo `closure_baseline` (as versões do ciclo anterior deixam de valer).
  4. Referência com falha de integridade (`record_hash`, `inputs_hash` ou `result_hash`) não é usada para afirmar divergência nem trocada por uma versão mais antiga: o aviso é de integridade.
  5. "Referência anterior ao último fechamento": o baseline do funcionário não pertence ao último fechamento da competência (ele não entrou nele). Avisos sobre esse dia podem incluir mudanças feitas com a competência aberta.
- **Aviso de divergência:** compara os hashes da referência com uma captura atual (`TimesheetSnapshotRecorder::compare`), indicando os campos do resultado apresentado que mudaram.
  - Resultado diferente → aviso (âmbar) na tela do dia, com origem, versão, data e responsável da referência, competência e campos alterados; na impressão atual, quadro "Atenção — espelho atual diverge do registro histórico da competência fechada" por funcionário, com os dias.
  - Só as entradas mudaram (ex.: tolerância num sábado) → informação na tela, nada na impressão.
  - Só o código das regras mudou (`rules_hash`) → só na comparação do detalhe.
  - Falha de integridade → aviso vermelho na tela e marcação na impressão. É distinto de divergência: diz que o registro histórico não é confiável, não que o espelho mudou.
  - O aviso aparece para quem vê o espelho (tela do dia, impressão); consultar o conteúdo histórico exige `hr`. O nome do responsável pelo registro de referência só aparece para quem tem `hr`: para os demais, não entra na mensagem nem nos dados do aviso (auditoria B3). A impressão atual nunca mostra o nome. Mudanças por caminhos sem snapshot (AFD, marcações, feriados, configuração, PIS) são detectadas, mas as versões intermediárias não existem; o texto diz isso.
- **`closure_baseline` × `late_baseline` na interface:** rótulos "Fechamento da competência (original)" e "Ponto de partida tardio (não é o fechamento original)", cores diferentes, e explicação da origem na tela e na impressão. O tardio diz que foi gravado na primeira alteração depois do fechamento anterior à implantação e retrata o dia naquele momento. A data e o responsável mostrados são os do próprio registro; nada é inventado para o fechamento original.
- **Concorrência (investigação F6.2d):**
  - Antes: justificativas bloqueavam funcionário (`FOR UPDATE`) → competência (compartilhado); o fechamento bloqueia competência → funcionário (implícito, chave estrangeira das linhas gravadas). Com a justificativa segurando o funcionário e o fechamento a competência, havia deadlock (MySQL 1213, reproduzido em teste com o fechamento). O cálculo da prévia segue a mesma ordem (bloqueia a competência e, ao gravar a apuração com chave estrangeira para o funcionário, o funcionário), mas não há teste de concorrência para ele: o mesmo risco é inferido pelo código, não demonstrado.
  - Agora: registro, edição e cancelamento bloqueiam primeiro a justificativa (edição/cancelamento), depois as competências da data e da seguinte (antigas e novas, em ordem crescente, `lockCompetences`), depois o funcionário. A leitura traz a linha inteira: lida só pelo índice de competência, não bloquearia a linha que o fechamento bloqueia (achado nos testes). Impacto: a justificativa passa a esperar cálculo, fechamento ou reabertura da competência logo no início, em vez de no meio; o bloqueio compartilhado dura alguns milissegundos a mais (a validação). Regras e mensagens inalteradas.
  - Leitura defasada: como nenhuma leitura comum acontece antes das esperas, a visão REPEATABLE READ da justificativa já enxerga um fechamento concorrente concluído (o `closure_baseline`), e as versões seguem a sequência. O UNIQUE (funcionário, dia, versão) continua como última barreira.
  - Validado em processos reais (`ConcurrencyTest`): fechamento em andamento × justificativa no mesmo dia; justificativa em andamento × fechamento (o ponto de partida inclui a justificativa); justificativa na competência anterior × fechamento do mesmo funcionário; duas justificativas do mesmo funcionário e dia; justificativa, edição e cancelamento segurando o funcionário × fechamento. Sem a nova ordem, os cenários de deadlock falham.
- **Limitações:**
  - Mudanças por caminhos sem snapshot não têm versões intermediárias.
  - O aviso da tela recalcula o dia atual a cada renderização da competência fechada com referência; a impressão atual faz uma captura por funcionário com referência no período.
  - A referência "anterior ao último fechamento" é identificada pelo `operation_uuid` e pela ordem dos ids do último fechamento da competência; se nenhum funcionário entrou nele, não há como identificar.
  - Concorrência com rotinas que não bloqueiam a competência (importação AFD, batidas manuais) segue fora dos snapshots, só detectada pelo hash.

## 12.7. Mudança de data entre competências (F6.3)

Comportamento já implementado na F6.2b–F6.2d, agora coberto de ponta a ponta com competências fechadas pelo fluxo real (`CompetenceMoveSnapshotsTest`). Uma única mudança funcional, descrita no fim.

- **Dias atingidos:** na edição, a data antiga e a nova; com término no dia seguinte, também o dia seguinte de cada uma. Cada dia pertence à competência do seu mês (setembro → 10/2026). Só os dias cuja competência está fechada no momento da operação geram snapshot; os demais não geram, e isso não é aviso nem erro.
- **Combinações testadas** (origem → destino):

  | Origem | Destino | Snapshots |
  |---|---|---|
  | fechada | mesma competência fechada | antes/depois dos dois dias, mesma competência |
  | fechada | fechada | antes/depois da origem (competência de origem) e do destino (competência de destino) |
  | fechada | aberta | só a origem |
  | aberta | fechada | só o destino |
  | aberta | aberta | nenhum |
  | último dia (30/09) | primeiro dia (01/10) | cada dia na sua competência; com a seguinte aberta, só 30/09 |
  | noturna 29/09→30/09 | noturna 30/09→01/10 | 29/09, 30/09 e 01/10 nas suas competências; com 11/2026 aberta, só 29/09 e 30/09 |

  "Aberta → fechada" é testada movendo de outubro (11/2026 apurada) para setembro (10/2026 fechada): o fluxo de fechamento não permite 11/2026 fechada com 10/2026 aberta. Destino fechado antes da implantação (sem ponto de partida) recebe `late_baseline`, como na F6.2c.
- **Sequência e histórico:** todos os snapshots da mudança têm o mesmo `operation_uuid` e apontam para o registro "alterada" do histórico; cada um aponta para o período da sua competência (`benefit_period_id` e `competence` coincidem). Por dia: `closure_baseline` (versão 1, do fechamento) → `before_change` → `after_change`, versões contíguas. O "antes" da origem tem a justificativa e o "depois" não; no destino, o inverso. Uma marcação manual vinculada aparece na origem só no "antes" e no destino só no "depois". Na noturna, o dia seguinte mostra a justificativa com a data de ocorrência do dia anterior.
- **Atomicidade:** falha simulada em cada etapa — movimentação da marcação manual, alteração da justificativa, histórico, "antes" e "depois" da origem, "antes" e "depois" do destino, e ponto de partida tardio do destino — desfaz tudo: marcações, justificativa, histórico, snapshots e competências ficam idênticos ao estado anterior.
- **Integridade histórica:** snapshots anteriores idênticos byte a byte depois da mudança; nenhum `late_baseline` depois de fechamento real; referência de cada dia = o `after_change`, sem aviso; o `before_change` diverge do estado atual pelo hash; todos passam na verificação de integridade.
- **Concorrência:** a edição bloqueia as competências da data antiga e da nova (e dias seguintes), em ordem crescente, antes do funcionário (F6.2d). Testado em processos reais: mudança para a competência que está sendo fechada, segurando o funcionário (sem deadlock; o ponto de partida do destino já inclui a justificativa); mudança para fora da competência que está sendo fechada (a edição espera, enxerga o `closure_baseline` e grava as versões seguintes). Sem o bloqueio da competência nova, o primeiro cenário dá deadlock.
- **Mudança funcional (defeito encontrado):** a impressão histórica agrupava os snapshots só pela operação; numa mudança entre competências, a folha com "Competência 10/2026" no cabeçalho trazia também o dia de 11/2026. O grupo passou a exigir também a mesma competência (`TimesheetSnapshotHistory::printGroup`): uma folha por competência. Os snapshots não mudaram.
- **Limitações:**
  - Origem aberta e destino fechado com a competência de destino posterior só ocorre com dados anteriores à implantação (coberto apenas pelo cenário de ponto de partida tardio).
  - A permissão de competência fechada não é exigida para o dia seguinte de uma justificativa noturna em outra competência fechada (regra da F6.2b, mantida).
  - Concorrência com rotinas que não bloqueiam a competência (AFD, batidas manuais) continua fora dos snapshots; só o hash detecta.

## 12.8. Roteiro de implantação (snapshots)

1. Backup da base antes da migration.
2. Executar a migration com um usuário que tenha `CREATE`, `REFERENCES` e `INDEX`. Ela não é reversível depois que houver snapshots (seção 12.3).
3. Privilégios do usuário da aplicação em `timesheet_snapshots`: somente `SELECT` e `INSERT` (`REVOKE UPDATE, DELETE`), com privilégios concedidos por tabela. Não verificado: se a exclusão de usuário, que anula `created_by` pela chave estrangeira (`nullOnDelete`), continua funcionando depois do `REVOKE` — testar em homologação.
4. Medir em homologação, com volume real, a duração do fechamento (cerca de 30 linhas por funcionário abrangido, gravadas na transação, com os funcionários bloqueados até o fim) e da impressão atual. Acima de `innodb_lock_wait_timeout` (50 s por padrão), justificativas e inclusão de batidas concorrentes recebem a mensagem de espera esgotada.
5. Conferir na base operacional se há `recision_date` vazio ou zerado: o escopo do fechamento trata só `NULL` como "sem rescisão" (achado B6 da auditoria, não confirmado).
6. Competências fechadas antes da implantação não têm ponto de partida; ele é gravado como `late_baseline` na primeira alteração de cada dia.

## 13. Testes

MySQL 8 no banco `testing` (no modo paralelo, um banco `testing_test_N` por processo, abaixo); `Tests\TestCase` aborta qualquer outra conexão antes das migrations. O MariaDB (driver `mariadb`, usado no servidor) também é aceito, para rodar a suíte contra ele. Arquivos em `tests/Feature/PointJustifications/`, `ManualPointRegistrarTest` e `EmployeePointsEditTest`. A concorrência é testada com processos PHP reais (`tests/Support/register-justification.php`, `DatabaseTruncation`) que largam no mesmo instante e atrasam a gravação dentro da transação: registro, edições simultâneas da mesma versão, cancelamentos simultâneos e edição contra cancelamento. Sem os bloqueios, esses testes falham.

**Execução paralela e testes de concorrência real:**
- Os testes rodam com Pest, isolados ou com `--parallel`. No modo paralelo, o Laravel (`TestDatabases`) troca o banco de cada processo por `testing_test_N`, em que N é o token do processo (`ParallelTesting::token()`).
- `PointJustificationConcurrencyTest` (6 testes, filho `tests/Support/register-justification.php`) e `TimesheetSnapshots/ConcurrencyTest` (9 testes, filho `tests/Support/timesheet-concurrency.php`) leem o banco da conexão ativa (`DB::connection()->getDatabaseName()`) e o repassam ao processo filho em `DB_DATABASE`. O filho usa o mesmo banco do pai; `DB_DATABASE=testing` não deve ser fixado nos filhos (com ele, o filho grava num banco diferente do teste em paralelo e os testes falham).
- Proteção dos bancos de teste: `Tests\TestCase::isTestDatabase()` aceita o nome inteiro somente pela regra `/^testing(?:_test_[1-9][0-9]*)?\z/` (`testing` ou `testing_test_N`, N inteiro positivo, sem sufixo nem quebra de linha; casos recusados em `tests/Unit/TestDatabaseGuardTest.php`). O teste pai valida o banco antes das gravações do teste e de novo antes de truncar as tabelas no `afterEach`. O filho confere, antes de qualquer consulta, o ambiente `testing`, o driver `mysql` da conexão padrão e o nome configurado; depois confirma que o banco efetivamente selecionado (`select database()`) é o mesmo. Fora disso, recusa e encerra.
- Comandos (no container, usuário `sail`; o modo paralelo do Pest aceita um caminho por execução):
  - `docker compose exec -T -u sail laravel.test vendor/bin/pest --parallel --compact tests/Feature/PointJustifications/PointJustificationConcurrencyTest.php`
  - `docker compose exec -T -u sail laravel.test vendor/bin/pest --parallel --compact tests/Feature/TimesheetSnapshots/ConcurrencyTest.php`
  - suíte completa: `docker compose exec -T -u sail laravel.test vendor/bin/pest --parallel --compact`
  - sem `--parallel`, os mesmos caminhos rodam isolados no banco `testing`.
- Resultado de referência (MySQL 8, 4 processos paralelos): os dois grupos passam isolados e em paralelo (6 e 9); suíte completa com 2136 aprovados, 20562 asserções, 718 s; as 2 únicas falhas são as preexistentes de `AfdWithholdingTest` (`afd:import refuse…` e `afd:correction wi…`). Pint sem pendências nos arquivos alterados.

Efeitos no controle de horas (F5):

| Arquivo | Cobertura |
|---|---|
| `TimesheetJustificationsTest` | Funções puras, sem banco: abono integral, parcial (entrada primeiro), limites pela tolerância e pelo atraso, intervalo previsto excluído, várias justificativas sem ultrapassar o atraso, virada da meia-noite, "não abonar", banco de horas e marcação única sem abono; critérios de conferência (`justifiesAbsence`) e de sugestão (`suppressesAbsenceSuggestion`); textos de `note()`. |
| `TimesheetMirrorJustificationsTest` | Espelho oficial (F5.1): abono, conferência de ausência, canceladas e outros funcionários ignorados, edição/mudança de data/cancelamento refletidos na leitura, falha na gravação deixando o espelho no estado anterior, espelho sem justificativas igual ao anterior, leitura sem gravação (competência fechada incluída) e número fixo de consultas. |
| `TimesheetAllowancePresentationTest` | Regra alternativa e comparação (D8), tela de horas extras e impressão com os mesmos valores (D9), prévia das decisões do AFD sem justificativas e horas extras inalteradas. |
| `SuggesterJustificationsTest` | Gerador de sugestões (F5.3): supressão, contadores sem sobreposição, `dedupe_key`, cauda não suprimida, conflito `absence_with_justification` informativo, ajustes confirmados/rejeitados intactos, competência fechada, consultas e tela. |
| `SkippedJustifiedMigrationTest` | Migration reversível; execuções anteriores recebem 0. Em arquivo próprio porque o DDL do MySQL confirma a transação do teste. |
| `TimesheetSnapshots/TimesheetSnapshotRecorderTest` | Snapshots (F6.2a): resultado igual ao espelho, entradas, feriado, fim de semana, virada da meia-noite, regra alternativa ligada/desligada, captura sem gravação, hash canônico e estável, mudança de hash por batida (AFD e manual), justificativa, motivo, feriado, jornada, tolerância e regra alternativa, outro dia/funcionário sem efeito, resultado preservado e divergência, captura antes/persistência depois, imutabilidade (model e builder), detecção de alteração direta, rollback, relacionamentos, usuário removido, versões e regras de repetição. |
| `TimesheetSnapshots/JustificationSnapshotsTest` | F6.2b: competência inexistente, aberta e apurada sem snapshot; reenvio e edição sem mudança; registro, edição e cancelamento (marcação removida) com antes/depois corretos, mesmo `operation_uuid`, histórico e período; mudança de data na competência e entre competências (aberta → fechada e fechada → aberta); justificativa noturna em duas competências fechadas; autorização preservada sem gravação; rollback em falha da alteração e de cada snapshot; troca de PIS detectada. |
| `TimesheetSnapshots/ClosureSnapshotsTest` | F6.2c: fechamento com todos os dias da janela por funcionário abrangido (sem batidas, rescindido com batida, rescindido sem dados fora), fins de semana e feriado, igualdade com os dois espelhos 26→25 que cruzam a janela, justificativas noturnas nas bordas, rollback em falha de qualquer snapshot, fechamento recusado sem snapshot, reabertura sem alterar snapshots, novo fechamento com novas versões, ponto de partida tardio uma única vez e só sem ponto de partida, exclusão de funcionário e de competência bloqueadas com mensagem na tela. |
| `TimesheetSnapshots/TimesheetSnapshotsMigrationTest` | Migration reversível, colunas, índices e chaves estrangeiras. |
| `TimesheetSnapshots/SnapshotHistoryTest` | F6.2d, referência: competência aberta (inclusive após reabertura) sem referência; logo após o fechamento; `after_change` e nunca `before_change`; novo fechamento substitui o ciclo anterior; fechada sem ponto de partida e tardio identificado; referência anterior ao último fechamento; aviso com origem, versão, data, responsável e campos; mudança só nas entradas informativa; registro adulterado como falha de integridade sem recorrer a versão antiga; avisos da impressão só com resultado diferente; consulta sem gravação. |
| `TimesheetSnapshots/SnapshotConsultationTest` | F6.2d, tela e impressão: todas as origens listadas em ordem com referência; detalhe com dados gravados e comparação; aviso na tela só com divergência (inclusive para quem não tem `hr`, sem acesso às versões); falha de integridade; dia sem snapshots; 403 sem `hr`; isolamento por funcionário e dia; impressão do fechamento com identificação, só do funcionário e com os 30 dias; impressão igual ao gravado depois de mudar batidas, feriado e jornada; tardio identificado com versões posteriores; dia adulterado marcado; 403/404/login; impressão atual com e sem aviso; consulta e impressões sem gravação; nome do responsável só para `hr`, no aviso de divergência e no de integridade (auditoria B3). |
| `TimesheetSnapshots/ConcurrencyTest` | F6.2d, processos reais (`tests/Support/timesheet-concurrency.php`, `DatabaseTruncation`): os cinco cenários da seção 12.6 (edição e cancelamento por dataset) e os dois de mudança entre competências da seção 12.7. |
| `TimesheetSnapshots/CompetenceMoveSnapshotsTest` | F6.3: tabela da seção 12.7 com fechamentos reais — mesma competência, duas fechadas (inclusive com marcação manual), combinações fechada/aberta, último → primeiro dia, noturna; versões contíguas e snapshots anteriores intactos; falha em cada etapa (7 pontos) e no ponto de partida tardio; referência, divergência, integridade e impressão com uma competência por folha. |
| `TimesheetSnapshots/TimesheetSnapshotsMigrationTest` (auditoria M1) | Rollback recusado com snapshots, sem DDL e com os registros intactos; tabela vazia removida. |
| `DatabaseFailureHandlingTest` (auditoria B5) | Classificação por código do driver; fechamento com espera esgotada, deadlock e integridade simulados (competência apurada, tabelas intactas, mensagem e log); falha inesperada propagada; exclusão de funcionário com chave estrangeira real (snapshot gravado durante a exclusão) e espera esgotada simulada; caminhos sem falha inalterados. |
| `JustificationHistoryScreenTest` | Histórico (F6.1): autorização e chamada direta sem `hr`, isolamento por funcionário, propriedade travada, consulta sem gravação, consultas fixas, ordem e ações, campos alterados, competência fechada, nome histórico do motivo, usuário excluído, escape, marcação atual/removida/ausente, aviso de justificativa movida e mudança de data entre competências (permissões nas duas competências, origem e destino no histórico, marcação movida). |

Resultado da F6.2d (MySQL 8, banco `testing`): 35 testes novos (11 + 17 + 7) passam; suíte completa com 2076 aprovados e 20315 asserções; as 2 únicas falhas são as preexistentes de `AfdWithholdingTest` (`afd:import refuse…` e `afd:correction wi…`), não relacionadas. Pint sem pendências. Mutações (impressão com o resultado atual, grupo sem filtro de funcionário, sem referência anterior ao fechamento, sem estado de integridade, tela sem isolamento, justificativa sem `lockCompetences` no registro e na edição/cancelamento) fazem testes falharem.

Resultado da F6.3 (MySQL 8, banco `testing`): 20 testes novos em `CompetenceMoveSnapshotsTest` e 2 em `ConcurrencyTest` passam; suíte completa com 2098 aprovados e 20469 asserções; as 2 únicas falhas continuam as preexistentes de `AfdWithholdingTest`. Pint sem pendências. Mutações: impressão sem filtro de competência e edição sem bloquear a competência nova fazem testes falharem.

Regressão: `TimesheetMirrorTest`, `TimesheetMirrorAlternativeTest`, `AlternativeComparisonTest`, `TimesheetAdjustmentSuggesterTest`, `TimesheetCompensationTest`, `AfdHistoricalDecisionsRecoverTest` e `BenefitSchemaTest` continuam valendo. Três ganharam somente os campos novos, sem mudar valores: `TimesheetMirrorAlternativeTest` (totais de atraso bruto e abonado), `TimesheetAdjustmentSuggesterTest` (`skipped_justified` e a leitura fixa das justificativas) e `BenefitSchemaTest` (coluna nova).

## 14. Pendências

- Matriz motivo × tratamento e exigência documental (RH).
- Supressão por justificativa nas compensações da cauda (exigiria contador próprio). Unificação da leitura da jornada prevista entre o validador e o `TimesheetMirror`.
- Validação visual da tela de histórico (F6.1) no navegador.
- Validação visual das versões do espelho, do aviso de divergência e das impressões histórica e atual (F6.2d) no navegador.
- Validação visual, na tela de ajustes de benefícios, do contador "Ignoradas por justificativa" e do conflito "Ausência com justificativa abonada" (F5.3).
