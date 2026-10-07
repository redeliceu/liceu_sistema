<?php
declare(strict_types=1);
require_once __DIR__.'/auth-config.php';
require_once __DIR__.'/security.php';

function authProfiles(): array { return ['admin','recepcao','pedagogico','aux_pedagogico','financeiro','professor','vendedor']; }
function authProfileLabels(): array { return ['admin'=>'Diretor Master','recepcao'=>'Recepção','pedagogico'=>'Pedagógico / Coordenação','aux_pedagogico'=>'Auxiliar Pedagógico','financeiro'=>'Financeiro / Cobrança','professor'=>'Professor','vendedor'=>'Vendedor']; }
function authPermissionCatalog(): array { return [
 'app.mapa'=>'Acessar Mapa','mapa.editar_pedagogico'=>'Editar operação pedagógica','mapa.admin_supremo'=>'Administração Suprema do Mapa',
 'app.visitas'=>'Acessar Visitas','visitas.operar'=>'Operar visitas','visitas.consultar'=>'Consultar aba Visitas','visitas.cq'=>'Controle de Qualidade',
 'app.cobranca'=>'Acessar Cobrança','cobranca.followup'=>'Follow-ups e promessas','cobranca.total'=>'Cobrança operacional completa',
 'app.analytics'=>'Acessar Analytics','app.professor'=>'Painel Professor','professor.chamadas'=>'Chamadas das próprias turmas',
 'sistema.usuarios'=>'Administrar usuários e permissões'
 ]; }
