# Módulo de Benefícios — VT / VR / VD

**Especificação funcional e técnica**

| | |
|---|---|
| Versão | 3.3 |
| Status | **Especificação definitiva** |
| Data | 29/09/2026 (3.0) · 20/10/2026 (3.1, 3.2, 3.3) |
| Escopo | Vale Transporte (VT), Vale Refeição (VR), Vale Desjejum (VD) |
| Stack | Laravel 13.21.1 · PHP 8.4.1 · Livewire 4.3.3 · Flux 2.15 Free · WireUI 2.6 · Pest 4 · MySQL (produção) · SQLite (testes/local) |
| Próximo passo | "Analisar esta especificação contra o código atual do projeto e criar o plano de implementação." |

> Este documento é o contrato funcional e técnico do módulo. Não contém código.
> Todas as regras aqui descritas estão decididas. Não há decisões de negócio pendentes.
> Pontos operacionais que exigem atenção no plano de implementação estão na seção 20.

---

## Sumário

1. Histórico de alterações
2. Regras decididas
3. Conceitos temporais: competência, dias-base, eventos previstos e realizados
4. Elegibilidade e participantes
5. Modelo de domínio
6. Regra de quantidade e saldo negativo
7. Regras financeiras
8. Ajustes
9. Deduplicação
10. Estados da competência e fechamento
11. Snapshot
12. Calendário
13. Integração com o ponto (MVP)
14. Telas do MVP
15. Valores monetários
16. Casos de borda
17. Proposta de banco (MySQL)
18. Fluxo completo da competência
19. Fora do escopo
20. Pontos de atenção para o plano de implementação
21. Dependências para a implementação

---

## 1. Histórico de alterações

| Versão | Alteração |
|---|---|
| 1.0 | Especificação inicial com pendências P1–P12. |
| 2.0 | P1–P12 resolvidas: janela de realizados = mês civil M−1; VR/VD vigentes em 01/M; admissão/rescisão não limitam o cálculo; saldo negativo com carry-forward; todos batem ponto; reuso de `Employee`; usuário único sem roles; planilha = Golden Dataset; VR/VD globais; saída = tela de apuração; `OtherAbsence` = −1. Deduplicação passou a ser por competência. Abertas D1–D5. |
| **3.0** | **D1** → eventos previstos permitidos na própria competência (`Manual`/`Hr`), com deduplicação contra sugestões do ponto. **D2** → saldo negativo sem benefício elegível na competência seguinte não migra; permanece como pendência administrativa rastreável. **D3** → referência em 01/M também para elegibilidade, itinerário e tarifa de VT. **D4** → `TimesheetAdjustmentSuggester` faz parte do MVP. **D5** → `app/Enums` e `app/Services` aprovados. Corrigida a inconsistência de participação: `recision_date` deixa de ser filtro; participação = elegibilidade em 01/M. Modelo de carry-forward revisto. Alteração de ajuste confirmado definida pelo fluxo normal (rejeição + substituição). |
| **3.1** | **Geração incremental de sugestões do ponto (etapa S1).** A geração deixa de exigir o ponto importado até o fim da janela: processa a janela até o **horizonte de cobertura** (último dia importado − 1), pode ser repetida após novas importações e registra cada execução (`benefit_suggestion_runs`). Dia útil sem nenhuma batida importada não gera ausência e é sinalizado como conflito. Regras R33–R35. Conflitos de importações corrigidas (S2) e cauda retroativa para a competência seguinte (S3) **não fazem parte desta versão**. |
| **3.2** | **Conflitos após correções no ponto (etapa S2).** Novos conflitos calculados (não persistidos) comparam ajustes existentes com a evidência atual do ponto: trabalho sem batida, sugestão fora do ponto coberto (cobertura anterior conhecida ou desconhecida) e ausência em dia que perdeu as batidas importadas; `absence_with_punch` passa a identificar o ajuste. Todo conflito informa ajuste, cobertura e ação recomendada. Conflitos não alteram ajustes e não bloqueiam confirmação nem fechamento. Batidas importadas não podem ser excluídas pela tela de edição de pontos. Cauda retroativa (S3) **continua fora**. |
| **3.3** | **Compensação entre competências (etapa S3).** Eventos da janela de uma competência fechada posteriores à sua cobertura registrada ("cauda") são sugeridos na competência imediatamente seguinte como compensação: origem `Timesheet`, classe retroativa, somente por um caminho dedicado e validado (R36–R39). Conflitos C1–C5 avaliam também as compensações; novos conflitos de cauda não compensada e não compensável. Competências fechadas nunca recebem lançamentos automáticos. |

---

## 2. Regras decididas

| # | Regra |
|---|---|
| R1 | Banco de produção: MySQL. SQLite em testes/desenvolvimento. |
| R2 | Competência = mês calendário completo do benefício (01 → último dia). |
| R3 | O período de ponto 26→25 continua válido para horas e não é alterado por este módulo. |
| R4 | Eventos **realizados** que ajustam a competência M são os do mês calendário M−1. |
| R5 | Dias-base = segunda a sexta da competência, excluindo feriados do cadastro existente. Sábado e domingo não entram. |
| R6 | Para benefícios, qualquer batida válida no dia = presença. Diferente da regra de horas extras, intencionalmente. |
| R7 | Batida em sábado, domingo ou feriado → +1 VT, +1 VR, +1 VD. Sem mínimo de horas. Sem proporcionalidade. |
| R8 | Feriado em sábado/domingo gera um único acréscimo. `HolidayWorked` tem precedência. |
| R9 | Ausência que retira o direito ao dia → −1 VT, −1 VR, −1 VD. O motivo explica/audita e não altera o impacto. |
| R10 | VT, VR e VD têm elegibilidade independente. |
| R11 | VT diário depende do itinerário e das tarifas; VT total = VT diário × dias finais VT. Sem VT, nenhum itinerário é exigido. |
| R12 | VR e VD têm valor diário global (não varia por funcionário, cargo, setor ou função). VD tem valor próprio. |
| R13 | Data de referência de toda vigência da competência M = **01/M**: valores VR/VD, elegibilidade (`EmployeeBenefit`), itinerário (`TransportRoute`) e tarifa (`TransportFarePrice`). Sem rateio no MVP. Histórico preservado. |
| R14 | Ajuste = evento com múltiplos impactos (um por tipo de benefício). |
| R15 | Status de ajuste: `Pending`, `Confirmed`, `Rejected`. Só `Confirmed` afeta o cálculo. Rejeitados não são apagados. |
| R16 | Origens: `Manual`, `Timesheet`, `Hr`, `Import`. Ajustes do ponto entram como `Pending`. |
| R17 | Quantidade final ≥ 0. O excedente negativo é aplicado na competência seguinte **somente** se houver elegibilidade ao mesmo benefício em 01/M+1; caso contrário, permanece como pendência administrativa rastreável. Nunca é descartado silenciosamente, nunca migra para M+2 em diante, nunca é aplicado em outro benefício e nunca gera `BenefitAdjustment`. |
| R18 | Admissão e rescisão não determinam quantidade nem proporcionalizam o cálculo, e não são filtro de participação. O ponto é a fonte dos dias trabalhados. |
| R19 | Não há fluxo para funcionários sem ponto; todos os participantes batem ponto. |
| R20 | Reutilizar `Employee`. Sem cadastro paralelo e sem novos campos de admissão/rescisão. |
| R21 | Um único usuário administrador com acesso total. Sem roles, permissions, policies, gates ou níveis de aprovação. |
| R22 | A planilha existente é a referência funcional e o Golden Dataset. |
| R23 | MVP entrega tela de apuração/fechamento. Sem exportações bancárias, CNAB ou formatos externos. |
| R24 | Competência: `Open` → `Calculated` → `Closed`. Reabertura explícita e auditável. |
| R25 | Fechamento preserva snapshot; mudanças posteriores de tarifas/valores/itinerários não alteram competências fechadas. |
| R26 | Deduplicação por efeito dentro da competência. Não existe trava global por funcionário + data. |
| R27 | Sem entidade de ausências/RH agora; a arquitetura permite criá-la no futuro, gerando ajustes com `source = Hr`. |
| R28 | Unitários em `DECIMAL(10,2)`, totais em `DECIMAL(12,2)`; sem float; cálculo interno em centavos inteiros. |
| R29 | Desconto de 6% do VT, salário e integração com folha estão fora do escopo. |
| R30 | Eventos **previstos** (administrativos, conhecidos antecipadamente) podem ter datas dentro da própria competência M e afetam diretamente M. Origem `Manual` (ou futuramente `Hr`). |
| R31 | O `TimesheetAdjustmentSuggester` faz parte do MVP, é idempotente e apenas lê `points`. |
| R32 | Lógica de domínio em `app/Enums` e `app/Services`, separada da camada Livewire. |
| R33 | **Horizonte de cobertura** = maior data de `points` com `type = 'importado'` − 1 dia. Batidas manuais não movem o horizonte; o dia mais recente é excluído por poder estar incompleto. A geração analisa somente `[início da janela, min(fim da janela, horizonte)]`. É uma aproximação baseada na premissa de um único AFD acumulado e cronológico (ver 13.5), não uma prova de que cada dia foi importado. |
| R34 | Dia útil dentro do horizonte **sem nenhuma batida importada** (de qualquer PIS) não gera sugestão de ausência para ninguém; é listado como conflito `Dia útil sem batidas`. Créditos de sábado/domingo/feriado continuam dependendo apenas de evidência positiva (batida). |
| R36 | **Compensação da cauda.** Se a competência anterior O está `Closed` e sua cobertura registrada (maior `processed_to` das execuções de O) não alcança o fim da janela de O, os dias seguintes à cobertura até o fim da janela, limitados ao horizonte (R33), são analisados com as regras de 13.3 e R34 na geração da competência imediatamente seguinte D. Os eventos viram ajustes `Timesheet`/`Pending` em D, classe retroativa, com observação automática que cita a data, a origem e a execução que define a cobertura. Sem execução registrada em O, nenhuma cauda é presumida; a tela orienta o lançamento `Manual`. |
| R37 | Compensação somente para funcionários participantes de O **e** de D. Quem participava de O e não participa de D é listado como pendência (conflito), sem ajuste. |
| R38 | Destino da compensação: somente a competência imediatamente seguinte à origem, editável. Destino fechado não recebe lançamentos: o evento é listado como conflito na página do destino e na da competência seguinte; o caminho é ajuste `Manual` retroativo. Reabrir a origem não cria nem desfaz compensações. |
| R39 | Identidade da compensação pela origem: `dedupe_key = "timesheet-comp:{origem}:{funcionário}:{data}:{motivo}"` (`UNIQUE`). Uma compensação existe no máximo uma vez em todo o sistema; rejeitada não é recriada. Na edição, motivo e observação podem mudar; a data, não. |
| R35 | A geração é incremental e idempotente: pode ser executada várias vezes durante a janela; cada execução cria apenas os eventos novos (DD1/DD4) e é registrada em `benefit_suggestion_runs`, inclusive quando nada é criado. Ajustes existentes nunca são alterados pela geração. |

