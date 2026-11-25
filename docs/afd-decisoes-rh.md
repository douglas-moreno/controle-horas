# AFD: decisões do RH sobre pendências históricas (F1: modelo e simulação; F2: gravação; F3: proteção do manual mantido; F4: recuperação por decisão; F5: revogação; F6: matriz do RH e relatório; F7: anotação de pontos importados)

## 1. Objetivo e escopo

Registrar, de forma auditável, a decisão do RH sobre cada pendência histórica do AFD (`afd_historical_nsrs`) que continua `pending`, sem alterar os lotes de correção já aplicados.

A **F1** entrega:
- o modelo de dados (histórico só por inclusão);
- a **simulação somente leitura** (`afd:decisions preview`): valida um manifesto de decisões e mostra a consequência prevista de cada uma.

A **F2** acrescenta a **gravação** (`afd:decisions apply`) só de `retain_manual`, `discard` e `defer`, nenhuma delas grava em `points` (seção 5a).

A **F4** acrescenta a gravação de `recover` para as classes que não são C2 (seção 5c).

A **F5** acrescenta a gravação de `revoke` (seção 5d).

A **F6** acrescenta a conversão da matriz do RH (CSV) em manifesto, o relatório da simulação para o RH e a geração do manifesto aprovado (seção 5e).

A **F7** acrescenta as anotações do RH sobre pontos **importados** (seção 5f), para os casos do NSR 172108 e dos NSRs 171492/171493.

> Nenhuma decisão é inferida pelo sistema, por exemplo por proximidade de horário. O sistema só valida e projeta; quem decide é o RH.

## 2. Modelo de dados

### `afd_historical_nsr_decisions` (append-only)

| Coluna | Conteúdo |
|---|---|
| `afd_historical_nsr_id` | Pendência (FK, restrict) |
| `afd_correction_batch_id` | Lote `decision` que aplicou (FK, restrict) |
| `decision` | `recover`, `retain_manual`, `discard`, `defer` ou `revoke` |
| `status_before` / `status_after` | Estado da pendência antes e depois |
| `related_manual_point_id` | Ponto manual mantido (`retain_manual`) |
| `recovered_point_id` | Ponto gravado (`recover`) |
| `decided_by`, `decided_role`, `decided_at` | Responsável do RH, cargo e data/hora da decisão |
| `justification` | Justificativa |
| `evidence_type`, `evidence_reference`, `evidence_sha256` | Evidência documental (ver seção 6) |
| `expected_consequence`, `expected_consequence_sha256` | Consequência calculada pela simulação e o hash correspondente. Na gravação (F2), o hash será recalculado para detectar mudança de estado entre a simulação e a gravação. |
| `acknowledged_warnings` | Avisos confirmados explicitamente |
| `review_due_on`, `review_notes` | Revisão prevista (`defer`) |
| `supersedes_decision_id` | Decisão que esta substitui (revisão ou revogação). É único: cada decisão é substituída no máximo uma vez. |
| `status` | `active` ou `superseded` |
| `active_key` | Coluna gerada e única: no máximo **uma decisão ativa por pendência** |

**Proteção no model** (`AfdHistoricalNsrDecision`):
- não é possível editar nem apagar uma decisão;
- a única mudança permitida é `active` → `superseded`.

Essa proteção vale para o Eloquent. Comandos SQL diretos não passam por ela (limitação conhecida).

### Outras alterações
- `afd_recovered_points.afd_historical_nsr_decision_id` (nulo, FK): liga uma recuperação futura à decisão que a autorizou. É nulo nas recuperações dos lotes de correção.
- `AfdCorrectionBatch::KIND_DECISION = 'decision'`.
- `afd_decision_approvals` (append-only) e `afd_correction_batches.afd_decision_approval_id` (nulo, FK única): aprovação autenticada do manifesto e o lote que ela autorizou (seção 5e).
- `AfdHistoricalNsr::decisions()` e `activeDecision()`.
- O registro principal (`afd_historical_nsrs`) continua sendo o estado atual que a cobertura de NSR (`AfdNsrCoverage`) lê. A F2 o atualizará a partir da decisão ativa.

