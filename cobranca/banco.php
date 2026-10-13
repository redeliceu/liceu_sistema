<?php
declare(strict_types=1);
require_once __DIR__.'/../mapa/banco.php';

function cobrancaDb(): PDO {
    $pdo=db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS cobranca_contatos(
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        matricula_id INTEGER NOT NULL,
        aluno_id INTEGER NOT NULL,
        usuario_id INTEGER NULL,
        usuario_nome TEXT NULL,
        canal VARCHAR(32) NOT NULL DEFAULT 'whatsapp',
        resultado VARCHAR(32) NOT NULL DEFAULT 'contato_realizado',
        observacao TEXT NULL,
        promessa_data TEXT NULL,
        promessa_valor REAL NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $indexExists = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1');
    $indexExists->execute(['cobranca_contatos', 'idx_cobranca_contatos_matricula']);
    if ($indexExists->fetchColumn() === false) {
        $pdo->exec('CREATE INDEX idx_cobranca_contatos_matricula ON cobranca_contatos(matricula_id,criado_em)');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS cobranca_status(
        matricula_id INTEGER PRIMARY KEY,
        status VARCHAR(32) NOT NULL DEFAULT 'pendente',
        proximo_contato TEXT NULL,
        observacao TEXT NULL,
        atualizado_por INTEGER NULL,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    return $pdo;
}