---

## 3. Conceitos temporais

### 3.1 Definições

| Conceito | Definição | Exemplo — competência 10/2026 |
|---|---|---|
| **Competência** | Mês calendário do benefício. | 01/10/2026 → 31/10/2026 |
| **Data de referência** | Primeiro dia da competência; define todas as vigências (R13). | 01/10/2026 |
| **Dias-base** | Seg–sex da competência, menos feriados. | 22 − 1 (12/10) = **21** |
| **Janela de eventos realizados** | Mês calendário M−1. Derivada, não editável. | 01/09/2026 → 30/09/2026 |
| **Período de ponto** | Ciclo 26→25 do módulo de horas. Não participa deste módulo. | — |

### 3.2 Três classes de evento

A classe é **derivada das datas do ajuste** em relação à competência a que ele pertence; não há campo próprio.

| Classe | Datas do ajuste | Origens permitidas | Exemplo (competência 10/2026) |
|---|---|---|---|
| **Realizado** | Dentro da janela (mês M−1) | `Timesheet`, `Manual`, `Hr`, `Import` | Sábado 12/09 trabalhado; falta em 21/09 |
| **Previsto** | Dentro da própria competência (mês M) | `Manual`, `Hr` | Férias 01/10 → 05/10 |
| **Correção retroativa** | Anterior à janela | `Manual`, `Hr`, `Import` (observação obrigatória); `Timesheet` **somente** como compensação da cauda da competência anterior fechada (R36–R39) | Falta de agosto descoberta em setembro; falta de 30/09 compensada em 11/2026 |

- Datas posteriores ao último dia da competência: proibidas.
- Um ajuste não pode misturar classes: o intervalo `[starts_on, ends_on]` fica inteiramente em uma delas. Um período que atravessa meses é lançado como eventos separados.

### 3.3 Visão de uma competência

```
Competência Outubro/2026  (referência 01/10/2026)
    ├── dias-base ................ 01/10 → 31/10   calendário
    ├── eventos previstos ........ 01/10 → 31/10   Manual / Hr
    └── eventos realizados ....... 01/09 → 30/09   Timesheet / Manual / Hr / Import

Competência Novembro/2026  (referência 01/11/2026)
    ├── dias-base ................ 01/11 → 30/11
    ├── eventos previstos ........ 01/11 → 30/11
    └── eventos realizados ....... 01/10 → 31/10   ← dias já cobertos por previstos de outubro não geram nova sugestão
```

Dias-base, eventos previstos e eventos realizados são grandezas distintas na documentação, na tela e no cálculo.

---

## 4. Elegibilidade e participantes

### 4.1 Elegibilidade

```
Competência M
    ↓
EmployeeBenefit vigente em 01/M, por tipo
    ↓
elegível ao tipo → calcula o tipo
```

- Uma linha de `EmployeeBenefit` = funcionário × tipo × vigência (`starts_on`, `ends_on` nulo = em aberto).
- Vigente em 01/M ⇔ `starts_on ≤ 01/M` e (`ends_on` nulo ou `ends_on ≥ 01/M`).
- Tipos independentes (R10). Exemplo: VT vigente → calcula VT; VR vigente → calcula VR; sem VD vigente → não calcula VD.
- Mudança de elegibilidade no meio do mês produz efeito somente na competência seguinte (R13).
- Vigências do mesmo funcionário e tipo não podem se sobrepor.

### 4.2 Participantes

Participante da competência M = funcionário com ao menos um `EmployeeBenefit` vigente em 01/M.

- `recision_date` e admissão **não** são usadas como filtro (R18).
- Consequência operacional: ao desligar um funcionário, o administrador encerra as vigências de `EmployeeBenefit` (`ends_on`). Sem isso, o funcionário continua participando.
- A tela pode exibir um **alerta informativo** quando um participante tiver `recision_date` preenchida. O alerta não altera a participação nem o cálculo.

---

## 5. Modelo de domínio

### 5.1 Enums (`app/Enums`; persistidos como `VARCHAR`)

| Enum | Valores |
|---|---|
| `BenefitType` | `Vt`, `Vr`, `Vd` |
| `BenefitPeriodStatus` | `Open`, `Calculated`, `Closed` |
| `AdjustmentReason` | ver 8.3 |
| `AdjustmentSource` | `Manual`, `Timesheet`, `Hr`, `Import` |
| `AdjustmentStatus` | `Pending`, `Confirmed`, `Rejected` |

Não usar `ENUM` nativo do MySQL.

### 5.2 Entidades

| Entidade | Responsabilidade |
|---|---|
| `Employee` *(existente)* | Cadastro do funcionário. Reutilizado sem campos novos. |
| `EmployeeBenefit` | Elegibilidade por tipo, com vigência. |
| `BenefitRate` | Valor diário global de VR ou VD, com vigência. |
| `TransportFare` | Identidade de um trecho/tarifa (ex.: "CPTM"). |
| `TransportFarePrice` | Preço de uma tarifa, com vigência. |
| `TransportRoute` | Trecho do itinerário do funcionário: tarifa × viagens/dia × vigência. |
| `BenefitPeriod` | Competência: mês, status, dias-base congelados, metadados de cálculo/fechamento. |
| `BenefitPeriodStatusChange` | Log de transições de estado (inclui reabertura com motivo). |
| `BenefitAdjustment` | Evento que altera quantidades. |
| `BenefitAdjustmentImpact` | Impacto do evento em um tipo de benefício. |
| `BenefitPeriodEmployee` | Participante da competência + snapshot mínimo do funcionário. |
| `BenefitCalculation` | Resultado por participante × tipo, incluindo saldo herdado e saldo gerado. |
| `BenefitCalculationTransportItem` | Snapshot dos trechos/tarifas usados no VT. |

Não existem: `BenefitClosing` (é estado), `EmployeeAbsence` (futuro), entidade de saldo, entidade de folha, entidade de permissões.

