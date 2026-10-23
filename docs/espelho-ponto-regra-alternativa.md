# Espelho de Ponto — Regra alternativa de horas extras e atrasos (Fase 7.1)

**Especificação — implementada, homologada pelo RH e publicada; módulo encerrado**

| | |
|---|---|
| Versão | 0.12 |
| Status | **Encerrado — fases 7.2 a 7.5 implementadas, cálculos homologados pelo RH, publicado em produção com a regra alternativa desligada por padrão; pendências residuais na seção 7** |
| Data | 22/10/2026 |
| Base de código | `main` @ `fa32a59` (última alteração do módulo); especificação iniciada sobre `e90c568` (espelho de ponto, `TimesheetMirror`, `OvertimeCalculator`) |
| Escopo | Regra alternativa de horas extras e atrasos, calculada em paralelo à regra oficial, somente para comparação |
| Próximo passo | Nenhuma sprint planejada (seção 14). Ativação operacional da regra é decisão separada; correções somente sob demanda, se surgirem problemas reais |

> Este documento não contém código. As fases 7.2 a 7.5 foram implementadas por autorização explícita do responsável
> (seções 10 a 13) e o RH validou os cálculos, considerando-os corretos (seção 6). Não há registro de aprovação
> jurídica formal da regra como um todo; as respostas jurídicas registradas (J1–J3) validam critérios específicos.
> A regra oficial de horas extras permanece inalterada e continua sendo a usada nos cálculos oficiais, na impressão
> e nos relatórios. A regra alternativa está desligada por padrão; ligá-la é uma decisão operacional separada.

Legenda de origem: **[D]** decisão do responsável · **[V]** validado formalmente por RH/jurídico (seção 6) ·
**[DT]** decisão técnica preliminar · **[PR]** proposta ainda não validada · **[C]** comprovado no código ou nos
dados · **[I]** inferido do `Cartao_Ponto_Exemplo.pdf` ou da simulação · **[P]** pendente.

---

## Sumário

0. Histórico de alterações
1. Regras gerais
2. Matriz de decisões P1–P7 e decisões complementares D1–D9
3. Decisões técnicas A1, A4, A5, A7, A8, almoço, situações de conferência e turno noturno
4. Especificação do cálculo alternativo
5. Casos de referência
6. Validações recebidas de RH/jurídico
7. Pendências
8. Plano técnico (fases 7.2–7.5) — concluído
9. Critérios de autorização e de aceite
10. Implementação da fase 7.2
11. Implementação da fase 7.3
12. Implementação da fase 7.4
13. Implementação da fase 7.5
14. Encerramento do módulo

---

## 0. Histórico de alterações

| Versão | Data | Alteração |
|---|---|---|
| 0.1 | 22/10/2026 | Consolidação das decisões P1–P7 e das decisões técnicas preliminares A1, A4, A5, A7, A8 |
| 0.2 | 22/10/2026 | Ordem de classificação; A4 generalizada; A5 por lacuna; registro da configuração; turnos noturnos |
| 0.3 | 22/10/2026 | Respostas de RH/jurídico registradas; atraso somente na entrada e na saída; qualquer par fora das janelas vai para conferência; acesso restrito ao RH; proposta de detecção de turno noturno; rótulo da situação de conferência |
| 0.4 | 22/10/2026 | Decisões do RH: dia misto, limites do almoço e almoço deslocado vão para conferência; rótulo confirmado. Auditoria de turno noturno: detecção por sequência descartada e proteção conservadora proposta. Perguntas respondidas removidas |
| 0.5 | 22/10/2026 | Decisões do responsável (D1–D4): proteção conservadora de turno noturno adotada; almoço parcialmente deslocado vai para conferência; rótulo único com motivo específico; registro da configuração mantido. Pendências reduzidas a três |
| 0.6 | 22/10/2026 | Decisões do responsável (D5–D7): `11:50 13:00` vai para conferência; presunção do almoço mantida com as exceções; acesso à comparação para todos os usuários autenticados (lista de e-mails removida). Divergência com R5 registrada |
| 0.7 | 22/10/2026 | Fase 7.2 implementada. Decisão D8: marcações em [12:00, 13:00) são desconsideradas na alternativa (trabalho no intervalo deixa de ser extra). Ordem: proteção noturna antes da deduplicação. Nova pendência: meio período com entrada ou saída dentro do almoço |
| 0.8 | 22/10/2026 | Decisão D9: meio período com marcação no almoço usa a saída presumida às 12:00 ou a entrada presumida às 13:00; encerra a pendência 4. Resultados reais dos testes e dos dados atualizados (seção 10) |
| 0.9 | 22/10/2026 | Fase 7.3 implementada: configuração `timesheet.alternative_rule.enabled`, desligada por padrão; desligada, nenhum dado alternativo é produzido (seção 11) |
| 0.10 | 22/10/2026 | Fase 7.4 implementada: `TimesheetMirror` acrescenta a chave `alternative` por dia e nos totais somente com a configuração ligada; tela de horas extras e impressão sem dados alternativos (seção 12) |
| 0.11 | 22/10/2026 | Fase 7.5 implementada: tela de comparação, correspondência código → funcionário e CSV, restritas a usuários autenticados (D7); código pseudonimizado estável (A8); registro da configuração de jornada em log a cada comparação (D4). Divergência R5 × D7 continua aberta (seção 13) |
| 0.12 | 22/10/2026 | Encerramento do módulo: fases 7.2 a 7.5 concluídas; RH validou os cálculos como corretos; política de acesso definida (todos os usuários autenticados, D7), encerrando a divergência R5 × D7; PIS duplicado em dois cadastros corrigido (qualidade de dados, fora das fases); push, deploy e migrations concluídos e sistema verificado após o deploy; regra alternativa desligada por padrão. Sem aprovação jurídica formal registrada. Nenhuma sprint nova (seção 14) |

## 1. Regras gerais

1. Jornada única para todos **[D][V]**: segunda a quinta 07:30–12:00 e 13:00–17:30; sexta 07:30–12:00 e
   13:00–16:30 (`config/timesheet.php`). Esta especificação não altera essa configuração.
2. Horas extras e atrasos calculados separadamente, sem compensação, na regra alternativa **[D][V]**.
3. Sábados, domingos e feriados usam os resultados da regra oficial; não há atraso nesses dias **[D][C]**.
   A regra alternativa não introduz divergência nesses dias.
4. 09/07 é feriado **[D]**; já cadastrado em `holidays` **[C]**.
5. A regra oficial (`OvertimeCalculator`) permanece inalterada durante toda a comparação **[D]**.
6. Impressão e relatórios oficiais continuam usando somente os valores oficiais **[D]**.
7. A regra alternativa não pode ser promovida a oficial sem autorização posterior e explícita **[D]**.
8. Jornadas que atravessam a meia-noite estão fora do escopo **[V]**. Para os dias úteis, aplica-se a proteção
   conservadora D1 (seção 3, turno noturno), que **não** detecta todos os turnos noturnos possíveis e **não**
   garante a classificação correta em todos os casos.
9. Toda comparação registra a configuração de jornada usada no cálculo **[D]** (D4). A configuração atual não
   necessariamente reproduz a jornada histórica real dos períodos recalculados.

## 2. Matriz de decisões P1–P7 e decisões complementares D1–D9

As validações formais estão na seção 6. Validação de um critério não equivale a aprovação jurídica da regra nem à
sua ativação; a homologação dos cálculos pelo RH está registrada na seção 6.

