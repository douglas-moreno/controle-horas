# Plano de Implementação — Módulo de Benefícios VT / VR / VD

| | |
|---|---|
| Base | `docs/modulo-beneficios-vt-vr-vd.md` — versão 3.0 (Especificação definitiva) |
| Data | 29/09/2026 |
| Fontes analisadas | Código do projeto (branch `main`, commit `145278e`), banco SQLite local (somente leitura), planilha `VT e VR -09.2026.xlsx` (somente leitura) |
| Status | Plano — nada foi implementado |

> Convenção de referência: "Spec §N" = seção N da especificação 3.0. "Rn" = regra Rn da especificação.
> A planilha contém dados pessoais (CPF, dados bancários). Este documento os omite; cenários são referenciados pela **linha** da aba `Controle VT-VR`.

---

## 1. Executive Summary

**Estado atual.** Aplicação Laravel 13.21.1 pequena e coesa: 4 models (`Employee`, `Point`, `Holiday`, `ImportedLines`), 11 componentes Livewire de negócio (class-based, views separadas), 1 controller de impressão, 1 export Excel. Toda a lógica de negócio vive dentro dos componentes Livewire; não existem `app/Services`, `app/Enums` nem `app/Actions` (exceto o `Logout` do starter kit). Migrations sem foreign keys e sem índices além da PK. Testes Pest 4 apenas em `tests/Feature`, sem factories de `Employee`/`Point`.

**Complexidade do módulo.** Média-alta para o tamanho do projeto: 12 tabelas novas, 5 enums, ~8 serviços, ~9 telas. O núcleo de risco está em quatro pontos: (1) cálculo financeiro exato com snapshot; (2) idempotência da geração de sugestões do ponto; (3) encadeamento de competências (saldo negativo); (4) integridade transacional em fechamento/reabertura.

**Compatibilidade.** O módulo pode ser construído **sem alterar** `Point`, `Holiday`, o cálculo de horas extras, a importação AFD nem o espelho de ponto. As únicas alterações em código existente são: `Employee` (trait `HasFactory` + relacionamentos), `EmployeeIndex::destroy()` (guarda contra exclusão com histórico), `employee-index.blade.php` (botão "Benefícios"), `routes/web.php` e a sidebar.

**Planilha.** Disponível e analisada (§9). Confirma a regra de dias-base (setembro/2026 = 21 dias úteis, com 07/09 descontado), a fórmula do VT por trecho × viagens/dia e os valores VR R$ 27,50 / VD R$ 7,50. Foram encontradas divergências pontuais (§9.4), nenhuma altera a especificação; uma exige confirmação (§14, OI-1).

---

## 2. Current Architecture

### 2.1 Stack confirmada no código

| Item | Real | Observação |
|---|---|---|
| Laravel | 13.21.1 (`php artisan --version`) | `CLAUDE.md` descreve v12 — divergência documental. |
| PHP | 8.4.1 | |
| Livewire | 4.3.3 | Componentes **class-based** em `app/Livewire`, view em `resources/views/livewire/*.blade.php`. |
| Volt | 1.11.1 instalado | **Nenhum uso** (nenhum `@volt`, `Volt::`, `new class extends Component`). |
| Flux | 2.15 Free | Usado no layout/sidebar e nas telas do starter kit (settings/auth). |
| WireUI | 2.6 | **Padrão das telas de negócio**: `x-ui-button` (37 usos), `x-ui-input`, `x-ui-datetime-picker`, `x-ui-select`, `x-ui-badge`, `x-ui-modal-card`, `x-ui-maskable`; notificações via `WireUiActions::notification()`. |
| Excel | maatwebsite/excel 3.1 | `app/Exports/EmployeeResumeReportExport.php`. |
| Banco | SQLite local (`database/database.sqlite`), `:memory:` nos testes (`phpunit.xml`) | MySQL em produção (Spec R1). `config/database.php`: `strict => true`, `engine => null` (InnoDB padrão no MySQL 8), `foreign_key_constraints` habilitado no SQLite (`DB_FOREIGN_KEYS=true`). |
| Timezone | `UTC` (`config/app.php`) | Módulo trabalha com datas puras (`DATE`); sem impacto, mas `now()` deve ser usado com cuidado perto da meia-noite. |
| Auth | Fortify; 2 usuários (seeder); rotas de negócio em `Route::middleware(['auth'])` | Sem roles/policies/gates — compatível com R21. |

### 2.2 Estrutura de código

```
app/
  Exports/EmployeeResumeReportExport.php
  Http/Controllers/TimesheetPrintController.php     (único controller de negócio)
  Http/Requests/TimesheetPrintRequest.php           (único Form Request)
  Livewire/                                         (lógica de negócio + UI)
    EmployeeIndex, EmployeeCreate, EmployeeEdit, EmployeeHorasExtras, EmployeePointsEdit,
    EmployeeResumeReport, EmployeesExtraReport, HolidayIndex, HolidayCreate, HolidayEdit,
    TimesheetPrint, Auth/*, Settings/*, Actions/Logout
  Models/ Employee, Point, Holiday, ImportedLines, User
database/
  factories/ HolidayFactory, UserFactory
  migrations/ (8 arquivos; 4 de negócio)
  seeders/DatabaseSeeder.php
resources/views/
  components/layouts/app/sidebar.blade.php          (navegação)
  livewire/*.blade.php
routes/web.php                                      (rotas planas, nomes: employees.*, holidays.*, reports.*)
tests/Feature/*.php, tests/Unit/ExampleTest.php
```

### 2.3 Convenções observadas

| Aspecto | Convenção real |
|---|---|
| Componentes | Um componente por página (`Index`, `Create`, `Edit`), propriedades públicas sem tipo (código antigo) ou tipadas (código recente: `TimesheetPrint`, `HolidayIndex`). |
| Validação | Inline em `$this->validate([...], [mensagens])` nos componentes. Form Request apenas no controller de impressão. |
| Feedback | `$this->notification()->success/error(...)` (WireUI). Confirmação de exclusão com `wire:confirm="Confirma excluir registro?"`. |
| Modais | `x-ui-modal-card` com flag booleana (`EmployeePointsEdit::$showAddPointModal`). |
| Tabelas | `<table>` Tailwind manual + paginação `->links()`. |
| Período | Navegação `periodoAnterior()/periodoProximo()` com `mes/ano` (padrão repetido em 4 componentes). |
| Casts | Mistos: `Point` usa `$casts`, `User` usa `casts()`, `Holiday` usa `Attribute`. **Novo código: `casts()`** (orientação do `CLAUDE.md`). |
| Migrations | Classe anônima; `id()`, `timestamps()`; **sem FKs, sem índices**. |
| Testes | Pest; `uses(RefreshDatabase::class)` por arquivo; `beforeEach` com `actingAs(User::factory()->create())`; helpers globais definidos no próprio arquivo de teste (`employeeWithPoints`, `timesheetEmployee`). `tests/Pest.php` só estende `TestCase` em `Feature` e faz `Holiday::flushCachedHolidays()` antes de cada teste. |
| Idioma | Mensagens e rótulos em pt-BR; nomes de classes/métodos em inglês (com exceções antigas: `EmployeeHorasExtras`, `periodoAnterior`). |

### 2.4 Divergências documentação × código

| # | Documento | Código |
|---|---|---|
| DC-1 | `CLAUDE.md`: Laravel 12 | Laravel 13.21.1 |
| DC-2 | `CLAUDE.md`: "use `<flux:*>` quando disponível" | Telas de negócio usam WireUI. O plano segue o código (regra "follow existing conventions" do mesmo `CLAUDE.md`). |
| DC-3 | `CLAUDE.md`: Volt listado | Volt não é usado. O plano não usa Volt. |
| DC-4 | `CLAUDE.md`: Form Requests para validação | Válido para controllers; componentes Livewire validam inline. O plano segue o padrão Livewire existente. |

---

## 3. Existing Components Reused

### 3.1 Funcionários

| Item | Detalhe |
|---|---|
| Arquivo | `app/Models/Employee.php` |
| Classe | `App\Models\Employee` |
| Responsabilidade | Cadastro de funcionário. |
| Campos | `id`, `pis` (string; único apenas na validação), `name`, `position`, `recision_date` (datetime nullable), timestamps. `$fillable` = pis, name, position, recision_date. Sem casts. |
| Relacionamentos | `points(): HasMany` — `hasMany(Point::class, 'pis', 'pis')`. |
| Dependências | `EmployeeIndex` (lista, filtro ativo/inativo, importação AFD, exclusão), `EmployeeCreate`, `EmployeeEdit`, `EmployeeHorasExtras`, `EmployeePointsEdit`, relatórios, `TimesheetPrint(Controller)`. |
| Migration | `2025_10_14_155403_create_employees_table.php` (sem índice em `pis`). |
| Factory | **Não existe** (confirmado). Model não usa `HasFactory`. |
| Exclusão | `EmployeeIndex::destroy()` → `$employee->delete()` físico, sem verificação. |
| `recision_date` | Filtro "ativo" repetido em 5 lugares: `whereNull('recision_date')->orWhere('recision_date', '')`. **No banco local, todos os 34 funcionários ativos têm `''` (string vazia), não `NULL`.** |
| PIS | 11 dígitos em todos os registros locais; sem duplicidade; sem caracteres não numéricos. |
| Observação para benefícios | Reutilizado como está (R20). Recebe `HasFactory` e relacionamentos novos (§4). `recision_date` só alimenta o alerta informativo (Spec §4.2), tratando `''` como vazio. |

### 3.2 Ponto