### 5.3 Relacionamentos

```
Employee 1─N EmployeeBenefit
Employee 1─N TransportRoute N─1 TransportFare 1─N TransportFarePrice
BenefitRate (global: tipo VR/VD + vigência)

BenefitPeriod 1─N BenefitAdjustment N─1 Employee
BenefitAdjustment 1─N BenefitAdjustmentImpact           (≤ 1 por BenefitType)
BenefitAdjustment 0..1─N BenefitAdjustment              (related_adjustment: substituição/correção)

BenefitPeriod 1─N BenefitPeriodEmployee N─1 Employee    (único por competência + funcionário)
BenefitPeriodEmployee 1─N BenefitCalculation            (≤ 1 por BenefitType)
BenefitCalculation(M) 0..1 ─ 0..1 BenefitCalculation(M−1)  (carried_from: saldo aplicado)
BenefitCalculation (VT) 1─N BenefitCalculationTransportItem
BenefitPeriod 1─N BenefitPeriodStatusChange
```

### 5.4 Serviços (`app/Services`; nomes finais refináveis no plano)

| Serviço | Responsabilidade |
|---|---|
| `BusinessCalendar` | Classifica dias; conta dias-base. |
| `BenefitEligibility` | Participantes e tipos elegíveis em 01/M. |
| `TransportDailyAmount` | Itinerário e tarifas vigentes em 01/M → VT diário. |
| `BenefitAdjustmentRegistrar` | Cria, substitui, confirma e rejeita ajustes; gera impactos padrão; conta dias; aplica deduplicação e validação de datas. |
| `TimesheetAdjustmentSuggester` | Lê `points` da janela M−1 → ajustes `Timesheet`/`Pending`; calcula conflitos. |
| `BenefitPeriodCalculator` | Gera o snapshot: quantidades, saldo, valores. |
| `BenefitPeriodWorkflow` | Transições de estado, pré-condições e log. |

Componentes Livewire apenas orquestram chamadas a esses serviços.

---

## 6. Regra de quantidade e saldo negativo

### 6.1 Fórmula (idêntica para VT, VR, VD)

Para cada participante elegível ao tipo T em 01/M:

```
base_days        = dias-base da competência M
positive_days    = Σ impactos Confirmed de T com quantity > 0   (ajustes da competência M)
negative_days    = Σ |impactos Confirmed de T com quantity < 0| (ajustes da competência M)
carried_in_days  = saldo negativo de T gerado em M−1 (ver 6.2), ou 0

raw_days         = base_days + positive_days − negative_days − carried_in_days
final_days       = max(raw_days, 0)
carried_out_days = max(−raw_days, 0)
```

A quantidade é calculada antes de qualquer valor monetário. Ajustes previstos e realizados entram igualmente em `positive_days`/`negative_days`.

### 6.2 Saldo negativo

**Geração.** `carried_out_days > 0` no `BenefitCalculation` de origem (competência M, tipo T).

**Aplicação.** Ao calcular M+1, o saldo é aplicado se, e somente se:

1. M está `Closed`; e
2. o funcionário é elegível ao **mesmo tipo T** em 01/M+1.

Quando aplicado, o cálculo de M+1 registra `carried_in_days` e `carried_from_calculation_id` apontando para o cálculo de origem. Cada saldo é aplicado no máximo uma vez (`UNIQUE(carried_from_calculation_id)`).

**Não aplicação.** Se o funcionário não for elegível a T em 01/M+1:

- o saldo não é aplicado em M+1;
- não é transferido para M+2 ou posteriores;
- não é aplicado em outro tipo;
- não gera `BenefitAdjustment`;
- permanece registrado no cálculo de origem como **pendência administrativa**, cujo tratamento (inclusive eventual desconto em folha, fora do sistema) cabe ao administrador.

**Rastreabilidade (derivada, sem nova tabela).** A situação de cada saldo é derivada dos dados existentes:

| Situação | Condição |
|---|---|
| Aguardando | `carried_out_days > 0`, M+1 inexistente ou não `Closed`, e nenhum cálculo o referencia. |
| Aplicado | Existe cálculo em M+1 com `carried_from_calculation_id` = origem. |
| Não aplicado (pendência administrativa) | `carried_out_days > 0`, M+1 `Closed` e nenhum cálculo o referencia. Motivo: "sem elegibilidade ao benefício em 01/M+1". |

A consulta de pendências informa funcionário, benefício, competência de origem, quantidade e motivo. O formato de apresentação é definido no plano de implementação.

Justificativa da modelagem: o saldo é aritmética determinística do cálculo de origem, não um evento a revisar. Mantê-lo em `BenefitCalculation` evita uma entidade de saldo independente e não altera snapshots fechados, pois a situação é derivada e não gravada na origem.

### 6.3 Exemplo

```
Competência 10 (VT): base 21, positivos 0, negativos 23, herdado 0 → raw −2 → final 0, saldo gerado 2

Cenário A — elegível ao VT em 01/11:
Competência 11 (VT): base 20, positivos 1, negativos 0, herdado 2 → raw 19 → final 19

Cenário B — sem VT em 01/11:
Competência 11: VT não calculado. Saldo de 2 dias (VT, origem 10/2026) = pendência administrativa.
VR e VD da competência 11 não são afetados pelo saldo de VT.
```

### 6.4 Integridade de sequência

- Competências são criadas em sequência: a nova é o mês seguinte à última existente (ou a primeira do sistema).
- Calcular/fechar M exige M−1 `Closed`, quando M−1 existir.
- Reabrir M exige M+1 não `Closed`; se M+1 estiver `Calculated`, volta para `Open` e sua prévia é descartada.
- Primeira competência do sistema: `carried_in_days = 0`.

---

## 7. Regras financeiras

### 7.1 VR e VD

```
unit_amount  = BenefitRate do tipo com maior valid_from ≤ 01/M
total_amount = final_days × unit_amount
```

- `valid_from` de `BenefitRate` é sempre dia 01 (alteração entra em vigor em nova competência).
- Sem valor vigente → cálculo bloqueado com mensagem.
- VD nunca herda o valor do VR.

Exemplo: VR R$ 27,50 × 21 = R$ 577,50. VD R$ 7,50 × 21 = R$ 157,50.

### 7.2 VT

```
routes       = TransportRoute do funcionário vigentes em 01/M
para cada route:
    fare_amount  = TransportFarePrice da tarifa com maior valid_from ≤ 01/M
    daily_amount = fare_amount × route.trips_per_day
unit_amount  = Σ daily_amount            (VT diário)
total_amount = final_days × unit_amount
```

| Trecho | Viagens/dia | Tarifa | Subtotal |
|---|---|---|---|
| CPTM | 2 | R$ 5,40 | R$ 10,80 |
| SP | 2 | R$ 8,40 | R$ 16,80 |
| **VT diário** | | | **R$ 27,60** |

21 dias × R$ 27,60 = R$ 579,60.

- Viagens/dia são registradas por trecho. Com a mesma quantidade em todos os trechos, o resultado equivale a "(Σ tarifas) × viagens".
- Mudança de itinerário ou tarifa em 15/10 → outubro usa o vigente em 01/10; novembro usa o vigente em 01/11. `valid_from`/`starts_on` podem ser qualquer data.
- Elegível ao VT sem itinerário vigente em 01/M, ou trecho sem preço vigente em 01/M → **bloqueia o fechamento**.

### 7.3 Total por participante

```
total = VT.total_amount + VR.total_amount + VD.total_amount   (tipo não elegível = 0)
```

---

## 8. Ajustes

### 8.1 Estrutura

- `BenefitAdjustment` = evento: competência, funcionário, motivo, origem, status, intervalo (`starts_on`–`ends_on`), `days_count`, observações, revisão.
- `BenefitAdjustmentImpact` = um registro por tipo com `quantity` assinada.
- Exemplo: Férias 01/10–05/10 na competência 10 → 1 evento, `days_count = 5`, impactos VT −5, VR −5, VD −5.

### 8.2 Contagem de dias

- Motivos de ausência: `days_count` = dias seg–sex não feriado em `[starts_on, ends_on]`. Sábados, domingos e feriados não estão na base, logo não são descontados.
- Motivos de trabalho: um evento por data (`starts_on = ends_on`), `days_count = 1`.
- Impactos padrão: `quantity = ±days_count` em cada um dos três tipos.
- Feriado cadastrado depois: ajustes de competências `Open`/`Calculated` são recontados no próximo cálculo; `Closed` não muda.