| # | Decisão | Regra funcional | Validação | Impacto medido (26/06–25/09) |
|---|---|---|---|---|
| P1 | Presumir intervalo 12:00–13:00 com duas marcações **[D]**; mantida em D6 | Dia útil com exatamente 2 marcações (após A1), ou seja, sem marcações específicas do almoço, sem par fora das janelas (A4), fora dos casos de limite do almoço (R8, D5), 1ª ≤ 12:00 e 2ª ≥ 13:00: trechos [1ª, 12:00] e [13:00, 2ª] | **Validado** — jurídico (J1); exceções nos limites do almoço pelo RH (R8) e pelo responsável (D5) | 1.993 dias; leitura literal geraria +60 min de extra na maioria |
| P2 | Limiar de 5 min também na saída antecipada **[D]** | Saída antecipada ≤ 5 min → 0; > 5 min → valor integral, sem descontar 5 | **Validado** — RH (R2) | 96 dias / 139 min zerados; 156 dias mantêm valor integral |
| P3 | Contar extras de 1 a 5 min **[D]** | Todo minuto trabalhado fora das janelas é extra, sem tolerância nem arredondamento | **Validado** — jurídico (J2, sujeito às normas aplicáveis) e RH (R2) | 639 dias / 2.012 min com extra de 1–5 min |
| P4 | Janelas e intervalo fixos, sem compensação **[D]** | **Alterada por D8:** trabalho em 12:00–13:00 não é mais extra; almoço deslocado, total ou parcial, continua indo para conferência (R9, D2). Ausência no almoço **não** é atraso (R3). Extra e atraso podem coexistir no mesmo dia | **Validado** — separação (J3, R1), almoço sem atraso (R3), almoço deslocado (R9); almoço parcial pelo responsável (D2) | 26 dias com extra no intervalo: 24 meios períodos continuam calculados; 2 dias com almoço fora ou parcialmente fora do intervalo vão para conferência. Os 4 dias com lacuna no intervalo (274 min) não geram atraso |
| P5 | Não calcular 1, 3 ou 5+ marcações **[D]** | Dia útil com quantidade ímpar ou > 4, **após deduplicação**: sem extra/atraso alternativos; sinalizar para conferência | Decidida | 94 dias antes da deduplicação; 89 depois |
| P6 | Deduplicar o mesmo minuto só na alternativa **[D]** | Ver A1. Banco, dados brutos e regra oficial inalterados. Marcações distintas com menos de 10 min de diferença não são descartadas | Decidida | 5 dias passam de 3 para 2 marcações (saída repetida) |
| P7 | Dia útil sem marcação: só sinalizar **[D]** | Status `Sem marcação`; sem falta, DSR ou atraso | Decidida | — |

**Decisões complementares do responsável (versões 0.5 a 0.8)** — não equivalem a validação formal de RH/jurídico:

| # | Decisão | Regra funcional | Onde |
|---|---|---|---|
| D1 | Proteção conservadora de turno noturno **[D]** | Dia útil com marcação entre 22:00 e 04:59 (inclusive) ou com mais de 14h entre a 1ª e a última marcação → conferência. Descanso inferior a 11h **não** é gatilho. A proteção não detecta todos os turnos noturnos nem garante a classificação correta em todos os casos | Seção 3, turno noturno; seção 4, passo 3 |
| D2 | Almoço parcialmente deslocado vai para conferência **[D]** | 4 marcações, trabalho em 12:00–13:00 e almoço [2ª, 3ª] não contido integralmente em 12:00–13:00 → conferência, sem extra nem atraso alternativos | Seção 3, almoço deslocado; seção 4, passo 6 |
| D3 | Rótulo único com motivo específico **[D]** | Limites do almoço, almoço deslocado e proteção de turno noturno usam `Conferência manual — fora das janelas previstas`, preservando o motivo da conferência. Sem caráter disciplinar | Seção 3, situações de conferência |
| D4 | Registro da configuração de jornada **[D]** | Cada comparação registra a configuração de jornada usada | Regra geral 9; seção 8, fase 7.5 |
| D5 | `11:50 13:00` vai para conferência **[D]** | Dia com 2 marcações como `11:50 13:00` vai integralmente para conferência, sem extra nem atraso alternativos, como `11:59 13:00` e `12:00 13:01` (R8). O critério geral continua pendente | Seção 3, limites do almoço; seção 4, passo 5 |
| D6 | Presunção do almoço mantida **[D]** | Sem marcações específicas do almoço (2 marcações), presume-se o intervalo 12:00–13:00 (P1), respeitadas as exceções de limites do almoço (R8, D5), almoço deslocado (R9, D2), pares fora das janelas (A4) e turno noturno (D1) | Seção 2, P1; seção 4, passo 7 |
| D7 | Acesso para todos os usuários autenticados **[D]** | A comparação, a correspondência código → funcionário e o CSV ficam disponíveis a qualquer usuário autenticado. Usuários não autenticados não têm acesso. Substitui a lista de e-mails autorizados e o bloqueio por padrão da versão 0.5. Divergia da validação R5; **política de acesso definida na versão 0.12: prevalece D7** (seção 7, itens encerrados) | Seção 3, A7 e A8 |
| D8 | Marcações no intervalo de almoço desconsideradas **[D]** | Os funcionários normalmente não registram o almoço. Na alternativa, presume-se o intervalo 12:00–13:00 e as marcações em [12:00, 13:00) não contam como extra nem atraso. A marcação exatamente às 13:00 pertence à janela da tarde. Banco, dados brutos, AFD e cálculo oficial não mudam. Substitui o extra por trabalho no intervalo da P4 | Seção 4, passo 7; complementada por D9 |
| D9 | Meio período com marcação no almoço **[D]** | Exatamente 2 marcações deduplicadas, fora da P1, com uma delas em [12:00, 12:59]: entrada antes das 12:00 e saída no almoço → saída presumida às 12:00; entrada no almoço e saída depois das 13:00 → entrada presumida às 13:00. A marcação no almoço não gera extra nem atraso por si só. Sem determinação inequívoca → conferência. Dados brutos, AFD e regra oficial inalterados | Seção 4, passo 7 |

## 3. Decisões técnicas

### A1 — Deduplicação das marcações **[DT]**

- A deduplicação é o primeiro passo do cálculo alternativo; a ordem completa está na seção 4.
- Marcações com o mesmo minuto (H:i) contam como uma só para classificação e pareamento.
- Exclusivo da regra alternativa. Registros do banco, dados brutos, espelho oficial e `OvertimeCalculator` não mudam.
- **Diferença explícita em relação ao oficial:** o espelho oficial conta as marcações brutas. Um dia como
  `07:26 17:30 17:30` aparece no oficial como `Marcações incompletas` (3 marcações) e, na alternativa, é calculado
  como dia de 2 marcações. A comparação deve exibir as duas situações lado a lado.

### A4 — Pares fora das janelas previstas **[V]**

- **Validado (R4, R7):** todos os casos fora das janelas previstas vão para conferência manual. Se **qualquer** par
  de marcações estiver fora das janelas, o dia inteiro vai para conferência, inclusive em dias mistos com
  4 marcações. Não há extra nem atraso alternativo para esse dia. O resultado oficial não é substituído.
- Definição: um par de marcações está **fora das janelas** quando a sobreposição com as janelas previstas
  (07:30–12:00 e 13:00–saída prevista) é de 0 minuto. O intervalo 12:00–13:00 não é janela de trabalho; portanto,
  um par confinado ao intervalo também está fora das janelas. Os pares são os registrados, [1ª, 2ª] e [3ª, 4ª],
  antes da P1.
- `12:10 12:50` e `12:00 13:00` estão fora das janelas → conferência.
- Exemplos de conferência: `18:00 22:00`; `05:00 07:20`; `06:00 07:00 18:00 19:00`; `12:10 12:50`; `12:00 13:00`;
  `07:30 12:00 18:00 20:00` (dia misto).
- Ocorrências no período analisado: nenhuma.

### A5 — Tolerância e lacunas **[V]**

- **Substituída pela resposta R3:** a ausência no horário de almoço não é contabilizada como atraso; o atraso
  considera **somente a entrada e a saída**.
- Atraso = entrada tardia + saída antecipada. Cada parcela é medida em minutos de janela (o intervalo 12:00–13:00
  não conta) e avaliada separadamente com o limiar de 5 min: até 5 min (inclusive) → 0; acima de 5 min → valor
  integral, sem descontar os 5 min (regra geral e P2, validados em R2).
- As parcelas **não se acumulam** para ultrapassar o limiar. Exemplo: entrada às 07:34 e saída às 17:26 → 0 + 0.
- Lacunas entre a 2ª e a 3ª marcação (almoço adiantado, atrasado ou prolongado, ou ausência no meio de uma janela)
  não geram atraso. Exemplos: retorno às 13:06 → 0; saída às 11:58 e retorno às 13:03 → 0.
- A regra de lacuna por trecho da versão 0.2 deixa de valer.

### Limites do almoço com duas marcações **[V] + [D]**

- **Validado (R8):** `11:59 13:00` e `12:00 13:01` vão para conferência manual. A presunção de almoço (P1) não se
  aplica nesses casos.
- **Decidido (D5):** `11:50 13:00` também vai integralmente para conferência manual, sem extra nem atraso
  alternativos.
- Esses pares se sobrepõem pouco às janelas (1 min nos casos de R8; 10 min em `11:50 13:00`). Na versão 0.3, os
  casos de R8 seguiam a P1 e geravam 08:59 de atraso; esse resultado deixa de valer.