| Item | Detalhe |
|---|---|
| Arquivo | `app/Models/Point.php`; migration `2025_10_14_160814_create_points_table.php` |
| Campos | `pis` **BIGINT** nullable, `date` DATE nullable, `time` TIME nullable, `type` string nullable (`importado` / `manual`), timestamps. Casts: `date => date`, `time => datetime:H:i`. |
| Relacionamento | `employee(): BelongsTo` por `pis` → `pis`. **Confirmado: `Employee → Point` é por PIS, não por `id`.** |
| Índices | Nenhum (apenas PK). |
| Importação | `EmployeeIndex::importPoints()`: arquivo AFD texto de largura fixa; data `substr(10,8)`, hora `substr(18,4)`, PIS `substr(23,12)`; controle incremental por `ImportedLines.line_number`; inserção em lote com `type = 'importado'`. |
| Registro manual | `EmployeePointsEdit::saveNewPoint()` → `type = 'manual'`; exclusão física por `removePoint()`. |
| Processamento | `EmployeeHorasExtras`, `EmployeeResumeReport`, `EmployeesExtraReport`, `TimesheetPrintController`: cada um agrupa batidas por dia e as distribui por posição (1ª–4ª). Regra de horas extras duplicada 3×. |
| Período 26→25 | `setPeriodFromMonth()` repetido em 4 componentes. |
| Batida válida hoje | Não há conceito explícito. Relatórios exigem entrada **e** saída para calcular horas. |
| Dados locais | ~171 mil batidas (2017–2026), 4 com data inválida (ano 0005), 57 PIS sem funcionário, última batida em 22/09/2026. |
| Factory | **Não existe** (confirmado). |
| Reuso | Leitura direta via `Point::query()` por intervalo de datas e lista de PIS. **Nenhuma query existente é reutilizável** (todas acopladas a componentes). |

**Riscos do vínculo por PIS para o módulo:**

| Risco | Impacto | Mitigação no plano |
|---|---|---|
| Tipos diferentes (`employees.pis` string × `points.pis` bigint) | Comparação depende de conversão implícita; zero à esquerda seria perdido no bigint. | O suggester normaliza ambos para string numérica sem zeros à esquerda antes de comparar em memória (§8.3). |
| PIS alterado no cadastro | Batidas antigas deixam de ser associadas. | Limitação aceita (Spec §16). Relatório de inconsistências lista PIS sem funcionário na janela. |
| PIS duplicado (não há unique no banco) | Dois funcionários receberiam as mesmas batidas. | Validação existente impede; suggester detecta PIS duplicado entre participantes e bloqueia com mensagem. |
| Sem índice em `points` | Consulta por janela varre a tabela. | Uma única consulta por geração (`whereBetween('date')` + `whereIn('pis')`), ~171 mil linhas: aceitável. **Não** adicionar índice (não alterar o módulo de ponto). |

### 3.3 Feriados

| Item | Detalhe |
|---|---|
| Arquivo | `app/Models/Holiday.php`; migration `2026_07_23_162743_create_holidays_table.php` (`date` UNIQUE); `HolidayFactory`; CRUD `HolidayIndex/Create/Edit`; testes `HolidayCrudTest`, `HolidayExtraHoursTest`. |
| `isHoliday(mixed $date): bool` | Normaliza a data para `Y-m-d` (`dateKey`, tolerante a erro) e consulta a coleção em cache. |
| `descriptionFor(mixed $date): ?string` | Idem, retorna a descrição. |
| `cachedHolidays(): Collection<string,string>` | Carrega **todos** os feriados uma vez (propriedade estática), chave `Y-m-d`. |
| Invalidação | `booted()`: `saved`/`deleted` chamam `flushCachedHolidays()`. `tests/Pest.php` também limpa antes de cada teste. |
| Dados locais | **1 feriado** (09/07/2026). Setembro/2026 exige 07/09; outubro/2026 exige 12/10. |
| Reuso pelo `BusinessCalendar` | Chamar `Holiday::isHoliday()` / `descriptionFor()` — sem nova consulta, sem duplicar regra. O cache estático é por processo; em fila/worker de longa duração não há risco porque o módulo roda em requisições web. |

### 3.4 UI e navegação

| Item | Detalhe | Reuso |
|---|---|---|
| Layout | `resources/views/components/layouts/app/sidebar.blade.php` (Flux sidebar, `<x-ui-notifications />`), namespace `layouts` registrado no `AppServiceProvider`. | Componentes novos usam o mesmo layout (padrão Livewire full-page já em uso). |
| Sidebar | Um grupo `flux:navlist.group` "Platform" com 5 itens. | Novo grupo "Benefícios". |
| Autorização | Somente `auth`. | Mantido (R21). |
| Padrões de tela | `HolidayIndex/Create/Edit` (mais recentes e tipados) são o melhor modelo. | Copiar estrutura. |

### 3.5 Testes existentes

| Arquivo | Cobertura |
|---|---|
| `HolidayCrudTest.php` | CRUD de feriados. |
| `HolidayExtraHoursTest.php` | Feriado nas horas extras (cria `Employee` + `Point::insert` manualmente). |
| `TimesheetPrintTest.php` | Espelho de ponto (helper `timesheetEmployee`). |
| `EmployeeResumeReportSortingTest.php` | Ordenação do resumo. |
| Auth/Settings/Dashboard/Example | Starter kit. |
| Employee CRUD | **Sem teste.** |
| Point import/edição | **Sem teste.** |

---

## 4. Required Changes

### 4.1 Matriz de impacto

| Área | Estado atual | Mudança necessária | Classificação | Risco | Prioridade |
|---|---|---|---|---|---|
| Employee (model) | Sem factory, 1 relação | `HasFactory`; relações `employeeBenefits`, `transportRoutes`, `benefitAdjustments`, `benefitPeriodEmployees`; método `hasBenefitHistory()` | Estender | Baixo | Alta (F0/F2) |
| Employee (exclusão) | Exclusão física livre | Guarda em `EmployeeIndex::destroy()` (§4.3) | Estender | Médio (regressão no CRUD) | Alta (F4) |
| Employee (tela) | Lista com ações | Botão "Benefícios" em `employee-index.blade.php` | Estender | Baixo | Média (F4) |
| Point | Sem factory | `PointFactory` usada via `PointFactory::new()` — **sem alterar `Point.php`** | Não alterar (+ factory) | Baixo | Alta (F0) |
| Holiday | Pronto | Nenhuma | Reutilizar | Baixo | — |
| Database | 4 tabelas de negócio, sem FKs | 12 tabelas novas com FKs e índices | Criar | Médio | Alta (F2) |
| Services | Inexistente | `app/Services` com 8 classes | Criar | Médio | Alta |
| Enums | Inexistente | `app/Enums` com 6 enums | Criar | Baixo | Alta (F1) |
| Livewire | 11 componentes | ~10 componentes novos | Criar | Médio | Média |
| Navigation | 1 grupo | Grupo "Benefícios" + rotas | Estender | Baixo | Média |
| Tests | Sem factories de domínio | 2 factories + ~15 arquivos de teste | Criar | Baixo | Alta (contínua) |
| Seeders | Seeder de usuários | Seeder opcional do catálogo inicial (§17 da spec, §12 deste plano) | Criar | Baixo | Baixa (F10) |

### 4.2 Novos campos

**Nenhum campo novo** em tabelas existentes. Nenhuma regra da especificação exige (R20; Spec §17).

### 4.3 Exclusão de funcionário (Spec §20.2)

Problema confirmado: `EmployeeIndex::destroy(Employee $employee)` executa `delete()` físico. Com as FKs `RESTRICT` novas, funcionários com histórico gerariam `QueryException` (erro 500 no Livewire).

Tratamento proposto (sem SoftDeletes):

1. `Employee::hasBenefitHistory(): bool` — `exists()` em `employee_benefits`, `transport_routes`, `benefit_adjustments` ou `benefit_period_employees`.
2. Em `destroy()`: se `true`, **não excluir** e emitir `notification()->error('Exclusão não permitida', 'O funcionário possui histórico de benefícios. Informe a data de rescisão e encerre as vigências de benefício.')`.
3. Caso contrário, comportamento atual inalterado.
4. Testes: exclusão sem histórico continua funcionando (regressão); exclusão com histórico é bloqueada e o registro permanece.

---

## 5. Database Plan

### 5.1 Convenções para as novas migrations

| Aspecto | Convenção atual | Novo módulo | Justificativa |
|---|---|---|---|
| Criação | `php artisan make:migration` | Igual, uma migration por tabela, em ordem de dependência | `CLAUDE.md` |
| PK | `id()` | `id()` (BIGINT UNSIGNED) | Igual |
| FK | Nenhuma | `foreignId()->constrained()->restrictOnDelete()`; usuários `nullOnDelete()` | Spec §17 (integridade financeira) |
| Índices | Nenhum | Conforme tabela abaixo | Spec §17 |
| Enums | — | `string(n)` | Spec §5.1 |
| Dinheiro | — | `decimal(10,2)` / `decimal(12,2)` | R28 |
| Timestamps | `timestamps()` | Igual; `benefit_period_status_changes` só `created_at` | |
| CHECK | — | **Não** usar (schema builder não gera; SQLite divergiria). Regras na aplicação. | Spec §17 |
| SoftDeletes | — | Nenhuma tabela | Spec §17 |

### 5.2 Tabelas (ordem de criação)