### 8.3 Motivos (`AdjustmentReason`)

| Valor | Descrição | Manual | Automático | Origens | Impacto padrão |
|---|---|---|---|---|---|
| `JustifiedAbsence` | Ausência justificada | Sim | Não (reclassificação de sugestão) | Manual, Hr, Import | −1 |
| `UnjustifiedAbsence` | Ausência injustificada | Sim | Sim (dia útil sem batida) | Manual, Timesheet, Import | −1 |
| `Vacation` | Férias | Sim | Futuro (Hr) | Manual, Hr, Import | −1 |
| `MedicalCertificate` | Atestado médico | Sim | Futuro (Hr) | Manual, Hr, Import | −1 |
| `Leave` | Licença | Sim | Futuro (Hr) | Manual, Hr, Import | −1 |
| `LeaveOfAbsence` | Afastamento | Sim | Futuro (Hr) | Manual, Hr, Import | −1 |
| `OtherAbsence` | Outro motivo de ausência | Sim | Não | Manual, Import | −1 |
| `SaturdayWorked` | Sábado trabalhado | Sim | Sim | Timesheet, Manual, Import | +1 |
| `SundayWorked` | Domingo trabalhado | Sim | Sim | Timesheet, Manual, Import | +1 |
| `HolidayWorked` | Feriado trabalhado | Sim | Sim | Timesheet, Manual, Import | +1 |
| `Manual` | Ajuste manual / correção | Sim | Não | Manual | Livre, por tipo |

- Motivos com impacto padrão geram sempre os três impactos, não editáveis.
- `Manual` permite impactos livres por tipo (sinal e quantidade), com observação obrigatória. É o motivo para correções que não representam ausência nem trabalho extra.
- Motivos de "ausência": `JustifiedAbsence`, `UnjustifiedAbsence`, `Vacation`, `MedicalCertificate`, `Leave`, `LeaveOfAbsence`, `OtherAbsence`.
- Motivos de "trabalho": `SaturdayWorked`, `SundayWorked`, `HolidayWorked`.

### 8.4 Origens

| Origem | Uso no MVP | Status inicial | Classes de evento permitidas |
|---|---|---|---|
| `Manual` | Lançamento pelo administrador. | `Confirmed` | Realizado, Previsto, Correção retroativa |
| `Timesheet` | Sugestão do `TimesheetAdjustmentSuggester`. | `Pending` | Realizado; Correção retroativa somente como compensação (R36–R39), criada por caminho dedicado |
| `Hr` | Reservado ao futuro domínio de ausências. Sem uso no MVP. | `Confirmed` | Realizado, Previsto, Correção retroativa |
| `Import` | Reservado a importação. Sem uso no MVP. | `Pending` | Realizado, Correção retroativa |

### 8.5 Status e transições

```
Pending ──confirmar──► Confirmed ──rejeitar──► Rejected
   └────rejeitar────► Rejected ──reconsiderar──► Pending
```

- Transições só com a competência do ajuste em `Open` ou `Calculated` (em `Calculated`, a competência volta para `Open`).
- Confirmar/rejeitar grava `reviewed_by`, `reviewed_at`, `review_notes`. Nota obrigatória na rejeição.
- Não há exclusão de ajustes. Remoção = `Rejected`.
- Confirmação/rejeição em lote permitida.
- Fechamento exige zero ajustes `Pending` na competência.

### 8.6 Alteração e cancelamento (fluxo normal, sem mecanismo paralelo)

| Situação | Competência do ajuste | Como fazer |
|---|---|---|
| Alterar ajuste `Pending` | `Open`/`Calculated` | Edição direta de motivo e datas; impactos padrão regenerados. |
| Alterar ajuste `Confirmed` (ex.: férias previstas passam de 01–05/10 para 01–03/10) | `Open`/`Calculated` | Ação "Alterar": em uma transação, o original vai para `Rejected` (nota automática "substituído") e é criado o novo ajuste com `related_adjustment_id` = original. O impacto anterior deixa de contar; não há duplicidade. |
| Cancelar evento (ex.: férias previstas canceladas) | `Open`/`Calculated` | Rejeitar o ajuste, com nota. |
| Alterar/cancelar evento cuja competência está `Closed` | `Closed` | O ajuste original é imutável. A correção é um ajuste `Manual` na competência aberta seguinte (correção retroativa), com `related_adjustment_id` = original e observação. |

Todo o histórico permanece auditável: o original rejeitado, a nota, o substituto e o vínculo entre eles.

### 8.7 Preparação para o futuro domínio de ausências

- Um futuro `EmployeeAbsence` criará ajustes pelo `BenefitAdjustmentRegistrar`, com `source = Hr`, podendo gerar eventos previstos e realizados.
- A coluna de vínculo com a ausência será criada junto com essa entidade. Nada é criado antecipadamente.

---

## 9. Deduplicação

### 9.1 Princípio

Deduplicação por **efeito dentro da competência** (R26). Não existe restrição global por funcionário + data. A única verificação que olha além da competência é a **cobertura** usada pelo gerador de sugestões (DD4), que impede o ponto de repetir um fato já tratado. Ela não bloqueia lançamentos manuais.

### 9.2 Regras

| # | Regra | Mecanismo |
|---|---|---|
| DD1 | A mesma sugestão do ponto não é criada duas vezes na mesma competência. Sugestão rejeitada não é recriada. Compensações (3.3) usam chave própria pela origem, válida em todo o sistema. | `dedupe_key = "timesheet:{period_id}:{employee_id}:{date}:{reason}"`; compensação: `"timesheet-comp:{origin_period_id}:{employee_id}:{date}:{reason}"`; `UNIQUE` |
| DD2 | Na mesma competência, para o mesmo funcionário e dia: no máximo um evento não rejeitado de **ausência**. | Validação no registro/confirmação; bloqueia. |
| DD3 | Na mesma competência, para o mesmo funcionário e dia: no máximo um evento não rejeitado de **trabalho**. | Idem; bloqueia. |
| DD4 | **Cobertura para sugestões:** o gerador não cria sugestão de ausência para um dia já coberto por ajuste de ausência não rejeitado do funcionário, **em qualquer competência** (inclui previstos da competência anterior). O mesmo vale para sugestões de trabalho em relação a ajustes de trabalho. | Consulta prévia na geração. |
| DD5 | Ajustes `Manual` (impacto livre) não são bloqueados por DD2/DD3; a tela alerta quando há outro evento no mesmo dia. | Alerta. |
| DD6 | Lançamentos manuais do mesmo funcionário/dia em competências diferentes são permitidos (correções/estornos legítimos). A tela informa quando o dia já produziu efeito em outra competência. | Alerta informativo. |

### 9.3 Exemplo (previsto + ponto)

```
Competência 10: Vacation 01/10–05/10, Confirmed, −5

Competência 11 (janela = outubro), ponto sem batidas em 01–05/10:
→ DD4: dias cobertos por ausência não rejeitada → nenhuma UnjustifiedAbsence gerada
→ sem duplo desconto
```

Se o ajuste de férias tiver sido rejeitado (cancelado), os dias deixam de estar cobertos e a ausência de batida gera sugestões normalmente.

Validações concorrentes usam bloqueio pessimista (`lockForUpdate`) sobre a competência durante registro, confirmação e geração.

---

## 10. Estados da competência e fechamento

### 10.1 Máquina de estados

```
          calcular                 fechar (recalcula + congela)
  Open ───────────► Calculated ──────────────────────────► Closed
    ▲ ◄──────────────────┘                                   │
    │  alteração de ajuste / nova geração de sugestões       │
    └────────────── reabrir (motivo obrigatório + log) ◄─────┘
```

| Estado | Permitido | Proibido |
|---|---|---|
| `Open` | Registrar/alterar/revisar ajustes; gerar sugestões; calcular. | — |
| `Calculated` | Consultar prévia; recalcular; fechar. Alteração de ajuste ou nova geração devolve para `Open` e descarta a prévia. | — |
| `Closed` | Consultar resultado congelado; consultar pendências de saldo. | Qualquer alteração em ajustes ou snapshot. |

- Reabertura não é estado: volta para `Open` e grava `BenefitPeriodStatusChange` com motivo obrigatório. O snapshot é descartado e regerado no novo fechamento.
- Não existe `Cancelled`. Uma competência `Open`, sem snapshot e mais recente, pode ser excluída.
- Todas as transições são registradas em `BenefitPeriodStatusChange`.

### 10.2 Pré-condições

