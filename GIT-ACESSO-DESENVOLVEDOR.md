# Git privado — modelo recomendado

## Estrutura

Mantenha o repositório privado sob uma conta/organização controlada pelo responsável do projeto. O desenvolvedor da empresa recebe acesso de escrita, não acesso de Owner/Admin.

## Proteções recomendadas para `main`

- bloquear push direto;
- exigir Pull Request;
- exigir pelo menos uma aprovação;
- bloquear force-push;
- bloquear exclusão da branch;
- exigir conversa resolvida antes do merge;
- usar CODEOWNERS para áreas críticas.

## Importante

Quem possui acesso de leitura a um repositório Git consegue clonar e manter uma cópia do código. Git não permite dar acesso ao fonte e, ao mesmo tempo, impedir tecnicamente que o fonte seja copiado. A proteção contra uso indevido precisa ser complementada por contrato/licença.

## Nunca versionar

- `private-config.php`;
- `private/`;
- `*.sqlite`, `*.db`;
- backups;
- ZIPs;
- logs;
- tokens e chaves;
- arquivos `.env` reais.

Use `private-config.example.php` como modelo sem segredos.