- **Pendente:** o critério geral para outros pares próximos dos limites do almoço (seção 7, item 1). Até a definição,
  somente os três casos confirmados vão para conferência por este passo; outros pares, como `12:00 13:10`, seguem a
  P1. No período analisado, nenhum dia de 2 marcações tem sobreposição de 1 a 5 min com as janelas **[C]**.

### Almoço deslocado **[V] + [D]**

- **Validado (R9):** quando houver trabalho entre 12:00 e 13:00 e o almoço tiver sido feito em outro horário, o dia
  vai para conferência manual. Esse período não é contado automaticamente como extra.
- **Decidido (D2):** o almoço parcialmente deslocado segue a mesma regra.
- Condição operacional, para dias com 4 marcações (após A1): o almoço registrado é o trecho entre a 2ª e a 3ª
  marcação. O dia vai para conferência, sem extra nem atraso alternativos, quando há minutos trabalhados dentro de
  12:00–13:00 **e** o almoço registrado não cabe integralmente em 12:00–13:00.
  - Almoço inteiramente fora do intervalo (ex.: `08:27 14:16 16:04 17:28`, almoço 14:16–16:04) → conferência (R9).
  - Almoço parcialmente sobreposto ao intervalo (ex.: `07:30 11:30 12:30 17:30`, almoço 11:30–12:30, trabalho em
    12:30–13:00) → conferência (D2).
  - Almoço inteiramente dentro do intervalo e mais curto (ex.: `07:30 12:00 12:30 17:30`) → não é deslocamento; o
    trabalho em 12:30–13:00 conta como extra pela P4 (00:30).
  - Almoço que cobre todo o intervalo (ex.: `07:35 11:26 13:13 17:36`) → não há trabalho em 12:00–13:00; o dia é
    calculado normalmente.
- Dias de 2 marcações sem almoço registrado (meio período, ex.: `07:23 12:17`) não têm almoço deslocado: os minutos
  trabalhados em 12:00–12:17 continuam contando como extra pela P4, como no PDF (29/06).
- Ocorrências na simulação: 2 dias com 4 marcações e almoço fora ou parcialmente fora do intervalo.

### A7 — Acesso à comparação **[D]**

- **Decidido (D7):** todos os usuários autenticados podem acessar os dados da comparação. Usuários não autenticados
  não têm acesso, seguindo a autenticação já usada pelas rotas do sistema (grupo `auth`) **[C]**.
- Removidos na versão 0.6: a lista de e-mails autorizados, a exigência dessa lista e o bloqueio por padrão vinculado
  a ela. Não são necessários papéis, permissões nem migrations.
- **Divergência encerrada:** a validação formal R5 do RH (seção 6) previa acesso **somente ao pessoal do RH**; a
  decisão D7 do responsável é mais ampla. Na versão 0.12 a política de acesso foi definida: todos os usuários
  autenticados podem acessar a comparação. R5 continua registrada como recebida, substituída por essa decisão.
- Implementado na fase 7.5 conforme D7 (seção 13): rotas no grupo `auth`, sem papéis ou permissões.

### A8 — Identificação no CSV **[DT]**

- O CSV de comparação identifica o funcionário por um código pseudonimizado **estável entre execuções**, para
  permitir comparação histórica. Não traz nome, PIS ou outros identificadores pessoais desnecessários.
- A correspondência código → funcionário aparece somente em tela autenticada (A7, D7), nunca no CSV.
- Implementado na fase 7.5 (seção 13): código derivado do ID com HMAC-SHA256 e a chave da aplicação (`APP_KEY`).
- A simulação da etapa 6 usou pseudônimos por execução (F01…F46), que **não** são estáveis.

### Situações de conferência **[V] + [D]**

- **Rótulo confirmado (R10):** `Conferência manual — fora das janelas previstas`, código técnico `outside_schedule`.
  O código é coerente com os valores em snake_case de `TimesheetDayStatus` (ex.: `non_working_day_worked`) **[C]**.
- **Aplicação (D3):** o mesmo rótulo e código valem para pares fora das janelas (A4), limites do almoço (R8), almoço
  deslocado total ou parcial (R9, D2) e proteção de turno noturno (D1).
- **Motivo específico (D3):** cada dia em conferência guarda também o motivo. A arquitetura documentada permite isso:
  cada dia do `TimesheetMirror` é um conjunto de campos que já traz `status`, `status_label` e `needs_review`
  **[C]**, e o bloco alternativo acrescenta um campo de motivo sem alterar os campos oficiais. Valores
  implementados na fase 7.2 (`AlternativeReviewReason`, seção 10) **[C]**:

  | Motivo | Situação |
  |---|---|
  | `pair_outside_windows` | Par fora das janelas, inclusive dia misto e par confinado ao intervalo (A4) |
  | `lunch_boundary` | Limites do almoço (R8, D5) |
  | `shifted_lunch` | Almoço deslocado, total ou parcial (R9, D2) |
  | `night_shift_guard` | Proteção de turno noturno (D1) |
  | `lunch_interval_punch` | Meio período com marcação no almoço sem determinação inequívoca (D9) |

- O rótulo e o motivo indicam que as marcações são insuficientes ou incompatíveis com a regra alternativa e precisam
  de conferência. **Não** são conclusão disciplinar, falta ou irregularidade do funcionário.
- Os demais casos de conferência mantêm os rótulos existentes do espelho (`Sem marcação`, `Marcações incompletas`,
  `Mais de 4 marcações`).

### Turno que atravessa a meia-noite **[C] + [D]**

Resultado da auditoria somente leitura de 22/10/2026:

- **Associação à data [C]:** cada marcação AFD traz data e hora próprias (`AfdParser`, item 1.3) e é gravada com
  essa data (`AfdImporter`). A marcação manual usa a data da tela de edição (`EmployeePointsEdit`). Não existe
  indicação de entrada ou saída, nem dado de turno ou escala (`employees` tem apenas PIS, nome, função e rescisão).
- **Agrupamento [C]:** o espelho agrupa por data registrada e ordena os horários de forma crescente dentro do dia
  (`TimesheetMirror::build` e `times`). O `OvertimeCalculator` tem um ajuste de virada de dia que nunca é acionado,
  porque a saída já vem ordenada depois da entrada.
- **Detecção por sequência (proposta da versão 0.3) — descartada:** "segunda marcação anterior à primeira" não é
  observável dentro do dia. O NSR, que daria a ordem real, não existe nas marcações já importadas (171.312 sem NSR)
  nem nas manuais (1.269) **[C]**.
- **Dados [C], últimos 12 meses:** nenhuma marcação entre 22:00 e 04:59; nenhum caso de última marcação ≥ 20:00
  seguida de primeira marcação < 06:00 no dia seguinte; maior duração entre a 1ª e a última marcação de 13h08
  (788 min), ou seja, 52 min abaixo do limite de 14h. Desde 2017 houve apenas 9 marcações entre 22:00 e 04:59
  (2018–2023).
- **Risco de cálculo diurno:** turnos noturnos seguidos aparecem como `06:00 22:00` no mesmo dia (saída da noite
  anterior + entrada da noite seguinte). Esse par se sobrepõe às janelas e seria calculado como 16h de jornada diurna.
  Um turno isolado (`22:00` no dia D, `06:00` no dia D+1) cai na P5 nos dois dias.
- **Proteção adotada (D1):** o dia útil vai para conferência (motivo `night_shift_guard`) quando:
  - (a) qualquer marcação estiver entre 22:00 e 04:59, inclusive; ou
  - (b) a duração entre a 1ª e a última marcação do dia, após A1, passar de 14h (mais de 840 min; exatamente 14h
    não aciona).

  Nos últimos 12 meses, nenhum dia seria afetado: não há falso positivo observado.
- **Gatilho descartado (D1):** descanso inferior a 11h. Teria 36 ocorrências em 22 funcionários nos últimos 12 meses,
  todas de jornada diurna (menor descanso: 9h31), ou seja, falsos positivos.
- **Limitações (D1):** a proteção **não** detecta todos os turnos noturnos possíveis e **não** garante a
  classificação correta em todos os casos. Exemplos:
  - um turno que termine antes das 22:00 e comece depois das 04:59, sem passar de 14h, não é identificado;
  - um turno isolado vira duas marcações soltas, que caem na P5, e não na proteção;
  - a proteção só atua em dias úteis, porque sábados, domingos e feriados usam o resultado oficial (regra geral 3).

  Uma detecção confiável exigiria dados que o sistema não tem: NSR em todas as marcações, indicação de entrada e
  saída ou cadastro de escala.

## 4. Especificação do cálculo alternativo

Somente dias úteis com jornada configurada. Sábados, domingos e feriados recebem o valor oficial.