| Ação | Pré-condições |
|---|---|
| Criar competência | É o mês seguinte à última existente (ou a primeira). |
| Gerar sugestões | Status `Open`/`Calculated`; horizonte de cobertura (R33) igual ou posterior ao primeiro dia da janela. A janela não precisa estar completamente importada (ver 20.1). |
| Calcular | Status `Open`/`Calculated`; M−1 `Closed` se existir; valor VR/VD vigente em 01/M para os tipos com participantes. |
| Fechar | Todas as de "Calcular"; zero ajustes `Pending`; todo elegível ao VT com itinerário e preços vigentes em 01/M. |
| Reabrir | Status `Closed`; M+1 não `Closed`; motivo informado. |

### 10.3 Fechamento

O fechamento recalcula e congela na mesma transação: o snapshot reflete exatamente o estado no instante do fechamento, mesmo que configurações tenham mudado após a última prévia.

### 10.4 Imutabilidade de configurações usadas

- `BenefitRate` e `TransportFarePrice` com `valid_from` ≤ 01 da última competência `Closed` não podem ser editados nem excluídos. Mudança = nova vigência.
- `TransportRoute` e `EmployeeBenefit` são encerrados por `ends_on`, nunca sobrescritos.
- O snapshot preserva os valores independentemente da configuração (seção 11).

---

## 11. Snapshot

| Dado | Onde |
|---|---|
| Dias-base | `benefit_periods.business_days` |
| Participante: nome, PIS, cargo | `benefit_period_employees` |
| Base, positivos, negativos, herdado (e origem), bruto, final, saldo gerado | `benefit_calculations` |
| Valor unitário, total, referência à taxa usada | `benefit_calculations` |
| Trechos: nome da tarifa, preço, viagens/dia, valor diário do trecho | `benefit_calculation_transport_items` |
| Ajustes considerados | Ajustes `Confirmed` da competência; imutáveis após `Closed`, portanto reproduzíveis. |

- FKs do snapshot para configuração (`benefit_rate_id`, `transport_route_id`, `transport_fare_id`) usam `ON DELETE SET NULL`; os valores copiados são a fonte de verdade.
- CPF e dados bancários não fazem parte do snapshot no MVP (R23).

---

## 12. Calendário (`BusinessCalendar`)

- `days(from, to)`: cada dia classificado como `BusinessDay`, `Saturday`, `Sunday` ou `Holiday` (precedência `Holiday` > `Saturday`/`Sunday`).
- `businessDays(month)`: lista e contagem de dias-base.
- Consultas auxiliares: feriados, sábados e domingos de um intervalo.
- Reutiliza `Holiday::isHoliday()` / `Holiday::cachedHolidays()`. Nenhuma alteração na tabela `holidays`.
- Alerta quando o ano da competência ou da janela não possui feriados cadastrados.

---

## 13. Integração com o ponto (MVP)

### 13.1 Fluxo

```
Point (batidas da janela M−1)
   ↓ análise por participante × dia (BusinessCalendar + batidas + cobertura DD4)
   ↓ classificação
BenefitAdjustment (source = Timesheet, status = Pending)
   ↓ revisão do administrador
Confirmed / Rejected
   ↓
BenefitPeriodCalculator
```

- O serviço apenas lê `points`. Nenhuma alteração no módulo de ponto nem no cálculo de horas.
- Idempotente (DD1 + DD4): executar novamente não duplica sugestões nem recria rejeitadas.
- Incremental (R33–R35): analisa a janela somente até o horizonte de cobertura. Após nova importação, uma nova execução analisa os dias que entraram no horizonte; os dias já analisados resultam em "já existentes" ou "cobertos". Cada execução é registrada em `benefit_suggestion_runs` com a última data importada, o horizonte, o intervalo processado e os contadores.
- Analisa somente participantes da competência M (elegíveis em 01/M).

### 13.2 Batida válida

Registro em `points` cujo PIS corresponde ao funcionário (relação existente `Employee::points()`), com data dentro da janela, de qualquer tipo (`importado` ou `manual`).

### 13.3 Classificação

| Situação do dia na janela | Resultado |
|---|---|
| Dia útil com ≥ 1 batida | Nada (R6). |
| Dia útil sem batida, não coberto por ausência (DD4) | Sugestão `UnjustifiedAbsence`, −1, `Pending`. Reclassificável na revisão. |
| Dia útil sem nenhuma batida importada de nenhum PIS (R34) | Nada para todos os participantes; conflito `Dia útil sem batidas`. |
| Dia posterior ao horizonte de cobertura (R33) | Não analisado nesta execução; aparece como "aguardando importação". |
| Dia útil sem batida, coberto por ausência não rejeitada (qualquer competência) | Nada. |
| Sábado com ≥ 1 batida, não coberto por trabalho (DD4) | Sugestão `SaturdayWorked`, +1, `Pending`. |
| Domingo com ≥ 1 batida, não coberto | Sugestão `SundayWorked`, +1, `Pending`. |
| Feriado (qualquer dia da semana) com ≥ 1 batida, não coberto | Sugestão `HolidayWorked`, +1, `Pending` (R8). |
| Sábado/domingo/feriado sem batida | Nada. |
| Batida após a meia-noite de jornada iniciada no dia anterior | Conta para o dia em que foi registrada; a revisão decide. |
| PIS no ponto sem funcionário | Ignorado; listado como inconsistência. |

### 13.4 Conflitos sinalizados para revisão

Os conflitos são **calculados** pelo serviço e exibidos na tela de ajustes. Não são persistidos e não geram ajustes por si.

| Conflito | Exemplo | Tratamento esperado |
|---|---|---|
| Dia útil coberto por ausência, mas com batida | Férias previstas 01–05/10 e batidas em 02/10 | Revisar: se as férias não ocorreram e a competência de origem está fechada, lançar correção `Manual` (8.6). |
| Sábado/domingo/feriado com batida em dia coberto por ausência | Batida no sábado durante férias | Sugestão +1 é gerada; conflito exibido. |
| Participante com `recision_date` preenchida | — | Alerta informativo (4.2). |
| Dia útil sem nenhuma batida importada (R34) | Nenhum funcionário bateu ponto em 15/09 | Ausências não sugeridas para a data; verificar a importação, o relógio ou o cadastro de feriados. |
| Horizonte anterior ao início da janela | Última batida importada em 01/09 (horizonte 31/08) | Geração bloqueada; a tela mostra a última batida importada, o horizonte e os dias aguardando importação. |

**Conflitos após correções no ponto (3.2).** Avaliados por ajuste não rejeitado e dia; um único tipo por ajuste/dia. "Ponto coberto" = até `min(fim da janela, horizonte)` (R33). Batida = qualquer tipo (R6). Ajustes rejeitados não geram conflito, inclusive quando a evidência volta a existir (o caminho é Reconsiderar).

| Conflito | Ajustes | Critério | Tratamento esperado |
|---|---|---|---|
| Ausência com batida (`absence_with_punch`) | Ausências de qualquer origem e competência | Dia útil com ausência e batida do funcionário, independentemente do ponto coberto | Revisar o ajuste indicado |
| Trabalho sem batida (`work_without_punch`) | Trabalho de origem `Timesheet` | Data no ponto coberto, sem batida do funcionário. Distingue dia com outras batidas importadas (evidência deixou de existir) de dia sem nenhuma batida importada (não é prova de que não houve trabalho) | Revisar |
| Sugestão fora do ponto coberto (`suggestion_beyond_coverage`) | `Timesheet` | Data após o ponto coberto atual e já processada por uma execução registrada (`processed_to` ≥ data). Trabalho com batida não entra | Revisar; a cobertura recuou |
| Cobertura anterior desconhecida (`suggestion_coverage_unknown`) | `Timesheet` | Igual ao anterior, sem execução registrada que tenha processado a data | Revisar; nenhuma cobertura histórica é presumida |
| Ausência em dia que perdeu as batidas importadas (`absence_without_imported_punches`) | Ausências `Timesheet` | Dia útil no ponto coberto, sem batida do funcionário e sem nenhuma batida importada, somente quando fica demonstrado que todas as sugestões do ponto da competência foram criadas por execuções registradas (que só sugerem ausência em dia com batidas importadas) e a sugestão mantém data e motivo originais; sem essa demonstração o conflito não é emitido | Revisar; não prova presença |

**Compensações (3.3).** Os conflitos acima também avaliam as compensações na página do destino, na data original do evento, com o ponto coberto até o horizonte; a execução que "processou a data" é a que registrou a cauda. A página da origem continua mostrando `absence_with_punch` para a compensação (ausência de qualquer competência), com a mesma ação recomendada. Conflitos de cauda (eventos sem ajuste):