## 3. Matriz de permissões

| Classe | `recover` | `retain_manual` | `discard` | `defer` |
|---|---|---|---|---|
| C1, C3 | sim | não (não há manual) | sim | sim |
| E1, E2a, E2b, E2c | sim | sim | sim | sim |
| X | sim | não | sim | sim |
| C2 | fora do fluxo (lote de recuperação) | | | |
| R | fora do fluxo (retenção) | | | |

Além da matriz, valem as regras abaixo:
- **`recover`**:
  - sem ponto com o mesmo REP e NSR;
  - não comprovada pelo legado;
  - **sem ponto com o mesmo PIS, data e minuto** (por isso E1, com manual no mesmo minuto, não pode ser recuperado enquanto o manual existir);
  - com **um único funcionário cadastrado** para o PIS (X não pode ser recuperado sem cadastro).
- **`retain_manual`**: o ponto informado existe, é `manual`, tem o mesmo PIS e o mesmo dia, e não está vinculado a outra pendência. Um ponto manual diferente do indicado na auditoria gera o aviso `manual_differs_from_audit`.
- **Estado**: `recover`, `retain_manual`, `discard` e `defer` só valem para `pending` sem decisão ativa ou com `defer` ou `revoke` ativo. `revoke` só vale para uma decisão ativa deste fluxo (`recover`, `retain_manual` ou `discard`) cujo estado confere. **As recuperações dos lotes de correção (C2) não são revogadas aqui**: só por `afd:correction revert`.
- **Dados obrigatórios**: responsável, data (não futura) e justificativa. A evidência (tipo e referência) é obrigatória para `discard` e para `recover` de C1, C3 e X. A data de revisão só vale para `defer` e não pode ser anterior à decisão.
- **Duplicidade**: o mesmo NSR ou o mesmo ponto manual repetido no manifesto recusa as linhas envolvidas.

## 4. Manifesto
```json
{
  "kind": "decision",
  "afd_sha256": "<SHA-256 do AFD>",
  "rep_serial": "<REP>",
  "expected_count": 1,
  "rows": [{
    "nsr": 0, "afd_line": 0, "afd_line_sha256": "<SHA-256 da linha>",
    "decision": "recover | retain_manual | discard | defer | revoke",
    "decided_by": "<responsável do RH>", "decided_role": "<cargo>", "decided_at": "<ISO 8601>",
    "justification": "<texto>",
    "evidence_type": "<tipo>", "evidence_reference": "<referência>", "evidence_sha256": "<hash>",
    "related_manual_point_id": 0,
    "review_due_on": "<AAAA-MM-DD>", "review_notes": "<texto>",
    "acknowledged_warnings": ["competence_calculated"]
  }]
}
```

## 5. Simulação
```
php artisan afd:decisions preview --manifest=<json> --afd=<AFD>
```

**Execução**:
- roda inteira sob `AfdPrecheck::whileReadOnly()`, com a sessão MySQL ou MariaDB em modo somente leitura e bloqueio de qualquer comando que não seja SELECT;
- é recusada dentro de uma transação aberta;
- o relatório traz `writes: none` e não contém PIS.

**Saída por linha**:
- erros e avisos;
- avisos ainda não confirmados;
- estado atual e estado resultante;
- consequência prevista, com o hash dela:
  - espelho do dia antes e depois (`TimesheetMirror::build` com ou sem a marcação);
  - competência de benefício cuja janela contém o dia, e a participação do funcionário;
  - ajustes de benefício no dia;
  - conflitos atuais (`TimesheetAdjustmentSuggester::conflicts`);
  - conflitos projetados.

**Avisos que exigem confirmação** (só em decisões que mudam pontos, mais `manual_differs_from_audit`):
- `competence_closed`
- `competence_calculated`
- `new_conflict`
- `day_becomes_odd`
- `day_becomes_excess`
- `employee_rescinded`
- `manual_differs_from_audit`

**Resultado geral e código de saída**:

