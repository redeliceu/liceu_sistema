<?php
declare(strict_types=1);
function whatsappGarantirSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_campanhas (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, tipo VARCHAR(40) NOT NULL, titulo VARCHAR(180) NOT NULL,
      data_referencia DATE NULL, status VARCHAR(30) NOT NULL DEFAULT 'rascunho', total INT NOT NULL DEFAULT 0,
      enviados INT NOT NULL DEFAULT 0, respondidos INT NOT NULL DEFAULT 0, criado_por VARCHAR(180) NULL,
      criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, atualizado_em DATETIME NULL,
      INDEX idx_wa_camp_data(data_referencia), INDEX idx_wa_camp_status(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_mensagens (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, campanha_id BIGINT NULL, aluno_id BIGINT NULL, telefone VARCHAR(32) NOT NULL,
      direcao VARCHAR(12) NOT NULL, tipo VARCHAR(40) NOT NULL DEFAULT 'texto', corpo TEXT NULL,
      provider_message_id VARCHAR(190) NULL, status VARCHAR(30) NOT NULL DEFAULT 'criada', contexto_json LONGTEXT NULL,
      erro TEXT NULL, criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, enviado_em DATETIME NULL,
      entregue_em DATETIME NULL, lido_em DATETIME NULL, recebido_em DATETIME NULL,
      UNIQUE KEY uq_wa_provider(provider_message_id), INDEX idx_wa_tel(telefone), INDEX idx_wa_aluno(aluno_id),
      INDEX idx_wa_camp(campanha_id), INDEX idx_wa_status(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_webhook_eventos (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, event_key VARCHAR(190) NULL, tipo VARCHAR(50) NOT NULL,
      telefone VARCHAR(32) NULL, provider_message_id VARCHAR(190) NULL, payload_json LONGTEXT NULL,
      processado TINYINT(1) NOT NULL DEFAULT 0, criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_wa_event_key(event_key), INDEX idx_wa_event_msg(provider_message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function whatsappTelefone(string $v): string { $n=preg_replace('/\\D+/','',$v)??''; if(strlen($n)===10||strlen($n)===11)$n='55'.$n; return $n; }