| Conflito | Critério | Tratamento esperado |
|---|---|---|
| Evento da cauda não compensado (`uncompensated_tail`) | Evento da cauda de O, até o horizonte, de participante de O e de D, sem compensação e sem ajuste do mesmo tipo, com D fechada. Exibido na página de D e na da competência seguinte a D | Ajuste `Manual` retroativo na próxima competência editável |
| Evento da cauda sem compensação possível (`tail_not_compensable`) | Evento da cauda de funcionário que participava de O e não participa de D | Tratar administrativamente (sem elegibilidade em D o ajuste não teria efeito) |

Dias úteis da cauda sem nenhuma batida importada aparecem como `Dia útil sem batidas` na página de D.

Ação recomendada: competência editável → revisar o ajuste (Pendente: rejeitar ou alterar; Confirmado: rejeitar ou substituir); competência fechada → lançar ajuste `Manual` retroativo na competência seguinte (8.6), sem reabrir. Nenhuma ação é executada automaticamente.

### 13.5 Premissa e limitações do horizonte de cobertura

- A importação (`EmployeeIndex::importPoints`) lê um único arquivo AFD acumulado, pulando as linhas já importadas (contador em `imported_lines`), e grava `type = 'importado'`. Não existe registro das datas cobertas por importação nem identificação de relógio.
- O horizonte supõe que o AFD é cronológico: se há registro no dia D, os dias anteriores a D estão completos. O dia D é excluído por poder estar incompleto.
- A premissa não é verificável pelos dados. Múltiplos relógios com arquivos independentes, AFD fora de ordem ou exclusão de batidas importadas tornam o horizonte impreciso; nesses casos o conflito `Dia útil sem batidas` é a principal salvaguarda contra ausências indevidas.
- Correções do ponto depois de uma geração não alteram ajustes existentes; desde a 3.2 são sinalizadas pelos conflitos de 13.4.
- A tela de edição de pontos recusa no servidor a exclusão de batidas importadas e de batidas de outro funcionário ou data (3.2). Exclusões fora dessa tela (banco de dados, `tinker`) não são impedidas: o model `Point` não tem proteção.
- Eventos da janela posteriores ao fechamento da competência são sugeridos na competência seguinte como compensação (R36–R39, versão 3.3). A geração do destino continua exigindo o horizonte no início da sua própria janela (10.2); até lá, a cauda aparece como "aguardando importação".
- Competências fechadas antes do registro de execuções (3.1) não têm cobertura comprovável: sua cauda não é compensada automaticamente.

---

## 14. Telas do MVP

Navegação: novo grupo "Benefícios" na sidebar existente. Acesso total do usuário autenticado (R21).

| Tela | Conteúdo mínimo |
|---|---|
| Valores VR/VD | Vigências por tipo; nova vigência (dia 01). |
| Tarifas de transporte | Tarifas; preços com vigência. |
| Benefícios do funcionário | Elegibilidade (VT/VR/VD) e itinerário, como extensão do cadastro existente de funcionários. A posição na UI é definida no plano. |
| Competências | Lista (mês, status, totais); criar a próxima competência. |
| Competência — Ajustes | Separação visual entre **Previstos**, **Realizados** e **Correções retroativas**; filtros (funcionário, motivo, origem, status); criar ajuste; alterar; confirmar/rejeitar individual e em lote; gerar sugestões do ponto; painel de conflitos. |
| Competência — Apuração | Colunas obrigatórias (R23): **Funcionário · VT qtd · VT valor · VR qtd · VR valor · VD qtd · VD valor · Total**. Detalhe por funcionário: base, positivos, negativos, saldo herdado (e origem), saldo gerado, itinerário. Ações: Calcular, Fechar, Reabrir. |
| Pendências de saldo | Saldos negativos não aplicados: funcionário, benefício, competência de origem, quantidade, motivo. Formato definido no plano. |

---

## 15. Valores monetários

- Banco: `DECIMAL(10,2)` para unitários (preço de tarifa, valor VR/VD, VT diário, valor diário do trecho); `DECIMAL(12,2)` para totais.
- Aplicação: cast `decimal:2` (string). Nunca float.
- Cálculo: string → centavos inteiros por parsing (`"5.40"` → `540`), operações com `int`, conversão de volta ao persistir.
- Arredondamento desnecessário: entradas com no máximo 2 casas multiplicadas apenas por inteiros (viagens, dias). Validação de entrada impõe 2 casas.
- Unitário e total persistidos; o total não é recalculado na leitura.

---

## 16. Casos de borda

Prioridade: **A** afeta valor pago · **B** consistência/auditoria · **C** operacional.

| Prio | Caso | Tratamento |
|---|---|---|
| A | Evento previsto + ponto sem batida | Férias confirmadas cobrem os dias (DD4); nenhuma `UnjustifiedAbsence` é gerada. |
| A | Evento previsto alterado | Competência aberta: substituição (original `Rejected` + novo com `related_adjustment_id`). Só o novo intervalo conta. Competência fechada: correção `Manual` na competência seguinte. |
| A | Evento previsto cancelado | Competência aberta: rejeição com nota. Competência fechada: correção `Manual` na seguinte. Histórico preservado. |
| A | Evento previsto que não ocorreu (funcionário trabalhou) | Conflito "ausência com batida" sinalizado; correção pelo administrador. |
| A | Saldo negativo com benefício elegível em M+1 | Aplicado em M+1 como saldo herdado. |
| A | Saldo negativo sem benefício em M+1 | Não aplicado, não migra, não passa para outro tipo; pendência administrativa (6.2). |
| A | Mudança de tarifa no meio da competência | Usa a tarifa vigente em 01/M; a nova vale a partir da competência seguinte. |
| A | Mudança de itinerário no meio da competência | Usa o itinerário vigente em 01/M. |
| A | Mudança de elegibilidade no meio da competência | Usa a elegibilidade vigente em 01/M. |
| A | Alteração de valor VR/VD | Nova vigência a partir de um dia 01; competências fechadas intactas. |
| A | Feriado não cadastrado | Dias-base incorretos; alerta se o ano não tem feriados. |
| A | Mesmo evento duas vezes na mesma competência | DD1–DD3. |
| A | Férias atravessando meses | Eventos separados por mês; cada parte segue sua classe (previsto/realizado). |
| A | Elegível ao VT sem itinerário ou sem preço vigente | Bloqueia o fechamento. |
| A | Alteração após cálculo | Volta para `Open`; o fechamento sempre recalcula. |
| A | Feriado em sábado/domingo trabalhado | Um único `HolidayWorked` +1. |
| A | Funcionário desligado com `EmployeeBenefit` ainda vigente | Continua participando (R18); alerta informativo por `recision_date`; o administrador encerra as vigências. |
| B | Reabertura de competência | Motivo + log; M+1 não pode estar fechada. |
| B | Sugestão automática rejeitada | Mantida `Rejected`; não recriada. |
| B | Ajuste manual duplicado | DD2/DD3 bloqueiam para motivos padrão; alerta para `Manual`. |
| B | Mesmo funcionário/dia em competências diferentes | Permitido para lançamentos; alerta informativo (DD6). |
| B | Funcionário sem VT / sem VR / sem VD | Sem vigência em 01/M → tipo não calculado; saldos desse tipo não aplicados. |
| B | Batida em fim de semana durante férias | +1 gerado; conflito sinalizado. |
| B | Funcionário excluído | Ver 20.2. |
| B | PIS alterado | Batidas antigas com o PIS anterior deixam de ser associadas (limitação do ponto, fora do escopo); relatório de inconsistências. |
| C | Batida incompleta (1 batida) | Presença (R6). |
| C | Falta em sábado/domingo | Sem efeito. |
| C | Batidas não importadas até o fim da janela | Geração bloqueada (20.1). |
| C | Primeira competência do sistema | Sem M−1: saldo herdado = 0. |
| C | Dias sem batida antes da admissão (na janela) | Sugestões de ausência geradas; a revisão as rejeita (R18: sem regra automática por admissão). |

---

## 17. Proposta de banco (MySQL 8 · InnoDB · utf8mb4)

**Convenções**