| # | Tabela | Objetivo | FKs | Índices / Unique | Riscos |
|---|---|---|---|---|---|
| 1 | `employee_benefits` | Elegibilidade por tipo e vigência | `employee_id` → employees RESTRICT; `created_by`/`updated_by` → users SET NULL | UNIQUE(`employee_id`,`benefit_type`,`starts_on`); INDEX(`benefit_type`,`starts_on`,`ends_on`) | Sobreposição de vigências só na aplicação. |
| 2 | `benefit_rates` | Valor diário global VR/VD | `created_by` → users | UNIQUE(`benefit_type`,`valid_from`) | `valid_from` dia 01 só na aplicação. |
| 3 | `transport_fares` | Catálogo de tarifas | — | UNIQUE(`name`) | Planilha tem nomes repetidos (§9.4 DV-6). |
| 4 | `transport_fare_prices` | Preço com vigência | `transport_fare_id` RESTRICT; `created_by` | UNIQUE(`transport_fare_id`,`valid_from`) | — |
| 5 | `transport_routes` | Itinerário do funcionário | `employee_id` RESTRICT; `transport_fare_id` RESTRICT; `created_by`/`updated_by` | INDEX(`employee_id`,`starts_on`,`ends_on`) | Sobreposição na aplicação. |
| 6 | `benefit_periods` | Competência | `calculated_by`/`closed_by`/`created_by` → users | UNIQUE(`competence`); INDEX(`status`) | — |
| 7 | `benefit_period_status_changes` | Log de estado | `benefit_period_id` CASCADE; `user_id` SET NULL | INDEX(`benefit_period_id`,`created_at`) | Só `created_at` (`public const UPDATED_AT = null` no model). |
| 8 | `benefit_adjustments` | Evento | `benefit_period_id` RESTRICT; `employee_id` RESTRICT; `related_adjustment_id` → self SET NULL; `reviewed_by`/`created_by`/`updated_by` | UNIQUE(`dedupe_key`); INDEX(`benefit_period_id`,`employee_id`,`status`); INDEX(`employee_id`,`status`,`starts_on`,`ends_on`) | Self-FK; UNIQUE com NULL múltiplo (MySQL e SQLite permitem). |
| 9 | `benefit_adjustment_impacts` | Impacto por tipo | `benefit_adjustment_id` CASCADE | UNIQUE(`benefit_adjustment_id`,`benefit_type`) | `quantity` assinado (`smallInteger`). |
| 10 | `benefit_period_employees` | Participante + snapshot | `benefit_period_id` CASCADE; `employee_id` RESTRICT | UNIQUE(`benefit_period_id`,`employee_id`) | — |
| 11 | `benefit_calculations` | Resultado por tipo | `benefit_period_employee_id` CASCADE; `carried_from_calculation_id` → self RESTRICT; `benefit_rate_id` → benefit_rates SET NULL | UNIQUE(`benefit_period_employee_id`,`benefit_type`); UNIQUE(`carried_from_calculation_id`); INDEX(`benefit_type`,`carried_out_days`) | Ordem de exclusão no recálculo (§5.3). |
| 12 | `benefit_calculation_transport_items` | Snapshot VT | `benefit_calculation_id` CASCADE; `transport_route_id`, `transport_fare_id` SET NULL | — | — |

Campos: exatamente os da Spec §17.1–§17.12.

### 5.3 Pontos de atenção de integridade

| Ponto | Detalhe |
|---|---|
| Recalcular M | Apagar `benefit_period_employees` de M dispara CASCADE até `benefit_calculations` e itens. Se algum cálculo de M+1 referenciar um cálculo de M (`carried_from`, RESTRICT), a exclusão falha. Pela Spec §6.4 isso só ocorre ao reabrir M com M+1 `Calculated`; o `BenefitPeriodWorkflow::reopen()` deve **primeiro** descartar o snapshot de M+1 (voltar para `Open`), **depois** descartar o de M. |
| Excluir competência | Só `Open`, sem snapshot, a mais recente. `benefit_adjustments` usa RESTRICT: excluir competência com ajustes exige excluir os ajustes antes — mas ajustes não são excluídos (Spec §8.5). **Regra de plano:** competência só pode ser excluída se não tiver ajustes. |
| Tipos numéricos | `days_count`, `base_days` etc. como `unsignedSmallInteger`; `raw_days` e `quantity` como `smallInteger`. |
| Datas | Colunas `date`. Casts `date` (Carbon) ou `immutable_date` — usar `immutable_date` nos models novos para evitar mutação acidental em cálculos. |

### 5.4 Models novos (`app/Models`)

`EmployeeBenefit`, `BenefitRate`, `TransportFare`, `TransportFarePrice`, `TransportRoute`, `BenefitPeriod`, `BenefitPeriodStatusChange`, `BenefitAdjustment`, `BenefitAdjustmentImpact`, `BenefitPeriodEmployee`, `BenefitCalculation`, `BenefitCalculationTransportItem`.

- Casts via `casts()`: enums (`BenefitType::class` etc.), `immutable_date`, `decimal:2`.
- Relacionamentos com tipos de retorno (`BelongsTo`, `HasMany`).
- Escopos úteis (sem lógica de negócio): `EmployeeBenefit::scopeActiveOn(date)`, `TransportRoute::scopeActiveOn(date)`, `BenefitAdjustment::scopeNotRejected()`, `scopeConfirmed()`.
- `BenefitPeriod`: acessores derivados `referenceDate()` (01/M), `windowStart()`/`windowEnd()` (mês M−1), `monthEnd()`, `isEditable()`.
- Factories para todos, com estados úteis (`BenefitPeriod::closed()`, `BenefitAdjustment::pending()`, `->vacation()`, etc.).

---

## 6. Domain/Application Services

Todas em `app/Services` (aprovado — Spec R32). Sem Repositories, sem Actions, sem DTOs formais: retornos em arrays com shape PHPDoc, como o código existente (`TimesheetPrintController::buildDays`). Erros de domínio lançam `Illuminate\Validation\ValidationException::withMessages([...])`, que o Livewire exibe nativamente.

### 6.1 `Money` (auxiliar)

| | |
|---|---|
| Entrada | `string` decimal (`"5.40"`) ou `int` centavos |
| Saída | `toCents(string): int`; `fromCents(int): string` (`"5.40"`) |
| Responsabilidade | Conversão exata por parsing de string; rejeitar mais de 2 casas. |
| Não faz | Arredondamento, formatação de moeda para tela (fica na view: `number_format`). |
| Dependências | Nenhuma. |
| Testes | Unit puro (`tests/Unit/MoneyTest.php`): `"5.40"`, `"10.32"`, `"0.1"`, `"1234.5"`, rejeição de `"1.234"`. |

Justificativa: evita float em dois serviços (`TransportDailyAmount`, `BenefitPeriodCalculator`); sem ele a conversão seria duplicada.

### 6.2 `BusinessCalendar`

| | |
|---|---|
| Entrada | `CarbonImmutable` datas / mês |
| Saída | `classify(date): CalendarDayType`; `days(from, to): Collection<string Y-m-d, CalendarDayType>`; `businessDays(from, to): Collection<CarbonImmutable>`; `businessDaysInMonth(month): int`; `yearHasHolidays(int): bool` |
| Responsabilidade | Precedência `Holiday` > `Saturday`/`Sunday` > `BusinessDay`. |
| Não faz | Consultar ponto, elegibilidade ou ajustes. |
| Dependências | `Holiday::isHoliday()` / `Holiday::cachedHolidays()`. |
| Testes | Feature (usa banco): dia útil, sábado, domingo, feriado em dia útil, feriado em sábado, contagem set/2026 = 21 com 07/09, out/2026 = 21 com 12/10, ano sem feriado. |

### 6.3 `BenefitEligibility`

| | |
|---|---|
| Entrada | `CarbonImmutable $referenceDate` (01/M) |
| Saída | `participants(ref): Collection<int employee_id, list<BenefitType>>`; `isEligible(Employee, BenefitType, ref): bool` |
| Responsabilidade | Vigência de `EmployeeBenefit` em 01/M; participante = ≥1 tipo (Spec §4). |
| Não faz | Filtrar por `recision_date` (R18). Validar sobreposição (isso é do cadastro). |
| Dependências | `EmployeeBenefit`. |
| Testes | Vigente, encerrada antes, iniciando depois, `ends_on` = 01/M, tipos independentes, funcionário com `recision_date` continua participante. |

### 6.4 `TransportDailyAmount`

| | |
|---|---|
| Entrada | `Employee`, `CarbonImmutable $referenceDate` |
| Saída | `array{daily_cents:int, items:list<array{route_id,fare_id,fare_name,fare_cents,trips_per_day,daily_cents}>, missing_prices:list<int>}` |
| Responsabilidade | Rotas vigentes em 01/M; preço vigente em 01/M (maior `valid_from ≤ ref`); Σ(preço × viagens). |
| Não faz | Multiplicar por dias; gravar snapshot; decidir bloqueio (só informa ausências). |
| Dependências | `TransportRoute`, `TransportFarePrice`, `Money`. Método de lote `forEmployees(Collection, ref)` com eager loading para o calculador (evitar N+1). |
| Testes | 1 trecho; 2 trechos; viagens diferentes por trecho (planilha linha 13); tarifa 10,32 (linha 33); mudança de preço em 15/10 (usa o de 01/10); mudança de rota em 15/10; sem rota; rota sem preço vigente. |

### 6.5 `BenefitAdjustmentRegistrar`