Ordem de classificação, conforme implementada em `AlternativeTimesheetRule` (seção 10). Os passos de conferência 1, 4, 5,
6 e 7 usam o rótulo `Conferência manual — fora das janelas previstas` / `outside_schedule`, com o motivo indicado (D3).

1. **Proteção de turno noturno** (D1): marcação entre 22:00 e 04:59, ou mais de 14h entre a 1ª e a última
   marcação → conferência, motivo `night_shift_guard`. Na versão 0.7 este passo vem antes da deduplicação, por
   decisão do responsável; o resultado é o mesmo, porque a deduplicação não muda os horários nem a duração.
2. **Deduplicação** (A1).
3. **Quantidade:** 0 marcações → `Sem marcação` (P7); quantidade ímpar ou > 4 → conferência com os rótulos
   existentes (P5).
4. **Pares fora das janelas** (A4): parear [1ª, 2ª] e [3ª, 4ª] como registrados. Qualquer par com 0 min de
   sobreposição com as janelas → conferência, motivo `pair_outside_windows`.
5. **Limites do almoço** (R8, D5): `11:59 13:00`, `12:00 13:01` e `11:50 13:00` → conferência, motivo
   `lunch_boundary`. Critério geral pendente (seção 7, item 1).
6. **Almoço deslocado** (R9, D2): 4 marcações, trabalho em 12:00–13:00 e almoço [2ª, 3ª] não contido integralmente
   em 12:00–13:00 → conferência, motivo `shifted_lunch`. Pela definição operacional, uma saída fora do horário de
   almoço com 4 marcações (ex.: `07:30 09:00 09:06 17:30`) também cai aqui.
7. **Presunção do almoço** (P1, D6, D8): as marcações em [12:00, 13:00) são desconsideradas e o trabalho efetivo é
   [1ª, última] menos 12:00–13:00.
   - 2 marcações com 1ª ≤ 12:00 e 2ª ≥ 13:00 (P1): trechos [1ª, 12:00] e [13:00, 2ª]. Se a 1ª for exatamente
     12:00, o trecho da manhã fica vazio e o resultado não muda.
   - 4 marcações: a 2ª e a 3ª não alteram o resultado. As marcações no almoço não contam (D8) e as ausências entre
     elas não são atraso (R3).
   - 2 marcações fora da P1 sem marcação no almoço (ex.: `07:30 11:00`, `13:30 17:30`): trecho único, literal.
   - **2 marcações fora da P1 com uma delas em [12:00, 12:59] (D9):**
     - 1ª antes das 12:00 e 2ª no almoço → saída presumida às 12:00 (ex.: `07:23 12:17`, `07:30 12:00`);
     - 1ª no almoço e 2ª depois das 13:00 → entrada presumida às 13:00 (ex.: `12:30 17:30`);
     - qualquer outra combinação → conferência, motivo `lunch_interval_punch`. Com a jornada atual, as demais
       combinações já param nos passos anteriores: duas marcações no almoço, ou entrada no almoço com saída às
       13:00, são pares fora das janelas; `12:00 13:01` é limite aprovado. O motivo fica como proteção.
8. **Cálculo:**
   - **Horas extras [I][V]** = chegada antecipada + após a saída prevista (P3). Sem tolerância. O trabalho dentro
     do intervalo não é extra (D8).
   - **Atraso [V]** = entrada tardia + saída antecipada, medidas em minutos de janela, cada uma zerada até 5 min e
     integral acima (A5, P2). Ausências entre a 2ª e a 3ª marcação não contam (R3). O atraso é informativo: não gera
     desconto, falta ou DSR.

Regras complementares:

- **Unidade:** minutos inteiros; totais = soma dos valores diários; exibição em HH:MM.
- **Dias em conferência:** sem extra nem atraso alternativos; o resultado oficial continua sendo calculado e exibido.
- **Relação com o oficial [C]:** em dia de 2 marcações que cobrem o intervalo,
  `oficial = max(0, extra_alternativo − atraso_bruto)`, sendo o atraso bruto a entrada tardia mais a saída
  antecipada sem limiar. A divergência é exatamente a compensação feita pelo oficial.

## 5. Casos de referência

Dia previsto: segunda a quinta 07:30–12:00 e 13:00–17:30; sexta até 16:30. Valores conferidos na versão 0.7 pelos testes da seção 10.

| Caso | Marcações | Extra oficial | Extra alternativo | Atraso alternativo | Origem |
|---|---|---|---|---|---|
| Entrada 07:36 | 07:36 12:00 13:00 17:30 (seg) | 0 | 0 | 00:06 | regra geral, R2 |
| Atraso grande e extra | 09:10 12:00 13:00 17:50 (ter) | 0 | 00:20 | 01:40 | PDF 30/06 |
| Saída antecipada | 07:23 12:00 13:00 16:22 (qua) | 0 | 00:07† | 01:08 | PDF 22/07 |
| Meio período com saída no almoço | 07:23 12:17 (seg) | 0 | 00:07 | 04:30 | PDF 29/06 (00:24/04:30 no PDF); D8, D9 |
| Meio período com saída no almoço | 06:18 12:12 (qui) | 0 | 01:12 | 04:30 | PDF 09/07 (01:24/04:30 no PDF); D8, D9 |
| Meio período com saída às 12:00 | 07:30 12:00 (seg) | 0 | 0 | 04:30 | D9 |
| Meio período com entrada no almoço | 12:30 17:30 (seg) | 0 | 0 | 04:30 | D9 |
| Meio período à tarde | 13:30 17:30 (seg) | 0 | 0 | 05:00 | trecho único; entrada tardia 270 + 30 |
| Saída 3 min antes | 07:30 17:27 (seg) | 0 | 0 | 0 | P2 |
| Retorno do almoço às 13:03 | 07:30 12:00 13:03 17:30 (seg) | 0 | 0 | 0 | R3 |
| Almoço curto dentro do intervalo | 07:30 12:00 12:30 17:30 (seg) | 00:30 | 0 | 0 | D8 (era 00:30 na versão 0.6) |
| Almoço de 12:10 a 12:50 | 07:30 12:10 12:50 17:30 (seg) | 00:20 | 0 | 0 | D8 |
| Almoço cobrindo o intervalo | 07:35 11:26 13:13 17:36 (qui) | 0 | 00:06 | 0 | R3; simulação, F44 |
| Almoço deslocado | 08:27 14:16 16:04 17:28 (qua) | 0 | — conferência (`shifted_lunch`) | — | R9 |
| Almoço parcialmente deslocado | 07:30 11:30 12:30 17:30 (seg) | 0 | — conferência (`shifted_lunch`) | — | D2 |
| Saída duplicada | 07:26 17:30 17:30 (seg) | 00:04 | 00:04 | 0 | A1, P1 |
| Entrada e saída dentro do limiar | 07:34 12:00 13:04 17:26 (qua) | 0 | 0 | 0 | A5, R3 |
| Fora das janelas | 18:00 22:00 (qua) | 0 | — conferência (`night_shift_guard`) | — | D1 (22:00), antes de A4 |
| Fora das janelas, 4 marcações | 06:00 07:00 18:00 19:00 (qua) | 0 | — conferência (`pair_outside_windows`) | — | A4, R4 |
| Dia misto | 07:30 12:00 18:00 20:00 (qua) | 0 | — conferência (`pair_outside_windows`) | — | A4, R7 |
| Confinado ao intervalo | 12:10 12:50 (qua) | 0 | — conferência (`pair_outside_windows`) | — | A4, R4 |
| Limites exatos do intervalo | 12:00 13:00 (qua) | 0 | — conferência (`pair_outside_windows`) | — | A4, R4 |
| 1 min antes do intervalo | 11:59 13:00 (qua) | 0 | — conferência (`lunch_boundary`) | — | R8 |
| 1 min depois do intervalo | 12:00 13:01 (qua) | 0 | — conferência (`lunch_boundary`) | — | R8 |
| 10 min antes do intervalo | 11:50 13:00 (qua) | 0 | — conferência (`lunch_boundary`) | — | D5 |
| 10 min depois do intervalo | 12:00 13:10 (qua) | 0 | 0 | 08:50‡ | P1; critério geral pendente |
| Turnos noturnos seguidos | 06:00 22:00 (qua) | 06:00 | — conferência (`night_shift_guard`) | — | D1 (a) e (b) |
| Marcação às 04:59 | 04:59 13:30 (qua) | 0 | — conferência (`night_shift_guard`) | — | D1 (a) |
| Duração acima de 14h | 05:10 19:20 (qua) | 04:10 | — conferência (`night_shift_guard`) | — | D1 (b) |
| Duração de exatamente 14h | 05:00 19:00 (qua) | 04:00 | 04:00 | 0 | D1 (b) não aciona |
| Sábado | 07:08 12:05 | 04:57 | 04:57 | — | PDF 27/06 |
| Feriado 09/07 | 05:49 12:01 13:00 | 06:11 | 06:11 | — | regra geral 4 |