- PK `id BIGINT UNSIGNED`; `created_at`/`updated_at`.
- Enums como `VARCHAR`.
- FKs para `users`: `ON DELETE SET NULL`. FKs de domínio: `ON DELETE RESTRICT`, salvo indicação.
- Colunas de auditoria (`created_by`, `updated_by`, `reviewed_by`, `calculated_by`, `closed_by`) mantidas mesmo com usuário único.
- Regras de domínio (valor ≥ 0, coerência de datas, `valid_from` de VR/VD no dia 01, não sobreposição de vigências) são validadas na aplicação. `CHECK` constraints não são geradas pelo schema builder e não se comportariam igual no SQLite; podem ser adicionadas em produção via SQL específico de MySQL, se desejado.
- **`employees` não é alterada.** Nenhuma tabela de folha, saldo, ausência, permissão ou cadastro paralelo.

### 17.1 `employee_benefits`

| Campo | Tipo | Observação |
|---|---|---|
| `employee_id` | BIGINT UNSIGNED | FK `employees` RESTRICT |
| `benefit_type` | VARCHAR(10) | |
| `starts_on` | DATE | Qualquer data |
| `ends_on` | DATE NULL | ≥ `starts_on` |
| `notes` | TEXT NULL | |
| `created_by`, `updated_by` | BIGINT UNSIGNED NULL | FK `users` |

Índices: `UNIQUE(employee_id, benefit_type, starts_on)`, `INDEX(benefit_type, starts_on, ends_on)`. Sem SoftDeletes.

### 17.2 `benefit_rates`

| Campo | Tipo | Observação |
|---|---|---|
| `benefit_type` | VARCHAR(10) | Apenas `vr` ou `vd` |
| `amount` | DECIMAL(10,2) | ≥ 0 |
| `valid_from` | DATE | Sempre dia 01 |
| `created_by` | BIGINT UNSIGNED NULL | FK `users` |

Índices: `UNIQUE(benefit_type, valid_from)`. Sem SoftDeletes.

### 17.3 `transport_fares`

| Campo | Tipo | Observação |
|---|---|---|
| `name` | VARCHAR(100) | `UNIQUE` |
| `operator` | VARCHAR(100) NULL | |
| `is_active` | BOOLEAN | Default true |

Sem SoftDeletes.

### 17.4 `transport_fare_prices`

| Campo | Tipo | Observação |
|---|---|---|
| `transport_fare_id` | BIGINT UNSIGNED | FK `transport_fares` RESTRICT |
| `amount` | DECIMAL(10,2) | ≥ 0 |
| `valid_from` | DATE | Qualquer data; consulta sempre em 01/M |
| `created_by` | BIGINT UNSIGNED NULL | FK `users` |

Índices: `UNIQUE(transport_fare_id, valid_from)`.

### 17.5 `transport_routes`

| Campo | Tipo | Observação |
|---|---|---|
| `employee_id` | BIGINT UNSIGNED | FK `employees` RESTRICT |
| `transport_fare_id` | BIGINT UNSIGNED | FK `transport_fares` RESTRICT |
| `trips_per_day` | TINYINT UNSIGNED | > 0 |
| `starts_on` | DATE | Qualquer data; consulta sempre em 01/M |
| `ends_on` | DATE NULL | |
| `notes` | TEXT NULL | |
| `created_by`, `updated_by` | BIGINT UNSIGNED NULL | FK `users` |

Índices: `INDEX(employee_id, starts_on, ends_on)`. Sem SoftDeletes.

### 17.6 `benefit_periods`

| Campo | Tipo | Observação |
|---|---|---|
| `competence` | DATE | Dia 01; `UNIQUE` |
| `status` | VARCHAR(20) | `BenefitPeriodStatus` |
| `business_days` | TINYINT UNSIGNED NULL | Congelado no cálculo |
| `calculated_at` | TIMESTAMP NULL | |
| `calculated_by` | BIGINT UNSIGNED NULL | FK `users` |
| `closed_at` | TIMESTAMP NULL | |
| `closed_by` | BIGINT UNSIGNED NULL | FK `users` |
| `notes` | TEXT NULL | |
| `created_by` | BIGINT UNSIGNED NULL | FK `users` |

Índices: `INDEX(status)`. Janela e data de referência são derivadas de `competence`. Sem SoftDeletes.

### 17.7 `benefit_period_status_changes`

| Campo | Tipo | Observação |
|---|---|---|
| `benefit_period_id` | BIGINT UNSIGNED | FK `benefit_periods` CASCADE |
| `from_status` | VARCHAR(20) NULL | |
| `to_status` | VARCHAR(20) | |
| `reason` | TEXT NULL | Obrigatório na reabertura |
| `user_id` | BIGINT UNSIGNED NULL | FK `users` |
| `created_at` | TIMESTAMP | |

Índices: `INDEX(benefit_period_id, created_at)`. Somente inserção.

### 17.8 `benefit_adjustments`

| Campo | Tipo | Observação |
|---|---|---|
| `benefit_period_id` | BIGINT UNSIGNED | FK `benefit_periods` RESTRICT |
| `employee_id` | BIGINT UNSIGNED | FK `employees` RESTRICT |
| `reason` | VARCHAR(40) | `AdjustmentReason` |
| `source` | VARCHAR(20) | `AdjustmentSource` |
| `status` | VARCHAR(20) | `AdjustmentStatus` |
| `starts_on` | DATE | Classe derivada (3.2) |
| `ends_on` | DATE | ≥ `starts_on`; mesma classe |
| `days_count` | SMALLINT UNSIGNED | |
| `notes` | TEXT NULL | Obrigatório para `Manual` e correção retroativa |
| `dedupe_key` | VARCHAR(191) NULL | `UNIQUE` (DD1) |
| `related_adjustment_id` | BIGINT UNSIGNED NULL | FK self, SET NULL (substituição/correção) |
| `reviewed_by` | BIGINT UNSIGNED NULL | FK `users` |
| `reviewed_at` | TIMESTAMP NULL | |
| `review_notes` | TEXT NULL | Obrigatório na rejeição |
| `created_by`, `updated_by` | BIGINT UNSIGNED NULL | FK `users` |

Índices: `INDEX(benefit_period_id, employee_id, status)`; `INDEX(employee_id, status, starts_on, ends_on)` (cobertura DD4). Sem SoftDeletes (remoção via `Rejected`).

### 17.9 `benefit_adjustment_impacts`

| Campo | Tipo | Observação |
|---|---|---|
| `benefit_adjustment_id` | BIGINT UNSIGNED | FK `benefit_adjustments` CASCADE |
| `benefit_type` | VARCHAR(10) | |
| `quantity` | SMALLINT | Assinado; ≠ 0 |

Índices: `UNIQUE(benefit_adjustment_id, benefit_type)`.

### 17.10 `benefit_period_employees`

| Campo | Tipo | Observação |
|---|---|---|
| `benefit_period_id` | BIGINT UNSIGNED | FK `benefit_periods` CASCADE |
| `employee_id` | BIGINT UNSIGNED | FK `employees` RESTRICT |
| `employee_name` | VARCHAR(255) | Snapshot |
| `pis` | VARCHAR(20) | Snapshot |
| `position` | VARCHAR(255) | Snapshot |

Índices: `UNIQUE(benefit_period_id, employee_id)`.

### 17.11 `benefit_calculations`

| Campo | Tipo | Observação |
|---|---|---|
| `benefit_period_employee_id` | BIGINT UNSIGNED | FK `benefit_period_employees` CASCADE |
| `benefit_type` | VARCHAR(10) | |
| `base_days` | SMALLINT UNSIGNED | |
| `positive_days` | SMALLINT UNSIGNED | |
| `negative_days` | SMALLINT UNSIGNED | |
| `carried_in_days` | SMALLINT UNSIGNED | Default 0 |
| `carried_from_calculation_id` | BIGINT UNSIGNED NULL | FK self RESTRICT; `UNIQUE`; origem do saldo aplicado (sempre M−1, mesmo tipo) |
| `raw_days` | SMALLINT | Assinado |
| `final_days` | SMALLINT UNSIGNED | `max(raw, 0)` |
| `carried_out_days` | SMALLINT UNSIGNED | `max(−raw, 0)`; saldo gerado |
| `unit_amount` | DECIMAL(10,2) | |
| `total_amount` | DECIMAL(12,2) | |
| `benefit_rate_id` | BIGINT UNSIGNED NULL | FK `benefit_rates` SET NULL (referência) |

Índices: `UNIQUE(benefit_period_employee_id, benefit_type)`, `UNIQUE(carried_from_calculation_id)`, `INDEX(benefit_type, carried_out_days)` (consulta de pendências).