| | |
|---|---|
| Entrada | `BenefitPeriod`, `Employee`, `AdjustmentReason`, `AdjustmentSource`, datas, notas, impactos livres (só `Manual`) |
| Saída | `BenefitAdjustment` persistido (com impactos) |
| Métodos | `register(...)`, `registerSuggestion(...)` (usado pelo suggester, com `dedupe_key`), `update(Pending)`, `replace(Confirmed, novosDados)`, `confirm`, `reject(notas)`, `reconsider`, `confirmMany`, `rejectMany` |
| Responsabilidades | (1) período editável (`Open`/`Calculated`); (2) classe de data (Realizado/Previsto/Retroativo, Spec §3.2) × origem permitida (Spec §8.4); (3) intervalo não cruza classes; (4) `days_count` via `BusinessCalendar` (ausência) ou 1 (trabalho); (5) impactos padrão ±`days_count` nos 3 tipos, ou livres para `Manual` com nota obrigatória; (6) DD2/DD3 na mesma competência; (7) alerta DD5/DD6 (retornado, não bloqueia); (8) `reviewed_*`; (9) se o período estava `Calculated`, chama `BenefitPeriodWorkflow::invalidate()`. |
| Não faz | Ler ponto; calcular valores; excluir ajustes. |
| Dependências | `BusinessCalendar`, `BenefitPeriodWorkflow` (apenas `invalidate`). |
| Testes | Ver §10 "Ajustes". |

### 6.6 `TimesheetAdjustmentSuggester`

| | |
|---|---|
| Entrada | `BenefitPeriod` |
| Saída | `generate(period): array{created:int, skipped_existing:int, skipped_covered:int}`; `conflicts(period): list<array{type, employee_id, date, message}>`; `lastImportedPointDate(): ?CarbonImmutable` |
| Responsabilidade | Ler `points` da janela M−1 para participantes; classificar dias; criar sugestões `Timesheet`/`Pending` idempotentes via `registerSuggestion`; calcular conflitos (não persistidos). Detalhe em §8. |
| Não faz | Escrever em `points`; confirmar ajustes; usar regras de horas extras. |
| Dependências | `BusinessCalendar`, `BenefitEligibility`, `BenefitAdjustmentRegistrar`, `Point` (somente leitura). |
| Testes | Ver §10 "Ponto". |

### 6.7 `BenefitPeriodCalculator`

| | |
|---|---|
| Entrada | `BenefitPeriod` |
| Saída | `calculate(period): array{issues: list<array{severity, employee_id, benefit_type, message}>}` + snapshot persistido |
| Responsabilidade | Dentro da transação aberta pelo workflow: apagar snapshot de M; dias-base; participantes; somar impactos `Confirmed` por funcionário×tipo (uma consulta agregada); ler saldo gerado em M−1 (se `Closed`) para o mesmo tipo; aplicar fórmula Spec §6.1; valores VR/VD (Spec §7.1); VT (`TransportDailyAmount`); gravar `benefit_period_employees`, `benefit_calculations`, itens de VT; `business_days`. |
| Não faz | Mudar status; validar pré-condições de fechamento; gerar ajustes (nunca cria `BenefitAdjustment` para saldo). |
| Dependências | `BusinessCalendar`, `BenefitEligibility`, `TransportDailyAmount`, `Money`. |
| Testes | Ver §10 "Cálculo" + Golden Dataset. |

### 6.8 `BenefitPeriodWorkflow`

| | |
|---|---|
| Entrada | `BenefitPeriod`, usuário autenticado |
| Métodos | `createNext(?CarbonImmutable firstCompetence)`, `calculate`, `close`, `reopen(reason)`, `invalidate`, `delete` |
| Responsabilidade | Pré-condições Spec §10.2; transações e `lockForUpdate`; transições; log em `benefit_period_status_changes`; bloqueios de fechamento (`Pending` > 0; `issues` bloqueantes do calculador); reabertura em cascata sobre M+1 `Calculated`. |
| Não faz | Regras de quantidade ou valor. |
| Dependências | `BenefitPeriodCalculator`. |
| Testes | Ver §10 "Fechamento". |

### 6.9 `BenefitCarryForwardReport`

| | |
|---|---|
| Entrada | opcional: competência |
| Saída | `list<array{employee_id, employee_name, benefit_type, origin_competence, days, status, reason}>` |
| Responsabilidade | Derivar situação dos saldos (Aguardando / Aplicado / Não aplicado) — Spec §6.2 — sem gravar nada. |
| Não faz | Aplicar saldo; alterar cálculos. |
| Testes | Três situações; saldo de VT não afeta VR/VD. |

Justificativa de existir separado: consulta não trivial (auto-relação + competência seguinte) usada por uma tela própria; mantê-la fora do calculador preserva o calculador como "escritor" único do snapshot.

### 6.10 Enums (`app/Enums`)

| Enum | Valores | Métodos |
|---|---|---|
| `BenefitType` | `Vt`, `Vr`, `Vd` (valores `vt`,`vr`,`vd`) | `label()` |
| `BenefitPeriodStatus` | `Open`, `Calculated`, `Closed` | `label()`, `isEditable()` |
| `AdjustmentReason` | 11 valores (Spec §8.3) | `label()`, `isAbsence()`, `isWork()`, `defaultSign(): ?int` (null para `Manual`), `allowedSources(): list<AdjustmentSource>` |
| `AdjustmentSource` | `Manual`, `Timesheet`, `Hr`, `Import` | `label()`, `initialStatus()` |
| `AdjustmentStatus` | `Pending`, `Confirmed`, `Rejected` | `label()` |
| `CalendarDayType` | `BusinessDay`, `Saturday`, `Sunday`, `Holiday` | — (usado pelo calendário e pelo suggester) |

`AdjustmentTiming` (Realizado/Previsto/Retroativo) **não** vira enum persistido; é derivado por `BenefitPeriod::timingOf(start, end)` retornando um enum de código `AdjustmentTiming` — incluído por ser usado em validação e agrupamento de tela (7º enum, apenas em memória).

---

## 7. Livewire/UI Plan

Padrão: componentes class-based em `app/Livewire/Benefits/`, views em `resources/views/livewire/benefits/`, WireUI para formulários/botões/notificações, tabelas Tailwind manuais, `wire:confirm` para ações destrutivas, `x-ui-modal-card` para formulários curtos. Componentes apenas orquestram serviços.

> Subpasta `app/Livewire/Benefits` não é pasta-base nova (fica dentro de `app/Livewire`); o `make:livewire Benefits/BenefitPeriodIndex` a cria. Mantém o módulo agrupado.

| Componente | Rota (nome) | URL | Conteúdo |
|---|---|---|---|
| `Benefits\BenefitRateIndex` | `benefits.rates.index` | `benefits/rates` | Vigências VR e VD; modal "Nova vigência" (tipo, valor, mês de início → dia 01); exclusão só de vigência não usada. |
| `Benefits\TransportFareIndex` | `benefits.fares.index` | `benefits/fares` | Lista de tarifas (nome, operadora, preço vigente hoje, ativo); criar tarifa (modal). |
| `Benefits\TransportFareEdit` | `benefits.fares.edit` | `benefits/fares/{transportFare}/edit` | Editar nome/operadora/ativo; histórico de preços; "Novo preço" (valor, `valid_from`). |
| `Benefits\EmployeeBenefitsEdit` | `employees.benefits` | `employees/{employee}/benefits` | Extensão do cadastro: vigências VT/VR/VD (adicionar, encerrar); itinerário (trechos: tarifa, viagens/dia, início, encerrar); VT diário calculado para hoje (via `TransportDailyAmount`). Acesso pelo botão "Benefícios" na lista de funcionários. |
| `Benefits\BenefitPeriodIndex` | `benefits.periods.index` | `benefits/periods` | Competências (mês, status, participantes, total); "Criar próxima competência"; excluir (se permitido). |
| `Benefits\BenefitPeriodAdjustments` | `benefits.periods.adjustments` | `benefits/periods/{benefitPeriod}/adjustments` | Cabeçalho (competência, janela, dias-base, status, última batida importada). Seções **Previstos / Realizados / Correções retroativas**. Filtros (funcionário, motivo, origem, status). Ações: novo ajuste (modal), alterar (modal), confirmar/rejeitar (individual, seleção em lote), "Gerar sugestões do ponto" (desabilitado com motivo se ponto incompleto). Painel de conflitos. |
| `Benefits\BenefitPeriodCalculation` | `benefits.periods.calculation` | `benefits/periods/{benefitPeriod}/calculation` | Tabela obrigatória (R23): Funcionário · VT qtd · VT valor · VR qtd · VR valor · VD qtd · VD valor · Total; linha de totais; detalhe expansível (base, +, −, herdado + origem, gerado, trechos). Ações: Calcular, Fechar (lista de impedimentos), Reabrir (modal com motivo obrigatório). |
| `Benefits\BenefitCarryForwardIndex` | `benefits.carry-forward.index` | `benefits/carry-forward` | Pendências de saldo (Spec §6.2): funcionário, benefício, competência de origem, dias, situação, motivo. Somente leitura. |

**Sidebar** (`resources/views/components/layouts/app/sidebar.blade.php`): novo `flux:navlist.group` heading "Benefícios" com: Competências, Valores VR/VD, Tarifas de transporte, Pendências de saldo. Benefícios do funcionário são acessados pela lista de funcionários.

**Formato monetário na tela:** `number_format($value, 2, ',', '.')` com prefixo `R$`, como convenção nova (não há precedente no projeto).

---

## 8. Point Integration Plan

### 8.1 Pré-condições da geração

1. Competência `Open` ou `Calculated`.
2. `lastImportedPointDate()` = `max(date)` de `points` com `type = 'importado'` ≥ último dia da janela. (Pontos `manual` não contam: podem ter sido lançados antecipadamente.)
3. Participantes com PIS duplicado → bloqueio com mensagem.