| Resultado | Quando | Código de saída |
|---|---|---|
| `valid` | sem erro e com todos os avisos confirmados | 0 |
| `needs_acknowledgement` | sem erro, mas com aviso não confirmado | 1 |
| `refused` | alguma linha com erro | 1 |

**Projeção de conflitos**: é simplificada. Uma ausência não rejeitada num dia que fica com batida gera `absence_with_punch` em dia útil, ou `work_on_absence` nos demais. Não substitui a sonda completa de conflitos, que continua obrigatória depois de qualquer gravação.

## 5a. Gravação (F2)
```
php artisan afd:decisions apply --manifest=<json> --afd=<AFD> --operator="<nome>" \
  --expect-host=… --expect-server-uuid=… --expect-database=… --no-interaction
```

**Pré-requisitos:**
- só aceita `recover` (desde a F4), `retain_manual`, `discard` e `defer`; `revoke` no manifesto recusa o lote inteiro;
- cada linha precisa trazer `expected_consequence_sha256`, com o valor de `consequence_sha256` da simulação aprovada pelo RH;
- exige o responsável (`--operator`) e a identidade do banco, e recusa produção (`AfdHistoricalCorrection::assertTarget`). A autorização `--production` existe só em `afd:correction`; `afd:decisions` continua recusando produção.

**Execução numa transação única:**
1. Trava as pendências e os pontos manuais do manifesto.
2. Reavalia tudo como na simulação. Grava só se o resultado for `valid` (sem erro e com todos os avisos confirmados) e se cada hash de consequência continuar igual. Hash diferente significa que o estado mudou desde a simulação, e o lote é recusado.
3. Cria o lote `decision` (`afd_correction_batches`, com SHA-256 do manifesto e do AFD, identidade do banco e `applied_by`).
4. Cria uma decisão `active` por linha. Se havia um `defer` ativo, ele passa a `superseded` e a nova decisão o referencia em `supersedes_decision_id`.
5. Atualiza o estado atual da pendência:
   - `retain_manual` → `manual_retained`, com `related_manual_point_id` e `decided_*`;
   - `discard` → `discarded_by_approval`, com `decided_*`;
   - `defer` → continua `pending`, sem alteração no registro.
6. Confere que a quantidade de decisões bate com o manifesto e que `points` não mudou (contagem e maior id).

**Depois da gravação:**
- repetir o mesmo manifesto devolve `already_applied`, sem gravar nada;
- qualquer falha desfaz tudo;
- os lotes de correção existentes não são alterados;
- a cobertura de NSR (`AfdNsrCoverage`) aceita os estados gravados, e o precheck continua sem inconsistência.

**Hash da consequência:** é o SHA-256 do JSON canônico, com as chaves ordenadas recursivamente (`AfdHistoricalDecisions::consequenceHash`). Assim ele pode ser reproduzido a partir de `expected_consequence` gravado, cujas chaves o MySQL reordena.

## 5c. Recuperação por decisão (F4)

A `recover` usa o mesmo `apply` e a mesma transação das demais decisões. Para cada linha:
1. Reconfere a linha do AFD da pendência (NSR e SHA-256).
2. Grava a marcação em `points` como `importado`, com REP e NSR e `afd_import_id` nulo. O índice único de REP + NSR recusa uma gravação concorrente.
3. Cria a decisão com `recovered_point_id`.
4. Grava o vínculo em `afd_recovered_points`: lote `decision`, `afd_historical_nsr_decision_id`, linha, SHA-256 da linha e hash canônico do ponto (`AfdHistoricalCorrection::pointHash`).
5. Atualiza a pendência para `recovered`, com `recovered_point_id` e `decided_*`.

Ao final:
- `points` ganhou exatamente as marcações recuperadas;
- os demais pontos não mudaram (impressão digital `AfdHistoricalCorrection::pointsFingerprint`);
- a cobertura passa a contar o NSR como `recovered`.

