# Integrações Liceu Brasil — TI

Pacote de integração somente leitura para o sistema externo.

## 1. API Acadêmica — Alunos e Turmas

Base: `https://mapaliceubrasil.infinityfree.me/mapa/integracao/v1/`

Token exclusivo do TI:

`iKoWtAtXFvU9QSZKzsYmYlPYp7LRqoDfB5i_ECVWPjg_SN0YNb0y69BwTLvPGXYT`

Header obrigatório:

`Authorization: Bearer iKoWtAtXFvU9QSZKzsYmYlPYp7LRqoDfB5i_ECVWPjg_SN0YNb0y69BwTLvPGXYT`

### Alunos
`GET /mapa/integracao/v1/?resource=alunos&status=ativo&limit=500&offset=0`

Paginação: repetir aumentando `offset` (0, 500, 1000...) até `count` retornar menos que `limit`. Limite máximo por chamada: 1000.

Aluno específico:
`GET /mapa/integracao/v1/?resource=aluno&id=123`

Turmas:
`GET /mapa/integracao/v1/?resource=turmas`

Turma específica com alunos:
`GET /mapa/integracao/v1/?resource=turma&id=10`

A resposta de aluno inclui cadastro e `matriculas`, com turma, professor, dia, horário, sala, tipo de curso e situação da matrícula/participação.

## 2. API Financeira — Planos e Valores

Base: `https://mapaliceubrasil.infinityfree.me/api/v1/financeiro/`

Token exclusivo do TI:

`CDWN41koKIvXhKr-Yqsf5HtCZWRl7VZzylgwRQ8DSmy2eO-D5jugoHhNQvRK7J2N`

Header obrigatório:

`Authorization: Bearer CDWN41koKIvXhKr-Yqsf5HtCZWRl7VZzylgwRQ8DSmy2eO-D5jugoHhNQvRK7J2N`

Todos os planos ativos:
`GET /api/v1/financeiro/`

Plano específico:
`GET /api/v1/financeiro/?id=1`

Retorna `id`, `nome`, `taxaMatricula`, `mensalidade`, `mensalidadePontualidade` e `ativo`. A API financeira possui limite de 120 requisições/minuto por cliente + IP e log de acessos.

## Segurança

- As duas APIs são somente leitura.
- Usar somente HTTPS.
- Não colocar os tokens em JavaScript público, aplicativo cliente ou repositório Git. Os tokens devem ficar no backend/servidor do sistema consumidor.
- Os tokens deste pacote são exclusivos para a integração do TI.
- No servidor, os novos tokens são validados por SHA-256; a API não precisa guardar o token acadêmico em texto puro.