### 8.2 Operação do usuário quando a janela ainda não terminou (Spec §20.1)

Sem mecanismo de contorno. Fluxo operacional:

| Momento | Ação do administrador |
|---|---|
| ~20–28 de M−1 | Criar competência M; cadastrar/revisar vigências; lançar **eventos previstos** de M (férias, afastamentos programados); lançar manualmente eventos realizados já conhecidos, se desejar. |
| Botão "Gerar sugestões" | Exibido desabilitado com o texto: "Batidas importadas até DD/MM/AAAA. A geração exige batidas até o último dia de <mês M−1>." |
| 1º dia útil de M (após importar o AFD) | Gerar sugestões; revisar; confirmar/rejeitar; calcular; fechar. |
| Se o pagamento precisar sair antes | O administrador lança manualmente (`Manual`) os eventos restantes da janela; ao gerar sugestões depois (competência ainda aberta), DD3/DD4 evitam duplicidade com os lançamentos manuais de mesmo sentido. Se a competência já estiver fechada, dias restantes entram como correção retroativa em M+1 (Spec §8.6). |

### 8.3 Algoritmo

```
1. ref = 01/M; window = [01/M−1, último dia M−1]
2. participants = BenefitEligibility::participants(ref)           → employee_id → types
3. employees = Employee::whereIn(id, participants)->get(id, pis, name, recision_date)
4. pisMap = normalize(employee.pis) → employee_id                  (string numérica, sem zeros à esquerda)
5. punches = Point::whereBetween(date, window)->whereIn(pis, pisList)
              ->select(pis, date)->distinct()->get()               (1 consulta)
   punchedDays[employee_id] = set(Y-m-d)
6. coverage = BenefitAdjustment::notRejected()
              ->whereIn(employee_id, …)
              ->where(starts_on ≤ window.end)->where(ends_on ≥ window.start)
              ->get(employee_id, reason, starts_on, ends_on)       (qualquer competência — DD4)
   coveredAbsence[employee_id] = set(dias); coveredWork[employee_id] = set(dias)
7. existingKeys = dedupe_keys da competência                       (DD1, inclui rejeitadas)
8. for day in BusinessCalendar::days(window):
     for employee in participants:
       punched = day ∈ punchedDays[e]
       match type(day):
         BusinessDay: if !punched && day ∉ coveredAbsence → UnjustifiedAbsence (−1)
         Holiday:     if punched && day ∉ coveredWork     → HolidayWorked (+1)
         Saturday:    if punched && day ∉ coveredWork     → SaturdayWorked (+1)
         Sunday:      if punched && day ∉ coveredWork     → SundayWorked (+1)
       key = "timesheet:{period}:{employee}:{Y-m-d}:{reason}"
       if key ∈ existingKeys → skipped_existing; else registerSuggestion(...)
9. se created > 0 e período Calculated → invalidate
```

Tudo dentro de `DB::transaction` com `BenefitPeriod::lockForUpdate()`. `UNIQUE(dedupe_key)` é a última barreira contra corrida.

### 8.4 Conflitos (calculados sob demanda, não persistidos)

| Conflito | Regra |
|---|---|
| Ausência com batida | Dia útil ∈ coveredAbsence **e** punched. |
| Trabalho em dia de ausência | Sábado/domingo/feriado punched **e** ∈ coveredAbsence. |
| Participante com rescisão | `recision_date` não nulo e ≠ `''`. |
| PIS sem funcionário | PIS com batida na janela que não mapeia para nenhum `Employee` (lista informativa). |

### 8.5 Eventos previstos × suggester (Spec §9.3)

| Caso | Comportamento |
|---|---|
| Férias previstas confirmadas em M (01–05/10) + sem ponto | Na competência M+1 (janela = M), os dias estão em `coveredAbsence` → nenhuma `UnjustifiedAbsence`. |
| Previsto `Pending` | Também cobre (não rejeitado) — evita sugestão enquanto aguarda revisão. |
| Previsto rejeitado (cancelado) | Deixa de cobrir; dias sem batida geram sugestão normalmente. |
| Previsto alterado (substituição) | Original `Rejected`, novo cobre apenas o novo intervalo. |
| Previsto atravessando meses | Lançado como dois ajustes (um por competência); o registrar rejeita intervalo que cruza a borda do mês com mensagem orientando o desmembramento. A competência seguinte pode ser criada antecipadamente para receber a segunda parte (criação só exige ser o mês seguinte à última existente). |

---

## 9. Golden Dataset / Spreadsheet Validation

### 9.1 Estrutura da planilha

| Aba | Visível | Papel |
|---|---|---|
| `Controle VT-VR` | Sim | **Principal.** Competência (B2 = 01/09/2026, "VT Ref a Setembro/2026"), dias úteis (N2 = 21), valor VR (AC2 = 27,50); uma linha por funcionário (linhas 5–46, 42 funcionários) com colunas de ajustes e fórmulas de quantidade VT/VR/VD. |
| `Vale transp calc` | Sim | Catálogo de tarifas (linha 3 nomes, linha 4 preços, 20 colunas C–V), VR (W1 = 27,50) e VD (W2 = 7,50); por funcionário: viagens mensais por trecho (`= dias VT × viagens/dia`) e totais VT (W) e VR+VD (Y). |
| `Recibo vt`, `VT Transferencia`, `Recib Avulso` | Ocultas | Saídas (recibos, relação de transferência). Fora do MVP (R23). |
| `Controle HE`, `Gráf1–3` | Ocultas | Legado de horas extras/gráficos. Irrelevante. |

### 9.2 Fórmulas (aba `Controle VT-VR`)

| Coluna | Significado | Entra em |
|---|---|---|
| N2 | Dias úteis da competência | VT, VR, VD |
| G | Dias VT (`'N'` = sem VT) | — |
| J | Falta justificada | −VT −VR −VD |
| K | Falta injustificada | −VT −VR −VD |
| L | Sábado | +VT +VR +VD |
| M | Domingo/feriado | +VT +VR +VD |
| N | Sáb/Dom/Fer somente VT | +VT |
| O | Dom/Fer somente VD | +VD |
| P | Desconto VR | −VR |
| Q | Desconto VD | −VD |
| R | Pago a menor VR | +VR +VD |
| S | Pago a menor VT | +VT |
| T | "Não quer VT" (dias) | −VT |
| U | Pago a maior VT/VR | −VT −VR −VD |
| V | Pago a maior VR | −VR −VD |
| W | Desconto atestado | −VT −VR −VD |
| X | Desconto férias | −VT −VR −VD |
| Y | Pago a maior / desconto VT | −VT |
| Z | Afastamento | **−VD apenas** |
| AA | Total VT (= `Vale transp calc`!W) | |
| AB | Total VR+VD (= `Vale transp calc`!Y = dias VR × 27,50 + dias VD × 7,50) | |
| AC | "Desc Folha VR-10%" = dias VR × 27,50 × 10% | não entra em AD |
| AD | Total VT + VR+VD | |

VT (aba `Vale transp calc`): para cada trecho, `viagens do mês = dias VT × viagens/dia` (multiplicadores observados: ×1, ×2, ×4); `W = Σ(viagens do mês × tarifa)` = dias × Σ(tarifa × viagens/dia). **Confirma o modelo por trecho da especificação (Spec §7.2).**

### 9.3 Conferência com a especificação

| Regra | Planilha | Situação |
|---|---|---|
| Competência = mês do benefício; base = dias úteis do mês | "Ref a Setembro/2026", 21 = 22 dias seg–sex − 07/09 | ✔ |
| Mesma quantidade para VT, VR, VD | Mesmas colunas-base; diferenças só via colunas específicas | ✔ (colunas específicas = ajuste `Manual` por tipo) |
| Sábado/domingo/feriado +1 nos três | L e M | ✔ |
| Ausências −1 nos três | J, K, W, X | ✔ — exceto Z (DV-1) |
| VR/VD valores globais | W1/W2 únicos | ✔ |
| VT = Σ(tarifa × viagens/dia) × dias | Fórmula W | ✔ |
| Sem 6% | Não há coluna | ✔ |
| Quantidade ≥ 0 com saldo | Sem `MAX`; nenhum caso negativo em set/2026 | ✔ (sem cenário) |

### 9.4 Divergências (especificação prevalece; nenhuma altera a spec)