Continuam valendo as regras da simulação:
- sem ponto com o mesmo REP e NSR;
- sem ponto com o mesmo PIS, data e minuto (E1 com o manual no mesmo minuto é recusado);
- um único funcionário com o PIS (X sem cadastro é recusado);
- evidência obrigatória para C1, C3 e X;
- avisos confirmados.

**Benefícios:** a recuperação **não altera ajustes de benefício nem competências**. O conflito projetado (por exemplo `absence_with_punch` com uma falta pendente) passa a aparecer em `conflicts()` e precisa ser decidido no módulo de benefícios. Um teste confere que o conflito projetado é o mesmo encontrado depois da recuperação.

**Reversão:** por `revoke` (seção 5d). `afd:correction revert` só desfaz os lotes de correção (`recovery`), não os lotes `decision`.

## 5d. Revogação (F5)

A `revoke` usa o mesmo `apply`, a mesma transação e o mesmo manifesto das demais decisões (pode ser misturada a elas). Exige responsável, data e justificativa; a evidência é opcional. A data não pode ser anterior à da decisão revogada.

Só é aceita para uma decisão **ativa** deste fluxo: `recover`, `retain_manual` ou `discard`, com o estado da pendência igual ao que ela produziu. Não há o que revogar em `defer`, nem em outra `revoke`. As C2 do lote 3 continuam só com `afd:correction revert`.

**Efeito, para qualquer decisão revogada:**
- a decisão revogada passa a `superseded`; a `revoke` é gravada como ativa, com `supersedes_decision_id`. Nada é apagado do histórico;
- a pendência volta a `pending`, com `recovered_point_id` e `decided_*` nulos e com o `related_manual_point_id` da auditoria (o valor guardado em `audit_related_manual_point_id` na consequência da decisão revogada);
- a pendência fica aberta para uma nova decisão, que substitui a `revoke`.

**Revogação de `retain_manual` ou `discard`:** nada muda em `points`. O ponto manual deixa de ser protegido (seção 5b).

**Revogação de `recover`:** só é aceita se, ao mesmo tempo:
- o ponto recuperado existe e está inalterado (hash igual ao gravado em `afd_recovered_points`);
- o dia não está em competência de benefício `closed`;
- não há ajuste de benefício `confirmed` do funcionário no dia.

Ela apaga **só** aquele ponto e marca o vínculo em `afd_recovered_points` como `reverted` (`reverted_by` = operador, `reverted_at`). Não depende de ser o último lote. Ao final, `points` perdeu só os pontos das recuperações revogadas e ganhou só as recuperações do manifesto; os demais não mudaram (impressão digital).

**Benefícios:** a revogação não altera ajustes nem competências. Os avisos (competência calculada, dia ímpar etc.) continuam exigindo confirmação.

**Consequência:** inclui `revoked_decision_id`. Se a decisão ativa mudar entre a simulação e a gravação (por exemplo, revogada e decidida de novo), o hash muda e o lote é recusado.

**Manifesto repetido:** um manifesto já aplicado devolve `already_applied` mesmo depois de revogado. Para decidir de novo, o RH faz uma nova decisão (com nova data), e isso gera outro manifesto.

## 5e. Matriz do RH, relatório e aprovação (F6)

Fluxo completo, sempre com arquivos novos (nenhuma saída sobrescreve um arquivo existente; todos ficam com permissão 600):

```
afd:decisions convert --matrix=matriz.csv --afd=AFD.txt --output=manifesto.json [--classes=E2a --employees=154 --timezone=America/Sao_Paulo]
afd:decisions preview --manifest=manifesto.json --afd=AFD.txt --output=simulacao.json --report=relatorio.md
  (o RH lê relatorio.md; se faltar confirmar avisos ou corrigir algo, corrige a matriz e repete convert + preview)
afd:decisions approve --manifest=manifesto.json --preview=simulacao.json --output=aprovado.json --approver=<e-mail do aprovador> --expect-host=… --expect-server-uuid=… --expect-database=…
afd:decisions apply --manifest=aprovado.json --afd=AFD.txt --operator=… --expect-host=… --expect-server-uuid=… --expect-database=…
```