† Entrada às 07:23, antes do início da jornada (07:30): 7 min trabalhados fora das janelas viram extra pela P3,
sem tolerância. O atraso de 01:08 é a saída antecipada (16:22 contra 17:30, acima do limiar de 5 min, P2).

‡ `12:00 13:10` não está entre os casos confirmados de R8 e D5 e segue a P1: trechos [12:00, 12:00] e [13:00, 13:10];
entrada tardia de 270 min (07:30–12:00) + saída antecipada de 260 min (13:10–17:30) = 530 min (08:50); extra 0.
O resultado pode mudar quando o critério geral for definido (seção 7, item 1).

Cálculos de apoio:

- **Almoço curto:** oficial 600 − 30 − 540 = 30; alternativo 0, porque as marcações de 12:00 e 12:30 são
  desconsideradas (D8). `07:30 12:10 12:50 17:30`: oficial 600 − 40 − 540 = 20; alternativo 0.
- **Meio período à tarde:** `13:30 17:30` não tem marcação no almoço; entrada tardia = 270 min (07:30–12:00) +
  30 min (13:00–13:30) = 300 (05:00); oficial 240 − 540 → 0.
- **Almoço cobrindo o intervalo:** oficial 231 + 263 = 494 → 0; alternativo, saída 6 min após 17:30 = 00:06; entrada
  com 5 min de atraso (≤ 5 → 0); lacuna do almoço não conta (R3).
- **Saída duplicada:** no oficial, `07:26 17:30 17:30` é lido como entrada, início do almoço e saída; sem almoço
  completo, desconta 1h: 604 − 60 − 540 = 4 min.
- **Turnos noturnos seguidos:** oficial 960 − 60 − 540 = 360 (06:00), calculado como jornada diurna. Exemplo
  ilustrativo do risco; não ocorreu no período analisado.
- **Meio período com marcação no almoço (D9):** `07:23 12:17` → trabalho [07:23, 12:00]; extra 7 (chegada antes
  das 07:30), saída antecipada de 270 min (13:00–17:30). `06:18 12:12` → extra 72, atraso 270. `12:30 17:30` →
  trabalho [13:00, 17:30]; entrada tardia de 270 min (07:30–12:00). O PDF conta como extra os minutos após 12:00
  (17 e 12 min); por D8 eles não contam. Oficial: 294, 354 e 300 min, sem desconto de almoço (até 6h) e abaixo
  da jornada → 0.
- **Duração acima de 14h:** 05:10 → 19:20 = 850 min (> 840) → conferência. O oficial continua 850 − 60 − 540 = 250
  (04:10).
- **Duração de exatamente 14h:** 05:00 → 19:00 = 840 min, não aciona D1; nenhuma marcação entre 22:00 e 04:59.
  Alternativo: chegada antecipada de 150 min + 90 min após 17:30 = 240 (04:00); atraso 0 (P1). Oficial:
  840 − 60 − 540 = 240 (04:00).
- **Ordem dos passos:** `18:00 22:00` também estaria fora das janelas (A4), mas a marcação às 22:00 aciona D1 antes
  (passo 3); o motivo registrado é `night_shift_guard`.
- **Casos de conferência:** o oficial continua sendo calculado e exibido pela regra vigente. `18:00 22:00` =
  240 − 540 → 0; `06:00 07:00 18:00 19:00` = 780 − 660 − 540 → 0; `07:30 12:00 18:00 20:00` = 750 − 360 − 540 → 0;
  `08:27 14:16 16:04 17:28` = 349 + 84 = 433 → 0; `07:30 11:30 12:30 17:30` = 600 − 60 − 540 → 0;
  `04:59 13:30` = 511 − 60 − 540 → 0; os pares `12:10 12:50`, `12:00 13:00`, `11:59 13:00`, `12:00 13:01`, `11:50 13:00` e
  `12:00 13:10` ficam
  abaixo de 6h e da jornada → 0.

Na simulação sem A1, A4, R3, R7–R9, D1, D2, D5, D8 e P2: 2.299 dias-funcionário, total oficial de 97.623 min, alternativa
com 99.695 min nos 2.205 dias comparáveis, 490 dias divergentes e 25/25 dias do PDF reproduzidos. Esses números
precisam ser recalculados com as decisões atuais; os números com a implementação estão na seção 10. Com D8, os meios
períodos do PDF que terminam dentro do almoço (29/06 e 09/07) vão para conferência: 23 dos 25 dias são reproduzidos
(seção 10).

## 6. Validações recebidas de RH/jurídico

Respostas formais registradas. Nomes, cargos e datas não foram informados e não são preenchidos aqui.

| # | Origem | Resposta | Critério afetado | Data da validação | Responsável |
|---|---|---|---|---|---|
| J1 | Jurídico | Aceita presumir o intervalo de almoço 12h–13h quando há apenas duas marcações | P1 | a preencher | a preencher |
| J2 | Jurídico | Aceita contabilizar integralmente extras de 1 a 5 minutos, sujeito às normas aplicáveis | P3 | a preencher | a preencher |
| J3 | Jurídico | Não identifica impedimento para apresentar extras e atrasos separadamente, sem compensação | regra geral 2, P4 | a preencher | a preencher |
| R1 | RH | Aceita apresentar extras e atrasos separadamente | regra geral 2, P4 | a preencher | a preencher |
| R2 | RH | Aceita a assimetria: tolerância de 5 min nos atrasos e extra desde o primeiro minuto | P2, P3 | a preencher | a preencher |
| R3 | RH | Não é necessário contabilizar o almoço como atraso; considerar somente entrada e saída | A5, P4 | a preencher | a preencher |
| R4 | RH | Todos os casos fora das janelas previstas devem ir para conferência manual | A4 | a preencher | a preencher |
| R5 | RH | A comparação deve ser acessível somente ao pessoal do RH | A7, A8 | a preencher | a preencher |
| R6 | RH | Aceita deixar fora do escopo jornadas que atravessam a meia-noite; todos os funcionários têm a mesma jornada | regra geral 1 e 8 | a preencher | a preencher |
| R7 | RH | Dia misto: se qualquer par estiver fora das janelas, o dia inteiro vai para conferência, sem extra ou atraso alternativo | A4 | a preencher | a preencher |
| R8 | RH | `11:59 13:00` e `12:00 13:01` vão para conferência; não aplicar a presunção de almoço | P1 | a preencher | a preencher |
| R9 | RH | Trabalho entre 12:00 e 13:00 com almoço em outro horário vai para conferência; não contar como extra automaticamente | P4 | a preencher | a preencher |
| R10 | RH | Rótulo confirmado: `Conferência manual — fora das janelas previstas` | situações de conferência | a preencher | a preencher |

As decisões D1–D9 (seção 2) são do responsável e não constam desta tabela de validações formais. A decisão D7
(acesso) divergia de R5; a política de acesso foi definida na versão 0.12 (seção 7, itens encerrados).

**Homologação dos cálculos (versão 0.12):** após a publicação, o RH verificou os cálculos da regra alternativa e os
considerou corretos. Nome, cargo e data da homologação não foram informados e não são preenchidos aqui. A homologação
do RH **não** equivale a aprovação jurídica formal: não há registro de manifestação jurídica sobre a regra como um
todo, além das respostas J1–J3 sobre critérios específicos.

## 7. Pendências

Nenhuma destas pendências foi resolvida por inferência. A homologação dos cálculos pelo RH (seção 6) não resolve as
pendências abaixo, que continuam sem decisão ou sem registro.

1. **Critério geral dos limites do almoço — pendente:** os casos confirmados são `11:59 13:00`, `12:00 13:01` (R8) e
   `11:50 13:00` (D5). Falta a regra que decide outros pares de 2 marcações próximos do intervalo, como `12:00 13:10`
   ou `11:30 13:00`. Todos os casos confirmados têm uma das marcações exatamente em 12:00 ou 13:00, mas isso não
   foi decidido como regra: aplicada de forma geral, levaria também `07:30 13:00` para conferência. Até a definição,
   só os três casos confirmados vão para conferência por este passo.
2. **Datas e responsáveis das validações formais — pendente:** preencher na seção 6 (J1–J3, R1–R10 e homologação do
   RH da versão 0.12).