| # | Divergência | Análise | Tratamento |
|---|---|---|---|
| DV-1 | Coluna Z (Afastamento) desconta **apenas VD**; fórmulas de VT e VR não incluem Z. | Contraria R9. Provável omissão de fórmula. Nenhuma linha de set/2026 usa Z → sem efeito no Golden Dataset atual. | Manter R9 (`LeaveOfAbsence` −1 nos três). Registrado em §14 (OI-2) apenas para ciência. |
| DV-2 | Colunas N (+VT) e O (+VD) permitem crédito de fim de semana para um único benefício. | R7 exige +1 nos três. Em set/2026, N e O estão vazias. | Casos excepcionais = ajuste `Manual` por tipo. |
| DV-3 | Coluna AC e recibo: "participação 10%" do VR (desconto em folha). | Não está na especificação. Não altera o total pago (AD). | Fora do MVP por analogia a R29 (folha). **Confirmar** — §14 OI-1. |
| DV-4 | Colunas R/V (pago a menor/maior VR) afetam VR **e** VD. | Correções manuais com dois impactos. | `Manual` com impactos VR e VD. |
| DV-5 | "Não quer VT" (T) como quantidade de dias. | Desistência parcial no mês. | `Manual` −VT; desistência total = encerrar vigência de VT. |
| DV-6 | Catálogo com nomes repetidos: "CMT BOM" (2 colunas, 5,90) e "INTEGRAÇÃO" (1,10 e 1,40); nome numérico "372". | `transport_fares.name` é UNIQUE. | Na carga inicial, nomes distintos definidos pelo administrador (ex.: "INTEGRAÇÃO 1,10" / "INTEGRAÇÃO 1,40"). Não é mudança de regra. |
| DV-7 | Funcionários identificados por nome e "Cód Func." (códigos diferentes entre abas; alguns vazios); sem PIS. 42 linhas vs 34 ativos no sistema; 2 linhas parecem placeholders. | Não há chave comum com `employees`. | Mapeamento manual na carga inicial (§12, fase F10). |
| DV-8 | Sistema tem só o feriado 09/07; planilha pressupõe 07/09. | Sem 07/09 o sistema calcularia 22 dias. | Pré-requisito: cadastrar feriados antes do Golden Dataset e da primeira competência real. |
| DV-9 | Totais de controle: `Vale transp calc`!W49 soma só W28:W46. | Erro de fórmula de rodapé, não usado em AD. | Usar AA47/AB47/AD47 da aba principal como referência. |

### 9.5 Transformação em testes

Arquivo `tests/Feature/Benefits/GoldenDatasetSeptember2026Test.php`, **sem ler o .xlsx em tempo de teste** (caminho externo ao repositório; dados pessoais). Os cenários são transcritos anonimizados (Funcionário L05, L06…) como dataset Pest.

Preparação comum: competência 09/2026; feriado 07/09/2026; VR 27,50 e VD 7,50 com `valid_from` 2026-09-01; catálogo de 20 tarifas com os preços da linha 4; ajustes `Manual`/`Confirmed` (o teste valida o **calculador**, não o suggester).

| Cenário | Linha | Entrada | Esperado |
|---|---|---|---|
| G1 | 5 | Sem VT; VR/VD | VT —; VR 21 = 577,50; VD 21 = 157,50 |
| G2 | 6 | CPTM×2 + SP×2; K=1, L=2 | 22 dias; VT 607,20; VR+VD 770,00 |
| G3 | 8 | CPTM×2 + "372"×2 | 21; VT 417,90 |
| G4 | 13 | UNI ABC×2 + TROLEBUS×1 + ETC DIADEMA×1 + INTEGRAÇÃO(1,40)×1; L=3 | 24; VT 577,20; VR+VD 840,00 |
| G5 | 10 | CPTM×2 + MUN MAUÁ×2; W=12 | 9; VT 203,40; VR+VD 315,00 |
| G6 | 15 | CPTM×2 + RIB.PIRES×2; L=4, W=2 | 23; VT 542,80 |
| G7 | 17 | CPTM×2 + BR7×2 + TROLEBUS×2; W=1 | 20; VT 708,00 |
| G8 | 26 | UNI ABC×4 | 21; VT 495,60 |
| G9 | 31 | CMT BOM×4 + TROLEBUS×2; L=2, W=5 | 18; VT 653,40; VR+VD 630,00 |
| G10 | 33 | SP TRANS (10,32)×2 | 21; VT 433,44 (precisão de centavos) |
| G11 | 39 | 3 trechos; X=21 (férias) | 0 dias; tudo 0,00 |
| G12 | 41 | CPTM×2; Y=3 (`Manual` −VT) | VT 18 = 194,40; VR/VD 21 (735,00) |
| G-ALL | 5–46 | Todos os 42 cenários | Σ VT 14.935,84; Σ VR+VD 30.065,00; Σ total 45.000,84 |

Regras de condução (Spec R22): divergência → investigar causa → registrar; **não** ajustar regra para "fazer passar". A coluna AC (10%) não é verificada (DV-3).

---

## 10. Test Strategy

### 10.1 Organização

- `tests/Feature/Benefits/*Test.php` (Pest; `uses(RefreshDatabase::class)`; `beforeEach` com `actingAs`). Serviços que tocam o banco ficam em `Feature` porque `tests/Pest.php` só associa `TestCase` a `Feature`.
- `tests/Unit/MoneyTest.php` para o auxiliar puro.
- Factories novas: `EmployeeFactory` (pis 11 dígitos únicos, name, position, `recision_date` null) com estado `terminated()`; `PointFactory` (pis, date, time, type `importado`) com estados `manual()`, `on(date)`, `forEmployee(Employee)`; factories de todos os models novos.
- Datas fixas no passado/futuro conhecido (set–nov/2026) — nada dependente de `now()`, exceto testes explícitos de "hoje".
- Não refatorar os helpers globais dos testes existentes (fora do escopo); os novos testes usam factories.

### 10.2 Casos

**Calendário** (`BusinessCalendarTest`): dia útil; sábado; domingo; feriado em dia útil; feriado em sábado (tipo `Holiday`); contagem set/2026 (21) e out/2026 (21); ano sem feriados → `yearHasHolidays` falso.

**Ponto** (`TimesheetAdjustmentSuggesterTest`): dia útil sem batida → `UnjustifiedAbsence` Pending; uma batida → nada; múltiplas batidas → nada; sábado/domingo/feriado com batida → +1 do motivo correto; feriado no sábado → só `HolidayWorked`; batida `manual` conta; PIS desconhecido → ignorado e listado; ponto incompleto (última importada < fim da janela) → bloqueio; idempotência (2ª execução cria 0); rejeitada não é recriada; dia coberto por previsto confirmado em M−1 → não sugere; coberto por `Pending` → não sugere; previsto rejeitado → sugere; não participante → ignorado; conflito ausência+batida listado; nenhuma escrita em `points` (contagem e `updated_at` inalterados).

**Ajustes** (`BenefitAdjustmentRegistrarTest`): ausência 1 dia; férias em intervalo com fim de semana e feriado (conta só dias úteis); previsto dentro de M (`Manual`) aceito; `Timesheet` fora da janela rejeitado; intervalo cruzando meses rejeitado; data posterior a M rejeitada; retroativo exige nota; `Manual` exige nota e aceita impactos livres por tipo; impactos padrão não editáveis; confirmar/rejeitar (nota obrigatória); reconsiderar; alterar `Pending` regenera impactos; substituir `Confirmed` → original `Rejected` + novo vinculado; DD2 (duas ausências mesmo dia) bloqueia; DD3 bloqueia; `Manual` no mesmo dia só alerta; mesmo dia em outra competência permitido; período `Closed` bloqueia qualquer operação; período `Calculated` volta a `Open`.

**Cálculo** (`BenefitPeriodCalculatorTest`): base; positivos; negativos; saldo zero; saldo negativo (final 0, gerado N); carry aplicado em M+1 elegível; carry não aplicado sem elegibilidade (VR/VD intactos); carry não vai para M+2; M−1 não fechada impede cálculo; sem benefício → tipo não calculado; VT multi-trecho; VR/VD valores e vigência em 01/M; `Pending`/`Rejected` ignorados; impactos de tipo não elegível ignorados; snapshot com trechos; sem N+1 (contagem de queries com `DB::enableQueryLog` para N funcionários).

**Configuração** (`BenefitConfigurationTest` + testes de tela): nova vigência VR em 01/11 não altera outubro; `valid_from` fora do dia 01 rejeitado; VD independente de VR; tarifa alterada em 15/10 → outubro usa a antiga; itinerário alterado em 15/10; elegibilidade encerrada em 15/10 → participa em outubro, não em novembro; vigências sobrepostas rejeitadas; preço usado em competência fechada não editável.

**Fechamento** (`BenefitPeriodWorkflowTest`): criar próxima (sequência); Open→Calculated; alteração em Calculated → Open; fechar com `Pending` → bloqueado; fechar com VT sem itinerário → bloqueado; fechar recalcula; snapshot imutável após mudança de tarifa/valor; reabrir exige motivo e grava log; reabrir com M+1 `Closed` bloqueado; reabrir com M+1 `Calculated` → M+1 volta a Open; alteração após fechamento bloqueada; excluir competência só a mais recente, Open, sem ajustes.

**Pendências de saldo** (`BenefitCarryForwardReportTest`): aguardando, aplicado, não aplicado.

**Telas** (um teste por componente): renderiza autenticado; ações principais chamam serviços e notificam; validações exibem mensagem.

**Regressão**: `EmployeeIndex` exclusão sem histórico; bloqueio com histórico; suíte existente completa verde (`HolidayExtraHoursTest`, `TimesheetPrintTest`, `EmployeeResumeReportSortingTest`, `HolidayCrudTest`).

**Golden Dataset**: §9.5.

### 10.3 Limitação dos testes

`lockForUpdate` é no-op no SQLite: concorrência não é provada pelos testes. A garantia vem de `UNIQUE(dedupe_key)`, UNIQUEs de snapshot e transações. Validar manualmente em MySQL antes da produção (§11 R-7).

---

## 11. Risks and Mitigations