**`convert`** (não acessa o banco):
- Lê CSV com separador `;`, `,` ou tabulação, UTF-8 com ou sem BOM, com campos entre aspas (inclusive com quebra de linha). O cabeçalho é comparado em minúsculas; colunas extras (as informativas da matriz original) são ignoradas.
- Colunas obrigatórias: `nsr`, `linha_afd`, `decisao`. Colunas de decisão: `responsavel`, `cargo`, `justificativa`, `data_decisao`, `evidencia_tipo`, `evidencia_referencia`, `evidencia_sha256`, `ponto_manual_mantido`, `revisao_em`, `revisao_notas`, `avisos_confirmados`. Para os filtros: `classe` e `funcionario_id`. A matriz original tem só `decisao`, `responsavel`, `justificativa` e `data_decisao`; as demais podem ser acrescentadas.
- `decisao` aceita (sem diferença de maiúsculas, acentos ou texto entre parênteses): `recuperar`/`recuperar AFD`, `manter manual`, `descartar`/`descartar AFD`, `manter pendente`/`adiar`, `revogar`, ou os códigos (`recover` etc.).
- `data_decisao`: `dd/mm/aaaa hh:mm[:ss]`, `aaaa-mm-dd hh:mm[:ss]` ou só a data (vale 00:00), no fuso `--timezone` (padrão `America/Sao_Paulo`), ou ISO 8601 com fuso explícito. `revisao_em`: `dd/mm/aaaa` ou `aaaa-mm-dd`.
- `ponto_manual_mantido`: id do ponto (com ou sem `#`). `avisos_confirmados`: códigos separados por vírgula, ponto e vírgula, barra vertical ou espaço.
- Gera, por linha, o SHA-256 da linha do AFD e a referência da matriz (`matrix_ref`: coluna `id` e número do registro no CSV); no manifesto, o SHA-256 da matriz, o fuso e os filtros.
- **Nada é deduzido:** só entram as linhas com `decisao` preenchida; responsável, data, justificativa, evidência, ponto manual e avisos confirmados vêm só da matriz. Em particular, `manter manual` sem `ponto_manual_mantido` não usa o ponto relacionado pela auditoria (a simulação recusa).
- Problemas estruturais recusam a matriz inteira, sem gerar manifesto: decisão não reconhecida, NSR ou linha inválidos, linha do AFD de outro NSR, datas inválidas, ponto manual não numérico, aviso desconhecido, NSR repetido, coluna obrigatória ausente, coluna repetida, quantidade de colunas diferente do cabeçalho, nenhuma linha com decisão. As regras de negócio (matriz de permissões, estado, evidência etc.) ficam com a simulação.

**`preview --report`:** relatório em Markdown, sem PIS (funcionário pelo id interno), com: resultado geral; contagens; por linha, a referência da matriz, a classe e a situação atual, a decisão e os dados do RH, as recusas, a consequência (efeito nos pontos, espelho do dia antes e depois, competência e participação, ajustes e conflitos antes e depois), cada aviso com o texto explicativo e se foi confirmado, e o hash da consequência. `--output` grava a simulação em JSON.

**`approve`**: autentica o aprovador — `--approver` é o e-mail de um usuário do sistema, e a senha dele é pedida sem eco (não funciona com `--no-interaction`; e-mail ou senha errados recusam sem gravar nada). Exige que a simulação salva seja do mesmo manifesto (SHA-256), do mesmo AFD e REP, com o mesmo número e ordem de NSR, e com resultado `valid` (sem recusa e com todos os avisos confirmados). Copia o `consequence_sha256` de cada linha para `expected_consequence_sha256` e registra `approved_from` (SHA-256 do manifesto e da simulação). `approved_from` também leva o aprovador (`approved_by`: id, nome e e-mail) e `approved_at`. Depois de conferir a identidade do banco, grava a aprovação em `afd_decision_approvals` (append-only: usuário, nome e e-mail copiados, SHA-256 do manifesto aprovado, do manifesto de origem e da simulação, número de linhas, identidade do banco e momento) e só então gera o arquivo. Um manifesto que já traz consequência aprovada é recusado. O `apply` só grava um manifesto cujo SHA-256 exato tenha aprovação registrada e ainda não usada; o lote guarda `afd_decision_approval_id`. Editar o arquivo aprovado (inclusive o aprovador) muda o SHA-256 e faz o `apply` recusar. Se o estado mudar entre a simulação e a gravação, o `apply` recusa (seção 5a).