Fora das pendências do módulo, por serem decisões separadas: a ativação operacional da regra alternativa e qualquer
promoção da regra a oficial (regra geral 7). Nenhuma das duas foi decidida.

Itens encerrados: almoço parcialmente sobreposto (D2), proteção de turno noturno (D1), rótulo de R8 e R9 (D3),
registro da configuração de jornada (D4), `11:50 13:00` (D5), presunção do almoço (D6), lista de e-mails
autorizados, removida por D7, meio período com marcação no almoço (D9) e, na versão 0.12:

- **Divergência de acesso entre R5 e D7 — encerrada:** o RH havia validado acesso somente ao seu pessoal (R5); o
  responsável decidiu acesso a todos os usuários autenticados (D7). Decisão final: todos os usuários autenticados
  podem acessar a comparação, a correspondência código → funcionário e o CSV; usuários não autenticados não têm
  acesso. É o comportamento implementado na fase 7.5.
- **PIS duplicado em dois cadastros — resolvido (qualidade de dados):** um PIS estava em dois cadastros de
  funcionário (seções 12 e 13), situação anterior às fases 7.x. Em 22/10/2026, após a publicação, o cadastro
  rescindido foi removido diretamente na base, por decisão do responsável; permaneceu um único cadastro, ativo, e as
  marcações do PIS foram preservadas (são vinculadas ao funcionário pelo PIS). A correção **não** fez parte das fases
  7.2–7.5 e não alterou código. Os números das seções 12 e 13, que contam esse PIS duas vezes por cadastro, são
  anteriores à correção.

## 8. Plano técnico (fases 7.2–7.5) — concluído

As fases 7.2 a 7.5 foram implementadas por autorização explícita do responsável (seções 10 a 13) e estão
**concluídas**. Nenhuma prevê migration, mudança no importador AFD, no módulo de benefícios, no Golden Dataset ou no
`OvertimeCalculator`.

| Fase | Entrega | Testes |
|---|---|---|
| 7.2 — **concluída** | Calculadora alternativa isolada (função pura): data, tipo do dia, marcações brutas, jornada e configuração → parcelas, situação e motivo, na ordem da seção 4 | 25 dias do PDF (extras e descontos); todos os casos da seção 5; limites 5/6 min na entrada e na saída; almoço curto, cobrindo o intervalo, deslocado e parcialmente deslocado; A1; A4 com 2 e 4 marcações, inclusive misto e confinado ao intervalo; `12:00 13:00`; `11:59 13:00`; `12:00 13:01`; `11:50 13:00`; `12:00 13:10`; meio período; D1 nos limites 21:59/22:00, 04:59/05:00 e 840/841 min; motivo de cada conferência; relação com o oficial (seção 4) |
| 7.3 — **concluída** | Bloco de configuração da alternativa, **desligado por padrão** | Configuração desligada = nenhum dado alternativo produzido |
| 7.4 — **concluída** | `TimesheetMirror` acrescenta dados alternativos (incluindo o motivo de conferência) por dia e nos totais somente com a configuração ligada | Campos e totais oficiais idênticos com a configuração ligada e desligada; testes de caracterização inalterados |
| 7.5 — **concluída** | Comparação de totais e divergências: tela, correspondência código → funcionário e CSV com código estável (A8) e motivo de conferência, todos restritos a usuários autenticados (A7, D7); registro da configuração de jornada usada (D4) | Usuário não autenticado é redirecionado ao login nas três superfícies; usuário autenticado acessa; impressão e relatórios oficiais sem dados alternativos; CSV sem nome/PIS; configuração registrada em cada comparação |

**Resultado das fases (versão 0.12):**

- **Testes:** suíte completa com 1.232 testes aprovados e nenhuma falha (seção 13). Os testes de caracterização da
  regra oficial não foram alterados.
- **Revisão visual:** interface aprovada.
- **Homologação:** o RH verificou os cálculos e os considerou corretos (seção 6). Sem aprovação jurídica formal
  registrada.
- **Publicação:** push para o GitHub, deploy e execução das migrations concluídos; sistema verificado em funcionamento
  após o deploy. O módulo não tem migration própria; as migrations executadas no deploy pertencem a outras entregas
  publicadas junto (benefícios e importação AFD).
- **Configuração:** a regra alternativa continua **desabilitada por padrão** (`TIMESHEET_ALTERNATIVE_RULE_ENABLED=false`,
  também fixada no ambiente de testes). Sua ativação operacional é uma decisão separada, não tomada neste módulo.
- **Regra oficial:** preservada. Cálculos oficiais, impressão e relatórios continuam usando somente a regra oficial.

## 9. Critérios de autorização e de aceite

Critérios originais (versão 0.11), com a situação na versão 0.12:

1. Validações J1–J3 e R1–R10 registradas (seção 6), com datas e responsáveis preenchidos. — **Parcial:** validações
   e homologação do RH registradas; datas e responsáveis continuam pendentes (seção 7, item 2).
2. Critério geral dos limites do almoço definido (seção 7, item 1). — **Pendente.**
3. Decisão do responsável sobre a divergência de acesso R5 × D7. — **Atendido:** todos os usuários autenticados
   (seção 7, itens encerrados).
4. Autorização explícita do responsável para as fases 7.2–7.5: concedida; as quatro fases foram implementadas com os
   itens 1–3 ainda pendentes. — **Atendido;** fases concluídas.

Critérios de aceite da implementação, quando autorizada (texto original):

- 25 dias do PDF: 23 idênticos em extra e atraso; 29/06 e 09/07 com o mesmo atraso do PDF e extra menor, porque os
  minutos no almoço não contam (D8, D9);
- todos os casos da seção 5 reproduzidos, inclusive o motivo de cada conferência;
- total oficial do período 26/06–25/09 mantido em 97.623 min;
- campos oficiais idênticos com a comparação ligada e desligada;
- acesso negado a usuários não autenticados, na tela, na correspondência e no CSV; acesso permitido a autenticados;
- suíte completa sem falhas;
- diferenças em relação à simulação explicadas pelas decisões P2, A1, A4, R3, R7–R9, D1, D2, D5, D8 e D9.

Resultados alcançados (versão 0.12):

| Critério de aceite | Resultado |
|---|---|
| 25 dias do PDF | Atendido: 23 idênticos; 29/06 e 09/07 com o mesmo atraso e extra menor por D8/D9 (seção 10) |
| Casos da seção 5, com motivo | Atendido: reproduzidos nos testes da fase 7.2 (seção 10) |
| Total oficial 26/06–25/09 em 97.623 min | Atendido por PIS e dia (seções 10 e 12) |
| Campos oficiais idênticos ligada e desligada | Atendido: testes das fases 7.3 e 7.4 e conferência dos 45 funcionários (seção 12) |
| Acesso negado a não autenticados, permitido a autenticados | Atendido nas três superfícies (seção 13), conforme a política de acesso definida |
| Suíte completa sem falhas | Atendido: 1.232 testes aprovados (seção 13) |
| Diferenças em relação à simulação explicadas | Atendido (seções 5 e 10) |
| Homologação dos cálculos (acrescentado na versão 0.12) | Atendido: RH considerou os cálculos corretos (seção 6) |

## 10. Implementação da fase 7.2

Implementada em 22/10/2026. Calculadora isolada, sem integração ao `TimesheetMirror`, às telas, à impressão ou aos
relatórios. Sem configuração de ativação (fase 7.3), migration ou alteração de dados.

| Arquivo | Conteúdo |
|---|---|
| `app/Services/AlternativeTimesheetRule.php` | `evaluate()` (função pura: tipo do dia, jornada, marcações brutas e extra oficial do dia) e `forDay()` (lê a jornada do dia da semana em `config/timesheet.php`), na ordem da seção 4 |
| `app/Services/AlternativeDayResult.php` | Resultado imutável: situação, motivo, extra, atraso e parcelas; valores nulos quando o dia não é calculado |
| `app/Enums/AlternativeReviewReason.php` | Motivos `night_shift_guard`, `pair_outside_windows`, `lunch_boundary`, `shifted_lunch` e `lunch_interval_punch` |
| `app/Enums/TimesheetDayStatus.php` | Nova situação `outside_schedule` (`Conferência manual — fora das janelas previstas`), nunca produzida pelo espelho oficial |
| `tests/Unit/AlternativeTimesheetRuleTest.php` | 100 testes: 25 dias do PDF e totais, casos da seção 5, limiares 5/6 min, lacunas, deduplicação, quantidades, almoço, D1 nos limites, sábado, domingo e feriado, propriedades das parcelas |
| `tests/Feature/AlternativeTimesheetRuleDayTest.php` | 12 testes: meios períodos com marcação no almoço (D9) com o oficial inalterado; jornada do dia da semana, feriado e sábado com o valor oficial, jornada ausente e a relação `oficial = max(0, extra_alternativo − déficit bruto)` em mais de 300 dias de 2 marcações |

