# Liceu Brasil — Hardening v3.7.0

Esta atualização é uma primeira camada de endurecimento de segurança. Ela reduz as superfícies mais críticas, mas não substitui revisão periódica, HTTPS, backups e atualização do servidor.

## O que foi reforçado

- sessão com cookies HttpOnly/SameSite e Secure quando HTTPS;
- expiração por inatividade (2h) e limite absoluto de sessão (12h);
- regeneração periódica do ID da sessão;
- CSRF obrigatório em POSTs autenticados do Mapa, Visitas e logout;
- login com bloqueio progressivo: 5 erros por usuário/IP ou 30 erros por IP em 15 minutos;
- senhas de usuários continuam armazenadas com `password_hash()`;
- endpoints de leitura do Mapa que expunham alunos passaram a exigir sessão e função autorizada;
- API de Visitas exige sessão do sistema, exceto rotas da Arena, que possuem autenticação própria;
- mensagens internas de exceção deixaram de ser devolvidas ao navegador nas APIs principais;
- limite de 5 MB para payload JSON nas APIs principais;
- bloqueio web de SQLite, backups, ZIPs, logs, configurações, diagnósticos e diretórios privados;
- headers HTTP de segurança;
- segredos removidos dos arquivos normais e centralizados em `private-config.php`;
- API financeira externa somente leitura com Bearer token e rate limit.

## Instalação

1. Faça backup da pasta atual e principalmente de `mapa/dados/mapa.sqlite`.
2. Copie os arquivos deste pacote mantendo a estrutura de pastas.
3. Não apague nem substitua `mapa/dados/mapa.sqlite`.
4. Confirme que `private-config.php` existe na raiz.
5. Confirme que a pasta `private/` não é acessível via navegador.
6. Force `Ctrl+F5` e teste login, Visitas, Mapa e Arena.
7. Teste a API financeira via HTTPS.

## Ação importante após instalar

Os tokens antigos da Central já existiam no código-fonte anterior. Mesmo tendo sido movidos para `private-config.php`, recomenda-se rotacionar os tokens na Central assim que possível e substituir os valores em `private-config.php`.

## Testes mínimos

- login válido;
- 5 tentativas de senha errada devem causar bloqueio temporário;
- abrir `/mapa/api.php?action=load` sem login deve retornar 401;
- abrir `/visitas/api.php?action=state` sem login deve retornar 401;
- tentar acessar `/mapa/dados/mapa.sqlite` pelo navegador deve retornar 403;
- tentar acessar `/private-config.php` deve retornar 403;
- alterações normais do Mapa e Visitas devem continuar funcionando.