## 5f. Anotações sobre pontos importados (F7)

Tabela `afd_point_decisions` (append-only, mesmo padrão de `afd_historical_nsr_decisions`), lote `point_annotation`, comando `afd:point-annotations {preview|apply}`. É **só registro**: não altera `points` nem os cálculos.

| Anotação | Uso | Dados próprios |
|---|---|---|
| `disregard` | O RH decidiu desconsiderar a marcação administrativamente (ex.: NSR 172108) | evidência documental obrigatória (tipo e referência) |
| `duplicate` | Batida duplicada de outro ponto (ex.: NSR 171493 duplicata do 171492) | `duplicate_of_point_id`: mesmo funcionário, data e minuto |
| `revoke` | Revoga a anotação ativa (volta o ponto a sem anotação) | data não anterior à da anotação revogada |

**Manifesto** (`kind: point_annotation`, `afd_sha256`, `rep_serial`, `expected_count`), uma linha por ponto: `nsr`, `afd_line`, `afd_line_sha256`, `point_id`, `decision`, `duplicate_of_point_id` (só `duplicate`), `decided_by`, `decided_role`, `decided_at`, `justification`, `evidence_type`, `evidence_reference`, `evidence_sha256`; no `apply`, `expected_point_hash` (hash do ponto mostrado pela simulação aprovada).

**Validação:** a linha do AFD (SHA-256, tipo 3, NSR) e o ponto gravado com o mesmo REP e NSR e com o mesmo funcionário, data e horário da linha. NSR que é pendência histórica é recusado (vai por `afd:decisions`). No máximo uma anotação ativa por ponto. Em `duplicate`, sem cadeias: o original não pode ser duplicata anotada e o ponto anotado não pode ser original de outra duplicata. Responsável, data (não futura) e justificativa obrigatórios.

**Gravação:** uma transação; trava os pontos, reavalia, confere `expected_point_hash`, grava a anotação com o hash canônico do ponto (e do original, em `duplicate`), passa a anterior a `superseded` na revogação e confere que `points` não mudou. Repetir o manifesto devolve `already_applied`.

**Efeito (decisão de 2026-10-25: só registro):**
- **Cálculos:** nenhum. O ponto continua contando no espelho, na impressão, nas horas extras e nos benefícios. O dia 02/10 do funcionário 64 continua com 3 batidas e "a revisar"; o dia 20/10 do funcionário 140 também.
- **Marcador:** o espelho (`hr_annotations` do dia: "16:30 Duplicada (RH)"), a impressão e a tela de pontos mostram a anotação.
- **Proteção:** o ponto anotado e o original de uma duplicada não podem ser excluídos nem ter funcionário, data, horário, tipo, REP ou NSR alterados enquanto a anotação estiver ativa (`Point::ANNOTATED_BY_HR_MESSAGE`). Só na aplicação: não há chave estrangeira para `points`, para que o histórico não impeça a exclusão depois da revogação.
- **Precheck:** aviso com a quantidade de pontos anotados por tipo.
- **Sem a migration:** espelho, tela de pontos, proteção e precheck conferem se a tabela existe (`AfdPointDecision::isAvailable`) e funcionam como antes.

**Regra futura para a batida duplicada (definida, não implementada):** se o RH decidir que a anotação `duplicate` deve ter efeito, a duplicata deixaria de contar em todo consumidor de `points` (TimesheetMirror e a distribuição das colunas, impressão, relatórios de horas extras e de resumo, comparação da regra alternativa e TimesheetAdjustmentSuggester), sempre a partir da anotação ativa, sem apagar o ponto. Exige autorização própria, porque muda espelho, horas extras e benefícios de competências possivelmente calculadas.

