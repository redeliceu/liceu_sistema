# API Financeira Liceu — v1

API somente leitura para consulta dos planos financeiros usados pelo Controle de Visitas.

## Endpoint

`GET /api/v1/financeiro/`

Opcionalmente, consulte um plano específico:

`GET /api/v1/financeiro/?id=1`

## Autenticação

Envie o token no header HTTP:

`Authorization: Bearer SEU_TOKEN`

As credenciais destinadas ao TI estão documentadas em `INTEGRACAO-TI/`. A API guarda somente o SHA-256 do token em `private-config.php`.

## Resposta

```json
{
  "ok": true,
  "apiVersion": "1.0",
  "resource": "planos_financeiros",
  "atualizadoEm": "2026-08-29T20:00:00-03:00",
  "total": 3,
  "planos": [
    {
      "id": 1,
      "nome": "Plano 1",
      "taxaMatricula": 69.90,
      "mensalidade": 0,
      "mensalidadePontualidade": 189.90,
      "ativo": true
    }
  ]
}
```

Os valores acima são apenas exemplo de formato. A resposta real vem diretamente da tabela `planos_financeiros`.

## Segurança

- somente GET;
- Bearer token individual;
- 120 requisições/minuto por cliente + IP;
- log de acessos no SQLite por 7 dias;
- não expõe alunos, documentos, matrículas individuais ou vendedores;
- use exclusivamente por HTTPS.

Para criar outro consumidor, gere um novo token aleatório e grave apenas `sha256(token)` em `finance_api_keys` dentro de `private-config.php`.