Sábados, domingos e feriados recebem o extra oficial como entrada e o devolvem sem alteração. A regra oficial e o
`OvertimeCalculator` não foram alterados.

**Reprodução do PDF (versão 0.8):**

| Dia | Marcações | PDF extra / atraso | Alternativa extra / atraso | Situação |
|---|---|---|---|---|
| 26/06 sex | 07:27 12:00 13:00 16:53 | 00:26 / 00:00 | 00:26 / 00:00 | igual |
| 27/06 sáb | 07:08 12:05 | 04:57 / — | 04:57 / — | igual (oficial) |
| 28/06 dom | 07:20 12:07 | 04:47 / — | 04:47 / — | igual (oficial) |
| 29/06 seg | 07:23 12:17 | 00:24 / 04:30 | 00:07 / 04:30 | extra difere (D8, D9) |
| 30/06 ter | 09:10 12:00 13:00 17:50 | 00:20 / 01:40 | 00:20 / 01:40 | igual |
| 01/07 qua | 07:24 12:00 13:00 19:44 | 02:20 / 00:00 | 02:20 / 00:00 | igual |
| 02/07 qui | 07:32 12:00 13:00 19:42 | 02:12 / 00:00 | 02:12 / 00:00 | igual |
| 03/07 sex | 07:20 12:00 13:00 16:49 | 00:29 / 00:00 | 00:29 / 00:00 | igual |
| 04/07 sáb | 06:14 12:10 | 05:56 / — | 05:56 / — | igual (oficial) |
| 05/07 dom | 06:44 12:08 | 05:24 / — | 05:24 / — | igual (oficial) |
| 06/07 seg | 07:31 12:00 13:00 19:44 | 02:14 / 00:00 | 02:14 / 00:00 | igual |
| 07/07 ter | 07:31 12:00 13:00 19:45 | 02:15 / 00:00 | 02:15 / 00:00 | igual |
| 08/07 qua | 07:31 12:00 13:00 17:50 | 00:20 / 00:00 | 00:20 / 00:00 | igual |
| 09/07 qui | 06:18 12:12 | 01:24 / 04:30 | 01:12 / 04:30 | extra difere (D8, D9) |
| 10/07 sex | 07:29 12:00 13:00 16:44 | 00:15 / 00:00 | 00:15 / 00:00 | igual |
| 13/07 seg | 07:33 12:00 13:00 17:54 | 00:24 / 00:00 | 00:24 / 00:00 | igual |
| 14/07 ter | 07:33 12:00 13:00 17:51 | 00:21 / 00:00 | 00:21 / 00:00 | igual |
| 15/07 qua | 07:24 12:00 13:00 17:50 | 00:26 / 00:00 | 00:26 / 00:00 | igual |
| 16/07 qui | 08:40 12:00 13:00 17:50 | 00:20 / 01:10 | 00:20 / 01:10 | igual |
| 17/07 sex | 07:22 12:00 13:00 16:48 | 00:26 / 00:00 | 00:26 / 00:00 | igual |
| 20/07 seg | 07:22 12:00 13:00 17:52 | 00:30 / 00:00 | 00:30 / 00:00 | igual |
| 21/07 ter | 07:32 12:00 13:00 17:57 | 00:27 / 00:00 | 00:27 / 00:00 | igual |
| 22/07 qua | 07:23 12:00 13:00 16:22 | 00:07 / 01:08 | 00:07 / 01:08 | igual |
| 23/07 qui | 07:33 12:00 13:00 17:54 | 00:24 / 00:00 | 00:24 / 00:00 | igual |
| 24/07 sex | 07:21 12:00 13:00 16:32 | 00:11 / 00:00 | 00:11 / 00:00 | igual |
| **Total** | | **37:19 / 12:58** | **36:50 / 12:58** | atraso igual; extra −29 min |

O PDF trata 09/07 como dia útil; no sistema, 09/07 é feriado cadastrado e receberia o valor oficial. A tabela usa a
mesma premissa do PDF.

**Dados reais, período 26/06–25/09 (somente leitura, versão 0.8):** 2.299 dias-funcionário; total oficial mantido
em 97.623 min.

| Situação na alternativa | Dias |
|---|---|
| Calculado (dia útil) | 2.037 |
| Sábado, domingo ou feriado (valor oficial) | 171 |
| Marcações incompletas (P5) | 89 |
| Conferência — `shifted_lunch` | 2 |

Os 31 meios períodos com marcação no almoço, que estavam em conferência na versão 0.7, passam a ser calculados (D9).
Nos 2.208 dias com valor alternativo, o extra alternativo soma 99.394 min, contra 97.030 min do oficial nos mesmos
dias. O atraso alternativo soma 32.668 min.

**Testes (versão 0.8):** 100 testes unitários e 12 de integração da regra alternativa; suíte completa com 1.177
testes aprovados e nenhuma falha. Os testes de caracterização da regra oficial não foram alterados.

## 11. Implementação da fase 7.3

Implementada em 22/10/2026. Somente o bloco de configuração; sem integração ao `TimesheetMirror` (fase 7.4), às telas,
à impressão ou aos relatórios. Sem migration ou alteração de dados.

| Arquivo | Conteúdo |
|---|---|
| `config/timesheet.php` | Bloco `alternative_rule.enabled` (`TIMESHEET_ALTERNATIVE_RULE_ENABLED`), desligado por padrão |
| `.env.example` | `TIMESHEET_ALTERNATIVE_RULE_ENABLED=false` |
| `app/Services/AlternativeTimesheetRule.php` | `isEnabled()` (somente o valor booleano `true` liga) e `forDay()` devolvendo `null` com a configuração desligada. `evaluate()` continua função pura, independente da configuração |
| `tests/Feature/AlternativeTimesheetRuleConfigTest.php` | 16 testes: padrão desligado; nenhum dado alternativo com a configuração desligada (dia útil, meio período, conferência, turno noturno, P5, sem marcação, feriado, sábado); valores não booleanos não ligam; dados produzidos com a configuração ligada; `evaluate()` igual ligada e desligada; espelho oficial e `OvertimeCalculator` idênticos ligada e desligada, marcações inalteradas |
| `tests/Feature/AlternativeTimesheetRuleDayTest.php` | Liga a configuração antes de cada teste, porque usa `forDay()`; asserções inalteradas |

**Testes (versão 0.9):** suíte completa com 1.193 testes aprovados e nenhuma falha. A regra oficial, o
`OvertimeCalculator`, o `TimesheetMirror` e os testes de caracterização não foram alterados.

## 12. Implementação da fase 7.4

Implementada em 22/10/2026. Somente dados no `TimesheetMirror`; sem tela, correspondência código → funcionário, CSV
ou registro da configuração (fase 7.5). Sem migration ou alteração de dados.

- **Configuração desligada (padrão):** dias e totais do espelho não têm a chave `alternative`; o array é o mesmo de
  antes da fase.
- **Configuração ligada:** cada dia recebe `alternative`, montado a partir do `AlternativeDayResult` de `forDay()`
  (marcações brutas do dia e extra oficial): `status`, `status_label`, `needs_review`, `review_reason`,
  `review_reason_label`, `considered_times`, `uses_official_overtime`, extra e atraso com as parcelas (minutos e
  HH:MM). Dias em conferência têm extra e atraso `null`, sem cálculo substituto. Os totais recebem `alternative`
  com a soma dos valores diários alternativos (extra, entrada tardia, saída antecipada, atraso) e `review_days`.
- **Campos oficiais:** os mesmos, com os mesmos valores, com a configuração ligada ou desligada.
- **Tela de horas extras e impressão:** continuam só com os valores oficiais. `EmployeeHorasExtras` remove a chave
  `alternative` antes de guardar os dias no estado da tela, para que os dados alternativos não cheguem ao navegador
  antes da fase 7.5. A impressão usa somente campos oficiais nomeados.

| Arquivo | Conteúdo |
|---|---|
| `app/Services/TimesheetMirror.php` | Injeta `AlternativeTimesheetRule`; acrescenta `alternative` por dia e nos totais somente com a configuração ligada. Cálculo oficial inalterado |
| `app/Livewire/EmployeeHorasExtras.php` | Remove `alternative` dos dias guardados no estado da tela |
| `tests/Feature/TimesheetMirrorAlternativeTest.php` | 9 testes: sem dados alternativos por padrão; campos e totais oficiais idênticos ligada e desligada, marcações inalteradas; valores por dia (casos da seção 5); conferência com motivo e oficial preservado (`shifted_lunch`, `night_shift_guard`); totais = soma diária; período vazio; tela sem dados alternativos; impressão idêntica ligada e desligada |
| `tests/Feature/AlternativeTimesheetRuleConfigTest.php` | O teste do espelho ligado e desligado (fase 7.3) passa a comparar somente os campos oficiais, porque a chave `alternative` agora existe com a configuração ligada |