function authProfilePermissions(string $role): array {
 $p=[
  'admin'=>array_keys(authPermissionCatalog()),
  'recepcao'=>['app.mapa','app.visitas','visitas.operar','app.cobranca','cobranca.followup'],
  'pedagogico'=>['app.mapa','mapa.editar_pedagogico','app.visitas','visitas.consultar'],
  'aux_pedagogico'=>['app.mapa'],
  'financeiro'=>['app.visitas','visitas.cq','app.cobranca','cobranca.followup','cobranca.total'],
  'professor'=>['app.professor','professor.chamadas'],
  'vendedor'=>[]
 ]; return $p[$role]??[];
}
function authInit(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios_sistema(id BIGINT AUTO_INCREMENT PRIMARY KEY,username VARCHAR(120) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,nome VARCHAR(255) NOT NULL,role VARCHAR(40) NOT NULL,vendedor_id INT NULL,professor_id INT NULL,ativo TINYINT NOT NULL DEFAULT 1,ultimo_login DATETIME NULL,criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS usuario_permissoes(usuario_id INT NOT NULL,permissao VARCHAR(160) NOT NULL,valor INT NOT NULL,atualizado_por INT NULL,atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(usuario_id,permissao))");
  $pdo->exec("CREATE TABLE IF NOT EXISTS auth_permissoes_auditoria(id BIGINT AUTO_INCREMENT PRIMARY KEY,usuario_id INT NOT NULL,permissao VARCHAR(160) NOT NULL,valor_anterior INT NULL,valor_novo INT NULL,alterado_por INT NULL,alterado_por_nome VARCHAR(255) NULL,criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS auth_login_tentativas(id BIGINT AUTO_INCREMENT PRIMARY KEY,ip VARCHAR(64) NOT NULL,username VARCHAR(120) NOT NULL,sucesso TINYINT NOT NULL DEFAULT 0,criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  if(!indiceExiste($pdo,'idx_auth_login_tentativas_lookup')) $pdo->exec("CREATE INDEX idx_auth_login_tentativas_lookup ON auth_login_tentativas(ip,username,criado_em)");
  if((int)$pdo->query("SELECT COUNT(*) FROM usuarios_sistema")->fetchColumn()===0){$st=$pdo->prepare("INSERT INTO usuarios_sistema(username,password_hash,nome,role) VALUES(?,?,?,?)");foreach(authDefaultUsers() as $u){if(!is_array($u)||empty($u['username'])||empty($u['password'])||empty($u['role']))continue;$st->execute([strtolower(trim((string)$u['username'])),password_hash((string)$u['password'],PASSWORD_DEFAULT),(string)($u['nome']??$u['username']),(string)$u['role']]);}}
}

function authRole(): string{return(string)($_SESSION['auth_role']??'guest');} function authUserId():?int{return isset($_SESSION['auth_user_id'])?(int)$_SESSION['auth_user_id']:null;} function authVendedorId():?int{return isset($_SESSION['auth_vendedor_id'])&&$_SESSION['auth_vendedor_id']!==null?(int)$_SESSION['auth_vendedor_id']:null;} function authProfessorId():?int{return isset($_SESSION['auth_professor_id'])&&$_SESSION['auth_professor_id']!==null?(int)$_SESSION['auth_professor_id']:null;} function authLogged():bool{return authUserId()!==null&&authRole()!=='guest';}
function authPermission(PDO $pdo,string $perm): bool {
  if(!authLogged()) return false;
  // V54.14: a exceção individual sempre vence o pacote padrão do perfil,
  // inclusive para Diretor Master. Antes o admin retornava true aqui e um
  // bloqueio individual salvo no painel nunca era aplicado.
  $s=$pdo->prepare("SELECT valor FROM usuario_permissoes WHERE usuario_id=? AND permissao=?");
  $s->execute([authUserId(),$perm]);
  $v=$s->fetchColumn();
  if($v!==false) return ((int)$v===1);
  return in_array($perm,authProfilePermissions(authRole()),true);
}
function authRequirePermission(PDO $pdo,string $perm,bool $json=true):void{if(!authLogged()){http_response_code(401);if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'Sessão expirada.']);}else echo 'Sessão expirada.';exit;}if(!authPermission($pdo,$perm)){http_response_code(403);if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'Sem permissão para esta ação.']);}else echo 'Sem permissão para esta área.';exit;}}
function authCsrfToken():string{if(empty($_SESSION['csrf_token'])||!is_string($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));return $_SESSION['csrf_token'];} function authVerifyCsrf(?string $t):bool{$e=(string)($_SESSION['csrf_token']??'');return $e!==''&&is_string($t)&&$t!==''&&hash_equals($e,$t);}
function authStatusPayload():array{return ['ok'=>true,'logged'=>authLogged(),'user'=>authLogged()?['id'=>authUserId(),'username'=>(string)($_SESSION['auth_username']??''),'nome'=>(string)($_SESSION['auth_nome']??''),'role'=>authRole(),'vendedorId'=>authVendedorId(),'professorId'=>authProfessorId()]:null,'csrfToken'=>authLogged()?authCsrfToken():null];}
function authLoginBlocked(PDO $pdo,string $u,string $ip):bool{$pdo->exec("DELETE FROM auth_login_tentativas WHERE criado_em < DATE_SUB(NOW(), INTERVAL 2 DAY)");$s=$pdo->prepare("SELECT COUNT(*) FROM auth_login_tentativas WHERE ip=? AND username=? AND sucesso=0 AND criado_em>=DATE_SUB(NOW(), INTERVAL 15 MINUTE)");$s->execute([$ip,$u]);if((int)$s->fetchColumn()>=5)return true;$s=$pdo->prepare("SELECT COUNT(*) FROM auth_login_tentativas WHERE ip=? AND sucesso=0 AND criado_em>=DATE_SUB(NOW(), INTERVAL 15 MINUTE)");$s->execute([$ip]);return(int)$s->fetchColumn()>=30;}
function authRecordLogin(PDO $pdo,string $u,string $ip,bool $ok):void{$pdo->prepare("INSERT INTO auth_login_tentativas(ip,username,sucesso)VALUES(?,?,?)")->execute([$ip,$u,$ok?1:0]);if($ok)$pdo->prepare("DELETE FROM auth_login_tentativas WHERE ip=? AND username=? AND sucesso=0")->execute([$ip,$u]);}
function authLogin(PDO $pdo,string $username,string $password):array{$username=strtolower(trim($username));$ip=liceuClientIp();if($username===''||$password==='')return['ok'=>false,'error'=>'Usuário ou senha inválidos.','status'=>401];if(authLoginBlocked($pdo,$username,$ip))return['ok'=>false,'error'=>'Muitas tentativas. Aguarde 15 minutos e tente novamente.','status'=>429];$s=$pdo->prepare("SELECT * FROM usuarios_sistema WHERE username=? LIMIT 1");$s->execute([$username]);$u=$s->fetch(PDO::FETCH_ASSOC);if(!$u||!(int)$u['ativo']||!password_verify($password,(string)$u['password_hash'])){authRecordLogin($pdo,$username,$ip,false);usleep(random_int(150000,350000));return['ok'=>false,'error'=>'Usuário ou senha inválidos.','status'=>401];}authRecordLogin($pdo,$username,$ip,true);session_regenerate_id(true);$_SESSION['auth_user_id']=(int)$u['id'];$_SESSION['auth_username']=$u['username'];$_SESSION['auth_nome']=$u['nome'];$_SESSION['auth_role']=$u['role'];$_SESSION['auth_vendedor_id']=$u['vendedor_id']!==null?(int)$u['vendedor_id']:null;$_SESSION['auth_professor_id']=$u['professor_id']!==null?(int)$u['professor_id']:null;$_SESSION['visitas_admin']=$u['role']==='admin';$_SESSION['visitas_recepcao']=$u['role']==='recepcao';$_SESSION['is_admin']=$u['role']==='admin';$_SESSION['is_vendedor']=$u['role']==='vendedor';$_SESSION['csrf_token']=bin2hex(random_bytes(32));$_SESSION['_security_created_at']=time();$_SESSION['_security_last_activity']=time();$pdo->prepare("UPDATE usuarios_sistema SET ultimo_login=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$u['id']]);return authStatusPayload();}
function authLogout():void{
  $_SESSION=[];
  if(ini_get('session.use_cookies')){
    $p=session_get_cookie_params();
    setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);
  }
  if(session_status()===PHP_SESSION_ACTIVE) session_destroy();
} function authRequireRoles(array $r):void{if(!authLogged()){http_response_code(401);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'Sessão expirada.']);exit;}if(!in_array(authRole(),$r,true)){http_response_code(403);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'Sem permissão.']);exit;}}
