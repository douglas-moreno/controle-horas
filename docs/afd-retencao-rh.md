# AFD — Retenção de marcação por decisão do RH (classificação R)

## 1. Objetivo

Permitir importar um AFD sem gravar em `points` uma marcação específica cuja legitimidade o RH não confirmou. O AFD original fica intacto, o cursor do relógio avança normalmente e a marcação permanece rastreável. Ela nunca vira lacuna e nunca é importada por engano.

A retenção registra uma **decisão formal do RH**. Não é uma rejeição técnica do registro: uma rejeição (`afd_import_rejections`) significa registro inválido no arquivo.

> A retenção **não confirma nem nega a legitimidade** da marcação e **não é conclusão sobre fraude ou erro** do funcionário. Ela apenas impede a gravação até uma decisão final.

## 2. Conceitos

| Item | Valor |
|---|---|
| Classificação | `R`: marcação retida por decisão do RH (`AfdHistoricalNsr::CLASSIFICATION_WITHHELD`). Fica fora de `CLASSIFICATIONS`, então a ação `register` não a aceita. |
| Estado | `withheld` (`AfdHistoricalNsr::STATUS_WITHHELD`) |
| Tabela | `afd_historical_nsrs`, a mesma das pendências históricas. Tem índice único `(rep_serial, nsr)`. |
| Lote | `afd_correction_batches.kind = withholding` (`AfdCorrectionBatch::KIND_WITHHOLDING`), com manifesto, SHA-256 do manifesto e do AFD, identidade do banco e `applied_by`. |
| Decisão formal | `decided_by` (responsável), `decision_justification`, `decided_at` e `evidence` (referência ao documento do RH). Todos são obrigatórios. |
| Importação | `afd_imports.withheld` conta as marcações retidas. `afd_imports.operator` guarda o responsável do comando `afd:import`. |

## 3. Fluxo

1. **Manifesto** (`kind: withholding`). É um arquivo próprio: não altera o AFD nem os manifestos já aplicados.
   ```json
   {
     "kind": "withholding",
     "afd_sha256": "<SHA-256 do AFD>",
     "rep_serial": "<REP>",
     "expected_count": 1,
     "rows": [{
       "nsr": 0, "afd_line": 0, "afd_line_sha256": "<SHA-256 da linha sem terminador>",
       "classification": "R",
       "evidence": "<documento do RH>", "decided_by": "<responsável>",
       "decision_justification": "<justificativa>", "decided_at": "<data ISO 8601>"
     }]
   }
   ```
2. **Simulação**, em sessão somente leitura:
   ```
   php artisan afd:correction withhold --manifest=<json> --afd=<AFD> --expect-host=… --expect-server-uuid=… --expect-database=… --no-interaction
   ```
3. **Registro**: o mesmo comando com `--apply --operator="<nome>"`. Roda numa transação única; repetir o comando devolve `already_applied`.
4. **Pré-validação** com `afd:precheck <AFD> --json`. O resultado esperado:
   - `records.withheld` > 0 e `records.withheld_nsrs`;
   - `records.new` sem as retidas;
   - `coverage.withheld_ahead`;
   - aviso "retida(s) por decisão do RH … não seriam gravadas".
5. **Importação supervisionada**:
   ```
   php artisan afd:import <AFD> --operator="<nome>" --expect-host=… --expect-server-uuid=… --expect-database=… --no-interaction
   ```
   O resultado traz `withheld` e `withheld_nsrs`. O cursor (`afd_reps.processed_to_nsr`) avança até o maior NSR do arquivo.
6. **Pré-validação posterior**: `coverage.counts.withheld` > 0, `coverage.nsrs.withheld` e o aviso "retida(s) … NÃO importada(s): aguardam decisão final do RH". A marcação não aparece como `gap`.

## 4. Validações

### Ação `withhold`

O manifesto é recusado por inteiro, sem gravar nada, se:
- o manifesto for de outro tipo, tiver `expected_count` divergente ou o SHA-256 do AFD for diferente;
- o REP for diferente do cabeçalho;
- houver NSR repetido;
- o AFD falhar na validação estrutural do importador (`AfdImporter::scan`): NSR contínuo, trailer e contadores;
- a linha do AFD estiver ausente, tiver SHA-256 diferente, não for marcação (tipo 3), tiver NSR diferente ou for marcação inválida;
- a classificação for diferente de `R`;
- faltar `evidence`, `decided_by`, `decision_justification` ou `decided_at`, ou a data for inválida ou futura;
- o NSR estiver até o cursor atual do REP (`AfdImporter::startFor`). Esse caso é pendência histórica (`register`), não retenção;
- o NSR já estiver gravado em `points` (REP + NSR), já registrado em `afd_historical_nsrs` ou já rejeitado.