**Dados reais, período 26/06–25/09 (somente leitura, versão 0.10):** total oficial de 97.623 min por funcionário (PIS)
e dia, como na seção 10. Espelho de todos os 45 funcionários com marcações no período: campos e totais oficiais
idênticos com a configuração ligada e desligada. Observação fora do escopo: um PIS está em dois cadastros de
funcionário; somado por cadastro, o espelho conta as marcações desse PIS duas vezes (101.179 min), situação anterior a
esta fase e independente da configuração. Corrigido depois, fora das fases (seção 7, itens encerrados).

**Testes (versão 0.10):** suíte completa com 1.202 testes aprovados e nenhuma falha. `OvertimeCalculator`, a lógica
oficial do `TimesheetMirror` e os testes de caracterização não foram alterados.

## 13. Implementação da fase 7.5

Implementada em 22/10/2026. Sem migration, alteração de dados ou nova dependência. Com a regra alternativa desligada
(padrão), as três superfícies não produzem dados: a tela e a correspondência exibem um aviso, o CSV responde 404 e o
menu não mostra o link.

**Superfícies** (grupo `auth`, conforme D7; usuário não autenticado é redirecionado ao login):

| Rota | Conteúdo |
|---|---|
| `reports.alternative-comparison` (`/reports/alternative-comparison`) | Período (26–25, como o espelho), configuração de jornada usada, totais por código (extra e atraso oficiais e alternativos, dias em conferência em cada regra, dias divergentes) e lista de dias divergentes ou de todos os dias, com situação e motivo de conferência. Valor não calculado aparece como `—`, nunca como zero |
| `reports.alternative-comparison.codes` (`/reports/alternative-comparison/codes`) | Correspondência código → nome e função, com busca. Não mostra PIS |
| `reports.alternative-comparison.csv` (`/reports/alternative-comparison/csv?start=AAAA-MM-DD&end=AAAA-MM-DD`) | CSV da comparação do período |

**Decisões técnicas [DT]:**

- **Fonte única:** `AlternativeComparison` lê os campos oficiais e a chave `alternative` do `TimesheetMirror` para os
  funcionários com marcações no período; tela e CSV usam o mesmo resultado. Nenhum cálculo novo.
- **Código (A8):** `F` + 10 caracteres hexadecimais do HMAC-SHA256 do ID do funcionário com `APP_KEY`. Estável entre
  execuções enquanto `APP_KEY` não mudar; a troca da chave muda todos os códigos.
- **Dia divergente:** com extra alternativo calculado, extra ou atraso diferente do oficial (o atraso oficial não
  calculado, como em dia de 3 marcações, é diferente de 0); sem extra alternativo, somente quando o oficial não exige
  conferência (ex.: almoço deslocado). Dias que as duas regras deixam sem cálculo (ex.: sem marcação) não são
  divergentes.
- **CSV:** UTF-8 com BOM, separador `;`, uma linha por código e dia do período, minutos inteiros, célula vazia = não
  calculado. Colunas: `codigo`, `data` (dd/mm/aaaa), `tipo_dia`, `marcacoes` (após a deduplicação A1),
  `oficial_situacao`, `oficial_extra_min`, `oficial_atraso_min`, `alternativa_situacao`, `alternativa_motivo`,
  `alternativa_extra_min`, `alternativa_atraso_min`, `divergente` (sim/não), `configuracao`. Sem nome nem PIS. Textos
  iniciados por `=`, `+`, `-`, `@`, tabulação ou retorno de carro recebem apóstrofo, para não virarem fórmula.
- **Registro da configuração (D4):** sem persistência em banco. Cada comparação (tela ou CSV), ligada ou desligada,
  grava no log da aplicação a superfície, o período, o usuário, se a regra estava ligada, a configuração de jornada
  (jornada por dia da semana e tolerância de entrada oficial) e um identificador curto (SHA-256). A mesma
  configuração aparece na tela e na coluna `configuracao` do CSV.

| Arquivo | Conteúdo |
|---|---|
| `app/Services/AlternativeComparison.php` | Comparação por código, totais, dias divergentes e registro da configuração |
| `app/Services/AlternativeEmployeeCode.php` | Código pseudonimizado estável (A8) |
| `app/Livewire/AlternativeComparisonReport.php` e view | Tela de comparação |
| `app/Livewire/AlternativeEmployeeCodes.php` e view | Correspondência código → funcionário |
| `app/Http/Controllers/AlternativeComparisonExportController.php` | CSV |
| `app/Http/Requests/AlternativeComparisonExportRequest.php` | Validação do período do CSV |
| `routes/web.php` | Três rotas no grupo `auth` |
| `resources/views/components/layouts/app/sidebar.blade.php` | Link "Comparação alternativa", somente com a regra ligada |
| `tests/Feature/AlternativeComparisonTest.php` | 30 testes: login exigido e acesso autenticado nas três superfícies; nada produzido com a regra desligada; totais, divergências, situação e motivo; valores oficiais iguais ao espelho e idênticos com a regra desligada; marcações inalteradas; `—` para não calculado; período inválido e sem marcações; CSV sem nome/PIS e consistente com a tela; validação do período; proteção contra fórmulas; código estável; correspondência; registro da configuração ligada e desligada; impressão e relatórios oficiais sem dados alternativos |

**Dados reais, período 26/06–25/09 (somente leitura, versão 0.11):** 45 funcionários com marcações, 45 códigos
distintos, 4.140 linhas, em cerca de 1 s. Totais iguais aos do espelho da seção 12: extra oficial 101.179 min,
alternativo 102.978 min; atraso oficial 32.043 min, alternativo 33.076 min; 616 dias divergentes (614 por valores, 2
por `shifted_lunch`). Os totais oficiais por cadastro incluem o PIS duplicado da seção 12, não corrigido nesta fase;
os dois cadastros recebem códigos diferentes. O PIS duplicado foi corrigido depois, fora das fases (seção 7, itens
encerrados); esses números são anteriores à correção.

**Pendências na versão 0.11:** seção 7, então com três itens. A fase seguiu D7 (todos os usuários autenticados), como
previa a seção 8; a divergência com R5 (acesso somente ao RH) ficou aberta naquela versão e foi encerrada na versão
0.12 com a definição da política de acesso (seção 7, itens encerrados).

**Testes (versão 0.11):** suíte completa com 1.232 testes aprovados e nenhuma falha. `OvertimeCalculator`, a lógica
oficial do `TimesheetMirror` e os testes de caracterização não foram alterados.

## 14. Encerramento do módulo

Registrado em 22/10/2026 (versão 0.12). Este documento é o plano vigente do módulo: não existe outro documento de
sprints para a regra alternativa. A seção 8 controla as fases 7.2–7.5.

| Item | Situação |
|---|---|
| Fases 7.2, 7.3, 7.4 e 7.5 | Concluídas (seções 10 a 13) |
| Testes | 1.232 aprovados, nenhuma falha |
| Revisão visual da interface | Aprovada |
| Homologação dos cálculos | RH considerou os cálculos corretos (seção 6) |
| Aprovação jurídica formal da regra | Não registrada; somente J1–J3 sobre critérios específicos (seção 6) |
| Política de acesso | Definida: todos os usuários autenticados (D7); divergência R5 × D7 encerrada (seção 7) |
| PIS duplicado em dois cadastros | Resolvido como qualidade de dados, fora das fases (seção 7) |
| Push, deploy e migrations | Concluídos; sistema verificado em funcionamento após o deploy |
| Regra alternativa | Desabilitada por padrão; ativação operacional é decisão separada |
| Regra oficial | Preservada; continua sendo a usada nos cálculos oficiais, na impressão e nos relatórios |

O módulo de regra alternativa do espelho de ponto está **formalmente encerrado**. Nenhuma sprint nova está planejada
para ele. Correções futuras serão tratadas sob demanda, somente se surgirem problemas reais. As pendências residuais
da seção 7 (critério geral dos limites do almoço; datas e responsáveis das validações) continuam abertas e não
impedem o encerramento, porque não alteram o comportamento implementado; qualquer decisão sobre elas que mude a regra
será tratada como trabalho sob demanda.