## 5b. Proteção do ponto manual mantido (F3)

Uma batida manual referenciada por uma pendência em `manual_retained` (`related_manual_point_id`) fica protegida:
- **No model (`Point`):** `delete()` e qualquer alteração de `pis`, `date`, `time` ou `type` lançam `DomainException` (`Point::RETAINED_BY_HR_MESSAGE`). Atualizar só os timestamps (`touch`) continua permitido.
- **Na tela (`EmployeePointsEdit`):**
  - o botão de excluir não aparece; no lugar dele aparece "Mantido pelo RH";
  - `removePoint` recusa com aviso, mesmo quando chamado diretamente.
- **Precheck:** mostra o aviso "N ponto(s) manual(is) mantido(s) por decisão do RH…".
- **Sem proteção:**
  - pontos `importado`;
  - manuais citados só pela auditoria (pendência `pending`) ou por pendência descartada;
  - manuais cuja pendência voltou a `pending` (revogação, F5).
- **Limitação:** a proteção é da aplicação. Uma exclusão direta pelo query builder ou por SQL não passa por ela, e nesse caso a cobertura acusa "manutenção de ponto manual inexistente" e bloqueia a importação. Isso está demonstrado em teste.

## 6. Evidência documental: decisão pendente

Na F1 só existem os campos `evidence_type`, `evidence_reference` e `evidence_sha256`. **Não há upload nem armazenamento de documentos**, e nenhuma evidência do RH foi presumida.

A proposta inicial abaixo **ainda precisa ser confirmada** antes da fase que implementar o armazenamento:
- formatos aceitos: PDF e imagens (JPEG/PNG);
- guarda em armazenamento privado (disco `local`, `storage/app/private`), sem rota pública;
- SHA-256 do arquivo gravado na decisão;
- acesso restrito a quem administra o RH;
- prazo de retenção a definir.

## 7. Limitações e riscos
- **Proteção append-only:** só no Eloquent. Um `UPDATE` ou `DELETE` direto no banco não é impedido; o banco só garante as FKs e a unicidade.
- **Revogação de `recover`:** recusada com competência fechada ou ajuste confirmado no dia; nesses casos, a decisão é do módulo de benefícios, e não há caminho por este fluxo. O ponto é apagado sem trava das demais batidas do dia; rode com a manutenção ativa ou sem uso concorrente da tela de pontos.
- **Ajuste dependente do dia:** a regra considera só ajustes `confirmed` do funcionário cujo intervalo contém o dia. Não há vínculo explícito entre ajuste e batida.
- **Concorrência na recuperação:** a regra de mesmo PIS, data e minuto é conferida dentro da transação, sem trava das batidas do dia. O NSR é protegido pelo índice único. Para garantir o resultado, rode com a manutenção ativa ou sem uso concorrente da tela de pontos.
- **Ponto manual mantido:** protegido na aplicação desde a F3 (seção 5b). Uma exclusão direta no banco não é impedida.
- **Aprovação do relatório pelo RH:** registrada em `afd_decision_approvals` com o usuário autenticado no `approve`, ligada ao lote gravado. O sistema não tem papéis: qualquer usuário cadastrado pode aprovar, e o aprovador digita a senha no terminal do operador. Guarde o relatório aprovado junto com a evidência. Editar a simulação salva não permite gravar nada diferente: o `apply` recalcula tudo e compara.
- **Relatório:** Markdown (texto). Não há PDF nem planilha de saída.
- **Lotes antigos:** os lotes 1 a 3 e as 146 linhas do lote 1 não são alterados por esta fase.

## 8. Próximas fases (cada uma com autorização própria)
- **F2:** gravação de `retain_manual`, `discard` e `defer`.
- **F3:** proteção dos manuais vinculados.
- **F4:** `recover` por decisão.
- **F5:** `revoke` (feita).
- **F6:** matriz do RH → manifesto, relatório e aprovação (feita).
- **F7:** anotação de pontos importados (NSR 172108, 171492/171493), só registro (feita).
- **F8:** tela para o RH.