| # | Risco | Tipo | Prob. | Impacto | Mitigação |
|---|---|---|---|---|---|
| R-1 | Feriados não cadastrados (07/09, 12/10, 02/11, 15/11, 20/11, 25/12…) | Negócio | Alta | Alto (valor pago) | Alerta `yearHasHolidays`; checklist de implantação; Golden Dataset depende disso. |
| R-2 | Mapeamento planilha → `Employee` incorreto na carga inicial | Negócio | Média | Alto | Carga pela tela de benefícios do funcionário (conferência visual), ou importador com PIS explícito (§12 F10). Conferência pelo total G-ALL. |
| R-3 | PIS divergente entre cadastro e ponto | Técnico | Baixa | Médio | Normalização; lista de PIS sem funcionário; bloqueio de PIS duplicado. |
| R-4 | Regressão em `EmployeeIndex` | Técnico | Baixa | Médio | Mudança mínima; testes de regressão novos (hoje inexistentes). |
| R-5 | Ordem de exclusão com self-FK RESTRICT no recálculo/reabertura | Técnico | Média | Médio | Workflow descarta M+1 antes de M; testes específicos. |
| R-6 | Float em algum ponto do cálculo | Técnico | Média | Alto | `Money` obrigatório; cast `decimal:2`; testes G10 (10,32) e G-ALL. |
| R-7 | Concorrência (duas abas gerando/fechando) | Técnico | Baixa (1 usuário) | Médio | Transações + `lockForUpdate` + UNIQUEs; teste manual em MySQL. |
| R-8 | Diferenças SQLite × MySQL (datas, decimal, FKs) | Técnico | Média | Médio | Colunas `date`/`decimal`; comparações por string `Y-m-d`; rodar a suíte ao menos uma vez contra MySQL local antes do deploy. |
| R-9 | Janela incompleta no momento do cálculo | Operacional | Alta | Médio | Operação descrita em §8.2; bloqueio visível. |
| R-10 | Pendências de saldo acumulam sem marcação de "tratado" | Operacional | Média | Baixo | Especificação não prevê marcação; relatório por competência de origem. Evolução futura se necessário. |
| R-11 | `recision_date = ''` gravado (34 registros locais) | Técnico | Certa | Baixo | Alerta trata `''` como vazio; módulo não filtra por rescisão (R18). |
| R-12 | Funcionário desligado continua participando se vigências não forem encerradas | Operacional | Média | Médio | Alerta informativo; mensagem de bloqueio de exclusão orienta encerrar vigências. |
| R-13 | Divergência Z (afastamento) e 10% VR da planilha | Negócio | — | Baixo/Médio | §14. |

---

## 12. Implementation Phases

Cada fase termina com: testes da fase verdes, suíte existente verde, `vendor/bin/pint --dirty --format agent`.

### F0 — Preparação de testes
- **Objetivo:** permitir testes do domínio sem helpers ad hoc.
- **Afetados:** `Employee` (+`HasFactory`), `EmployeeFactory`, `PointFactory`.
- **Dependências:** nenhuma.
- **Implementação:** factories; `Point.php` **não** é alterado (`PointFactory::new()`).
- **Testes:** `EmployeeFactoryTest`/smoke; regressão de `EmployeeIndex` (listar, excluir — hoje sem cobertura).
- **Conclusão:** factories criam registros válidos; suíte verde.

### F1 — Enums, Money, BusinessCalendar
- **Objetivo:** fundamentos sem banco novo.
- **Afetados:** `app/Enums/*`, `app/Services/Money.php`, `app/Services/BusinessCalendar.php`.
- **Dependências:** F0.
- **Testes:** `MoneyTest` (Unit), `BusinessCalendarTest`.
- **Conclusão:** contagens de set/out 2026 corretas com feriados de teste.

### F2 — Banco e models
- **Objetivo:** 12 tabelas, models, factories, relacionamentos de `Employee`.
- **Afetados:** 12 migrations, 12 models, 12 factories, `Employee` (relações + `hasBenefitHistory`).
- **Dependências:** F1 (enums nos casts).
- **Testes:** `BenefitSchemaTest`: FKs RESTRICT/CASCADE/SET NULL, UNIQUEs (dedupe, snapshot, carried_from), casts de enum/decimal/data.
- **Conclusão:** `migrate:fresh` limpo em SQLite **e** MySQL local; testes verdes.

### F3 — Configuração VR/VD e tarifas
- **Objetivo:** cadastros globais.
- **Afetados:** `BenefitRateIndex`, `TransportFareIndex`, `TransportFareEdit` (+ views), rotas, sidebar (grupo "Benefícios" com itens já existentes).
- **Dependências:** F2.
- **Testes:** validações (dia 01, 2 casas, UNIQUE), imutabilidade de preço/valor usado em competência fechada (testado após F9 — marcar TODO no teste ou adicionar em F9).
- **Conclusão:** telas funcionais e testadas.

### F4 — Elegibilidade e itinerários
- **Objetivo:** extensão do cadastro de funcionários.
- **Afetados:** `BenefitEligibility`, `TransportDailyAmount`, `EmployeeBenefitsEdit` (+ view), `employee-index.blade.php` (botão), `EmployeeIndex::destroy()` (guarda), rota `employees.benefits`.
- **Dependências:** F3.
- **Testes:** `BenefitEligibilityTest`, `TransportDailyAmountTest`, tela, guarda de exclusão.
- **Conclusão:** VT diário exibido corretamente para cenários G2, G4, G10.

### F5 — Competência (workflow básico)
- **Objetivo:** criar/listar/excluir competência; log de estados.
- **Afetados:** `BenefitPeriodWorkflow` (`createNext`, `delete`, `invalidate`), `BenefitPeriodIndex`.
- **Dependências:** F2.
- **Testes:** sequência de criação; exclusão restrita.
- **Conclusão:** competências criadas em ordem com log.

### F6 — Ajustes
- **Objetivo:** registrar, revisar, alterar ajustes.
- **Afetados:** `BenefitAdjustmentRegistrar`, `BenefitPeriodAdjustments` (+ view com modais).
- **Dependências:** F1, F5.
- **Testes:** `BenefitAdjustmentRegistrarTest`, tela.
- **Conclusão:** todos os casos §10.2 "Ajustes" verdes.

### F7 — Integração com ponto
- **Objetivo:** sugestões idempotentes + conflitos.
- **Afetados:** `TimesheetAdjustmentSuggester`; botão/painel em `BenefitPeriodAdjustments`.
- **Dependências:** F4 (elegibilidade), F6.
- **Testes:** `TimesheetAdjustmentSuggesterTest`.
- **Conclusão:** geração idempotente; nenhuma escrita em `points`.

### F8 — Cálculo
- **Objetivo:** snapshot completo, saldo, valores; tela de apuração (prévia).
- **Afetados:** `BenefitPeriodCalculator`, `BenefitPeriodWorkflow::calculate`, `BenefitPeriodCalculation` (+ view).
- **Dependências:** F4, F6.
- **Testes:** `BenefitPeriodCalculatorTest`; **Golden G1–G12**.
- **Conclusão:** G1–G12 verdes.

### F9 — Fechamento, reabertura, imutabilidade, pendências
- **Objetivo:** ciclo completo de estados e saldo.
- **Afetados:** `BenefitPeriodWorkflow::close/reopen`, guardas de imutabilidade em `BenefitRateIndex`/`TransportFareEdit`, `BenefitCarryForwardReport`, `BenefitCarryForwardIndex`.
- **Dependências:** F8.
- **Testes:** `BenefitPeriodWorkflowTest`, `BenefitCarryForwardReportTest`.
- **Conclusão:** todos os casos §10.2 "Fechamento" verdes.

### F10 — Golden Dataset completo e carga inicial
- **Objetivo:** G-ALL; preparar produção.
- **Afetados:** `GoldenDatasetSeptember2026Test` (G-ALL); `database/seeders/BenefitCatalogSeeder.php` (opcional: 20 tarifas com preços da planilha a partir de 2026-09-01; VR 27,50 e VD 7,50 a partir de 2026-09-01). Elegibilidade e itinerários pela tela `EmployeeBenefitsEdit` (42 linhas; conferência visual contra a planilha).
- **Dependências:** F9; feriados cadastrados (R-1).
- **Testes:** G-ALL (Σ 45.000,84).
- **Conclusão:** totais idênticos à planilha; checklist de implantação pronto.

### F11 — Acabamento
- **Objetivo:** navegação final, textos, Pint, suíte completa, rodada em MySQL.
- **Conclusão:** `php artisan test --compact` verde em SQLite e MySQL.

---

## 13. Detailed File-Level Plan

Legenda: **C** criar · **M** modificar · **R** apenas consultar/reutilizar. Caminhos verificados no projeto; arquivos novos seguem os diretórios existentes (ou aprovados: `app/Enums`, `app/Services`).

### F0
| Ação | Arquivo |
|---|---|
| C | `database/factories/EmployeeFactory.php` |
| C | `database/factories/PointFactory.php` |
| M | `app/Models/Employee.php` (trait `HasFactory`) |
| C | `tests/Feature/EmployeeIndexTest.php` (regressão: listagem, exclusão) |
| R | `app/Models/Point.php`, `app/Livewire/EmployeeIndex.php`, `tests/Pest.php` |

### F1
| Ação | Arquivo |
|---|---|
| C | `app/Enums/BenefitType.php`, `BenefitPeriodStatus.php`, `AdjustmentReason.php`, `AdjustmentSource.php`, `AdjustmentStatus.php`, `CalendarDayType.php`, `AdjustmentTiming.php` |
| C | `app/Services/Money.php`, `app/Services/BusinessCalendar.php` |
| C | `tests/Unit/MoneyTest.php`, `tests/Feature/Benefits/BusinessCalendarTest.php` |
| R | `app/Models/Holiday.php`, `database/factories/HolidayFactory.php` |

