# Justificativas do controle de ponto (F1: estrutura e permissões; F2: tela e validação; F3: gravação e efeitos; F4: edição e cancelamento)

## 1. Objetivo e escopo

Registrar, na tela `points-edit` (botão de edição de cada dia na tela de horas extras), as ocorrências da jornada que o RH justifica: faltas, atrasos, saídas, esquecimentos de marcação etc. A justificativa é um registro independente das batidas, com efeito imediato e sem aprovação posterior.

- **F1** — catálogo de motivos, tabelas, histórico append-only e permissões.
- **F2** — `ManualPointRegistrar`, seção de justificativas na tela e validação no servidor.
- **F3** — gravação pelo `PointJustificationService`: idempotência, sobreposição, competência fechada, criação da batida manual com classificação pela sequência do dia e histórico.
- **F4** — edição e cancelamento, com controle otimista pela `version`, movimentação e remoção controladas da batida vinculada e histórico.

Fora do escopo até aqui: efeito no espelho de ponto e banco de horas (F5), documentos comprobatórios (política do RH pendente).

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

## 12. Testes

MySQL 8 no banco `testing`; `Tests\TestCase` aborta qualquer outra conexão antes das migrations. Arquivos em `tests/Feature/PointJustifications/`, `ManualPointRegistrarTest` e `EmployeePointsEditTest`. A concorrência é testada com processos PHP reais (`tests/Support/register-justification.php`, `DatabaseTruncation`) que largam no mesmo instante e atrasam a gravação dentro da transação: registro, edições simultâneas da mesma versão, cancelamentos simultâneos e edição contra cancelamento. Sem os bloqueios, esses testes falham.

## 13. Pendências

- Matriz motivo × tratamento e exigência documental (RH).
- Efeito no espelho (abono do atraso, dia fora de conferência) e unificação da leitura da jornada prevista com o `TimesheetMirror` (F5).
- Validação visual da edição e do cancelamento no navegador.