Com `--apply` também são exigidos o responsável e a identidade do banco (hostname, server_uuid e nome). Em produção, como as demais ações de correção, a ação é recusada.

### Importação (`AfdImporter::persist`)

A importação roda numa transação única, com a linha do REP travada:
- **Cobertura:** `AfdNsrCoverage` confere as retenções acima do cursor. Se a decisão não estiver documentada ou se a linha, o NSR, o tipo ou o SHA-256 forem diferentes do arquivo, há inconsistência e a importação é recusada.
- **Leitura das retenções:** são lidas com `lockForUpdate` e conferidas de novo, linha a linha (`AfdNsrCoverage::withheldProblem`). Divergência recusa o arquivo inteiro.
- **Marcação retida:** não é inserida nem rejeitada. Só soma em `withheld`.
- **Separação dos contadores:** importados, existentes, rejeitados e retidos são disjuntos. A soma dá o total de marcações acima do cursor.
- **Falhas:** qualquer falha desfaz tudo: pontos, importação, rejeições e cursor. O índice único `points(rep_serial, nsr)` e a consulta prévia de duplicidade não mudam.

## 5. Efeito no cursor e em importações futuras

- O cursor avança normalmente sobre a marcação retida.
- Em qualquer importação posterior (o mesmo AFD ou um AFD maior), o NSR fica abaixo do cursor e é contado em `already_processed`, sem nunca ser gravado.
- A cobertura passa a classificá-lo como `withheld`, e não como `gap`. Por isso ele não bloqueia as próximas importações e continua visível como não resolvido.
- Um registro `withheld` com ponto gravado para o mesmo REP e NSR, ou sem decisão documentada, é inconsistência e bloqueia.

## 6. Auditoria

Consultas somente de leitura:
```sql
SELECT * FROM afd_correction_batches WHERE kind = 'withholding';
SELECT nsr, afd_line, afd_line_sha256, classification, status, evidence, decided_by, decision_justification, decided_at
  FROM afd_historical_nsrs WHERE status = 'withheld';
SELECT id, operator, cursor_before, cursor_after, imported, existing, rejected, withheld FROM afd_imports ORDER BY id;
SELECT COUNT(*) FROM points WHERE rep_serial = '<REP>' AND nsr = <NSR>;  -- deve ser 0
```

Conferências:
- O SHA-256 da linha do AFD (sem terminador) é igual a `afd_line_sha256`.
- O `afd:precheck` mostra o NSR em `coverage.nsrs.withheld`, com 0 inconsistências.

## 7. Decisões de arquitetura

- **Tabela:** reutiliza `afd_historical_nsrs` com estado próprio, em vez de criar uma tabela nova. Assim a cobertura de NSR, a unicidade `(rep_serial, nsr)` e a conferência de linha e SHA-256 continuam num lugar só, e o NSR retido é coberto depois que o cursor o ultrapassa.
- **Ação própria (`withhold`):** é a única que cria `R`. O `register` continua restrito às classificações históricas e a NSR até o cursor.
- **Colunas novas em `afd_imports`:** `withheld` e `operator`, adicionadas por migration aditiva com default. Nenhum lote histórico é alterado.
- **Comando `afd:import`:** usa o mesmo `AfdImporter::import` da tela de funcionários e permite a importação supervisionada durante a janela de manutenção.
  - Exige o responsável e a identidade do banco.
  - Não recusa produção: importar é uma operação normal do sistema.
  - Não tem simulação própria; a simulação é o `afd:precheck`.
- **Rescisão:** o precheck deixa de contar o funcionário rescindido quando a marcação dele está retida, porque ela não seria gravada.

## 8. Limitações

- **Decisão final:** ainda não existe ação para registrar a decisão final do RH sobre uma marcação retida (descartar ou validar e importar). `R` não é recuperável automaticamente, e qualquer tratamento posterior exige uma alteração própria. Os campos `decided_*` guardam a decisão de retenção.
- **Reversão:** não existe reversão do lote `withholding`. Antes da importação, desfazer a retenção exige SQL manual autorizado ou a restauração de um backup.
- **REP ainda inexistente:** quando o REP ainda não existe em `afd_reps`, o registro da retenção não tem linha a travar. Faça o registro com a aplicação em manutenção. A importação reconfere tudo sob a trava do REP.
- **Duplicidade:** o docblock do `AfdImporter` diz que `afd_import_rejections` também é consultada antes da inserção de pontos, mas `insertPoints` consulta só `points`. Esse comportamento é anterior a esta alteração e não mudou.
