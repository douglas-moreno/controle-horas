# Justificativas do controle de ponto (F1: estrutura e permissões; F2: tela e validação; F3: gravação e efeitos; F4: edição e cancelamento; F5.1: abono no espelho; F5.2: regra alternativa, tela e impressão; F5.3: sugestões de benefícios; F5.4: documentação e regressão)

## 1. Objetivo e escopo

Registrar, na tela `points-edit` (botão de edição de cada dia na tela de horas extras), as ocorrências da jornada que o RH justifica: faltas, atrasos, saídas, esquecimentos de marcação etc. A justificativa é um registro independente das batidas, com efeito imediato e sem aprovação posterior.

- **F1** — catálogo de motivos, tabelas, histórico append-only e permissões.
- **F2** — `ManualPointRegistrar`, seção de justificativas na tela e validação no servidor.
- **F3** — gravação pelo `PointJustificationService`: idempotência, sobreposição, competência fechada, criação da batida manual com classificação pela sequência do dia e histórico.
- **F4** — edição e cancelamento, com controle otimista pela `version`, movimentação e remoção controladas da batida vinculada e histórico.
- **F5.1** — abono do atraso e conferência de ausências no espelho de ponto (`TimesheetJustifications`), calculados na leitura.
- **F5.2** — mesmo abono na regra alternativa e na comparação (D8); atraso bruto, abonado e líquido, motivo e período na tela de horas extras e na impressão (D9).

Fora do escopo até aqui: sugestões de ausência dos benefícios (F5.3), banco de horas, documentos comprobatórios (política do RH pendente).

## 2. Permissões

Permissões individuais em `users.permissions` (JSON). Usuários existentes começam sem nenhuma.

| Permissão | Gate | Uso |
|---|---|---|
| `hr` | `manage-point-justifications` | Consultar, registrar, editar e cancelar justificativas |
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

## 13. Testes

MySQL 8 no banco `testing`; `Tests\TestCase` aborta qualquer outra conexão antes das migrations. Arquivos em `tests/Feature/PointJustifications/`, `ManualPointRegistrarTest` e `EmployeePointsEditTest`. A concorrência é testada com processos PHP reais (`tests/Support/register-justification.php`, `DatabaseTruncation`) que largam no mesmo instante e atrasam a gravação dentro da transação: registro, edições simultâneas da mesma versão, cancelamentos simultâneos e edição contra cancelamento. Sem os bloqueios, esses testes falham.

Efeitos no controle de horas (F5):

| Arquivo | Cobertura |
|---|---|
| `TimesheetJustificationsTest` | Funções puras, sem banco: abono integral, parcial (entrada primeiro), limites pela tolerância e pelo atraso, intervalo previsto excluído, várias justificativas sem ultrapassar o atraso, virada da meia-noite, "não abonar", banco de horas e marcação única sem abono; critérios de conferência (`justifiesAbsence`) e de sugestão (`suppressesAbsenceSuggestion`); textos de `note()`. |
| `TimesheetMirrorJustificationsTest` | Espelho oficial (F5.1): abono, conferência de ausência, canceladas e outros funcionários ignorados, edição/mudança de data/cancelamento refletidos na leitura, falha na gravação deixando o espelho no estado anterior, espelho sem justificativas igual ao anterior, leitura sem gravação (competência fechada incluída) e número fixo de consultas. |
| `TimesheetAllowancePresentationTest` | Regra alternativa e comparação (D8), tela de horas extras e impressão com os mesmos valores (D9), prévia das decisões do AFD sem justificativas e horas extras inalteradas. |
| `SuggesterJustificationsTest` | Gerador de sugestões (F5.3): supressão, contadores sem sobreposição, `dedupe_key`, cauda não suprimida, conflito `absence_with_justification` informativo, ajustes confirmados/rejeitados intactos, competência fechada, consultas e tela. |
| `SkippedJustifiedMigrationTest` | Migration reversível; execuções anteriores recebem 0. Em arquivo próprio porque o DDL do MySQL confirma a transação do teste. |

Regressão: `TimesheetMirrorTest`, `TimesheetMirrorAlternativeTest`, `AlternativeComparisonTest`, `TimesheetAdjustmentSuggesterTest`, `TimesheetCompensationTest`, `AfdHistoricalDecisionsRecoverTest` e `BenefitSchemaTest` continuam valendo. Três ganharam somente os campos novos, sem mudar valores: `TimesheetMirrorAlternativeTest` (totais de atraso bruto e abonado), `TimesheetAdjustmentSuggesterTest` (`skipped_justified` e a leitura fixa das justificativas) e `BenefitSchemaTest` (coluna nova).

## 14. Pendências

- Matriz motivo × tratamento e exigência documental (RH).
- Supressão por justificativa nas compensações da cauda (exigiria contador próprio). Unificação da leitura da jornada prevista entre o validador e o `TimesheetMirror`.
- Validação visual da edição e do cancelamento no navegador.
- Validação visual, na tela de ajustes de benefícios, do contador "Ignoradas por justificativa" e do conflito "Ausência com justificativa abonada" (F5.3).