### F2
| Ação | Arquivo |
|---|---|
| C | `database/migrations/*_create_employee_benefits_table.php`, `*_create_benefit_rates_table.php`, `*_create_transport_fares_table.php`, `*_create_transport_fare_prices_table.php`, `*_create_transport_routes_table.php`, `*_create_benefit_periods_table.php`, `*_create_benefit_period_status_changes_table.php`, `*_create_benefit_adjustments_table.php`, `*_create_benefit_adjustment_impacts_table.php`, `*_create_benefit_period_employees_table.php`, `*_create_benefit_calculations_table.php`, `*_create_benefit_calculation_transport_items_table.php` |
| C | `app/Models/EmployeeBenefit.php`, `BenefitRate.php`, `TransportFare.php`, `TransportFarePrice.php`, `TransportRoute.php`, `BenefitPeriod.php`, `BenefitPeriodStatusChange.php`, `BenefitAdjustment.php`, `BenefitAdjustmentImpact.php`, `BenefitPeriodEmployee.php`, `BenefitCalculation.php`, `BenefitCalculationTransportItem.php` |
| C | `database/factories/` — uma factory por model acima |
| M | `app/Models/Employee.php` (relacionamentos + `hasBenefitHistory()`) |
| C | `tests/Feature/Benefits/BenefitSchemaTest.php` |

### F3
| Ação | Arquivo |
|---|---|
| C | `app/Livewire/Benefits/BenefitRateIndex.php`, `TransportFareIndex.php`, `TransportFareEdit.php` |
| C | `resources/views/livewire/benefits/benefit-rate-index.blade.php`, `transport-fare-index.blade.php`, `transport-fare-edit.blade.php` |
| M | `routes/web.php` (rotas `benefits.rates.*`, `benefits.fares.*` no grupo `auth`) |
| M | `resources/views/components/layouts/app/sidebar.blade.php` (grupo "Benefícios") |
| C | `tests/Feature/Benefits/BenefitRateIndexTest.php`, `TransportFareTest.php` |
| R | `app/Livewire/HolidayIndex.php`, `HolidayCreate.php` (padrão), `resources/views/livewire/holiday-*.blade.php` |

### F4
| Ação | Arquivo |
|---|---|
| C | `app/Services/BenefitEligibility.php`, `app/Services/TransportDailyAmount.php` |
| C | `app/Livewire/Benefits/EmployeeBenefitsEdit.php`, `resources/views/livewire/benefits/employee-benefits-edit.blade.php` |
| M | `app/Livewire/EmployeeIndex.php` (`destroy()` com guarda) |
| M | `resources/views/livewire/employee-index.blade.php` (botão "Benefícios") |
| M | `routes/web.php` (`employees.benefits`) |
| C | `tests/Feature/Benefits/BenefitEligibilityTest.php`, `TransportDailyAmountTest.php`, `EmployeeBenefitsEditTest.php` |
| M | `tests/Feature/EmployeeIndexTest.php` (bloqueio com histórico) |

### F5
| Ação | Arquivo |
|---|---|
| C | `app/Services/BenefitPeriodWorkflow.php` (criação, exclusão, invalidação, log) |
| C | `app/Livewire/Benefits/BenefitPeriodIndex.php`, `resources/views/livewire/benefits/benefit-period-index.blade.php` |
| M | `routes/web.php`, sidebar (item Competências) |
| C | `tests/Feature/Benefits/BenefitPeriodWorkflowTest.php` (parte 1) |

### F6
| Ação | Arquivo |
|---|---|
| C | `app/Services/BenefitAdjustmentRegistrar.php` |
| C | `app/Livewire/Benefits/BenefitPeriodAdjustments.php`, `resources/views/livewire/benefits/benefit-period-adjustments.blade.php` |
| M | `routes/web.php` |
| C | `tests/Feature/Benefits/BenefitAdjustmentRegistrarTest.php`, `BenefitPeriodAdjustmentsTest.php` |
| R | `app/Livewire/EmployeePointsEdit.php` + view (padrão de modal) |

### F7
| Ação | Arquivo |
|---|---|
| C | `app/Services/TimesheetAdjustmentSuggester.php` |
| M | `app/Livewire/Benefits/BenefitPeriodAdjustments.php` + view (geração, painel de conflitos) |
| C | `tests/Feature/Benefits/TimesheetAdjustmentSuggesterTest.php` |
| R | `app/Models/Point.php`, `app/Livewire/EmployeeIndex.php` (formato da importação) |

### F8
| Ação | Arquivo |
|---|---|
| C | `app/Services/BenefitPeriodCalculator.php` |
| M | `app/Services/BenefitPeriodWorkflow.php` (`calculate`) |
| C | `app/Livewire/Benefits/BenefitPeriodCalculation.php`, `resources/views/livewire/benefits/benefit-period-calculation.blade.php` |
| M | `routes/web.php` |
| C | `tests/Feature/Benefits/BenefitPeriodCalculatorTest.php`, `GoldenDatasetSeptember2026Test.php` (G1–G12) |

### F9
| Ação | Arquivo |
|---|---|
| M | `app/Services/BenefitPeriodWorkflow.php` (`close`, `reopen`) |
| C | `app/Services/BenefitCarryForwardReport.php` |
| C | `app/Livewire/Benefits/BenefitCarryForwardIndex.php`, `resources/views/livewire/benefits/benefit-carry-forward-index.blade.php` |
| M | `app/Livewire/Benefits/BenefitRateIndex.php`, `TransportFareEdit.php` (imutabilidade) |
| M | `app/Livewire/Benefits/BenefitPeriodCalculation.php` + view (fechar/reabrir) |
| M | `routes/web.php`, sidebar (Pendências de saldo) |
| C | `tests/Feature/Benefits/BenefitCarryForwardReportTest.php`; M `BenefitPeriodWorkflowTest.php` (parte 2) |

### F10
| Ação | Arquivo |
|---|---|
| M | `tests/Feature/Benefits/GoldenDatasetSeptember2026Test.php` (G-ALL) |
| C (opcional) | `database/seeders/BenefitCatalogSeeder.php` |
| R | Planilha (fora do repositório) |

### F11
| Ação | Arquivo |
|---|---|
| M | Ajustes finos nas views/rotas criadas |
| R | Suíte completa |

**Arquivos explicitamente não alterados:** `app/Models/Point.php`, `app/Models/Holiday.php`, `app/Models/ImportedLines.php`, `app/Livewire/EmployeeHorasExtras.php`, `EmployeePointsEdit.php`, `EmployeeResumeReport.php`, `EmployeesExtraReport.php`, `TimesheetPrint.php`, `app/Http/Controllers/TimesheetPrintController.php`, `app/Exports/*`, migrations existentes, `EmployeeCreate.php`, `EmployeeEdit.php`.

---

## 14. Open Issues

Somente questões descobertas na análise que afetam a implementação. Nenhuma reabre decisão da especificação 3.0.

| # | Questão | Origem | Impacto | Proposta |
|---|---|---|---|---|
| OI-1 | A planilha calcula "Desc Folha VR-10%" (coluna AC) e o recibo mostra "participação 10%" do VR. A especificação exclui 6% do VT e integração com folha, mas não menciona o 10% do VR. | Planilha §9.4 DV-3 | Não altera valores pagos; decide se a tela de apuração mostra uma coluna informativa. | Tratar como fora do escopo (analogia a R29). **Confirmar antes de F8.** |
| OI-2 | Coluna Z (Afastamento) da planilha desconta só VD; a especificação desconta os três. | Planilha §9.4 DV-1 | Sem efeito em set/2026. | Especificação prevalece (R9). Registrado para ciência; nenhuma ação. |
| OI-3 | Mapeamento dos 42 funcionários da planilha para `employees` (sem PIS na planilha; códigos inconsistentes; possíveis linhas fictícias). | Planilha §9.4 DV-7 | Bloqueia a carga inicial real (F10), não o desenvolvimento. | Carga via tela com conferência; administrador confirma quais linhas são reais. |
| OI-4 | Nomes repetidos no catálogo de tarifas ("CMT BOM", "INTEGRAÇÃO"). | Planilha §9.4 DV-6 | Bloqueia a carga por UNIQUE(`name`). | Administrador define nomes distintos na carga. |
| OI-5 | Feriados de 2026–2027 ausentes no sistema. | Banco local | Bloqueia Golden Dataset e primeira competência real. | Cadastro prévio pelo CRUD existente. |

---

## 15. Recommended Implementation Order

1. **F0** — `EmployeeFactory`, `PointFactory`, `HasFactory` em `Employee`, teste de regressão de `EmployeeIndex`.
2. **F1** — Enums, `Money`, `BusinessCalendar` + testes.
3. **F2** — 12 migrations, 12 models, factories, relações de `Employee`, `BenefitSchemaTest`; validar `migrate:fresh` em MySQL local.
4. **F3** — Valores VR/VD e tarifas (telas, rotas, sidebar).
5. **F4** — `BenefitEligibility`, `TransportDailyAmount`, tela de benefícios do funcionário, botão na lista, guarda de exclusão.
6. **F5** — `BenefitPeriodWorkflow` (criação/exclusão/log) e lista de competências.
7. **F6** — `BenefitAdjustmentRegistrar` e tela de ajustes.
8. **F7** — `TimesheetAdjustmentSuggester`, conflitos, operação de janela incompleta.
9. **F8** — `BenefitPeriodCalculator`, `calculate`, tela de apuração; Golden G1–G12. *(Confirmar OI-1 antes.)*
10. **F9** — Fechamento, reabertura, imutabilidade, relatório e tela de pendências de saldo.
11. **F10** — Golden G-ALL; cadastro de feriados; carga inicial (seeder opcional do catálogo + tela para funcionários); resolver OI-3/OI-4/OI-5.
12. **F11** — Acabamento, Pint, suíte completa em SQLite e MySQL.
