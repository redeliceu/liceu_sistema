<?php
declare(strict_types=1);
// Copie para private-config.php no servidor e preencha os segredos.
return [
    // V54 — banco principal MySQL/MariaDB. Não versione a senha real.
    'mysql' => [
        'host' => 'SEU_HOST_MYSQL',
        'port' => 3306,
        'database' => 'if0_42632555_liceubrasil',
        'username' => 'SEU_USUARIO_MYSQL',
        'password' => 'SUA_SENHA_MYSQL',
    ],
    'auth_default_users' => [
        ['username'=>'admin','password'=>'TROQUE_AQUI','nome'=>'Administrador','role'=>'admin'],
        ['username'=>'recepcao','password'=>'TROQUE_AQUI','nome'=>'Recepção','role'=>'recepcao'],
        ['username'=>'vendedor','password'=>'TROQUE_AQUI','nome'=>'Vendedor','role'=>'vendedor'],
    ],
    'mapa_admin_password' => 'TROQUE_AQUI',
    'mapa_vendedor_password' => 'TROQUE_AQUI',
    'visitas_reception_password' => 'TROQUE_AQUI',
    'contacts_api_token' => 'TROQUE_AQUI',
    'intake_site_token' => 'TROQUE_AQUI',
    'intake_campaign_key' => 'TROQUE_AQUI',
    'fachada_source_id' => 'TROQUE_AQUI',
    // Guarde somente o SHA-256 do token da API financeira.
    'finance_api_keys' => [
        'sistema_externo' => 'SHA256_DO_TOKEN_AQUI',
    ],
];