Situação do saldo (aguardando / aplicado / não aplicado) é derivada (6.2); não há coluna de status nem tabela de saldo. Justificativa: evita entidade independente e não altera cálculos de competências fechadas.

### 17.12 `benefit_calculation_transport_items`

| Campo | Tipo | Observação |
|---|---|---|
| `benefit_calculation_id` | BIGINT UNSIGNED | FK `benefit_calculations` CASCADE |
| `transport_route_id` | BIGINT UNSIGNED NULL | FK SET NULL |
| `transport_fare_id` | BIGINT UNSIGNED NULL | FK SET NULL |
| `fare_name` | VARCHAR(100) | Snapshot |
| `fare_amount` | DECIMAL(10,2) | Snapshot (vigente em 01/M) |
| `trips_per_day` | TINYINT UNSIGNED | Snapshot |
| `daily_amount` | DECIMAL(10,2) | `fare_amount × trips_per_day` |

### 17.13 `benefit_suggestion_runs` (3.1)

Trilha somente de inserção das execuções da geração de sugestões do ponto (R35). Execuções bloqueadas não são registradas. Não há registros para execuções anteriores à versão 3.1.

| Campo | Tipo | Observação |
|---|---|---|
| `benefit_period_id` | BIGINT UNSIGNED | FK `benefit_periods` CASCADE |
| `user_id` | BIGINT UNSIGNED NULL | FK `users` SET NULL |
| `last_imported_on` | DATE | Maior data importada observada |
| `horizon_on` | DATE | `last_imported_on − 1` (R33) |
| `processed_from` | DATE | Início da janela |
| `processed_to` | DATE | `min(fim da janela, horizon_on)` |
| `created` | INT UNSIGNED | Sugestões criadas |
| `skipped_existing` | INT UNSIGNED | Já existentes (DD1) |
| `skipped_covered` | INT UNSIGNED | Cobertas por outro ajuste (DD4) |
| `skipped_unobserved_days` | INT UNSIGNED | Dias úteis sem nenhuma batida importada (R34) |
| `tail_origin_period_id` | BIGINT UNSIGNED NULL | (3.3) FK `benefit_periods` SET NULL: origem O, quando fechada |
| `tail_origin_run_id` | BIGINT UNSIGNED NULL | (3.3) FK `benefit_suggestion_runs` SET NULL: execução de O que define a cobertura; nula sem histórico |
| `tail_origin_covered_until` | DATE NULL | (3.3) `processed_to` dessa execução |
| `tail_from` / `tail_to` | DATE NULL | (3.3) Início da cauda / último dia processado |
| `tail_pending_days` | INT UNSIGNED | (3.3) Dias da cauda aguardando importação |
| `compensation_created` | INT UNSIGNED | (3.3) Compensações criadas (incluídas em `created`) |
| `compensation_skipped_existing` / `compensation_skipped_covered` / `compensation_skipped_unobserved_days` / `compensation_not_compensable` | INT UNSIGNED | (3.3) Já existentes, cobertas (DD4), dias sem batidas, eventos de não participantes do destino |
| `created_at` | TIMESTAMP | Data/hora da execução; sem `updated_at` |

Índices: `INDEX(benefit_period_id, created_at)`. Execuções anteriores à 3.3 têm as colunas de cauda nulas.

---

## 18. Fluxo completo da competência

| # | Passo | Estado | Detalhe |
|---|---|---|---|
| 1 | Criar competência M | → `Open` | Mês seguinte à última existente. |
| 2 | Calcular dias-base de M | `Open` | `BusinessCalendar` sobre o mês M. |
| 3 | Determinar elegibilidade em 01/M | `Open` | `EmployeeBenefit` vigente em 01/M define participantes e tipos. |
| 4 | Registrar/identificar eventos previstos de M | `Open` | `Manual` (futuro `Hr`), datas dentro de M. |
| 5 | Analisar ponto de M−1 | `Open` | Janela = mês M−1, analisada até o horizonte de cobertura (R33); pode ser repetida após novas importações. |
| 6 | Gerar sugestões `Timesheet`/`Pending` | `Open` | Incremental e idempotente; respeita cobertura (DD4); ignora dias úteis sem batidas (R34); registra a execução; calcula conflitos. |
| 7 | Revisar ajustes | `Open` | Previstos, realizados e correções separados; painel de conflitos. |
| 8 | Confirmar/rejeitar | `Open` | Individual ou lote; alteração por substituição. |
| 9 | Calcular VT | → `Calculated` | Itinerário e tarifas vigentes em 01/M × dias finais. |
| 10 | Calcular VR | `Calculated` | Valor global vigente em 01/M × dias finais. |
| 11 | Calcular VD | `Calculated` | Valor global vigente em 01/M × dias finais. |
| 12 | Revisar apuração | `Calculated` | Tela de apuração; saldos herdados e gerados. Alteração → `Open`. |
| 13 | Fechar | → `Closed` | Pré-condições 10.2; recalcula na mesma transação. |
| 14 | Congelar snapshot | `Closed` | Snapshot imutável; saldos gerados ficam disponíveis para M+1 ou como pendência administrativa. |

---

## 19. Fora do escopo

- Desconto legal de 6% do VT; salário; integração com folha (inclusive desconto de saldo pendente).
- Operadoras, cartões de transporte, CNAB, exportações bancárias.
- Roles, permissions, policies, gates, níveis de aprovação.
- Entidade de ausências/RH (`EmployeeAbsence`).
- Fluxo para funcionários sem ponto.
- Proporcionalidade por admissão/rescisão; campos de admissão/rescisão novos.
- Rateio de valores, tarifas ou itinerários dentro da competência.
- Transferência automática de saldo além de M+1.
- Qualquer alteração no módulo de ponto ou no cálculo de horas extras.
- Novo cadastro de funcionários.

---

## 20. Pontos de atenção para o plano de implementação

Não são decisões de negócio pendentes; são pontos técnicos/operacionais que o plano deve tratar sem alterar as regras acima.

### 20.1 Momento do cálculo × fim da janela

O cálculo de M costuma ocorrer no fim de M−1 (ex.: 29/09 para outubro, com pagamento no penúltimo ou último dia útil), antes do fim da janela (30/09). Desde a versão 3.1 a geração de sugestões é incremental (R33–R35):

- com o ponto importado até 29/09, a geração analisa 01/09 a 28/09; 29/09 e 30/09 aparecem na tela como "aguardando importação" e nenhum deles vira ausência;
- após nova importação, uma nova execução analisa os dias que entraram no horizonte, sem duplicar o que já foi sugerido;
- se a competência já estiver fechada quando os últimos dias forem importados, os eventos desses dias continuam sendo lançados manualmente como correção retroativa em M+1 (8.6). A sugestão automática dessa "cauda" é a etapa S3 e ainda não existe.

A tela mostra a última batida importada, o horizonte, o intervalo processável, os dias aguardando importação, os dias úteis sem batidas e a última execução. Nenhum dia da janela fica sem análise silenciosamente.

### 20.2 Exclusão de funcionário

`EmployeeIndex::destroy()` faz exclusão física. As FKs `RESTRICT` do módulo farão essa exclusão falhar para funcionários com histórico. Solução proposta: antes de excluir, verificar se existe histórico de benefícios e exibir mensagem orientando a encerrar as vigências em vez de excluir. Sem SoftDeletes e sem campos novos (R20).

### 20.3 Documentação do projeto

`CLAUDE.md` descreve Laravel 12; o projeto usa Laravel 13.21.1. Não afeta a especificação.

### 20.4 Validação da fórmula do VT contra a planilha

O modelo registra viagens/dia por trecho (7.2). A validação contra o Golden Dataset deve confirmar a equivalência com a planilha.

---

## 21. Dependências para a implementação

| Dependência | Motivo |
|---|---|
| **Planilha de VT/VR (Golden Dataset)** | Não está disponível no contexto atual. Deve ser anexada ao Claude Code na etapa de implementação para: (1) identificar os cenários; (2) reproduzi-los em testes Pest; (3) comparar resultados; (4) investigar divergências sem ajustar regras apenas para "fazer passar". A ausência da planilha não altera nenhuma regra desta especificação. |
| Feriados cadastrados | Anos das competências iniciais e das respectivas janelas. |
| Ponto importado | Batidas importadas até o fim da janela antes da geração de sugestões (20.1). |
| Carga inicial de configurações | Valores VR/VD, tarifas e preços, itinerários e elegibilidade de cada funcionário, a partir da planilha. |
| Factories de teste | `EmployeeFactory` e `PointFactory` não existem e são necessárias para os testes. |
